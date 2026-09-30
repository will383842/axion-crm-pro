<?php

namespace App\Crm\Joignabilite;

use App\Crm\Doublons\AdressesPartagees;
use App\Crm\Emails\VerificationEmail;
use App\Crm\Personnes\NatureEmail;
use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use stdClass;

/**
 * LA JOIGNABILITÉ D'UNE FICHE — une définition, calculée ici (chantier D).
 *
 * Modèle : `federations.contactabilite` (#255), étendu à TOUTES les fiches
 * (`companies`) et à toutes les personnes (`contacts`). L'état est rangé dans
 * la colonne `joignabilite` des deux tables (migrations `2026_10_01_000022`
 * et `000023`, qui disent pourquoi une colonne plutôt qu'une vue ou des
 * étiquettes).
 *
 * ── `email_valide` DIT CE QUE DIT L'ENVOI ────────────────────────────────────
 *
 * La règle est celle de `crm:campagne:destinataires`, et elle se décide PAR
 * ADRESSE, jamais par fiche : toutes les occurrences d'une adresse dans
 * l'espace (adresse générique d'une fiche vivante, adresse d'une personne
 * d'une fiche vivante — la corbeille n'est jamais lue) sont regroupées, et
 * UNE seule occurrence qui la condamne la condamne PARTOUT.
 *
 * ⚠️ LIMITE ASSUMÉE — ESPACE CONTRE SEGMENT. La joignabilité regroupe les
 * occurrences de l'ESPACE ; la campagne, celles des fiches de SON SEGMENT.
 * L'équivalence `email_valide` ⟺ « l'adresse part » tient quand toutes les
 * occurrences de l'adresse sont dans le segment de la campagne — c'est ce
 * que garde le test d'accord (`JoignabiliteTest`). Hors de ce cas, les deux
 * peuvent diverger (une occurrence hors segment qui condamne ou qui vérifie
 * l'adresse ; le test fige les deux sens). La joignabilité est un INDICATEUR
 * DE CIBLAGE ; la liste de campagne reste la vérité au moment de l'envoi.
 *
 * ── Les états d'une ADRESSE, dans l'ordre où ils se décident ────────────────
 *
 *  1. `email_interdit`     opposition (`opt_out`) ou liste de suppression, dans
 *                          l'UNIVERS de l'espace (`business`, ou `vivier` pour
 *                          l'espace des candidats) — la question de
 *                          `EligibiliteCampagne::peutRecevoir`, posée par lot.
 *                          Testée EN PREMIER : une adresse opposée ET invalide
 *                          est `email_interdit`, parce que l'opposition est une
 *                          VOLONTÉ, qui doit se lire telle quelle ;
 *  2. `email_invalide`     GARDÉE, jamais envoyée : syntaxe refusée par
 *                          `FILTER_VALIDATE_EMAIL` (la règle de la campagne),
 *                          une occurrence au statut `invalid`/`disposable`, ou
 *                          une vérification `invalide`/`jetable` ;
 *  3. `email_non_verifie`  aucune occurrence n'a été vérifiée `valide` par
 *                          `crm:emails:verifier` ;
 *  4. `email_personnel`    messagerie grand public (`NatureEmail`, gmail…) ou
 *                          adresse marquée personnelle : jamais en campagne
 *                          (décision D3) ;
 *  5. `email_partage`      adresse de CABINET COMPTABLE ou de DOMICILIATION
 *                          portée par plusieurs fiches (`AdressesPartagees`) :
 *                          écartée par défaut de la campagne,
 *                          `--avec-adresses-partagees` la garde. ÉTAT DÉDIÉ
 *                          plutôt qu'une note « exclue par défaut » : sinon
 *                          `email_valide` compterait des adresses qui ne partent
 *                          pas, exactement l'écart que cet état doit fermer ;
 *  6. `email_valide`       elle part.
 *
 * Sans adresse : `sans_email_avec_telephone`, sinon `sans_contact`.
 *
 * ── Une ENTREPRISE ──────────────────────────────────────────────────────────
 *
 * La MEILLEURE de ses adresses (générique et personnes non supprimées), dans
 * l'ordre de `ETATS`. Sans aucune adresse : téléphone de la fiche OU d'une
 * personne, sinon `sans_contact`.
 *
 * ⚠️ UNE PHOTO. Une opposition arrivée depuis ne change l'état qu'au prochain
 * calcul. Il sert à CIBLER ; l'envoi repose la question adresse par adresse.
 *
 * Rien ici ne supprime ni ne réécrit une adresse.
 */
