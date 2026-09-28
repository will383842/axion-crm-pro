<?php

namespace App\Crm\Rgpd;

use App\Crm\Taxonomy;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * EFFACEMENT (art. 17) DES COORDONNÉES D'UNE PERSONNE LÀ OÙ LES FICHES
 * D'ORGANISATION LES PORTENT — une seule définition, pour les deux portes
 * d'effacement (`GdprErasureService`, console ; `SiteGdprService`, site), et
 * pour l'export des articles 15 et 20.
 *
 * ── Le trou qu'on ferme (relecture de la PR #255, 2026-09-29) ───────────────
 *
 * Les deux services effaçaient les `contacts` PAR ADRESSE, et rien d'autre du
 * côté des organisations. Or l'adresse ou le mobile d'une personne vivent aussi
 * dans `companies.email_generic`, `companies.phone`,
 * `companies.signals.contact_channels` (e-mails, téléphones, `details`) et sur
 * des fiches personnes qui portent son numéro sans son adresse. Ce trou
 * existait déjà pour les organisateurs d'événements en production.
 *
 * ── Quel numéro est celui de la PERSONNE (relecture S3) ─────────────────────
 *
 * Le numéro inscrit sur la fiche d'une personne n'est pas toujours le sien :
 * c'est souvent le STANDARD de son organisation. L'effacer partout couperait
 * l'organisation et ses collègues. Un numéro n'est traité comme PERSONNEL — et
 * alors effacé partout et opposé — que s'il n'est porté par AUCUNE autre
 * personne, et s'il est un portable (06/07) ou n'est le téléphone d'aucune
 * fiche d'organisation. Sinon, il ne quitte que les fiches de la personne
 * elle-même (supprimées), et il n'est PAS opposé : `opt_out.phone` est une
 * opposition GLOBALE, qui fermerait le standard à toute l'organisation. La
 * personne, elle, reste protégée du retour par l'opposition sur son adresse et
 * par le registre `contacts_retires` (empreinte de son nom).
 *
 * ── Fiches PROTÉGÉES ────────────────────────────────────────────────────────
 *
 * Mettre une coordonnée à NULL est un UPDATE : le verrou de la base ne vise que
 * la suppression de la fiche. C'est la promesse de `FichesProtegees` : « la
 * protection ne fait JAMAIS obstacle au droit d'une personne ».
 *
 * ── Espace par espace, et preuve indépendante ───────────────────────────────
 *
 * Sous le rôle de production (RLS forcée), une requête sans contexte d'espace
 * ne voit rien. `effacerPartout()` et `residusPartout()` travaillent donc espace
 * par espace, dans leur contexte (relecture S8). Et si le rôle courant est
 * soumis à la RLS, le reste de l'effacement (tables hors de ce fichier) n'a
 * pas pu tout voir : l'effacement ne se déclare alors PAS complet.
 *
 * `residus()` cherche plus large que `effacer()` n'efface (texte entier de
 * `signals` et `metadata`, notes libres de Will), et cherche les numéros par
 * leurs SEULS CHIFFRES, sans la normalisation de l'effacement (relecture S4) :
 * un numéro mal formé (« 01 23 45 67 89 poste 12 ») que l'effacement ne
 * reconnaît pas rend l'effacement « incomplet » au lieu de le laisser se dire
 * fait.
 *
 * Coût : ces recherches ne sont servies par aucun index — un balayage par
 * demande. Un effacement est rare et c'est un droit à délai légal : la justesse
 * prime (même arbitrage que `activities`).
 */
final class EffacementCoordonneesFiches
{
    /** Empreinte du nom d'une fiche personne, NON salée : comparée dans la même requête, jamais stockée. */
    private const CLE_NOM = "encode(digest(normalize_name(coalesce(first_name, '') || '_' || last_name), 'sha256'), 'hex')";

    // ── Relevé de la personne ───────────────────────────────────────────────

