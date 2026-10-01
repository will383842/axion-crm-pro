<?php

namespace App\Console\Commands;

use App\Crm\Presse\ClassementMedia;
use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\MediaIncertain;
use App\Crm\Presse\QualificationPresse;
use App\Crm\Presse\SiteMedia;
use App\Models\Company;
use App\Services\Tags\AutoTaggerService;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * CLASSER LES MÉDIAS PAR LA LECTURE DE LEUR PAGE D'ACCUEIL (chantier F,
 * décision de Will du 2026-10-01).
 *
 * ── QUI ──────────────────────────────────────────────────────────────────
 *
 * Toute fiche VIVANTE de l'espace business qui a au moins une ligne `media`
 * vivante ET qui est :
 *   - une fiche de presse (relation `presse_media`), ou
 *   - un MÉDIA INCERTAIN (`MediaIncertain` : NAF 63.12Z / 58.19Z venu du seul
 *     `naf-extract`, étiquette `media-possible:a-verifier`).
 * `--perimetre=presse|media-possible` restreint à l'un des deux. Les sociétés
 * de production et autres fiches `media` hors de ces deux cas ne sont pas
 * lues.
 *
 * ── COMMENT ──────────────────────────────────────────────────────────────
 *
 * La page lue est le site VÉRIFIÉ de la fiche, et lui seul (v5, 2026-10-01 :
 * les sites devinés depuis le nom étaient souvent faux) : l'URL de
 * `metadata.site_media` quand son statut est `verifie` ou `trouve-verifie`
 * (`SiteMedia::urlVerifiee`, posée par `crm:presse:verifier-sites` — le
 * premier `media.website` de la fiche, CHEMIN COMPRIS, à défaut
 * `companies.website`, ou un site trouvé). Un site non vérifié n'est PAS lu :
 * classement au nom seul, compté `sites_non_verifies`. Lancer
 * `crm:presse:verifier-sites` AVANT.
 * `LecturePageAccueil` la lit (robots.txt évalué pour ce chemin,
 * User-Agent identifiable, délai par domaine, délai d'attente court,
 * concurrence bornée, garde SSRF). `ClassementMedia` en tire thèmes, public,
 * format TV et, pour un média incertain, un verdict proposé. Sans site, ou si
 * robots.txt l'interdit, ou si le site ne répond pas : classement par le NOM
 * (fiche, médias, émission) et le thème éditorial de la source seulement ;
 * page trop grosse, non HTML ou d'encodage inconnu : idem, marquée
 * `illisible` (sautée à la relance) —
 * `inconnu` sans signal net. `--sans-reseau` : aucun appel, nom seulement.
 * Une même page n'est lue qu'UNE fois par exécution (une chaîne et ses
 * émissions partagent souvent un site).
 *
 * ── CE QUI EST ÉCRIT, ET RIEN D'AUTRE ────────────────────────────────────
 *
 *   - `companies.metadata.classement_media` (valeurs retenues, scores
 *     numériques, mode de lecture, version des règles, date) — aucun texte de
 *     la page, aucun nom, aucune adresse ; `updated_at` n'est pas touché ;
 *   - les étiquettes DÉRIVÉES par la synchro automatique ordinaire
 *     (`AutoTaggerService::syncTags`) : `media-sujet:`, `media-public:`,
 *     `media-format:`, `media-possible:semble-media|semble-pas-media`.
 * JAMAIS la relation, la nature, la protection (`src:`, étiquettes
 * verrouillées), ni aucune suppression de fiche ou de contact. Une étiquette
 * posée à la main dans un de ces namespaces gagne (`ClassementMedia`).
 *
 * ── PRUDENCE ─────────────────────────────────────────────────────────────
 *
 * ESSAI À BLANC PAR DÉFAUT : les sites sont lus et classés, chaque paquet est
 * ANNULÉ — les compteurs sont ceux du réel, rien n'est écrit. `--appliquer`
 * écrit. Par paquets (`--paquet`, 40), reprenable (`--depuis-id`), borné
 * (`--limite`). IDEMPOTENTE : une fiche déjà classée par la version courante
 * des règles est sautée ; avec `--reclasser`, une fiche dont le classement ne
 * change pas n'est pas réécrite. Un classement lu sur le site n'est jamais
 * remplacé par un classement « au nom seul » de la même version.
 *
 * ── JOURNAL ──────────────────────────────────────────────────────────────
 *
 * Des COMPTEURS seulement (le dépôt et les journaux de workflow sont
 * publics) : ni nom, ni site, ni texte.
 */