final class Joignabilite
{
    public const EMAIL_VALIDE = 'email_valide';

    public const EMAIL_PARTAGE = 'email_partage';

    public const EMAIL_NON_VERIFIE = 'email_non_verifie';

    public const EMAIL_PERSONNEL = 'email_personnel';

    public const EMAIL_INVALIDE = 'email_invalide';

    public const EMAIL_INTERDIT = 'email_interdit';

    public const SANS_EMAIL_AVEC_TELEPHONE = 'sans_email_avec_telephone';

    public const SANS_CONTACT = 'sans_contact';

    /**
     * Du plus joignable au moins joignable — l'ordre de l'agrégation par fiche.
     *
     * @var list<string>
     */
    public const ETATS = [
        self::EMAIL_VALIDE,
        self::EMAIL_PARTAGE,
        self::EMAIL_NON_VERIFIE,
        self::EMAIL_PERSONNEL,
        self::EMAIL_INVALIDE,
        self::EMAIL_INTERDIT,
        self::SANS_EMAIL_AVEC_TELEPHONE,
        self::SANS_CONTACT,
    ];

    /** @var array<string, string> libellés (exportés vers l'écran) */
    public const LIBELLES = [
        self::EMAIL_VALIDE => 'E-mail vérifié valide (part en campagne)',
        self::EMAIL_PARTAGE => 'E-mail partagé (cabinet, domiciliation) — exclu par défaut',
        self::EMAIL_NON_VERIFIE => 'E-mail non vérifié',
        self::EMAIL_PERSONNEL => 'E-mail personnel (jamais en campagne)',
        self::EMAIL_INVALIDE => 'E-mail invalide (gardé, jamais envoyé)',
        self::EMAIL_INTERDIT => 'E-mail interdit (opposition ou suppression)',
        self::SANS_EMAIL_AVEC_TELEPHONE => 'Sans e-mail, avec téléphone',
        self::SANS_CONTACT => 'Sans contact',
    ];

    /**
     * Au plus, le nombre de fiches que `recalculer()` ajoute à celles qu'on lui
     * donne (les autres porteuses des mêmes adresses). Au-delà, il s'en tient
     * aux fiches données (journal `warning`).
     */
    public const EXTENSION_MAX = 5000;

    /** Statuts qui condamnent l'adresse (règle de la liste de campagne). */
    private const STATUTS_CONDAMNES = ['invalid', 'disposable'];

