<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Doublons\Rapprochement;
use App\Crm\Doublons\RefusFusion;
use App\Crm\Presse\DoublonsPresse;
use App\Crm\Presse\QualificationPresse;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * LES DOUBLONS DE LA PRESSE (constat en production du 2026-10-01).
 *
 * L'harmonisation (#264) a créé une fiche par ligne `media` : un même titre
 * venu de plusieurs sources (ARCOM et kit presse, Wikidata et kit presse…) a
 * plusieurs fiches sans SIREN, et `crm:presse:importer` rejette les lignes de
 * ce titre (`rapprochement_ambigu`). Cette commande les regroupe et :
 *
 *  - FUSIONNE (par `FusionFiches` : annulable, journalisée, la fiche absorbée
 *    à la corbeille, jamais supprimée) les cas STRICTS seulement — deux fiches
 *    sans SIREN, même titre, même type, départements et adresses compatibles,
 *    aucune relation saisie à la main (`DoublonsPresse::juger`, re-jugé par
 *    `FusionFiches` dans la transaction) ;
 *  - laisse les ÉDITIONS (même titre, départements différents) telles quelles ;
 *  - dépose TOUT LE RESTE dans la file « Doublons à vérifier » (motif
 *    `presse_homonyme`) — dont tout journal face à la fiche de son ÉDITEUR
 *    (SIREN) : décision de Will, jamais de fusion automatique.
 *
 * Une paire déjà écartée par un humain (« ce ne sont pas des doublons ») ou
 * dont une fusion a été annulée n'est jamais fusionnée ni redéposée.
 *
 * ── Le groupe et la fiche gardée ─────────────────────────────────────────
 * Un groupe = les fiches vivantes `presse_media` qui portent une ligne `media`
 * vivante de même nom normalisé et de même famille de type. S'il y a
 * plusieurs départements, chaque département forme son sous-groupe (les
 * éditions) ; une fiche sans département n'est alors rattachée à aucune
 * édition d'office : elle va dans la file face à chacune. Dans un sous-groupe,
 * la fiche gardée est la meilleure fiche sans SIREN (`DoublonsPresse::
 * meilleure`) ; chaque autre fiche est jugée face à elle.
 *
 * ── Options ──────────────────────────────────────────────────────────────
 * À BLANC PAR DÉFAUT : chaque fusion passe par le même chemin puis est
 * annulée, et rien n'est déposé dans la file. `--appliquer` écrit. Chaque
 * fusion est UNE transaction courte. `--limite=N` : N groupes ;
 * `--depuis-id=N` : reprendre au groupe dont la plus petite fiche est N.
 * `--compteurs-seulement` : que des nombres (journaux publics des workflows),
 * ni nom, ni adresse, ni identifiant. Sans cette option non plus, la sortie ne
 * cite jamais de nom ni d'adresse : des compteurs, et le dernier groupe traité
 * (un identifiant de fiche) pour reprendre.
 */