class CrmPresseClasserMedias extends Command
{
    protected $signature = 'crm:presse:classer-medias
                            {--appliquer : Écrire (sans cette option : essai à blanc, rien n\'est écrit)}
                            {--dry-run : Essai à blanc explicite (c\'est déjà le défaut)}
                            {--depuis-id= : Reprendre à la fiche d\'identifiant N (incluse)}
                            {--limite= : Ne traiter que les N premières fiches}
                            {--paquet=40 : Fiches lues et validées par transaction}
                            {--perimetre=tout : tout | presse | media-possible}
                            {--concurrence=4 : Requêtes HTTP simultanées au plus (1 à 8)}
                            {--delai-domaine-ms=1000 : Attente minimale entre robots.txt et la page d\'un même domaine}
                            {--timeout=6 : Délai d\'attente d\'une requête, en secondes (1 à 15)}
                            {--sans-reseau : Aucun appel réseau : classement par le nom seulement}
                            {--reclasser : Relire aussi les fiches déjà classées par la version courante}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Classe les médias (thème, public, format TV, verdict « média possible ») par la lecture de leur page d\'accueil.';

    public const PERIMETRES = ['tout', 'presse', 'media-possible'];

    /** @var array<string, int> */
    private array $bilan = [];

    private string $workspaceId = '';

    /** @var array<string, array{statut: string, zones: array<string, string>, structure: array{articles: int, dates: int}, code?: int, finale?: string}> */
    private array $cacheHotes = [];

    private const CACHE_MAX = 5000;

    public function handle(AutoTaggerService $tagger): int
    {
        $discret = (bool) $this->option('compteurs-seulement');
        $appliquer = (bool) $this->option('appliquer');
        if ($appliquer && (bool) $this->option('dry-run')) {
            $this->error('--appliquer et --dry-run sont incompatibles.');

            return self::FAILURE;
        }
        $limite = $this->entierOption('limite');
        $depuis = $this->entierOption('depuis-id');
        $paquet = $this->entierOption('paquet');
        $concurrence = $this->entierOption('concurrence');
        $delai = $this->entierOption('delai-domaine-ms', true);
        $timeout = $this->entierOption('timeout');
        if ($limite === false || $depuis === false || $paquet === false || $paquet === null
            || $concurrence === false || $concurrence === null || $concurrence > 8
            || $delai === false || $delai === null || $timeout === false || $timeout === null || $timeout > 15) {
            $this->error('Options numériques invalides (--concurrence 1 à 8, --timeout 1 à 15, entiers positifs).');

            return self::FAILURE;
        }
        $perimetre = (string) $this->option('perimetre');
        if (! in_array($perimetre, self::PERIMETRES, true)) {
            $this->error('--perimetre : tout, presse ou media-possible.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error('Espace business introuvable.');

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;

        // Le conteneur peut fournir le lecteur (tests : attente observée).
        $lecteur = app()->bound(LecturePageAccueil::class)
            ? app(LecturePageAccueil::class)
            : new LecturePageAccueil($concurrence, $timeout, $delai);
        $sansReseau = (bool) $this->option('sans-reseau');
        $reclasser = (bool) $this->option('reclasser');

        $this->cacheHotes = [];
        $this->bilan = array_fill_keys([
            'fiches_lues', 'paquets', 'sites_lus', 'sans_site', 'sites_non_verifies', 'robots_interdits', 'sites_injoignables',
            'sites_illisibles', 'pages_deja_lues', 'classements_ecrits', 'classements_inchanges', 'lus_sur_site_gardes',
            'etiquettes_media_ajoutees', 'etiquettes_media_retirees',
        ], 0);

        $dernier = null;
        $interruption = null;
        try {
            WorkspaceContext::run($this->workspaceId, function () use ($depuis, $limite, $paquet, $appliquer, $perimetre, $reclasser, $sansReseau, $lecteur, $tagger, &$dernier): void {
                $curseur = ($depuis ?? 1) - 1;
                while (true) {
                    $restant = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['fiches_lues']);
                    if ($restant <= 0) {
                        break;
                    }
                    $fiches = $this->candidates($curseur, $restant, $perimetre, $reclasser);
                    if ($fiches === []) {
                        break;
                    }
                    $this->traiterPaquet($fiches, $appliquer, $sansReseau, $lecteur, $tagger);
                    $curseur = (int) $fiches[count($fiches) - 1]->id;
                    $dernier = $curseur;
                }
            });
        } catch (Throwable $e) {
            $interruption = $e;
        }

        if ($interruption !== null) {
            $this->error("INTERROMPU après {$this->bilan['paquets']} paquet(s)"
                . ($appliquer ? ' validé(s) : ils restent en base (idempotente).' : ' (à blanc : rien n\'a été écrit).')
                . ($dernier === null || $discret ? '' : ' Reprendre avec --depuis-id=' . ($dernier + 1) . '.'));
            if ($discret) {
                Log::error('crm:presse:classer-medias interrompu', ['exception' => $interruption]);

                throw new RuntimeException('crm:presse:classer-medias interrompu (détail masqué : --compteurs-seulement, voir le journal du serveur).');
            }

            throw $interruption;
        }

        Log::info('crm.presse.classer_medias', $this->bilan + ['appliquer' => $appliquer]);
        $this->info($appliquer ? 'Classement appliqué.' : '[À BLANC] rien n\'a été écrit (--appliquer pour écrire).');
        ksort($this->bilan);
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->bilan),
            array_values($this->bilan),
        ));
        if (! $discret && $dernier !== null) {
            $this->line("Dernière fiche traitée : {$dernier} (reprendre avec --depuis-id=" . ($dernier + 1) . ').');
        }

        return self::SUCCESS;
    }

    /**
     * Les fiches suivantes du périmètre, avec ce qu'il faut pour les classer.
     *
     * @return list<stdClass>
     */
    private function candidates(int $curseur, int $combien, string $perimetre, bool $reclasser): array
    {
        $incertain = MediaIncertain::conditionSql('c.id', 'c');
        $presse = 'c.relation_type = ?';
        $condition = match ($perimetre) {
            'presse' => $presse,
            'media-possible' => $incertain,
            default => "({$presse} OR {$incertain})",
        };
        $liaisons = [$this->workspaceId, $curseur];
        if ($perimetre !== 'media-possible') {
            $liaisons[] = QualificationPresse::RELATION;
        }
        // Déjà classée par la version courante des règles : sautée (idempotence).
        $v = "(c.metadata -> '" . ClassementMedia::CLE . "' ->> 'v')";
        $dejaClasse = $reclasser ? ''
            : " AND COALESCE(CASE WHEN {$v} ~ '^[0-9]+$' THEN {$v}::int END, 0) < " . ClassementMedia::VERSION;
        $liaisons[] = $combien;

        $fiches = DB::select(
            "SELECT c.id, c.denomination, c.website, c.metadata -> '" . ClassementMedia::CLE . "' AS classement,
                    c.metadata -> '" . SiteMedia::CLE . "' AS site_media,
                    ({$incertain}) AS incertain
               FROM companies c
              WHERE c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.id > ?
                AND EXISTS (SELECT 1 FROM media m WHERE m.company_id = c.id AND m.deleted_at IS NULL)
                AND {$condition}{$dejaClasse}
              ORDER BY c.id
              LIMIT ?",
            $liaisons,
        );
        if ($fiches === []) {
            return [];
        }

        $medias = [];
        foreach (DB::table('media')->whereIn('company_id', array_map(static fn (stdClass $f): int => (int) $f->id, $fiches))
            ->whereNull('deleted_at')->orderBy('id')
            ->get(['company_id', 'name', 'media_type', 'website', 'editorial_theme', 'diffusion_zone']) as $m) {
            $medias[(int) $m->company_id][] = $m;
        }
        foreach ($fiches as $f) {
            $f->medias = $medias[(int) $f->id] ?? [];
        }

        return array_values($fiches);
    }

    /** @param  list<stdClass>  $fiches */
    private function traiterPaquet(array $fiches, bool $appliquer, bool $sansReseau, LecturePageAccueil $lecteur, AutoTaggerService $tagger): void
    {
        // 1. Lire les sites (HORS transaction : aucun verrou tenu pendant le réseau).
        $bases = [];
        foreach ($fiches as $f) {
            // Le site VÉRIFIÉ seulement (`SiteMedia`) : un site deviné depuis
            // le nom (paris.fr pour « PARIS LIVE ») n'est jamais lu.
            $cible = SiteMedia::urlVerifiee($f->site_media);
            if ($cible === null) {
                $brut = LecturePageAccueil::cible(is_string($f->website) ? $f->website : null);
                foreach ($f->medias as $m) {
                    $brut ??= LecturePageAccueil::cible(is_string($m->website) ? $m->website : null);
                }
                $f->nonVerifie = $brut !== null;
            }
            $f->base = $cible;
            if ($cible !== null && ! $sansReseau) {
                if (isset($this->cacheHotes[$cible]) || isset($bases[$cible])) {
                    $this->bilan['pages_deja_lues']++;
                } else {
                    $bases[$cible] = true;
                }
            }
        }
        if ($bases !== []) {
            if (count($this->cacheHotes) > self::CACHE_MAX) {
                $this->cacheHotes = [];
            }
            $this->cacheHotes = $lecteur->lire(array_keys($bases)) + $this->cacheHotes;
        }

        // 2. Classer, puis écrire dans UNE transaction par paquet.
        $delta = [];
        DB::beginTransaction();
        try {
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            foreach ($fiches as $f) {
                $this->compter($delta, 'fiches_lues');
                $this->classerFiche($f, $sansReseau, $tagger, $delta);
            }
            if ($appliquer) {
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
        foreach ($delta as $cle => $n) {
            $this->bilan[$cle] = ($this->bilan[$cle] ?? 0) + $n;
        }
        $this->bilan['paquets']++;
    }

    /** @param  array<string, int>  $delta */
    private function classerFiche(stdClass $f, bool $sansReseau, AutoTaggerService $tagger, array &$delta): void
    {
        $lecture = ClassementMedia::LECTURE_NOM;
        $zones = [];
        $structure = ['articles' => 0, 'dates' => 0];
        if ($f->base === null) {
            $this->compter($delta, ($f->nonVerifie ?? false) ? 'sites_non_verifies' : 'sans_site');
        } elseif (! $sansReseau) {
            $lu = $this->cacheHotes[$f->base] ?? null;
            $statut = $lu['statut'] ?? LecturePageAccueil::STATUT_INJOIGNABLE;
            if ($statut === LecturePageAccueil::STATUT_LU && $lu !== null) {
                $lecture = ClassementMedia::LECTURE_SITE;
                $zones = $lu['zones'];
                $structure = $lu['structure'];
                $this->compter($delta, 'sites_lus');
            } elseif ($statut === LecturePageAccueil::STATUT_ROBOTS) {
                $lecture = ClassementMedia::LECTURE_ROBOTS;
                $this->compter($delta, 'robots_interdits');
            } elseif ($statut === LecturePageAccueil::STATUT_ILLISIBLE) {
                // Trop grosse, type refusé, encodage inconnu : classée par son
                // nom ET marquée — sautée à la relance, jamais relue en boucle.
                $lecture = ClassementMedia::LECTURE_ILLISIBLE;
                $this->compter($delta, 'sites_illisibles');
            } else {
                $lecture = ClassementMedia::LECTURE_INJOIGNABLE;
                $this->compter($delta, 'sites_injoignables');
            }
        }

        $noms = [is_string($f->denomination) ? $f->denomination : ''];
        $types = [];
        $zonesDiffusion = [];
        foreach ($f->medias as $m) {
            $noms[] = (string) $m->name;
            if (is_string($m->editorial_theme) && $m->editorial_theme !== '') {
                $noms[] = $m->editorial_theme;
            }
            $types[] = (string) $m->media_type;
            if (is_string($m->diffusion_zone)) {
                $zonesDiffusion[] = $m->diffusion_zone;
            }
        }
        $zones['nom'] = implode(' . ', $noms);

        $classement = ClassementMedia::classer($zones, $structure, $types, $zonesDiffusion, (bool) $f->incertain, $lecture);

        $avant = is_string($f->classement) ? json_decode($f->classement, true) : null;
        if (is_array($avant) && ($avant['lecture'] ?? null) === ClassementMedia::LECTURE_SITE
            && ($avant['v'] ?? null) === ClassementMedia::VERSION && $lecture !== ClassementMedia::LECTURE_SITE) {
            $this->compter($delta, 'lus_sur_site_gardes');

            return;
        }
        foreach ($classement['themes'] as $t) {
            $this->compter($delta, 'theme:' . $t);
        }
        foreach ($classement['secteurs'] as $s) {
            $this->compter($delta, 'secteur:' . $s);
        }
        foreach ($classement['publics'] as $p) {
            $this->compter($delta, 'public:' . $p);
        }
        if ($classement['format'] !== null) {
            $this->compter($delta, 'format:' . $classement['format']);
        }
        if ($classement['verdict'] !== null) {
            $this->compter($delta, 'verdict:' . $classement['verdict']);
        }

        if (ClassementMedia::memeClassement($classement, $avant)) {
            $this->compter($delta, 'classements_inchanges');

            return;
        }

        $valeur = $classement + ['le' => now()->toDateString()];
        DB::update(
            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, ?::jsonb)
              WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL",
            [ClassementMedia::CLE, json_encode($valeur, JSON_THROW_ON_ERROR), (int) $f->id, $this->workspaceId],
        );
        $this->compter($delta, 'classements_ecrits');

        $company = Company::query()->find((int) $f->id);
        if ($company === null) {
            return;
        }
        $resultat = $tagger->syncTags($company);
        foreach (['added' => 'etiquettes_media_ajoutees', 'removed' => 'etiquettes_media_retirees'] as $sens => $cle) {
            foreach ($resultat[$sens] as $slug) {
                if (str_starts_with($slug, 'media-')) {
                    $this->compter($delta, $cle);
                }
            }
        }
    }

    /** @param  array<string, int>  $delta */
    private function compter(array &$delta, string $cle, int $n = 1): void
    {
        $delta[$cle] = ($delta[$cle] ?? 0) + $n;
    }

    /** Un entier strictement positif (ou nul si `$zeroPermis`), null si absent, false si invalide. */
    private function entierOption(string $nom, bool $zeroPermis = false): int|false|null
    {
        $valeur = $this->option($nom);
        if (is_int($valeur)) {
            $valeur = (string) $valeur;
        }
        if ($valeur === null || $valeur === '') {
            return null;
        }
        if (! is_string($valeur) || preg_match('/^\d+$/', $valeur) !== 1) {
            return false;
        }
        $n = (int) $valeur;

        return $n > 0 || ($zeroPermis && $n === 0) ? $n : false;
    }
}
