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
 * des fiches personnes qui portent son numéro sans son adresse.
 *
 * ── Deux temps (relecture P1) ───────────────────────────────────────────────
 *
 * 1. Le temps SYNCHRONE — la demande attend, et le site coupe à 10 s
 *    (`axionia/src/server/crm-sync/config.ts`). Relevé, effacement, export
 *    n'y emploient QUE des recherches servies par un index (migration
 *    `2026_09_29_000002`, garde `EffacementServiParDesIndexTest`) :
 *      - `contacts.email` (égalité)            → `idx_contacts_email` ;
 *      - `contacts (workspace_id, person_key)` → `idx_contacts_workspace_person_key` ;
 *      - chiffres de `contacts.phone`          → `idx_contacts_telephone_chiffres` ;
 *      - `lower(companies.email_generic)`      → `idx_companies_email_generic_minuscules` ;
 *      - chiffres de `companies.phone`         → `idx_companies_telephone_chiffres` ;
 *      - e-mails des canaux                    → `idx_companies_canaux_emails` (GIN) ;
 *      - chiffres des téléphones des canaux    → `idx_companies_canaux_telephones` (GIN).
 *    Chaque requête répète l'expression ET le prédicat partiel de son index
 *    à l'identique : sinon le planificateur ne s'en sert pas.
 * 2. Le temps DIFFÉRÉ — `App\Jobs\VerifierEffacementRgpd` : la preuve
 *    (`residus()`, qui lit TOUT : texte de `signals` et `metadata`, notes
 *    libres) et le balayage de la timeline par les numéros personnels
 *    (`effacerTimeline()`). Il met la demande à jour et journalise.
 *
 * ── Quel numéro est celui de la PERSONNE (relectures S3, E2) ────────────────
 *
 * Le numéro inscrit sur la fiche d'une personne est souvent le STANDARD de son
 * organisation. Un numéro n'est PERSONNEL — effacé partout — que s'il n'est
 * porté par AUCUNE autre personne, et qu'il est un portable (06/07) ou n'est le
 * standard d'aucune fiche d'organisation : ni `companies.phone`, ni un
 * téléphone de ses canaux. Il n'est OPPOSÉ (`opt_out.phone`, opposition
 * GLOBALE) que s'il est personnel dans CHAQUE espace où on le trouve
 * (`opposables()`) : jamais par union.
 *
 * ── Fiches PROTÉGÉES ────────────────────────────────────────────────────────
 *
 * Mettre une coordonnée à NULL est un UPDATE : le verrou de la base ne vise que
 * la suppression de la fiche. C'est la promesse de `FichesProtegees` : « la
 * protection ne fait JAMAIS obstacle au droit d'une personne ».
 */
final class EffacementCoordonneesFiches
{
    /** Empreinte du nom d'une fiche personne, NON salée : comparée dans la même requête, jamais stockée. */
    private const CLE_NOM = "encode(digest(normalize_name(coalesce(first_name, '') || '_' || last_name), 'sha256'), 'hex')";

    /**
     * Prédicat des deux index partiels des canaux. `jsonb_exists` et non
     * l'opérateur `?`, que PDO prendrait pour un paramètre.
     */
    private const AVEC_CANAUX = "jsonb_exists(signals, 'contact_channels')";

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
     * Parmi ces numéros, ceux qui sont ceux de la PERSONNE dans cet espace
     * (cf. en-tête, S3 et E2).
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
            if (self::existe($autres)) {
                continue;
            }

            $portable = false;
            foreach ($variantes as $v) {
                if (preg_match('/^0[67]\d{8}$/', $v) === 1) {
                    $portable = true;
                }
            }
            // Le STANDARD d'une organisation : son téléphone, ou un téléphone
            // de ses canaux (E2) — les deux disent « joindre l'organisation ».
            $standard = self::existe(self::fichesAuNumero($variantes, $workspaceId))
                || self::existe(self::fichesAuxCanaux([], $variantes, $workspaceId));

