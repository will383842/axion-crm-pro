<?php

namespace App\Console\Commands;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 C21-003 (S1) — LES DOUBLONS DE PERSONNE, COMPTÉS. PAS FUSIONNÉS.
 *
 * Mesure du 2026-08-19 en production (`04_PREUVES/agent-21/prod_doublons_contacts.txt`) :
 * **1 319 567 contacts, 410 481 avec e-mail pour seulement 234 263 adresses
 * distinctes** — soit **49 492 groupes en doublon, 225 710 fiches impliquées,
 * 176 218 surnuméraires = 42,93 % des contacts joignables**.
 *
 * ── CE QUE CETTE COMMANDE FAIT, ET SURTOUT CE QU'ELLE NE FAIT PAS ──────────
 *
 * Elle **lit**. Aucun `UPDATE`, aucun `DELETE`, aucun `CREATE INDEX`. Elle
 * compte, elle compare au chiffre figé ci-dessous, et elle dit si ça empire.
 *
 * Elle ne fusionne pas, parce qu'une fusion de fiches est un choix de contenu
 * (quel nom garde-t-on ? quelle entreprise ? que deviennent les activités, les
 * tâches et les envois rattachés aux `id` absorbés ?) : ce n'est pas un
 * correctif, c'est un chantier, et il change ce que l'utilisateur voit.
 *
 * Elle ne pose pas non plus l'unicité, et ce n'est pas de la prudence — c'est
 * une mesure :
 *
 *     CREATE UNIQUE INDEX CONCURRENTLY contacts_workspace_id_email_key
 *       ON contacts (workspace_id, email)
 *       WHERE email IS NOT NULL AND deleted_at IS NULL;
 *
 * échouerait sur `SQLSTATE 23505`, **176 218 lignes la violant**. La garde
 * `tests/Feature/Crm/DoublonsPersonnesTest.php` en fait la démonstration en
 * base : elle sème des doublons, tente l'index, et constate le 23505 ; puis
 * elle retire les doublons et le MÊME index passe. L'ordre des opérations
 * n'est donc pas une opinion : **fusionner d'abord, contraindre ensuite**.
 *
 * ── L'ORDRE COMPLET, ÉCRIT ICI POUR NE PAS SE PERDRE ────────────────────────
 *
 *  1. poser `CRM_PERSON_KEY_SECRET` et jouer `crm:remplir-cle-personne`
 *     (A05-001) — sans `person_key`, on n'a aucune clé de rapprochement stable
 *     pour décider QUI est la même personne ;
 *  2. jouer cette commande, garder sa sortie : c'est l'état de départ ;
 *  3. écrire la campagne de fusion (hors périmètre ici : elle touche
 *     `activities`, `crm_notes`, `crm_tasks`, `deals`, `email_sends`,
 *     `email_threads`, `audience_members`, `linkedin_*` — 10 tables portent une
 *     clé étrangère vers `contacts.id`, mesure `\d contacts`) ;
 *  4. rejouer cette commande jusqu'à 0 surnuméraire ;
 *  5. alors seulement, l'index UNIQUE ci-dessus, en `CONCURRENTLY`.
 *
 * ── POURQUOI ELLE N'AFFICHE JAMAIS UNE ADRESSE EN ENTIER ────────────────────
 *
 * Un diagnostic ne doit pas devenir un export de données personnelles. La
 * console réserve déjà la lecture des coordonnées à la permission
 * `contacts.view_pii` (migration `2026_08_15_000005`) ; une commande qui
 * déverserait 49 492 adresses dans un journal de cron contournerait cette
 * permission par le bas. Les groupes sont donc rendus **masqués**
 * (`j…(11)@mairie.fr`) : le domaine et la longueur suffisent à reconnaître un
 * motif — une boîte générique de mairie, un catch-all — et les `id` rendus
 * permettent d'aller voir les fiches par le chemin normal, celui qui vérifie
 * la permission.
 *
 * ── POURQUOI UNE BOUCLE PAR WORKSPACE ──────────────────────────────────────
 *
 * `contacts` porte `FORCE ROW LEVEL SECURITY` et une policy stricte : sans
 * `app.current_workspace_id`, un rôle non-propriétaire voit ZÉRO ligne et cette
 * commande rendrait « 0 doublon » sur une base qui en porte 176 218. C'est
 * exactement le silence que `CrmRemplirClePersonne` décrit dans son en-tête.
 * On pose donc le contexte espace par espace — **et on garde un contrôle** :
 * si la somme par espace vaut 0 alors qu'un décompte hors contexte voit des
 * lignes, la commande ÉCHOUE au lieu d'annoncer un faux zéro.
 *
 * ── LES JUMEAUX ────────────────────────────────────────────────────────────
 *
 * L'audit ne nomme que `contacts`. L'inventaire des index UNIQUE portant sur un
 * e-mail (joué sur `axion_crm_test_lot7`) en montre **quatre** dans la même
 * situation : `contacts`, `candidates`, `journalists`, `health_practitioners`
 * — quatre tables de personne, scopées par espace, colonne `email` en `citext`,
 * **aucune unicité**. `candidates` est le vivier : il porte 0 ligne en
 * production aujourd'hui, ce qui veut dire que la même maladie y est **encore
 * gratuite à soigner**. La commande les mesure toutes ; seul `contacts` porte
 * un chiffre de référence, parce que c'est le seul qui ait été mesuré.
 */