    /** La clé d'une adresse : celle de la liste de campagne. */
    public static function cle(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * L'univers de la liste de suppression d'un espace : `vivier` pour
     * l'espace des candidats, `business` sinon — la règle de
     * `crm:emails:verifier`.
     */
    public static function universDe(string $workspaceId): string
    {
        return DB::table('workspaces')->where('id', $workspaceId)->whereNull('deleted_at')->value('slug') === Taxonomy::VIVIER_WORKSPACE_SLUG
            ? 'vivier'
            : 'business';
    }

    /**
     * L'état d'UNE adresse, connaissant TOUTES ses occurrences — la décision
     * pure (sans base), dans l'ordre de l'en-tête.
     *
     * @param  list<array{statut: ?string, verification: ?string, perso: bool}>  $occurrences
     */
    public static function decider(string $cle, array $occurrences, bool $interdite, bool $partagee): string
    {
        if ($interdite) {
            return self::EMAIL_INTERDIT;
        }
        foreach ($occurrences as $o) {
            if (in_array($o['statut'], self::STATUTS_CONDAMNES, true)
                || in_array($o['verification'], [VerificationEmail::INVALIDE, VerificationEmail::JETABLE], true)) {
                return self::EMAIL_INVALIDE;
            }
        }
        if (filter_var($cle, FILTER_VALIDATE_EMAIL) === false) {
            return self::EMAIL_INVALIDE;
        }
        $verifiee = false;
        $perso = NatureEmail::de($cle) === 'perso';
        foreach ($occurrences as $o) {
            $verifiee = $verifiee || $o['verification'] === VerificationEmail::VALIDE;
            $perso = $perso || $o['perso'];
        }
        if (! $verifiee) {
            return self::EMAIL_NON_VERIFIE;
        }
        if ($perso) {
            return self::EMAIL_PERSONNEL;
        }

        return $partagee ? self::EMAIL_PARTAGE : self::EMAIL_VALIDE;
    }

    /**
     * L'état de chaque adresse donnée, lu sur TOUTES ses occurrences dans
     * l'espace.
     *
     * @param  list<string>  $emails
     * @return array<string, string> clé (`cle()`) => état
     */
    public static function etatsAdresses(string $workspaceId, array $emails, string $univers): array
    {
        $cles = [];
        foreach ($emails as $e) {
            $c = self::cle($e);
            if ($c !== '') {
                $cles[$c] = true;
            }
        }
        $cles = array_keys($cles);
        if ($cles === []) {
            return [];
        }

        /** @var array<string, list<array{statut: ?string, verification: ?string, perso: bool}>> $occurrences */
        $occurrences = array_fill_keys($cles, []);
        foreach (array_chunk($cles, 1000) as $paquet) {
            // `contacts.email` est un CITEXT : la casse est déjà ignorée, et
            // l'index `idx_contacts_email` sert la recherche.
            // La personne ET sa fiche hors corbeille : la campagne ne lit que
            // des fiches vivantes (relecture de #265).
            foreach (DB::table('contacts')
                ->join('companies', 'companies.id', '=', 'contacts.company_id')
                ->where('contacts.workspace_id', $workspaceId)->whereIn('contacts.email', $paquet)
                ->whereNull('contacts.deleted_at')->whereNull('companies.deleted_at')
                ->get(['contacts.email', 'contacts.email_status', DB::raw("contacts.metadata -> 'email_verification' AS verif"), DB::raw("contacts.metadata ->> 'email_nature' AS nature")]) as $p) {
                $c = self::cle((string) $p->email);
                $occurrences[$c][] = [
                    'statut' => self::texte($p->email_status),
                    'verification' => VerificationEmail::statutDe(self::json($p->verif), $c),
                    'perso' => $p->nature === 'perso',
                ];
            }
            // Servie par `idx_companies_email_generic_minuscules`.
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNotNull('email_generic')
                ->whereIn(DB::raw('lower(email_generic)'), $paquet)->whereNull('deleted_at')
                ->get(['email_generic', DB::raw("signals -> 'email_generic_verification' AS verif")]) as $f) {
                $c = self::cle((string) $f->email_generic);
                $occurrences[$c][] = [
                    'statut' => null,
                    'verification' => VerificationEmail::statutDe(self::json($f->verif), (string) $f->email_generic),
                    'perso' => false,
                ];
            }
        }

        $interdites = self::interditesParmi($cles, $univers);
        // Question posée DANS le contexte de l'espace (la fonction SQL le
        // vérifie) : ce calcul peut être appelé hors de lui.
        $partagees = WorkspaceContext::run($workspaceId, static fn (): array => AdressesPartagees::exclues($workspaceId, $cles));

        $etats = [];
        foreach ($cles as $c) {
            $etats[$c] = self::decider(
                $c,
                $occurrences[$c] ?? [],
                isset($interdites[ListeSuppression::empreinte($c)]),
                isset($partagees[$c]),
            );
        }

        return $etats;
    }

    /**
     * L'état d'une ENTREPRISE, connaissant ceux de ses adresses.
     *
     * @param  list<?string>  $etatsAdresses  états des adresses (null = pas d'adresse)
     */
    public static function etatEntreprise(array $etatsAdresses, bool $aUnTelephone): string
    {
        $meilleur = null;
        foreach ($etatsAdresses as $etat) {
            if ($etat === null) {
                continue;
            }
            if ($meilleur === null || array_search($etat, self::ETATS, true) < array_search($meilleur, self::ETATS, true)) {
                $meilleur = $etat;
            }
        }

        return $meilleur ?? ($aUnTelephone ? self::SANS_EMAIL_AVEC_TELEPHONE : self::SANS_CONTACT);
    }

    /**
     * Les adresses opposées OU supprimées parmi celles données, dans UN
     * univers, en deux requêtes — la question de
     * `EligibiliteCampagne::peutRecevoir($email, $univers)`, posée pour un lot.
     *
     * @param  list<string>  $emails
     * @return array<string, true> empreinte => true
     */
    public static function interditesParmi(array $emails, string $univers): array
    {
        $empreintes = [];
        foreach ($emails as $email) {
            $c = self::cle($email);
            if ($c !== '') {
                $empreintes[ListeSuppression::empreinte($c)] = true;
            }
        }
        if ($empreintes === []) {
            return [];
        }
        $interdites = [];
        foreach (array_chunk(array_keys($empreintes), 1000) as $paquet) {
            foreach (['opt_out', 'email_suppressions'] as $table) {
                foreach (DB::table($table)->where('scope', $univers)->whereIn('email_hash', $paquet)->pluck('email_hash') as $h) {
                    $interdites[(string) $h] = true;
                }
            }
        }

        return $interdites;
    }

