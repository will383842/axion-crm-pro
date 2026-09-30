<?php

namespace App\Crm\Joignabilite;

use App\Crm\Emails\QualificationEmail;
use App\Crm\Emails\VerificationEmail;
use App\Support\ListeSuppression;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * LA JOIGNABILITÉ D'UNE FICHE — une définition, calculée ici (chantier D).
 *
 * Modèle : `federations.contactabilite` (#255), étendu à TOUTES les fiches
 * (`companies`) et à toutes les personnes (`contacts`). L'état est rangé dans
 * la colonne `joignabilite` des deux tables (migration `2026_10_01_000022`,
 * qui dit pourquoi une colonne plutôt qu'une vue ou des étiquettes).
 *
 * ── Les états ───────────────────────────────────────────────────────────────
 *
 *  - `email_valide`       l'adresse a été vérifiée VALIDE par `crm:emails:verifier`
 *                         (`VerificationEmail::statutDe` = `valide`), son statut
 *                         n'est ni `invalid` ni `disposable`, et elle n'est ni
 *                         opposée ni en liste de suppression. C'est la règle de
 *                         `crm:campagne:destinataires`, mot pour mot ;
 *  - `email_non_verifie`  une adresse existe, rien ne la condamne, mais elle n'a
 *                         pas (encore) été vérifiée par `crm:emails:verifier` ;
 *  - `email_invalide`     l'adresse est GARDÉE, jamais envoyée : syntaxe fausse,
 *                         domaine qui ne reçoit pas, jetable, ou statut
 *                         `invalid`/`disposable` (un rebond dur l'écrit) ;
 *  - `email_interdit`     l'adresse est GARDÉE, jamais envoyée : opposition
 *                         (`opt_out`) ou liste de suppression (plainte, rebonds
 *                         répétés, manuel) — `EligibiliteCampagne::peutRecevoir`
 *                         répondrait NON. État ajouté aux cinq demandés : une
 *                         opposition n'est pas une adresse invalide, et les
 *                         confondre rendrait impossible de répondre à « cette
 *                         personne s'est-elle opposée ? » ;
 *  - `sans_email_avec_telephone`  aucune adresse, un téléphone ;
 *  - `sans_contact`       ni adresse ni téléphone.
 *
 * ── Une ENTREPRISE ──────────────────────────────────────────────────────────
 *
 * Elle est joignable par la MEILLEURE de ses adresses : l'e-mail générique
 * (`email_generic`, vérification dans `signals.email_generic_verification`)
 * et celles de ses personnes non supprimées — exactement les adresses que lit
 * `crm:campagne:destinataires`. Ordre : valide > non vérifiée > invalide >
 * interdite. Sans aucune adresse : téléphone de la fiche OU d'une personne,
 * sinon `sans_contact`.
 *
 * ⚠️ UNE PHOTO. L'état est juste au moment du calcul ; une opposition arrivée
 * depuis ne le change qu'au prochain calcul. Il sert à CIBLER. L'envoi, lui,
 * repose la question adresse par adresse (`EligibiliteCampagne::peutRecevoir`),
 * comme toujours.
 *
 * Rien ici ne supprime ni ne réécrit une adresse : garder une adresse invalide
 * empêche de la réimporter et de la réécrire.
 */
final class Joignabilite
{
    public const EMAIL_VALIDE = 'email_valide';

    public const EMAIL_NON_VERIFIE = 'email_non_verifie';

    public const EMAIL_INVALIDE = 'email_invalide';

    public const EMAIL_INTERDIT = 'email_interdit';

    public const SANS_EMAIL_AVEC_TELEPHONE = 'sans_email_avec_telephone';

    public const SANS_CONTACT = 'sans_contact';

    /**
     * Du plus joignable au moins joignable — l'ordre de l'agrégation.
     *
     * @var list<string>
     */
    public const ETATS = [
        self::EMAIL_VALIDE,
        self::EMAIL_NON_VERIFIE,
        self::EMAIL_INVALIDE,
        self::EMAIL_INTERDIT,
        self::SANS_EMAIL_AVEC_TELEPHONE,
        self::SANS_CONTACT,
    ];

    /** @var array<string, string> libellés (exportés vers l'écran) */
    public const LIBELLES = [
        self::EMAIL_VALIDE => 'E-mail vérifié valide',
        self::EMAIL_NON_VERIFIE => 'E-mail non vérifié',
        self::EMAIL_INVALIDE => 'E-mail invalide (gardé, jamais envoyé)',
        self::EMAIL_INTERDIT => 'E-mail interdit (opposition ou suppression)',
        self::SANS_EMAIL_AVEC_TELEPHONE => 'Sans e-mail, avec téléphone',
        self::SANS_CONTACT => 'Sans contact',
    ];

    /** Statuts de personne qui condamnent l'adresse (règle de la liste de campagne). */
    private const STATUTS_CONDAMNES = ['invalid', 'disposable'];

    /**
     * L'état d'UNE adresse (null si aucune adresse).
     *
     * @param  array<string, true>  $interdites  empreintes (`ListeSuppression::empreinte`) opposées ou supprimées
     */
    public static function etatAdresse(?string $email, ?string $statut, mixed $verification, array $interdites): ?string
    {
        $email = is_string($email) ? trim($email) : '';
        if ($email === '') {
            return null;
        }
        $verdict = VerificationEmail::statutDe($verification, QualificationEmail::normaliser($email));
        if (! QualificationEmail::syntaxeValide($email)
            || in_array($statut, self::STATUTS_CONDAMNES, true)
            || $verdict === VerificationEmail::INVALIDE
            || $verdict === VerificationEmail::JETABLE
        ) {
            return self::EMAIL_INVALIDE;
        }
        if (isset($interdites[ListeSuppression::empreinte(QualificationEmail::normaliser($email))])) {
            return self::EMAIL_INTERDIT;
        }

        return $verdict === VerificationEmail::VALIDE ? self::EMAIL_VALIDE : self::EMAIL_NON_VERIFIE;
    }

    /**
     * L'état d'une PERSONNE.
     *
     * @param  array<string, true>  $interdites
     */
    public static function etatPersonne(?string $email, ?string $statut, mixed $verification, ?string $telephone, array $interdites): string
    {
        return self::etatAdresse($email, $statut, $verification, $interdites)
            ?? (self::renseigne($telephone) ? self::SANS_EMAIL_AVEC_TELEPHONE : self::SANS_CONTACT);
    }

    /**
     * L'état d'une ENTREPRISE, connaissant celui de son adresse générique et
     * ceux de ses personnes.
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
     * Les adresses opposées OU supprimées parmi celles données, en DEUX
     * requêtes — la même question que `EligibiliteCampagne::peutRecevoir`
     * (portée `business`, empreinte seule), posée pour un lot entier.
     *
     * @param  list<string>  $emails
     * @return array<string, true> empreinte => true
     */
    public static function interditesParmi(array $emails): array
    {
        $empreintes = [];
        foreach ($emails as $email) {
            $normalise = QualificationEmail::normaliser($email);
            if ($normalise !== '') {
                $empreintes[ListeSuppression::empreinte($normalise)] = true;
            }
        }
        if ($empreintes === []) {
            return [];
        }
        $interdites = [];
        foreach (array_chunk(array_keys($empreintes), 1000) as $paquet) {
            foreach (['opt_out', 'email_suppressions'] as $table) {
                foreach (DB::table($table)->where('scope', 'business')->whereIn('email_hash', $paquet)->pluck('email_hash') as $h) {
                    $interdites[(string) $h] = true;
                }
            }
        }

        return $interdites;
    }

    /**
     * Calcule l'état des entreprises données ET de leurs personnes (lecture
     * seule). Les fiches à la corbeille sont ignorées.
     *
     * @param  array<int>  $ids
     * @return array{entreprises: array<int, array{avant: ?string, apres: string}>, personnes: array<int, array{avant: ?string, apres: string}>}
     */
    public static function calculer(string $workspaceId, array $ids): array
    {
        $resultat = ['entreprises' => [], 'personnes' => []];
        $ids = array_values($ids);
        if ($ids === []) {
            return $resultat;
        }
        $fiches = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'email_generic', 'phone', 'joignabilite', DB::raw("signals -> 'email_generic_verification' AS verif")]);
        $personnes = DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->whereIn('company_id', $ids)
            ->whereNull('deleted_at')
            ->get(['id', 'company_id', 'email', 'email_status', 'phone', 'joignabilite', DB::raw("metadata -> 'email_verification' AS verif")]);

        $emails = [];
        foreach ($fiches as $f) {
            if (is_string($f->email_generic) && trim($f->email_generic) !== '') {
                $emails[] = $f->email_generic;
            }
        }
        foreach ($personnes as $p) {
            if (is_string($p->email) && trim($p->email) !== '') {
                $emails[] = $p->email;
            }
        }
        $interdites = self::interditesParmi($emails);

        /** @var array<int, list<stdClass>> $parFiche */
        $parFiche = [];
        foreach ($personnes as $p) {
            $parFiche[(int) $p->company_id][] = $p;
        }

        foreach ($fiches as $f) {
            $etats = [self::etatAdresse(self::texte($f->email_generic), null, self::json($f->verif), $interdites)];
            $telephone = self::renseigne(self::texte($f->phone));
            foreach ($parFiche[(int) $f->id] ?? [] as $p) {
                $etatPersonne = self::etatPersonne(self::texte($p->email), self::texte($p->email_status), self::json($p->verif), self::texte($p->phone), $interdites);
                $resultat['personnes'][(int) $p->id] = ['avant' => self::texte($p->joignabilite), 'apres' => $etatPersonne];
                $etats[] = self::etatAdresse(self::texte($p->email), self::texte($p->email_status), self::json($p->verif), $interdites);
                $telephone = $telephone || self::renseigne(self::texte($p->phone));
            }
            $resultat['entreprises'][(int) $f->id] = ['avant' => self::texte($f->joignabilite), 'apres' => self::etatEntreprise($etats, $telephone)];
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
     * Recalcule et écrit, pour les fiches données (et leurs personnes). Appelé
     * par `crm:emails:verifier` dans la transaction du lot qui vient de changer
     * une vérification ou un statut.
     *
     * @param  array<int>  $ids
     * @return array{entreprises: int, personnes: int}
     */
    public static function recalculer(string $workspaceId, array $ids): array
    {
        return self::ecrire($workspaceId, self::calculer($workspaceId, array_values(array_unique($ids))));
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