    /**
     * Les téléphones portés par les fiches personnes de cette adresse ou de
     * cette clé de personne — à relever AVANT de les supprimer.
     *
     * @return list<string>
     */
    public static function telephonesDesContacts(string $email, ?string $workspaceId = null, ?string $personKey = null): array
    {
        $requete = self::contactsDeLaPersonne($email, $personKey, $workspaceId);
        if ($requete === null) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('strval', $requete->whereNotNull('phone')->pluck('phone')->all()),
            static fn (string $t): bool => trim($t) !== '',
        )));
    }

    /**
     * Les empreintes de NOM des fiches personnes de cette adresse ou de cette
     * clé de personne : elles reconnaissent un doublon de la même personne.
     *
     * @return list<string>
     */
    public static function clesNomDesContacts(string $email, ?string $workspaceId = null, ?string $personKey = null): array
    {
        $requete = self::contactsDeLaPersonne($email, $personKey, $workspaceId);
        if ($requete === null) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $requete
            ->selectRaw(self::CLE_NOM . ' AS cle')
            ->pluck('cle')
            ->all())));
    }

    /**
     * Parmi ces numéros, ceux qui sont ceux de la PERSONNE (cf. en-tête, S3).
     *
     * @param  list<string>  $telephones
     * @param  list<string>  $clesNom
     * @return list<string>
     */
    public static function numerosPersonnels(array $telephones, string $email, array $clesNom, ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $personnels = [];
        foreach (array_values(array_unique($telephones)) as $telephone) {
            $variantes = self::variantes([$telephone]);
            if ($variantes === []) {
                continue;
            }

            // Porté par une AUTRE personne (ni cette adresse, ni ce nom) ?
            $autres = self::contactsAuNumero($variantes, $workspaceId)
                ->where(function (Builder $q) use ($email): void {
                    $q->whereNull('email')->orWhere('email', '!=', $email);
                });
            if ($clesNom !== []) {
                $autres->whereRaw('NOT (' . self::CLE_NOM . ' IN (' . self::marques($clesNom) . '))', $clesNom);
            }
            if ($autres->exists()) {
                continue;
            }

            $portable = false;
            foreach ($variantes as $v) {
                if (preg_match('/^0[67]\d{8}$/', $v) === 1) {
                    $portable = true;
                }
            }
            $standard = self::fiches($workspaceId)
                ->whereNotNull('phone')
                ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes)
                ->exists();

            if ($portable || ! $standard) {
                $personnels[] = $telephone;
            }
        }

        return $personnels;
    }

    // ── Effacement ──────────────────────────────────────────────────────────

    /**
     * Efface dans UN espace (ou partout si `$workspaceId` est null et que le
     * rôle voit tout).
     *
     * @param  list<string>  $telephones  tous les numéros relevés pour la personne
     * @param  list<string>  $clesNom
     * @return array{bilan: array<string, int>, personnels: list<string>}
     */
    public static function effacer(string $email, array $telephones, array $clesNom = [], ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $personnels = self::numerosPersonnels($telephones, $email, $clesNom, $workspaceId);
        $tous = self::variantes($telephones);
        $variantes = self::variantes($personnels);
        $bilan = [
            'contacts_par_email' => 0,
            'companies_email_generic' => 0,
            'companies_phone' => 0,
            'companies_canaux' => 0,
            'contacts_doublons_par_telephone' => 0,
            'contacts_telephone_retire' => 0,
        ];

        if ($email !== '') {
            // Déjà fait par les services quand le rôle voit tout ; ici pour le
            // travail par espace sous RLS (S8).
            $contacts = DB::table('contacts')->where('email', $email);
            if ($workspaceId !== null) {
                $contacts->where('workspace_id', $workspaceId);
            }
            $bilan['contacts_par_email'] = $contacts->delete();

            // La fiche de vérification de l'adresse part avec l'adresse.
            $bilan['companies_email_generic'] = self::fiches($workspaceId)
                ->whereRaw('lower(email_generic) = ?', [$email])
                ->update([
                    'email_generic' => null,
                    'signals' => DB::raw("signals - 'email_generic_verification'"),
                    'updated_at' => now(),
                ]);
        }

        // Un DOUBLON de la personne (même nom, aucune adresse) qui porte l'un
        // de SES numéros — même le standard : c'est une fiche de la personne.
        if ($tous !== [] && $clesNom !== []) {
            $bilan['contacts_doublons_par_telephone'] = self::contactsAuNumero($tous, $workspaceId)
                ->whereNull('email')
                ->whereRaw(self::CLE_NOM . ' IN (' . self::marques($clesNom) . ')', $clesNom)
                ->delete();
        }

        // Un numéro PERSONNEL quitte tout : fiche d'organisation, autres fiches.
        if ($variantes !== []) {
            $bilan['companies_phone'] = self::fiches($workspaceId)
                ->whereNotNull('phone')
                ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes)
                ->update(['phone' => null, 'updated_at' => now()]);
            $bilan['contacts_telephone_retire'] = self::contactsAuNumero($variantes, $workspaceId)
                ->update(['phone' => null, 'updated_at' => now()]);
        }

        foreach (self::fichesAvecCanaux($email, $variantes, $workspaceId) as $fiche) {
            $signals = json_decode((string) $fiche->signals, true);
            if (! is_array($signals) || ! is_array($signals['contact_channels'] ?? null)) {
                continue;
            }
            $canaux = self::nettoyerCanaux($signals['contact_channels'], $email, $variantes);
            if ($canaux === $signals['contact_channels']) {
                continue;
            }
            $signals['contact_channels'] = $canaux;
            DB::table('companies')->where('id', $fiche->id)->update([
                'signals' => json_encode($signals, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
            $bilan['companies_canaux']++;
        }

        return ['bilan' => $bilan, 'personnels' => $personnels];
    }

    /**
     * Efface dans CHAQUE espace business, dans son contexte (RLS). Le relevé
     * de la personne (numéros, empreintes de nom) est complété espace par
     * espace : sous RLS, le relevé fait hors contexte ne voyait rien.
     *
     * @param  list<string>  $telephones
     * @param  list<string>  $clesNom
     * @return array{bilan: array<string, int>, personnels: list<string>}
     */
    public static function effacerPartout(string $email, array $telephones, array $clesNom): array
    {
        $bilan = [];
        $personnels = [];
        foreach (self::espaces() as $espace) {
            $resultat = WorkspaceContext::run($espace, static function () use ($email, $telephones, $clesNom, $espace): array {
                $tels = array_values(array_unique(array_merge($telephones, self::telephonesDesContacts($email, $espace))));
                $cles = array_values(array_unique(array_merge($clesNom, self::clesNomDesContacts($email, $espace))));

                return self::effacer($email, $tels, $cles, $espace);
            });
            foreach ($resultat['bilan'] as $cle => $n) {
                $bilan[$cle] = ($bilan[$cle] ?? 0) + $n;
            }
            $personnels = array_merge($personnels, $resultat['personnels']);
        }

        return ['bilan' => $bilan, 'personnels' => array_values(array_unique($personnels))];
    }

    // ── Preuve ──────────────────────────────────────────────────────────────

    /**
     * Ce qui reste de l'adresse et des numéros PERSONNELS, par emplacement,
     * dans un espace. Vide : rien.
     *
     * @param  list<string>  $telephonesPersonnels
     * @return array<string, int>
     */
    public static function residus(string $email, array $telephonesPersonnels, ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $residus = [];

        // Les zones où chercher, et la colonne (ou le texte) à lire.
        $zones = [
            'contacts.email' => ['contacts', 'email::text'],
            'contacts.phone' => ['contacts', 'phone'],
            'companies.email_generic' => ['companies', 'email_generic'],
            'companies.phone' => ['companies', 'phone'],
            'companies.signals' => ['companies', 'signals::text'],
            'companies.metadata' => ['companies', 'metadata::text'],
            'federations.partenariat_note' => ['federations', 'partenariat_note'],
            'events.demarche_note' => ['events', 'demarche_note'],
        ];

        foreach ($zones as $nom => [$table, $colonne]) {
            $n = 0;
            if ($email !== '' && ! in_array($nom, ['contacts.phone', 'companies.phone'], true)) {
                $candidats = self::table($table, $workspaceId)
                    ->whereRaw("coalesce({$colonne}, '') ILIKE ?", ['%' . self::echapperLike($email) . '%'])
                    ->selectRaw("{$colonne} AS texte")
                    ->pluck('texte')
                    ->all();
                foreach ($candidats as $texte) {
                    if (str_contains(mb_strtolower((string) $texte), $email)) {
                        $n++;
                    }
                }
            }
            if ($nom !== 'contacts.email' && $nom !== 'companies.email_generic') {
                // Les numéros par leurs SEULS chiffres : aucune normalisation
                // commune avec l'effacement (S4).
                foreach (self::empreintesChiffres($telephonesPersonnels) as $chiffres) {
                    $n += self::table($table, $workspaceId)
                        ->whereRaw("regexp_replace(coalesce({$colonne}, ''), '[^0-9]', '', 'g') LIKE ?", ['%' . $chiffres . '%'])
                        ->count();
                }
            }
            if ($n > 0) {
                $residus[$nom] = $n;
            }
        }

        return $residus;
    }

    /**
     * `residus()` dans chaque espace, et, si le rôle courant est soumis à la
     * RLS, l'aveu que le reste de l'effacement n'a pas pu tout voir.
     *
     * @param  list<string>  $telephonesPersonnels
     * @return array<string, int>
     */
    public static function residusPartout(string $email, array $telephonesPersonnels): array
    {
        $residus = [];
        foreach (self::espaces() as $espace) {
            $trouves = WorkspaceContext::run($espace, static fn (): array => self::residus($email, $telephonesPersonnels, $espace));
            foreach ($trouves as $cle => $n) {
                $residus[$cle] = ($residus[$cle] ?? 0) + $n;
            }
        }
        if (! self::roleVoitTout()) {
            // Les tables que ce fichier ne parcourt pas espace par espace
            // (timeline, candidats, courriels…) ont été effacées SANS contexte :
            // sous RLS, elles n'ont rien vu. On ne dit pas « complet ».
            $residus['perimetre_non_verifie_sous_rls'] = 1;
        }

        return $residus;
    }

    /** Le rôle de la connexion voit-il toutes les lignes (propriétaire, BYPASSRLS) ? */
    public static function roleVoitTout(): bool
    {
        $role = DB::selectOne('SELECT (rolsuper OR rolbypassrls) AS voit FROM pg_roles WHERE rolname = current_user');

        return $role !== null && (bool) $role->voit;
    }

    // ── Export (articles 15 et 20) ──────────────────────────────────────────

    /**
     * Les fiches d'organisation qui portent l'adresse ou un numéro PERSONNEL
     * de la personne — et, pour chacune, SEULEMENT les valeurs de la personne
     * et leur emplacement (relecture S1). L'e-mail générique ou le standard
     * d'une fiche trouvée par ses canaux appartiennent à un TIERS : ils ne
     * sortent pas (art. 15 § 4).
     *
     * @param  list<string>  $telephonesPersonnels
     * @return list<array{id: int, denomination: ?string, emplacements: list<array{emplacement: string, valeur: string}>}>
     */
    public static function fichesPortant(string $email, array $telephonesPersonnels = [], ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $variantes = self::variantes($telephonesPersonnels);
        if ($email === '' && $variantes === []) {
            return [];
        }

        $requete = self::fiches($workspaceId)->where(function (Builder $q) use ($email, $variantes): void {
            if ($email !== '') {
                $q->orWhereRaw('lower(email_generic) = ?', [$email])
                    ->orWhereRaw("coalesce(signals->'contact_channels', '{}'::jsonb)::text ILIKE ?", ['%' . self::echapperLike($email) . '%']);
            }
            if ($variantes !== []) {
                $q->orWhereRaw(self::chiffres("coalesce(phone, '')") . ' IN (' . self::marques($variantes) . ')', $variantes);
                foreach ($variantes as $v) {
                    $q->orWhereRaw(
                        "regexp_replace(coalesce(signals->'contact_channels'->>'phones', ''), '[^0-9]', '', 'g') LIKE ?",
                        ['%' . $v . '%'],
                    );
                }
            }
        });

        $resultat = [];
        foreach ($requete->orderBy('id')->get(['id', 'denomination', 'email_generic', 'phone', 'signals']) as $f) {
            $emplacements = [];
            if ($email !== '' && is_string($f->email_generic) && mb_strtolower(trim($f->email_generic)) === $email) {
                $emplacements[] = ['emplacement' => 'companies.email_generic', 'valeur' => $email];
            }
            if (is_string($f->phone) && self::telephoneVise($f->phone, $variantes)) {
                $emplacements[] = ['emplacement' => 'companies.phone', 'valeur' => $f->phone];
            }
            $signals = json_decode((string) $f->signals, true);
            $canaux = is_array($signals) && is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];
            foreach (is_array($canaux['emails'] ?? null) ? $canaux['emails'] : [] as $e) {
                if (is_string($e) && $email !== '' && mb_strtolower(trim($e)) === $email) {
                    $emplacements[] = ['emplacement' => 'companies.signals.contact_channels.emails', 'valeur' => $email];
                }
            }
            foreach (is_array($canaux['phones'] ?? null) ? $canaux['phones'] : [] as $t) {
                if (is_string($t) && self::telephoneVise($t, $variantes)) {
                    $emplacements[] = ['emplacement' => 'companies.signals.contact_channels.phones', 'valeur' => $t];
                }
            }
            if ($emplacements !== []) {
                $resultat[] = ['id' => (int) $f->id, 'denomination' => $f->denomination, 'emplacements' => $emplacements];
            }
        }

        return $resultat;
    }

    /**
     * Retire l'adresse et les numéros des canaux d'une fiche. Les autres
     * valeurs sont gardées dans leur ordre.
     *
     * @param  array<mixed>  $canaux
     * @param  list<string>  $variantes
     * @return array<mixed>
     */
    public static function nettoyerCanaux(array $canaux, string $email, array $variantes): array
    {
        if ($email !== '' && is_array($canaux['emails'] ?? null)) {
            $canaux['emails'] = array_values(array_filter(
                $canaux['emails'],
                static fn (mixed $e): bool => ! is_string($e) || mb_strtolower(trim($e)) !== $email,
            ));
        }
        if ($email !== '' && is_array($canaux['details'] ?? null)) {
            foreach (array_keys($canaux['details']) as $cle) {
                if (mb_strtolower(trim((string) $cle)) === $email) {
                    unset($canaux['details'][$cle]);
                }
            }
        }
        if ($variantes !== [] && is_array($canaux['phones'] ?? null)) {
            $canaux['phones'] = array_values(array_filter(
                $canaux['phones'],
                static fn (mixed $t): bool => ! is_string($t) || ! self::telephoneVise($t, $variantes),
            ));
        }

        return $canaux;
    }

    // ── Internes ────────────────────────────────────────────────────────────

    /**
     * Les espaces business (le vivier ne porte pas de fiches d'organisation),
     * corbeille comprise : leurs données sont toujours en base.
     *
     * @return list<string>
     */
    private static function espaces(): array
    {
        return array_values(array_map('strval', DB::table('workspaces')
            ->where('slug', '!=', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->pluck('id')
            ->all()));
    }

    private static function contactsDeLaPersonne(string $email, ?string $personKey, ?string $workspaceId): ?Builder
    {
        $email = mb_strtolower(trim($email));
        $personKey = $personKey !== null && trim($personKey) !== '' ? $personKey : null;
        if ($email === '' && $personKey === null) {
            return null;
        }

        // Par clé de personne OU par adresse — comme la suppression (S5).
        $requete = DB::table('contacts')->where(function (Builder $q) use ($email, $personKey): void {
            if ($email !== '') {
                $q->orWhere('email', $email);
            }
            if ($personKey !== null) {
                $q->orWhere('person_key', $personKey);
            }
        });
        if ($workspaceId !== null) {
            $requete->where('workspace_id', $workspaceId);
        }

        return $requete;
    }

    private static function fiches(?string $workspaceId): Builder
    {
        return self::table('companies', $workspaceId);
    }

    private static function table(string $table, ?string $workspaceId): Builder
    {
        $requete = DB::table($table);
        if ($workspaceId !== null) {
            $requete->where('workspace_id', $workspaceId);
        }

        return $requete;
    }

    /**
     * Les fiches personnes qui portent l'un de ces numéros. Corbeille
     * comprise, VOLONTAIREMENT : une fiche à la corbeille garde la coordonnée.
     *
     * @param  list<string>  $variantes
     */
    private static function contactsAuNumero(array $variantes, ?string $workspaceId): Builder
    {
        return self::table('contacts', $workspaceId)
            ->whereNotNull('phone')
            ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes);
    }

    /**
     * Les fiches dont les canaux PEUVENT porter l'adresse ou un numéro : un
     * préfiltre SQL large, puis la comparaison exacte en PHP.
     *
     * @param  list<string>  $variantes
     * @return iterable<\stdClass>
     */
    private static function fichesAvecCanaux(string $email, array $variantes, ?string $workspaceId): iterable
    {
        if ($email === '' && $variantes === []) {
            return [];
        }

        return self::fiches($workspaceId)
            // `jsonb_exists` et non l'opérateur `?`, que PDO prendrait pour un paramètre.
            ->whereRaw("jsonb_exists(signals, 'contact_channels')")
            ->where(function (Builder $q) use ($email, $variantes): void {
                if ($email !== '') {
                    $q->orWhereRaw("(signals->'contact_channels')::text ILIKE ?", ['%' . self::echapperLike($email) . '%']);
                }
                foreach ($variantes as $v) {
                    $q->orWhereRaw(
                        "regexp_replace(coalesce(signals->'contact_channels'->>'phones', ''), '[^0-9]', '', 'g') LIKE ?",
                        ['%' . $v . '%'],
                    );
                }
            })
            ->get(['id', 'signals']);
    }

    /**
     * @param  list<string>  $telephones
     * @return list<string>
     */
    private static function variantes(array $telephones): array
    {
        $variantes = [];
        foreach ($telephones as $t) {
            foreach (ListeSuppression::variantesTelephone($t) as $v) {
                // Un numéro trop court (« 15 », « 3949 ») viserait n'importe qui.
                if (strlen($v) >= 9) {
                    $variantes[$v] = true;
                }
            }
        }

        // `strval` : PHP convertit une clé « 33612345678 » en entier.
        return array_map('strval', array_keys($variantes));
    }

    /**
     * Les chiffres qui identifient un numéro pour la recherche de résidus :
     * les 9 chiffres d'abonné d'un numéro français où qu'ils soient dans la
     * saisie (« 01 23 45 67 89 poste 12 »), sinon les 9 derniers chiffres.
     *
     * @param  list<string>  $telephones
     * @return list<string>
     */
    private static function empreintesChiffres(array $telephones): array
    {
        $empreintes = [];
        foreach ($telephones as $t) {
            $chiffres = (string) preg_replace('/\D/', '', $t);
            if (strlen($chiffres) < 9) {
                continue;
            }
            $empreintes[] = preg_match('/(?:0033|33|0)([1-9]\d{8})/', $chiffres, $m) === 1 ? $m[1] : substr($chiffres, -9);
        }

        return array_values(array_unique($empreintes));
    }

    /** @param  list<string>  $variantes */
    private static function telephoneVise(string $telephone, array $variantes): bool
    {
        foreach (ListeSuppression::variantesTelephone($telephone) as $v) {
            if (in_array($v, $variantes, true)) {
                return true;
            }
        }

        return false;
    }

    private static function chiffres(string $colonne): string
    {
        return "regexp_replace({$colonne}, '[^0-9]', '', 'g')";
    }

    /** @param  list<string>  $valeurs */
    private static function marques(array $valeurs): string
    {
        return implode(', ', array_fill(0, count($valeurs), '?'));
    }

    private static function echapperLike(string $valeur): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $valeur);
    }
}