    /**
     * Calcule l'état des entreprises données ET de leurs personnes. Les fiches
     * à la corbeille sont ignorées.
     *
     * `$verrouiller` (dans une transaction) : les fiches et leurs personnes
     * sont lues `FOR UPDATE`. Une écriture concurrente de `crm:emails:verifier`
     * attend alors la fin du calcul et recalcule APRÈS lui — jamais un état
     * périmé écrit par-dessus son résultat ; et si elle est passée avant, la
     * lecture verrouillée voit ses données.
     *
     * @param  array<int>  $ids
     * @return array{entreprises: array<int, array{avant: ?string, apres: string}>, personnes: array<int, array{avant: ?string, apres: string}>}
     */
    public static function calculer(string $workspaceId, array $ids, string $univers, bool $verrouiller = false): array
    {
        $resultat = ['entreprises' => [], 'personnes' => []];
        $ids = array_values($ids);
        if ($ids === []) {
            return $resultat;
        }
        $requeteFiches = DB::table('companies')->where('workspace_id', $workspaceId)->whereIn('id', $ids)->whereNull('deleted_at')->orderBy('id');
        // Les personnes des seules fiches lues ci-dessus (vivantes) : `calculer()`
        // ne rend d'état que pour elles.
        $requetePersonnes = DB::table('contacts')->where('workspace_id', $workspaceId)->whereIn('company_id', $ids)->whereNull('deleted_at')->orderBy('id');
        if ($verrouiller) {
            $requeteFiches->lockForUpdate();
            $requetePersonnes->lockForUpdate();
        }
        $fiches = $requeteFiches->get(['id', 'email_generic', 'phone', 'joignabilite']);
        $personnes = $requetePersonnes->get(['id', 'company_id', 'email', 'phone', 'joignabilite']);

        $emails = [];
        foreach ($fiches as $f) {
            if (self::renseigne(self::texte($f->email_generic))) {
                $emails[] = (string) $f->email_generic;
            }
        }
        foreach ($personnes as $p) {
            if (self::renseigne(self::texte($p->email))) {
                $emails[] = (string) $p->email;
            }
        }
        $etats = self::etatsAdresses($workspaceId, $emails, $univers);
        $etatDe = static fn (?string $email): ?string => self::renseigne($email) ? ($etats[self::cle((string) $email)] ?? null) : null;

        /** @var array<int, list<stdClass>> $parFiche */
        $parFiche = [];
        foreach ($personnes as $p) {
            $parFiche[(int) $p->company_id][] = $p;
        }

        foreach ($fiches as $f) {
            $adresses = [$etatDe(self::texte($f->email_generic))];
            $telephone = self::renseigne(self::texte($f->phone));
            foreach ($parFiche[(int) $f->id] ?? [] as $p) {
                $etat = $etatDe(self::texte($p->email));
                $resultat['personnes'][(int) $p->id] = [
                    'avant' => self::texte($p->joignabilite),
                    'apres' => $etat ?? (self::renseigne(self::texte($p->phone)) ? self::SANS_EMAIL_AVEC_TELEPHONE : self::SANS_CONTACT),
                ];
                $adresses[] = $etat;
                $telephone = $telephone || self::renseigne(self::texte($p->phone));
            }
            $resultat['entreprises'][(int) $f->id] = ['avant' => self::texte($f->joignabilite), 'apres' => self::etatEntreprise($adresses, $telephone)];
        }

        return $resultat;
    }