class CrmDoublonsPersonnes extends Command
{
    /**
     * LE CHIFFRE FIGÉ — mesure de production du 2026-08-19, audit 360 C21-003,
     * grille `agent-21_qualite-donnees.md` ligne 1d.
     *
     * Il est ici pour qu'on VOIE le glissement : la commande compare son
     * décompte du jour à celui-ci et dit EMPIRE / STABLE / AMELIORE. Sans
     * repère, « 176 218 doublons » est une phrase ; avec lui, c'est une pente.
     *
     * ⚠️ Ces sept nombres sont liés arithmétiquement et la garde le vérifie
     * (`surnumeraires = avec_email - distincts = fiches - groupes`, et le taux
     * s'en déduit). Corriger l'un sans les autres fait ROUGIR la suite : c'est
     * le seul moyen qu'une mise à jour distraite ne fabrique pas un repère faux.
     *
     * @var array<string, int|float|string>
     */
    public const REFERENCE_PRODUCTION = [
        'date' => '2026-08-19',
        'constat' => 'C21-003',
        'lignes' => 1319567,
        'avec_email' => 410481,
        'distincts' => 234263,
        'groupes' => 49492,
        'fiches_impliquees' => 225710,
        'surnumeraires' => 176218,
        'taux_pourcent' => 42.93,
    ];

    /**
     * Tables de personne mesurées. **Liste blanche**, et pas par élégance : le
     * nom de table est interpolé dans le SQL (on ne peut pas lier un identifiant
     * en paramètre). Tout ce qui n'est pas dans cette liste est REFUSÉ.
     *
     * @var list<string>
     */
    public const TABLES_PERSONNE = ['contacts', 'candidates', 'journalists', 'health_practitioners'];

    protected $signature = 'crm:doublons-personnes
        {--tables= : tables a mesurer, separees par des virgules (defaut : contacts ; « toutes » = les 4 tables de personne)}
        {--top=10 : nombre de groupes les plus gros a detailler (0 = aucun)}
        {--plafond= : echouer si le nombre de surnumeraires depasse ce seuil (pour une tache planifiee)}
        {--json : ne rendre que la mesure, en JSON}';

    protected $description = 'Compte les doublons d adresse e-mail sur les tables de personne. Ne fusionne rien, n ecrit rien.';

    public function handle(): int
    {
        $tables = $this->tablesDemandees();
        if ($tables === null) {
            return self::FAILURE;
        }

        $espaces = array_values(DB::table('workspaces')->orderBy('id')->pluck('id')->all());

        $mesures = [];
        foreach ($tables as $table) {
            // Une table absente n'est PAS « zero doublon ». Un controle qui
            // passe au vert parce qu'il n'a rien trouve a regarder est un faux
            // vert : on echoue, en le disant.
            if (! Schema::hasTable($table)) {
                $this->error("REFUS : la table « {$table} » n'existe pas dans cette base. "
                    . 'Une table absente ne vaut pas « zero doublon » : la mesure est impossible, pas nulle.');

                return self::FAILURE;
            }

            $mesures[$table] = $this->mesurerTable($table, $espaces);
        }

        $incoherences = $this->incoherences($mesures);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'reference_production' => self::REFERENCE_PRODUCTION,
                'espaces' => count($espaces),
                'tables' => $mesures,
                'incoherences' => $incoherences,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->rendreEnTexte($mesures, count($espaces));
        }

        foreach ($incoherences as $message) {
            $this->error($message);
        }

        if ($incoherences !== []) {
            return self::FAILURE;
        }