class CrmPresseDoublons extends Command
{
    protected $signature = 'crm:presse:doublons
                            {--appliquer : Écrire (sans cette option : à blanc, rien n\'est écrit)}
                            {--limite= : Ne traiter que les N premiers groupes}
                            {--depuis-id= : Reprendre au groupe dont la plus petite fiche a l\'identifiant N (inclus)}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Dédoublonne les fiches de presse : fusion automatique (annulable) des seuls cas stricts, le reste dans « Doublons à vérifier ».';

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> */
    private array $refus = [];

    private string $ws = '';

    private bool $aBlanc = true;

    private string $operateur = '?';

    public function handle(FusionFiches $fusion, AuditHashChain $audit): int
    {
        $discret = (bool) $this->option('compteurs-seulement');
        $this->aBlanc = ! (bool) $this->option('appliquer');

        $limite = $this->entier('limite');
        $depuis = $this->entier('depuis-id');
        if ($limite === false || $depuis === false) {
            $this->error('--limite et --depuis-id attendent un entier positif.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $ws = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($ws === null) {
            $this->error($discret ? 'Espace business introuvable.' : "Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $this->ws = (string) $ws;
        $this->operateur = 'crm:presse:doublons ' . self::operateur();

        $this->bilan = array_fill_keys([
            'groupes', 'fiches_vues', 'fusionnees', 'fusions_refusees', 'deposees_a_verifier', 'deja_en_file',
            'ecartees_par_un_humain', 'fusions_annulees_non_reprises', 'editions_distinctes',
        ], 0);
        $this->refus = [];

        $dernier = null;
        $erreur = null;
        try {
            WorkspaceContext::run($this->ws, function () use ($fusion, $limite, $depuis, &$dernier): void {
                $traites = 0;
                foreach ($this->groupes($depuis ?? 0) as $g) {
                    if ($limite !== null && $traites >= $limite) {
                        return;
                    }
                    $this->traiterGroupe($fusion, (string) $g->nom, (string) $g->famille, self::ids((string) $g->fiches));
                    $dernier = (int) $g->premier;
                    $traites++;
                    $this->bilan['groupes']++;
                }
            });
        } catch (Throwable $e) {
            $erreur = $e;
            Log::error('crm:presse:doublons : arrêt', ['dernier_groupe' => $dernier, 'erreur' => get_class($e)]);
        }

        if (! $this->aBlanc) {
            try {
                $audit->record([
                    'workspace_id' => $this->ws, 'user_id' => null, 'method' => 'DOUBLONS_PRESSE',
                    'path' => 'artisan crm:presse:doublons', 'status' => $erreur === null ? 200 : 500, 'ip' => null,
                    'user_agent' => 'cli ' . $this->operateur,
                    'payload_hash' => hash('sha256', json_encode(['bilan' => $this->bilan, 'refus' => $this->refus], JSON_THROW_ON_ERROR)),
                ]);
            } catch (Throwable $e) {
                $erreur ??= $e;
            }
        }

        $this->info($this->aBlanc
            ? '[À BLANC] chaque fusion a été annulée, rien n\'a été déposé : rien n\'a été écrit.'
            : 'Dédoublonnage de la presse appliqué.');
        $lignes = [];
        foreach ($this->bilan as $cle => $n) {
            $lignes[] = [$cle, $n];
        }
        ksort($this->refus);
        foreach ($this->refus as $raison => $n) {
            $lignes[] = ["refus_{$raison}", $n];
        }
        $this->table(['compteur', 'nombre'], $lignes);
        if ($this->aBlanc) {
            $this->line('MESURÉ : chaque fusion prise seule, sur le même chemin que le réel, puis annulée.');
        }

        if ($erreur !== null) {
            if ($discret) {
                throw new RuntimeException('crm:presse:doublons interrompu (détail masqué : --compteurs-seulement, voir le journal du serveur).');
            }
            $this->error('INTERROMPU. Les fusions validées sont acquises et annulables.'
                . ($dernier !== null ? ' Reprendre avec --depuis-id=' . ($dernier + 1) . '.' : ''));

            throw $erreur;
        }
        if (! $discret && $dernier !== null) {
            $this->line("Dernier groupe traité : plus petite fiche {$dernier}.");
        }
        if (! $this->aBlanc) {
            $this->info('Une fusion s\'annule par : php artisan crm:doublons:fusionner --annuler=<numéro>');
        }

        return self::SUCCESS;
    }

    /**
     * Les groupes : nom normalisé et famille de type portés par au moins deux
     * fiches vivantes `presse_media`, dans l'ordre de leur plus petite fiche.
     *
     * @return list<stdClass>
     */
    private function groupes(int $depuis): array
    {
        $famille = DoublonsPresse::familleSql('pd_m.media_type');
        $lignes = DB::select(
            "SELECT normalize_name(pd_m.name) AS nom, {$famille} AS famille,
                    min(pd_c.id) AS premier, array_agg(DISTINCT pd_c.id ORDER BY pd_c.id) AS fiches
             FROM media pd_m
             JOIN companies pd_c ON pd_c.id = pd_m.company_id AND pd_c.workspace_id = pd_m.workspace_id
             WHERE pd_m.workspace_id = ? AND pd_m.deleted_at IS NULL AND pd_c.deleted_at IS NULL
               AND pd_c.relation_type = ? AND {$famille} IS NOT NULL
               AND normalize_name(pd_m.name) IS NOT NULL AND normalize_name(pd_m.name) <> ''
             GROUP BY 1, 2
             HAVING count(DISTINCT pd_c.id) > 1 AND min(pd_c.id) >= ?
             ORDER BY premier, nom, famille",
            [$this->ws, QualificationPresse::RELATION, $depuis],
        );

        return array_values(array_filter($lignes, static fn ($l): bool => $l instanceof stdClass));
    }

    /** @param  list<int>  $ids */
    private function traiterGroupe(FusionFiches $fusion, string $nom, string $famille, array $ids): void
    {
        $profils = DoublonsPresse::profils($this->ws, $ids, $nom, $famille);
        $this->bilan['fiches_vues'] += count($profils);
        if (count($profils) < 2) {
            return;
        }

        // Les éditions : un sous-groupe par département quand il y en a
        // plusieurs ; une fiche sans département (ou à plusieurs) n'est
        // rattachée d'office à aucune.
        $parDepartement = [];
        $flottantes = [];
        foreach ($profils as $p) {
            if (count($p['departements']) === 1) {
                $parDepartement[$p['departements'][0]][] = $p;
            } else {
                $flottantes[] = $p;
            }
        }
        if (count($parDepartement) <= 1) {
            $this->traiterSousGroupe($fusion, array_values($profils));

            return;
        }
        $gardes = [];
        foreach ($parDepartement as $sousGroupe) {
            $gardes[] = $this->traiterSousGroupe($fusion, $sousGroupe);
        }
        // Entre éditions : jamais un doublon.
        $n = count($gardes);
        $this->bilan['editions_distinctes'] += intdiv($n * ($n - 1), 2);
        foreach ($flottantes as $f) {
            foreach ($gardes as $garde) {
                if (DoublonsPresse::juger($garde, $f) === DoublonsPresse::EDITIONS) {
                    $this->bilan['editions_distinctes']++;

                    continue;
                }
                $this->deposer($garde, $f);
            }
        }
    }

    /**
     * Juge chaque fiche face à la fiche gardée du sous-groupe ; rend le profil
     * de la fiche gardée après les fusions.
     *
     * @param  non-empty-list<array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}>  $profils
     * @return array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int}
     */
    private function traiterSousGroupe(FusionFiches $fusion, array $profils): array
    {
        $candidates = array_values(array_filter($profils, static fn (array $p): bool => $p['siren'] === null && ! $p['manuelle'] && $p['presse']));
        $gardeId = DoublonsPresse::meilleure($candidates !== [] ? $candidates : $profils);
        $garde = null;
        foreach ($profils as $p) {
            if ($p['id'] === $gardeId) {
                $garde = $p;
            }
        }
        if ($garde === null) {
            throw new RuntimeException('fiche_gardee_introuvable');
        }

        foreach ($profils as $autre) {
            if ($autre['id'] === $garde['id']) {
                continue;
            }
            $verdict = DoublonsPresse::juger($garde, $autre);
            if ($verdict === DoublonsPresse::EDITIONS) {
                $this->bilan['editions_distinctes']++;

                continue;
            }
            if ($verdict === DoublonsPresse::A_VERIFIER) {
                $this->deposer($garde, $autre);

                continue;
            }
            $etat = $this->etatPaire($garde['id'], $autre['id']);
            if ($etat['ecartee']) {
                $this->bilan['ecartees_par_un_humain']++;

                continue;
            }
            if ($etat['fusion_annulee']) {
                // Défaite par un humain : jamais reprise seule.
                $this->bilan['fusions_annulees_non_reprises']++;
                $this->deposer($garde, $autre);

                continue;
            }
            try {
                $fusion->fusionner(
                    $this->ws,
                    $garde['id'],
                    $autre['id'],
                    Rapprochement::PRESSE_MEME_TITRE,
                    FusionFiches::MODE_AUTO,
                    $etat['flag'],
                    null,
                    $this->operateur,
                    $this->aBlanc,
                );
                $this->bilan['fusionnees']++;
                $garde = DoublonsPresse::apresAbsorption($garde, $autre);
            } catch (RefusFusion $r) {
                $this->bilan['fusions_refusees']++;
                $this->refus[$r->raison] = ($this->refus[$r->raison] ?? 0) + 1;
                $this->deposer($garde, $autre);
            }
        }

        return $garde;
    }

    /**
     * La paire dans la file « Doublons à vérifier » (motif `presse_homonyme`,
     * jamais `fusion_auto`) — sauf si elle y est déjà, dans un sens ou dans
     * l'autre, quel que soit son état. La fiche à SIREN est proposée comme
     * fiche à garder.
     *
     * @param  array{id: int, siren: ?string}  $a
     * @param  array{id: int, siren: ?string}  $b
     */
    private function deposer(array $a, array $b): void
    {
        [$garde, $autre] = $a['siren'] === null && $b['siren'] !== null ? [$b['id'], $a['id']] : [$a['id'], $b['id']];
        if ($this->flagExistant($garde, $autre) !== null) {
            $this->bilan['deja_en_file']++;

            return;
        }
        if (! $this->aBlanc) {
            DB::table('duplicate_flags')->insert([
                'workspace_id' => $this->ws,
                'entity_type' => 'company',
                'entity_a_id' => $garde,
                'entity_b_id' => $autre,
                'similarity' => Rapprochement::score(Rapprochement::PRESSE_HOMONYME),
                'motif' => Rapprochement::PRESSE_HOMONYME,
                'fusion_auto' => false,
                'detected_at' => now(),
            ]);
        }
        $this->bilan['deposees_a_verifier']++;
    }

    /**
     * @return array{ecartee: bool, fusion_annulee: bool, flag: ?int} la paire
     *                                                                écartée par un humain, une fusion déjà annulée, la paire en attente dans la file
     */
    private function etatPaire(int $a, int $b): array
    {
        $flag = $this->flagExistant($a, $b);
        $fusionAnnulee = DB::table('fusions_fiches')->where('workspace_id', $this->ws)
            ->where(static fn ($q) => $q
                ->where(static fn ($x) => $x->where('garde_id', $a)->where('absorbee_id', $b))
                ->orWhere(static fn ($x) => $x->where('garde_id', $b)->where('absorbee_id', $a)))
            ->exists();

        return [
            'ecartee' => $flag !== null && $flag->resolution === 'keep_both',
            'fusion_annulee' => $fusionAnnulee,
            'flag' => $flag !== null && $flag->reviewed_at === null ? (int) $flag->id : null,
        ];
    }

    private function flagExistant(int $a, int $b): ?stdClass
    {
        $flag = DB::table('duplicate_flags')->where('workspace_id', $this->ws)->where('entity_type', 'company')
            ->where(static fn ($q) => $q
                ->where(static fn ($x) => $x->where('entity_a_id', $a)->where('entity_b_id', $b))
                ->orWhere(static fn ($x) => $x->where('entity_a_id', $b)->where('entity_b_id', $a)))
            ->orderBy('id')->first(['id', 'reviewed_at', 'resolution']);

        return $flag instanceof stdClass ? $flag : null;
    }

    /** @return list<int> */
    private static function ids(string $tableau): array
    {
        return array_values(array_map('intval', array_filter(explode(',', trim($tableau, '{}')), static fn (string $v): bool => $v !== '')));
    }

    private function entier(string $nom): int|false|null
    {
        $v = $this->option($nom);
        if ($v === null || $v === '') {
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1) {
            return false;
        }

        return (int) $v;
    }

    private static function operateur(): string
    {
        $utilisateur = get_current_user();
        $hote = gethostname();

        return ($utilisateur !== '' ? $utilisateur : '?') . '@' . ($hote !== false ? $hote : '?');
    }
}