    /**
     * Écrit les états qui ont CHANGÉ. À appeler dans une transaction qui a posé
     * `app.conserver_updated_at` : calculer n'est pas modifier la fiche.
     *
     * @param  array{entreprises: array<int, array{avant: ?string, apres: string}>, personnes: array<int, array{avant: ?string, apres: string}>}  $calcul
     * @return array{entreprises: int, personnes: int}
     */
    public static function ecrire(string $workspaceId, array $calcul): array
    {
        $ecrites = ['entreprises' => 0, 'personnes' => 0];
        foreach (['entreprises' => 'companies', 'personnes' => 'contacts'] as $cle => $table) {
            $changes = array_filter($calcul[$cle], static fn (array $e): bool => $e['avant'] !== $e['apres']);
            foreach (array_chunk($changes, 1000, true) as $paquet) {
                $liaisons = [];
                foreach ($paquet as $id => $e) {
                    array_push($liaisons, $id, $e['apres']);
                }
                $liaisons[] = $workspaceId;
                $lignes = DB::select(
                    "UPDATE {$table} AS t SET joignabilite = v.etat
                     FROM (VALUES " . implode(', ', array_fill(0, count($paquet), '(?::bigint, ?::text)')) . ') AS v(id, etat)
                     WHERE t.id = v.id AND t.workspace_id = ?::uuid AND t.deleted_at IS NULL
                       AND t.joignabilite IS DISTINCT FROM v.etat
                     RETURNING t.id',
                    $liaisons,
                );
                $ecrites[$cle] += count($lignes);
            }
        }

        return $ecrites;
    }

    /**
     * Recalcule et écrit, pour les fiches données ET pour toutes les fiches qui
     * portent l'une de leurs adresses (une adresse condamnée l'est PARTOUT).
     * Appelé par `crm:emails:verifier` dans la transaction du lot qui vient de
     * changer une vérification ou un statut.
     *
     * @param  array<int>  $ids
     * @return array{entreprises: int, personnes: int}
     */
    public static function recalculer(string $workspaceId, array $ids, string $univers): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return ['entreprises' => 0, 'personnes' => 0];
        }

        return self::ecrire($workspaceId, self::calculer($workspaceId, self::fichesPortantLesMemesAdresses($workspaceId, $ids), $univers));
    }

    /**
     * Les fiches données, plus toutes celles qui portent l'une de leurs
     * adresses (générique ou personne).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public static function fichesPortantLesMemesAdresses(string $workspaceId, array $ids): array
    {
        $cles = [];
        foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereIn('id', $ids)->whereNull('deleted_at')->whereNotNull('email_generic')->pluck('email_generic') as $e) {
            $cles[self::cle((string) $e)] = true;
        }
        foreach (DB::table('contacts')
            ->join('companies', 'companies.id', '=', 'contacts.company_id')
            ->where('contacts.workspace_id', $workspaceId)->whereIn('contacts.company_id', $ids)
            ->whereNull('contacts.deleted_at')->whereNull('companies.deleted_at')->whereNotNull('contacts.email')
            ->pluck('contacts.email') as $e) {
            $cles[self::cle((string) $e)] = true;
        }
        $tous = array_fill_keys($ids, true);
        $liste = array_values(array_filter(array_map('strval', array_keys($cles)), static fn (string $k): bool => $k !== ''));
        foreach (array_chunk($liste, 1000) as $paquet) {
            foreach (DB::table('contacts')
                ->join('companies', 'companies.id', '=', 'contacts.company_id')
                ->where('contacts.workspace_id', $workspaceId)->whereIn('contacts.email', $paquet)
                ->whereNull('contacts.deleted_at')->whereNull('companies.deleted_at')
                ->limit(self::EXTENSION_MAX + 1)->pluck('contacts.company_id') as $id) {
                $tous[(int) $id] = true;
            }
            if (count($tous) > self::EXTENSION_MAX) {
                break;
            }
            foreach (DB::table('companies')->where('workspace_id', $workspaceId)->whereNotNull('email_generic')
                ->whereIn(DB::raw('lower(email_generic)'), $paquet)->whereNull('deleted_at')
                ->limit(self::EXTENSION_MAX + 1)->pluck('id') as $id) {
                $tous[(int) $id] = true;
            }
        }

        if (count($tous) > self::EXTENSION_MAX) {
            // Une adresse portée par un très grand nombre de fiches (une
            // domiciliation, un cabinet) : on ne recalcule pas des milliers de
            // fiches dans la transaction d'un lot de vérification. Les fiches
            // données, elles, sont toujours recalculées ; les autres le seront
            // au prochain `crm:joignabilite:calculer`. On le DIT.
            Log::warning('Joignabilité : extension du recalcul plafonnée', ['fiches' => count($ids), 'plafond' => self::EXTENSION_MAX]);

            return $ids;
        }

        return array_keys($tous);
    }

    private static function renseigne(?string $valeur): bool
    {
        return $valeur !== null && trim($valeur) !== '';
    }

    private static function texte(mixed $valeur): ?string
    {
        return is_scalar($valeur) ? (string) $valeur : null;
    }

    private static function json(mixed $valeur): mixed
    {
        return is_string($valeur) ? json_decode($valeur, true) : $valeur;
    }
}
