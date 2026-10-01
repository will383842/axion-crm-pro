<?php

namespace App\Crm\Listes;

use App\Crm\Emails\QualificationEmail;
use App\Models\ListeManuelle;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * IMPORTER UN FICHIER DANS UNE LISTE MANUELLE — par RAPPROCHEMENT, jamais par
 * création.
 *
 * Chaque ligne du fichier désigne une fiche qui existe DÉJÀ dans l'espace :
 *
 *  - `organisation:123` / `contact:123` — la référence CRM que rend
 *    `crm:campagne:destinataires` (`crm_ref`) ;
 *  - un SIREN (9 chiffres, espaces tolérés) — l'organisation qui le porte ;
 *  - un identifiant CRM (nombre entier) — une organisation, ou une personne
 *    dans une colonne `contact_id` ;
 *  - une adresse e-mail — la ou les PERSONNES qui la portent ; à défaut, la ou
 *    les ORGANISATIONS qui la portent comme adresse générique ou dans leurs
 *    canaux (`signals.contact_channels`).
 *
 * Une ligne qui ne se rapproche de RIEN est comptée et rejetée
 * (`introuvable`) : on n'invente jamais une fiche, et une adresse libre qui
 * n'existe pas dans le CRM n'entre jamais dans une liste. Une ligne illisible
 * est rejetée `format_inconnu`. Le bilan ne rend que des NUMÉROS de ligne,
 * jamais les valeurs (un fichier d'adresses est une donnée personnelle).
 *
 * Formats : CSV (virgule, point-virgule ou tabulation ; en-tête reconnu ou
 * non) et JSONL (un objet par ligne, clés `crm_ref`, `contact_id`,
 * `organisation_id`/`company_id`, `siren`, `email`).
 */
final class ImportListe
{
    /** Taille maximale d'un fichier importé, en octets. */
    public const TAILLE_MAX = 5 * 1024 * 1024;

    /** Nombre maximal de lignes non vides. */
    public const LIGNES_MAX = 20000;

    /** Nombre maximal de lignes rejetées citées (par leur numéro) dans le bilan. */
    public const EXEMPLES_MAX = 50;

    public const FORMAT_INCONNU = 'format_inconnu';

    public const INTROUVABLE = 'introuvable';

    /**
     * Colonnes reconnues, par ordre de priorité quand une ligne en porte
     * plusieurs : la référence la plus précise d'abord.
     *
     * @var array<string, list<string>>
     */
    private const COLONNES = [
        'crm_ref' => ['crm_ref', 'ref_crm', 'reference'],
        'contact_id' => ['contact_id', 'id_contact', 'personne_id'],
        'organisation_id' => ['organisation_id', 'company_id', 'id_organisation', 'entreprise_id'],
        'siren' => ['siren'],
        'email' => ['email', 'e-mail', 'mail', 'courriel', 'adresse_email'],
    ];

    /**
     * Analyse le contenu et rapproche chaque ligne. N'écrit RIEN.
     *
     * @return array{cles: array{organisations: list<int>, personnes: list<int>}, bilan: array<string, mixed>}
     *
     * @throws InvalidArgumentException fichier trop gros, trop long ou vide
     */
    public static function analyser(string $workspaceId, string $contenu): array
    {
        if (strlen($contenu) > self::TAILLE_MAX) {
            throw new InvalidArgumentException('Fichier trop volumineux (5 Mo au plus).');
        }
        $contenu = self::sansBom($contenu);
        $lignes = preg_split('/\r\n|\r|\n/', $contenu) ?: [];

        /** @var list<array{numero: int, texte: string}> $utiles */
        $utiles = [];
        foreach ($lignes as $i => $texte) {
            if (trim($texte) !== '') {
                $utiles[] = ['numero' => $i + 1, 'texte' => $texte];
            }
        }
        if ($utiles === []) {
            throw new InvalidArgumentException('Fichier vide : aucune ligne à rapprocher.');
        }
        if (count($utiles) > self::LIGNES_MAX + 1) {
            throw new InvalidArgumentException('Fichier trop long : ' . self::LIGNES_MAX . ' lignes au plus par import.');
        }

        $jsonl = str_starts_with(ltrim($utiles[0]['texte']), '{');
        $cles = $jsonl ? self::lireJsonl($utiles) : self::lireCsv($utiles);

        return self::rapprocher($workspaceId, $cles);
    }

    /**
     * Analyse, puis ajoute à la liste ce qui a été rapproché.
     *
     * @return array<string, mixed> le bilan, avec `ajout` (ajoutés, réactivés, déjà présents)
     */
    public static function importer(ListeManuelle $liste, string $contenu, ?string $par): array
    {
        $analyse = self::analyser((string) $liste->workspace_id, $contenu);
        $ajout = ListesManuelles::ajouter(
            $liste,
            $analyse['cles']['organisations'],
            $analyse['cles']['personnes'],
            $par,
            ListesManuelles::ORIGINE_IMPORT,
        );

        return $analyse['bilan'] + ['ajout' => $ajout];
    }

    // ── Lecture ─────────────────────────────────────────────────────────────

    /**
     * @param  list<array{numero: int, texte: string}>  $utiles
     * @return list<array{numero: int, type: ?string, valeur: string}>
     */
    private static function lireJsonl(array $utiles): array
    {
        $cles = [];
        foreach ($utiles as $l) {
            $objet = json_decode($l['texte'], true);
            if (! is_array($objet) || array_is_list($objet)) {
                $cles[] = ['numero' => $l['numero'], 'type' => null, 'valeur' => ''];

                continue;
            }
            $normalise = [];
            foreach ($objet as $k => $v) {
                if (is_scalar($v)) {
                    $normalise[mb_strtolower(trim((string) $k))] = trim((string) $v);
                }
            }
            $cles[] = ['numero' => $l['numero']] + self::depuisColonnes($normalise);
        }

        return $cles;
    }

    /**
     * @param  list<array{numero: int, texte: string}>  $utiles
     * @return list<array{numero: int, type: ?string, valeur: string}>
     */
    private static function lireCsv(array $utiles): array
    {
        $premiere = $utiles[0]['texte'];
        $separateur = ',';
        $max = 0;
        foreach ([';', ',', "\t"] as $s) {
            $n = substr_count($premiere, $s);
            if ($n > $max) {
                $max = $n;
                $separateur = $s;
            }
        }

        $entete = array_map(
            static fn (?string $c): string => mb_strtolower(trim((string) $c, " \t\"'")),
            str_getcsv($premiere, $separateur),
        );
        $reconnues = array_filter($entete, static function (string $c): bool {
            foreach (self::COLONNES as $alias) {
                if (in_array($c, $alias, true)) {
                    return true;
                }
            }

            return false;
        });
        $avecEntete = $reconnues !== [];

        $cles = [];
        foreach ($avecEntete ? array_slice($utiles, 1) : $utiles as $l) {
            $cellules = array_map(static fn (?string $c): string => trim((string) $c), str_getcsv($l['texte'], $separateur));
            if ($avecEntete) {
                $associe = [];
                foreach ($entete as $i => $nom) {
                    if ($nom !== '' && isset($cellules[$i])) {
                        $associe[$nom] = $cellules[$i];
                    }
                }
                $cles[] = ['numero' => $l['numero']] + self::depuisColonnes($associe);
            } else {
                // Sans en-tête : la première cellule non vide, reconnue par sa forme.
                $valeur = '';
                foreach ($cellules as $c) {
                    if ($c !== '') {
                        $valeur = $c;
                        break;
                    }
                }
                $cles[] = ['numero' => $l['numero']] + self::deviner($valeur);
            }
        }

        return $cles;
    }

    /**
     * @param  array<string, string>  $ligne  nom de colonne normalisé => valeur
     * @return array{type: ?string, valeur: string}
     */
    private static function depuisColonnes(array $ligne): array
    {
        foreach (self::COLONNES as $type => $alias) {
            foreach ($alias as $nom) {
                $v = $ligne[$nom] ?? '';
                if ($v === '') {
                    continue;
                }

                return match ($type) {
                    'crm_ref', 'email' => self::deviner($v),
                    'siren' => self::siren($v),
                    'contact_id' => ctype_digit($v) ? ['type' => 'contact', 'valeur' => $v] : ['type' => null, 'valeur' => ''],
                    default => ctype_digit($v) ? ['type' => 'organisation', 'valeur' => $v] : ['type' => null, 'valeur' => ''],
                };
            }
        }

        return ['type' => null, 'valeur' => ''];
    }

    /**
     * Reconnaît une valeur isolée par sa forme.
     *
     * @return array{type: ?string, valeur: string}
     */
    private static function deviner(string $v): array
    {
        $v = trim($v, " \t\"'");
        if (preg_match('/^(organisation|contact):(\d+)$/i', $v, $m) === 1) {
            return ['type' => strtolower($m[1]), 'valeur' => $m[2]];
        }
        if (str_contains($v, '@')) {
            $email = QualificationEmail::normaliser($v);

            return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
                ? ['type' => 'email', 'valeur' => $email]
                : ['type' => null, 'valeur' => ''];
        }
        $chiffres = preg_replace('/[\s.]/u', '', $v) ?? '';
        if (preg_match('/^\d{9}$/', $chiffres) === 1) {
            return ['type' => 'siren', 'valeur' => $chiffres];
        }
        // Un entier seul, qui n'a pas la forme d'un SIREN : un identifiant CRM
        // d'organisation. Une personne se désigne par `contact:<id>` ou par une
        // colonne `contact_id`.
        if (preg_match('/^\d{1,18}$/', $v) === 1) {
            return ['type' => 'organisation', 'valeur' => $v];
        }

        return ['type' => null, 'valeur' => ''];
    }

    /** @return array{type: ?string, valeur: string} */
    private static function siren(string $v): array
    {
        $chiffres = preg_replace('/[\s.]/u', '', $v) ?? '';

        return preg_match('/^\d{9}$/', $chiffres) === 1
            ? ['type' => 'siren', 'valeur' => $chiffres]
            : ['type' => null, 'valeur' => ''];
    }

    private static function sansBom(string $contenu): string
    {
        return str_starts_with($contenu, "\xEF\xBB\xBF") ? substr($contenu, 3) : $contenu;
    }

    // ── Rapprochement ───────────────────────────────────────────────────────

    /**
     * @param  list<array{numero: int, type: ?string, valeur: string}>  $cles
     * @return array{cles: array{organisations: list<int>, personnes: list<int>}, bilan: array<string, mixed>}
     */
    private static function rapprocher(string $ws, array $cles): array
    {
        /** @var array<string, array<string, true>> $parType */
        $parType = ['organisation' => [], 'contact' => [], 'siren' => [], 'email' => []];
        foreach ($cles as $c) {
            if ($c['type'] !== null) {
                $parType[$c['type']][$c['valeur']] = true;
            }
        }
        // ⚠️ PHP range une clé « 123456789 » comme un ENTIER : on repasse en
        // texte, sinon `siren` (CHAR) serait comparé à un nombre.
        $valeurs = static fn (string $type): array => array_map(
            static fn (int|string $v): string => (string) $v,
            array_keys($parType[$type] ?? []),
        );

        // Chaque valeur distincte => [organisations], [personnes].
        $organisationsParId = self::organisationsParId($ws, $valeurs('organisation'));
        $personnesParId = self::personnesParId($ws, $valeurs('contact'));
        $organisationsParSiren = self::organisationsParSiren($ws, $valeurs('siren'));
        [$personnesParEmail, $organisationsParEmail] = self::parEmail($ws, $valeurs('email'));

        $organisations = [];
        $personnes = [];
        /** @var array<string, int> $rejetees */
        $rejetees = [self::FORMAT_INCONNU => 0, self::INTROUVABLE => 0];
        /** @var array<string, int> $parSorte */
        $parSorte = ['crm' => 0, 'siren' => 0, 'email_personne' => 0, 'email_organisation' => 0];
        /** @var list<array{ligne: int, motif: string}> $exemples */
        $exemples = [];
        $rapprochees = 0;
        $doublons = 0;
        $plusieurs = 0;
        $vues = [];
        $rejeter = static function (int $numero, string $motif) use (&$rejetees, &$exemples): void {
            $rejetees[$motif] = ($rejetees[$motif] ?? 0) + 1;
            if (count($exemples) < self::EXEMPLES_MAX) {
                $exemples[] = ['ligne' => $numero, 'motif' => $motif];
            }
        };

        foreach ($cles as $c) {
            if ($c['type'] === null) {
                $rejeter($c['numero'], self::FORMAT_INCONNU);

                continue;
            }
            $cle = $c['type'] . ':' . $c['valeur'];
            if (isset($vues[$cle])) {
                $doublons++;

                continue;
            }
            $vues[$cle] = true;

            $orgs = [];
            $pers = [];
            $sorte = 'crm';
            switch ($c['type']) {
                case 'organisation':
                    $orgs = $organisationsParId[$c['valeur']] ?? [];
                    break;
                case 'contact':
                    $pers = $personnesParId[$c['valeur']] ?? [];
                    break;
                case 'siren':
                    $orgs = $organisationsParSiren[$c['valeur']] ?? [];
                    $sorte = 'siren';
                    break;
                case 'email':
                    // La PERSONNE d'abord : cocher une adresse nominative, c'est
                    // viser cette personne, pas toute son organisation.
                    $pers = $personnesParEmail[$c['valeur']] ?? [];
                    $orgs = $pers === [] ? ($organisationsParEmail[$c['valeur']] ?? []) : [];
                    $sorte = $pers !== [] ? 'email_personne' : 'email_organisation';
                    break;
            }

            if ($orgs === [] && $pers === []) {
                // Rien dans le CRM ne porte cette valeur : la ligne est
                // REJETÉE. On ne crée jamais de fiche, ni d'adresse libre.
                $rejeter($c['numero'], self::INTROUVABLE);

                continue;
            }
            $rapprochees++;
            $parSorte[$sorte] = ($parSorte[$sorte] ?? 0) + 1;
            if (count($orgs) + count($pers) > 1) {
                $plusieurs++;
            }
            foreach ($orgs as $id) {
                $organisations[$id] = $id;
            }
            foreach ($pers as $id) {
                $personnes[$id] = $id;
            }
        }

        // La presse n'entre dans aucune liste (segment ouvert ou non)
        // (`GardePresse`) : `ListesManuelles::ajouter()` la refusera. On le
        // compte DÈS L'ANALYSE à blanc, pour que l'écran l'annonce avant
        // l'import — sans rien retirer des clés (le refus reste au seul
        // endroit qui écrit).
        [$orgsAdmises, $persAdmises] = ListesManuelles::sansPresse(array_values($organisations), array_values($personnes));
        $presse = (count($organisations) - count($orgsAdmises)) + (count($personnes) - count($persAdmises));

        return [
            'cles' => ['organisations' => array_values($organisations), 'personnes' => array_values($personnes)],
            'bilan' => [
                'lignes_lues' => count($cles),
                'rapprochees' => $rapprochees,
                'rejetees' => $rejetees,
                'doublons_dans_le_fichier' => $doublons,
                'rapprochees_a_plusieurs_fiches' => $plusieurs,
                'par_type' => $parSorte,
                'organisations_retrouvees' => count($organisations),
                'personnes_retrouvees' => count($personnes),
                'presse_refusees' => $presse,
                'exemples_rejets' => $exemples,
            ],
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<int>>
     */
    private static function organisationsParId(string $ws, array $ids): array
    {
        $trouves = [];
        foreach (array_chunk($ids, 1000) as $paquet) {
            foreach (DB::table('companies')->whereNull('deleted_at')->where('workspace_id', $ws)->whereIn('id', array_map('intval', $paquet))->pluck('id') as $id) {
                $trouves[(string) $id] = [(int) $id];
            }
        }

        return $trouves;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, list<int>>
     */
    private static function personnesParId(string $ws, array $ids): array
    {
        $trouves = [];
        foreach (array_chunk($ids, 1000) as $paquet) {
            foreach (DB::table('contacts')->whereNull('deleted_at')->where('workspace_id', $ws)->whereIn('id', array_map('intval', $paquet))->pluck('id') as $id) {
                $trouves[(string) $id] = [(int) $id];
            }
        }

        return $trouves;
    }

    /**
     * @param  list<string>  $sirens
     * @return array<string, list<int>>
     */
    private static function organisationsParSiren(string $ws, array $sirens): array
    {
        $trouves = [];
        foreach (array_chunk($sirens, 1000) as $paquet) {
            foreach (DB::table('companies')->whereNull('deleted_at')->where('workspace_id', $ws)->whereIn('siren', $paquet)->get(['id', 'siren']) as $l) {
                $trouves[trim((string) $l->siren)][] = (int) $l->id;
            }
        }

        return $trouves;
    }

    /**
     * Personnes (`contacts.email`, CITEXT indexé) et organisations (adresse
     * générique, index `idx_companies_email_generic_norm` ; canaux typés, index
     * GIN de `signals`) qui portent ces adresses normalisées.
     *
     * @param  list<string>  $emails
     * @return array{0: array<string, list<int>>, 1: array<string, list<int>>}
     */
    private static function parEmail(string $ws, array $emails): array
    {
        $personnes = [];
        $organisations = [];
        foreach (array_chunk($emails, 500) as $paquet) {
            foreach (DB::table('contacts')->whereNull('deleted_at')->where('workspace_id', $ws)->whereIn('email', $paquet)->get(['id', 'email']) as $l) {
                $personnes[QualificationEmail::normaliser((string) $l->email)][] = (int) $l->id;
            }

            $restantes = array_values(array_filter($paquet, static fn (string $e): bool => ! isset($personnes[$e])));
            if ($restantes === []) {
                continue;
            }
            $marques = implode(', ', array_fill(0, count($restantes), '?'));
            foreach (DB::table('companies')->whereNull('deleted_at')->where('workspace_id', $ws)
                ->whereRaw("email_generic IS NOT NULL AND email_generic <> '' AND lower(btrim(email_generic)) IN ({$marques})", $restantes)
                ->get(['id', 'email_generic']) as $l) {
                $organisations[QualificationEmail::normaliser((string) $l->email_generic)][] = (int) $l->id;
            }
            foreach ($restantes as $e) {
                // Les canaux : la liste `emails` (index GIN `@>`).
                $motif = json_encode(['contact_channels' => ['emails' => [$e]]], JSON_THROW_ON_ERROR);
                foreach (DB::table('companies')->whereNull('deleted_at')->where('workspace_id', $ws)
                    ->whereRaw('signals @> ?::jsonb', [$motif])
                    ->pluck('id') as $id) {
                    $organisations[$e][] = (int) $id;
                }
            }
        }
        foreach ($organisations as $e => $ids) {
            $organisations[$e] = array_values(array_unique($ids));
        }

        return [$personnes, $organisations];
    }
}