        return $this->verifierPlafond($mesures);
    }

    /**
     * Mesure UNE table, espace par espace, puis agrège.
     *
     * @param  list<mixed>  $espaces
     * @return array<string, mixed>
     */
    private function mesurerTable(string $table, array $espaces): array
    {
        $agrege = [
            'lignes' => 0,
            'avec_email' => 0,
            'email_vide' => 0,
            'distincts' => 0,
            'groupes' => 0,
            'fiches_impliquees' => 0,
            'avec_email_actifs' => 0,
            'distincts_actifs' => 0,
        ];
        $groupes = [];

        foreach ($espaces as $espace) {
            WorkspaceContext::run((string) $espace, function () use ($table, $espace, &$agrege, &$groupes): void {
                $c = $this->comptesUnEspace($table, (string) $espace);
                foreach ($agrege as $cle => $_) {
                    $agrege[$cle] += $c[$cle];
                }

                foreach ($this->plusGrosGroupes($table, (string) $espace) as $g) {
                    $groupes[] = $g;
                }
            });
        }

        // `count(DISTINCT email)` est calculé PAR ESPACE puis sommé : c'est
        // exactement le `GROUP BY workspace_id, email` de la mesure d'audit.
        // Deux espaces qui portent la même adresse ne sont PAS un doublon — ce
        // sont deux clients distincts, et les fusionner serait une fuite entre
        // espaces. La garde le vérifie explicitement.
        $agrege['surnumeraires'] = $agrege['avec_email'] - $agrege['distincts'];
        $agrege['surnumeraires_actifs'] = $agrege['avec_email_actifs'] - $agrege['distincts_actifs'];
        $agrege['taux_pourcent'] = $agrege['avec_email'] > 0
            ? round($agrege['surnumeraires'] * 100 / $agrege['avec_email'], 2)
            : 0.0;

        // CONTRÔLE ANTI-FAUX-ZÉRO. Sous RLS, une boucle par espace qui perdrait
        // son contexte rendrait 0 sans erreur. On recompte hors contexte : si
        // la boucle rend 0 et que le décompte nu voit des lignes, le 0 est un
        // mensonge, pas une bonne nouvelle.
        $agrege['lignes_hors_contexte'] = (int) DB::table($table)->count();
        $agrege['reference'] = $table === 'contacts' ? self::REFERENCE_PRODUCTION : null;

        // ⚠️ UNE BASE VIDE N'EST PAS UNE AMÉLIORATION.
        //
        // Mesuré sur `axion_crm_test_lot7` fraîchement migrée : la commande
        // annonçait « AMELIORE » sur 0 fiche, simplement parce que 0 < 176 218.
        // C'est exactement le vert déguisé que cette commande est censée
        // débusquer — un chiffre rassurant produit par l'absence de données.
        // Sans une seule fiche joignable, il n'y a pas de mesure : on le dit.
        $agrege['verdict'] = match (true) {
            $agrege['avec_email'] === 0 => 'MESURE VIDE',
            $table === 'contacts' => self::verdict(
                $agrege['surnumeraires'],
                (int) self::REFERENCE_PRODUCTION['surnumeraires'],
            ),
            default => 'SANS REFERENCE',
        };

        usort($groupes, static fn (array $a, array $b): int => $b['fiches'] <=> $a['fiches']);
        $top = max(0, (int) $this->option('top'));
        $agrege['plus_gros_groupes'] = array_slice($groupes, 0, $top);

        return $agrege;
    }

    /**
     * @return array<string, int>
     */
    private function comptesUnEspace(string $table, string $espace): array
    {
        // `$table` vient de la liste blanche TABLES_PERSONNE — vérifié dans
        // tablesDemandees(), jamais d'une saisie libre.
        $ligne = DB::selectOne(
            <<<SQL
            SELECT
                count(*)                                                              AS lignes,
                count(email)                                                          AS avec_email,
                count(*) FILTER (WHERE email IS NOT NULL AND btrim(email::text) = '') AS email_vide,
                count(DISTINCT email)                                                 AS distincts,
                count(email) FILTER (WHERE deleted_at IS NULL)                        AS avec_email_actifs,
                count(DISTINCT email) FILTER (WHERE deleted_at IS NULL)               AS distincts_actifs
            FROM {$table}
            WHERE workspace_id = ?
            SQL,
            [$espace],
        );

        $groupes = DB::selectOne(
            <<<SQL
            SELECT count(*) AS groupes, coalesce(sum(n), 0) AS fiches
            FROM (
                SELECT email, count(*) AS n
                FROM   {$table}
                WHERE  workspace_id = ?
                  AND  email IS NOT NULL
                GROUP  BY email
                HAVING count(*) > 1
            ) g
            SQL,
            [$espace],
        );

        return [
            'lignes' => (int) $ligne->lignes,
            'avec_email' => (int) $ligne->avec_email,
            'email_vide' => (int) $ligne->email_vide,
            'distincts' => (int) $ligne->distincts,
            'avec_email_actifs' => (int) $ligne->avec_email_actifs,
            'distincts_actifs' => (int) $ligne->distincts_actifs,
            'groupes' => (int) $groupes->groupes,
            'fiches_impliquees' => (int) $groupes->fiches,
        ];
    }

    /**
     * Les groupes les plus fournis, ADRESSE MASQUÉE (cf. en-tête).
     *
     * @return list<array<string, mixed>>
     */
    private function plusGrosGroupes(string $table, string $espace): array
    {
        $top = max(0, (int) $this->option('top'));
        if ($top === 0) {
            return [];
        }

        $lignes = DB::select(
            <<<SQL
            SELECT email::text AS adresse,
                   count(*)    AS fiches,
                   (array_agg(id ORDER BY id))[1:5] AS premiers_ids
            FROM   {$table}
            WHERE  workspace_id = ?
              AND  email IS NOT NULL
            GROUP  BY email
            HAVING count(*) > 1
            ORDER  BY count(*) DESC, email
            LIMIT  {$top}
            SQL,
            [$espace],
        );

        return array_values(array_map(static fn ($l): array => [
            'espace' => substr($espace, 0, 8),
            'adresse_masquee' => self::masquer((string) $l->adresse),
            'fiches' => (int) $l->fiches,
            'premiers_ids' => trim((string) $l->premiers_ids, '{}'),
        ], $lignes));
    }

    /**
     * `jean.dupont@mairie.fr` → `j…(11)@mairie.fr`.
     *
     * On garde la première lettre, la LONGUEUR de la partie locale et le
     * domaine entier : de quoi reconnaître une boîte générique ou un catch-all,
     * sans rendre l'adresse lisible. Une adresse sans « @ » (il en existe :
     * `email_vide` les compte) est rendue par un simple repère de longueur.
     */
    public static function masquer(string $adresse): string
    {
        $position = strrpos($adresse, '@');
        if ($position === false || $position === 0) {
            return '(adresse sans domaine, longueur ' . mb_strlen($adresse) . ')';
        }

        $local = substr($adresse, 0, $position);
        $domaine = substr($adresse, $position + 1);

        return mb_substr($local, 0, 1) . '...(' . mb_strlen($local) . ')@' . $domaine;
    }

    /**
     * EMPIRE / STABLE / AMELIORE, par comparaison au chiffre figé.
     *
     * Méthode publique et pure : la garde l'exerce sur des valeurs choisies, ce
     * qu'aucun jeu de données de test ne permettrait (on ne va pas semer
     * 176 219 contacts pour prouver que la commande sait dire « empire »).
     *
     * Sans accent : cette chaîne est lue par une garde, et un contrôle sur du
     * texte français se joue sur une sous-chaîne sans lettre accentuée.
     */
    public static function verdict(int $mesure, int $reference): string
    {
        if ($reference <= 0) {
            return 'SANS REFERENCE';
        }
        if ($mesure > $reference) {
            return 'EMPIRE';
        }
        if ($mesure < $reference) {
            return 'AMELIORE';
        }

        return 'STABLE';
    }

    /**
     * Les contradictions internes de la mesure. Une commande de diagnostic qui
     * se contredit ne doit pas rendre un chiffre : elle doit le dire.
     *
     * @param  array<string, array<string, mixed>>  $mesures
     * @return list<string>
     */
    private function incoherences(array $mesures): array
    {
        $messages = [];

        foreach ($mesures as $table => $m) {
            // (1) Les deux chemins de calcul doivent tomber d'accord :
            //     surnumeraires = avec_email - distincts  (par comptage)
            //                   = fiches_impliquees - groupes (par regroupement)
            $parGroupes = $m['fiches_impliquees'] - $m['groupes'];
            if ($m['surnumeraires'] !== $parGroupes) {
                $messages[] = "INCOHERENCE sur « {$table} » : le comptage donne {$m['surnumeraires']} "
                    . "surnumeraires, le regroupement en donne {$parGroupes}. La mesure n'est pas fiable.";
            }

            // (2) Le faux zero sous RLS.
            if ($m['lignes'] === 0 && $m['lignes_hors_contexte'] > 0) {
                $messages[] = "FAUX ZERO sur « {$table} » : la boucle par espace ne voit aucune ligne "
                    . "alors qu'un decompte hors contexte en voit {$m['lignes_hors_contexte']}. "
                    . 'Le contexte workspace ne mord pas (RLS, ou table workspaces vide) : '
                    . 'le resultat serait un zero mensonger.';
            }
        }

        return $messages;
    }

    /**
     * @param  array<string, array<string, mixed>>  $mesures
     */
    private function verifierPlafond(array $mesures): int
    {
        $plafond = $this->option('plafond');
        if ($plafond === null || $plafond === '') {
            return self::SUCCESS;
        }

        $plafond = (int) $plafond;
        $total = array_sum(array_map(static fn (array $m): int => (int) $m['surnumeraires'], $mesures));

        if ($total > $plafond) {
            $this->error("PLAFOND DEPASSE : {$total} surnumeraires mesures, plafond {$plafond}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null null = refus (table hors liste blanche)
     */
    private function tablesDemandees(): ?array
    {
        $demande = trim((string) ($this->option('tables') ?? ''));

        if ($demande === '') {
            return ['contacts'];
        }
        if ($demande === 'toutes') {
            return self::TABLES_PERSONNE;
        }

        $tables = array_values(array_filter(array_map('trim', explode(',', $demande))));
        $inconnues = array_diff($tables, self::TABLES_PERSONNE);

        if ($inconnues !== []) {
            $this->error('REFUS : table(s) hors liste blanche : ' . implode(', ', $inconnues) . '. '
                . 'Attendu parmi : ' . implode(', ', self::TABLES_PERSONNE) . '. '
                . 'Le nom de table est interpole dans le SQL, il ne peut pas venir d une saisie libre.');

            return null;
        }

        return $tables;
    }

    /**
     * @param  array<string, array<string, mixed>>  $mesures
     */
    private function rendreEnTexte(array $mesures, int $espaces): void
    {
        $ref = self::REFERENCE_PRODUCTION;

        $this->info("Doublons d adresse e-mail — mesure sur {$espaces} espace(s) de travail.");
        $this->line('Repere fige (production ' . $ref['date'] . ', constat ' . $ref['constat'] . ') sur contacts : '
            . $ref['surnumeraires'] . ' surnumeraires sur ' . $ref['avec_email']
            . ' fiches joignables, soit ' . $ref['taux_pourcent'] . ' %.');
        $this->newLine();

        foreach ($mesures as $table => $m) {
            $this->line("── {$table} ────────────────────────────────────────────");
            $this->line("  lignes                 : {$m['lignes']}");
            $this->line("  avec une adresse       : {$m['avec_email']}"
                . ($m['email_vide'] > 0 ? "  (dont {$m['email_vide']} adresse(s) VIDE(S), non nulles)" : ''));
            $this->line("  adresses distinctes    : {$m['distincts']}");
            $this->line("  groupes en doublon     : {$m['groupes']}");
            $this->line("  fiches impliquees      : {$m['fiches_impliquees']}");
            $this->line("  SURNUMERAIRES          : {$m['surnumeraires']}  ({$m['taux_pourcent']} % des joignables)");
            $this->line("  dont hors suppression  : {$m['surnumeraires_actifs']}"
                . '   <- c est ce nombre-la que l index UNIQUE partiel devra voir a zero');
            $this->line("  verdict vs repere      : {$m['verdict']}");

            foreach ($m['plus_gros_groupes'] as $g) {
                $this->line("     [{$g['espace']}] {$g['adresse_masquee']} : {$g['fiches']} fiches "
                    . "(ids {$g['premiers_ids']}...)");
            }
            $this->newLine();
        }

        $contacts = $mesures['contacts'] ?? null;
        if ($contacts !== null && $contacts['surnumeraires'] > 0) {
            $this->warn('CE QU IL FAUDRAIT FAIRE, ET POURQUOI CE N EST PAS FAIT ICI :');
            $this->line('  L unicite visee est :');
            $this->line('    CREATE UNIQUE INDEX CONCURRENTLY contacts_workspace_id_email_key');
            $this->line('      ON contacts (workspace_id, email) WHERE email IS NOT NULL AND deleted_at IS NULL;');
            $this->line('  Elle echouerait MAINTENANT en SQLSTATE 23505 : ' . $contacts['surnumeraires_actifs']
                . ' ligne(s) la violent. Poser la contrainte avant la fusion, c est un deploiement rouge,');
            $this->line('  pas une protection. L ordre est : (1) crm:remplir-cle-personne, (2) campagne de fusion,');
            $this->line('  (3) cette commande a zero, (4) alors l index. Voir l en-tete de cette commande.');
        }
    }
}