            if ($portable || ! $standard) {
                $personnels[] = $telephone;
            }
        }

        return $personnels;
    }

    /**
     * Ceux de ces numéros qu'on TROUVE dans cet espace : sur une fiche
     * personne, une fiche d'organisation ou ses canaux.
     *
     * @param  list<string>  $telephones
     * @return list<string>
     */
    public static function presents(array $telephones, ?string $workspaceId = null): array
    {
        $presents = [];
        foreach (array_values(array_unique($telephones)) as $telephone) {
            $variantes = self::variantes([$telephone]);
            if ($variantes === []) {
                continue;
            }
            if (self::existe(self::contactsAuNumero($variantes, $workspaceId))
                || self::existe(self::fichesAuNumero($variantes, $workspaceId))
                || self::existe(self::fichesAuxCanaux([], $variantes, $workspaceId))) {
                $presents[] = $telephone;
            }
        }

        return $presents;
    }

    // ── Effacement (synchrone, indexé) ──────────────────────────────────────

    /**
     * Efface dans UN espace (ou partout si `$workspaceId` est null et que le
     * rôle voit tout). `presents` est relevé AVANT l'effacement.
     *
     * @param  list<string>  $telephones  tous les numéros relevés pour la personne
     * @param  list<string>  $clesNom
     * @return array{bilan: array<string, int>, personnels: list<string>, presents: list<string>}
     */
    public static function effacer(string $email, array $telephones, array $clesNom = [], ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $personnels = self::numerosPersonnels($telephones, $email, $clesNom, $workspaceId);
        $presents = self::presents($telephones, $workspaceId);
        $tous = self::variantes($telephones);
        $variantes = self::variantes($personnels);
        $bilan = [
            'contacts_par_email' => 0,
            'companies_email_generic' => 0,
            'companies_phone' => 0,
            'companies_canaux' => 0,
            'contacts_doublons_par_telephone' => 0,
            'contacts_telephone_retire' => 0,
            'adresses_partagees' => 0,
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
            $bilan['companies_email_generic'] = self::fichesALAdresse($email, $workspaceId)
                ->update([
                    'email_generic' => null,
                    'signals' => DB::raw("signals - 'email_generic_verification'"),
                    'updated_at' => now(),
                ]);

        }

        // Chantier 5 : l'empreinte salée de l'adresse (si elle était partagée)
        // et celles de l'adresse et des numéros PERSONNELS dans le journal des
        // fusions partent avec eux — `doublons_effacer`, bornée à l'espace du
        // contexte (le rôle applicatif ne calcule pas d'empreinte).
        $espace = $workspaceId ?? WorkspaceContext::current();
        if ($espace !== null && ($email !== '' || $variantes !== [])) {
            // Dans le contexte de CET espace : la fonction refuse tout autre.
            $r = WorkspaceContext::run($espace, static fn (): mixed => DB::selectOne(
                'SELECT public.doublons_effacer(?::uuid, ?, ?::jsonb) AS n',
                [$espace, $email, json_encode($variantes, JSON_THROW_ON_ERROR)],
            ));
            $bilan['adresses_partagees'] = (int) ($r->n ?? 0);
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
            $bilan['companies_phone'] = self::fichesAuNumero($variantes, $workspaceId)
                ->update(['phone' => null, 'updated_at' => now()]);
            $bilan['contacts_telephone_retire'] = self::contactsAuNumero($variantes, $workspaceId)
                ->update(['phone' => null, 'updated_at' => now()]);
        }

        $adresses = $email !== '' ? [$email] : [];
        foreach (self::fichesAuxCanaux($adresses, $variantes, $workspaceId)->get(['id', 'signals']) as $fiche) {
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

        return ['bilan' => $bilan, 'personnels' => $personnels, 'presents' => $presents];
    }

    /**
     * Efface dans CHAQUE espace business, dans son contexte (RLS). Le relevé
     * de la personne (numéros, empreintes de nom) est complété espace par
     * espace : sous RLS, le relevé fait hors contexte ne voyait rien.
     *
     * `opposables` : les numéros personnels dans CHAQUE espace où on les
     * trouve — les seuls qu'une opposition globale peut viser (E2).
     *
     * @param  list<string>  $telephones
     * @param  list<string>  $clesNom
     * @return array{bilan: array<string, int>, personnels: list<string>, opposables: list<string>}
     */
    public static function effacerPartout(string $email, array $telephones, array $clesNom): array
    {
        $bilan = [];
        $personnels = [];
        $parEspace = [];
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
            $parEspace[] = $resultat;
        }
        $personnels = array_values(array_unique($personnels));

        return ['bilan' => $bilan, 'personnels' => $personnels, 'opposables' => self::opposables($personnels, $parEspace)];
    }

    /**
     * Les numéros personnels dans TOUS les espaces où on les trouve, et
     * aucun autre : pas d'union (E2). Un numéro personnel ici mais standard
     * là-bas n'est pas opposé — l'opposition fermerait le standard.
     *
     * @param  list<string>  $personnels
     * @param  list<array{personnels: list<string>, presents: list<string>}>  $parEspace
     * @return list<string>
     */
    public static function opposables(array $personnels, array $parEspace): array
    {
        return array_values(array_filter($personnels, static function (string $numero) use ($parEspace): bool {
            foreach ($parEspace as $espace) {
                if (in_array($numero, $espace['presents'], true) && ! in_array($numero, $espace['personnels'], true)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * `opposables()` pour une porte qui n'a effacé que dans UN espace (le
     * site) : les autres espaces sont relevés tels quels, chacun dans son
     * contexte.
     *
     * @param  list<string>  $personnels  personnels dans l'espace effacé
     * @param  list<string>  $clesNom
     * @return list<string>
     */
    public static function opposablesPartout(string $email, array $personnels, array $clesNom, string $espaceEfface): array
    {
        $parEspace = [];
        foreach (self::espaces() as $espace) {
            if ($espace === $espaceEfface) {
                continue;
            }
            $parEspace[] = WorkspaceContext::run($espace, static function () use ($email, $personnels, $clesNom, $espace): array {
                $cles = array_values(array_unique(array_merge($clesNom, self::clesNomDesContacts($email, $espace))));

                return [
                    'presents' => self::presents($personnels, $espace),
                    'personnels' => self::numerosPersonnels($personnels, $email, $cles, $espace),
                ];
            });
        }

        return self::opposables($personnels, $parEspace);
    }

    // ── Export (articles 15 et 20, synchrone, indexé) ───────────────────────

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
        $adresses = $email !== '' ? [$email] : [];
        if ($adresses === [] && $variantes === []) {
            return [];
        }

        // Trois recherches indexées, réunies par identifiant (un OR entre
        // elles empêcherait chacune de servir son index).
        $ids = [];
        if ($email !== '') {
            $ids = array_merge($ids, self::fichesALAdresse($email, $workspaceId)->pluck('id')->all());
        }
        if ($variantes !== []) {
            $ids = array_merge($ids, self::fichesAuNumero($variantes, $workspaceId)->pluck('id')->all());
        }
        $ids = array_merge($ids, self::fichesAuxCanaux($adresses, $variantes, $workspaceId)->pluck('id')->all());
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $resultat = [];
        foreach (DB::table('companies')->whereIn('id', $ids)->orderBy('id')->get(['id', 'denomination', 'email_generic', 'phone', 'signals']) as $f) {
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
     * `fichesPortant()` dans CHAQUE espace, dans son contexte (relecture E3),
     * avec le relevé des numéros personnels de l'espace. `perimetre` avoue,
     * si le rôle courant est soumis à la RLS, que le RESTE de l'export (tables
     * lues sans contexte) n'a pas pu tout voir : l'export ne revient jamais
     * vide sans le dire.
     *
     * @return array{fiches: list<array{id: int, denomination: ?string, emplacements: list<array{emplacement: string, valeur: string}>}>, perimetre: string}
     */
    public static function fichesPortantPartout(string $email): array
    {
        $fiches = [];
        foreach (self::espaces() as $espace) {
            $trouvees = WorkspaceContext::run($espace, static function () use ($email, $espace): array {
                $personnels = self::numerosPersonnels(
                    self::telephonesDesContacts($email, $espace),
                    $email,
                    self::clesNomDesContacts($email, $espace),
                    $espace,
                );

                return self::fichesPortant($email, $personnels, $espace);
            });
            $fiches = array_merge($fiches, $trouvees);
        }

        return ['fiches' => $fiches, 'perimetre' => self::roleVoitTout() ? 'complet' : 'non_verifie_sous_rls'];
    }

    // ── Preuve (DIFFÉRÉE : `App\Jobs\VerifierEffacementRgpd`) ───────────────

    /**
     * Ce qui reste de l'adresse et des numéros PERSONNELS, par emplacement,
     * dans un espace. Vide : rien. Des parcours complets : JAMAIS sur le
     * chemin synchrone (P1).
     *
     * Plus large que l'effacement (texte entier de `signals` et `metadata`,
     * notes libres de Will), et indépendante de sa normalisation (S4) : les
     * numéros sont cherchés par `motifsTelephones()`, qui exige leurs
     * FRONTIÈRES (E1).
     *
     * @param  list<string>  $telephonesPersonnels
     * @return array<string, int>
     */
    public static function residus(string $email, array $telephonesPersonnels, ?string $workspaceId = null): array
    {
        $email = mb_strtolower(trim($email));
        $motifs = self::motifsTelephones($telephonesPersonnels);
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
                foreach ($motifs as $motif) {
                    $n += self::table($table, $workspaceId)
                        ->whereRaw("coalesce({$colonne}, '') ~ ?", [$motif])
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
            // (candidats, courriels…) ont été effacées SANS contexte : sous
            // RLS, elles n'ont rien vu. On ne dit pas « complet ».
            $residus['perimetre_non_verifie_sous_rls'] = 1;
        }

        return $residus;
    }

    /**
     * La timeline (`activities.payload`), balayée par les numéros PERSONNELS
     * (relecture E4) — pas seulement par le numéro de la demande. Un parcours
     * complet de `activities` : temps différé seulement.
     *
     * @param  list<string>  $telephonesPersonnels
     */
    public static function effacerTimeline(array $telephonesPersonnels, ?string $workspaceId = null): int
    {
        $supprimees = 0;
        foreach (self::motifsTelephones($telephonesPersonnels) as $motif) {
            $supprimees += self::table('activities', $workspaceId)
                ->whereRaw("coalesce(payload::text, '') ~ ?", [$motif])
                ->delete();
        }

        return $supprimees;
    }

    /** Le rôle de la connexion voit-il toutes les lignes (propriétaire, BYPASSRLS) ? */
    public static function roleVoitTout(): bool
    {
        $role = DB::selectOne('SELECT (rolsuper OR rolbypassrls) AS voit FROM pg_roles WHERE rolname = current_user');

        return $role !== null && (bool) $role->voit;
    }

    /**
     * Les espaces business (le vivier ne porte pas de fiches d'organisation),
     * corbeille comprise : leurs données sont toujours en base.
     *
     * @return list<string>
     */
    public static function espaces(): array
    {
        return array_values(array_map('strval', DB::table('workspaces')
            ->where('slug', '!=', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->pluck('id')
            ->all()));
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

    /**
     * Les expressions régulières (POSIX, Postgres) qui reconnaissent un numéro
     * dans un texte (relecture E1) : SES chiffres, un séparateur au plus entre
     * deux (espace, point, tiret, parenthèse), et ses FRONTIÈRES — pas de
     * chiffre juste avant ni juste après. Un SIREN suivi d'une date ne forme
     * donc plus « le numéro » par hasard ; « 06.00.00.00.42 (poste 3) », si.
     *
     * @param  list<string>  $telephones
     * @return list<string>
     */
    public static function motifsTelephones(array $telephones): array
    {
        $sep = '[ .()-]?';
        $motifs = [];
        foreach ($telephones as $t) {
            $chiffres = (string) preg_replace('/\D/', '', $t);
            if (strlen($chiffres) < 9) {
                continue;
            }
            if (preg_match('/^(?:0033|33|0)0?([1-9]\d{8})$/', $chiffres, $m) === 1) {
                // Français : 06…, +33 6…, 33 6…, 0033 6…, +33 (0)6…
                $abonne = $m[1];
                $prefixe = '(\+?33[ .]?(\(0\))?|0033[ .]?(\(0\))?|0)';
                $motifs[] = '(^|[^0-9])' . $prefixe . $sep . implode($sep, str_split($abonne)) . '([^0-9]|$)';
            } else {
                $motifs[] = '(^|[^0-9])' . implode($sep, str_split($chiffres)) . '([^0-9]|$)';
            }
        }

        return array_values(array_unique($motifs));
    }

    // ── Internes : chaque recherche ci-dessous est servie par un index ──────

    private static function contactsDeLaPersonne(string $email, ?string $personKey, ?string $workspaceId): ?Builder
    {
        $email = mb_strtolower(trim($email));
        $personKey = $personKey !== null && trim($personKey) !== '' ? $personKey : null;
        if ($email === '' && $personKey === null) {
            return null;
        }

        // Par clé de personne OU par adresse — comme la suppression (S5).
        // `idx_contacts_email`, `idx_contacts_workspace_person_key`.
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

    /** `idx_companies_email_generic_minuscules`. */
    private static function fichesALAdresse(string $email, ?string $workspaceId): Builder
    {
        return self::table('companies', $workspaceId)
            ->whereNotNull('email_generic')
            ->whereRaw('lower(email_generic) = ?', [$email]);
    }

    /**
     * `idx_companies_telephone_chiffres`.
     *
     * @param  list<string>  $variantes
     */
    private static function fichesAuNumero(array $variantes, ?string $workspaceId): Builder
    {
        return self::table('companies', $workspaceId)
            ->whereNotNull('phone')
            ->whereRaw(self::chiffres('phone') . ' IN (' . self::marques($variantes) . ')', $variantes);
    }

    /**
     * Les fiches personnes qui portent l'un de ces numéros. Corbeille
     * comprise, VOLONTAIREMENT : une fiche à la corbeille garde la coordonnée.
     * `idx_contacts_telephone_chiffres`.
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
     * Les fiches dont les CANAUX portent l'une de ces adresses ou l'un de ces
     * numéros — égalité exacte, par les fonctions des deux index GIN
     * (`idx_companies_canaux_emails`, `idx_companies_canaux_telephones`).
     *
     * @param  list<string>  $adresses  en minuscules
     * @param  list<string>  $variantes
     */
    private static function fichesAuxCanaux(array $adresses, array $variantes, ?string $workspaceId): Builder
    {
        return self::table('companies', $workspaceId)
            ->whereRaw(self::AVEC_CANAUX)
            ->where(function (Builder $q) use ($adresses, $variantes): void {
                if ($adresses !== []) {
                    $q->orWhereRaw('canaux_emails(signals) && ARRAY[' . self::marques($adresses) . ']::text[]', $adresses);
                }
                if ($variantes !== []) {
                    $q->orWhereRaw('canaux_telephones(signals) && ARRAY[' . self::marques($variantes) . ']::text[]', $variantes);
                }
                if ($adresses === [] && $variantes === []) {
                    $q->whereRaw('false');
                }
            });
    }

    /**
     * « Au moins une ligne ? » par un COMPTE, jamais par `exists()`. Un
     * `EXISTS` porte un `LIMIT 1` : le planificateur peut alors préférer
     * parcourir l'index de l'ESPACE en espérant tomber vite sur une ligne —
     * c'est-à-dire lire tout l'espace quand le numéro n'y est pas (mesuré
     * en CI, garde `EffacementServiParDesIndexTest`). Les lignes comptées
     * sont une poignée : le compte passe par l'index de la recherche.
     */
    private static function existe(Builder $requete): bool
    {
        return $requete->count() > 0;
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

    /** L'expression EXACTE des deux index de chiffres. */
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
