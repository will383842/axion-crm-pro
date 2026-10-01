<?php

namespace App\Console\Commands;

use App\Crm\Presse\LecturePageAccueil;
use App\Crm\Presse\MediaIncertain;
use App\Crm\Presse\QualificationPresse;
use App\Crm\Presse\SiteMedia;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * VÉRIFIER — ET TROUVER — LE SITE DES MÉDIAS (constat en production du
 * 2026-10-01 : sites DEVINÉS depuis le nom, souvent faux ; voir `SiteMedia`).
 *
 * ── QUI ──────────────────────────────────────────────────────────────────
 *
 * Le périmètre de `crm:presse:classer-medias` : toute fiche VIVANTE de
 * l'espace business qui a au moins une ligne `media` vivante ET qui est une
 * fiche de presse (`presse_media`) ou un média incertain (`MediaIncertain`).
 * `--perimetre=presse|media-possible` restreint.
 *
 * ── COMMENT ──────────────────────────────────────────────────────────────
 *
 *   1. VÉRIFIER le site existant (premier `media.website`, chemin compris, à
 *      défaut `companies.website`) : domaine générique (liste noire) ou
 *      domaine partagé par plus de `--partage-max` fiches sans
 *      correspondance → `non-conforme` d'office, sans lecture ; sinon la page
 *      est lue par `LecturePageAccueil` (mêmes protections que le
 *      classement : robots.txt, SSRF, délai par domaine, flux borné) et
 *      `SiteMedia::juger()` tranche (réponse 2xx, arrivée sur le même
 *      domaine hors parkeur, pas de page de parking, nom, indice de média
 *      pour un nom à un seul mot) : `verifie`, `a-confirmer` (non fiable)
 *      ou `non-conforme`.
 *      Illisible, injoignable, robots.txt qui interdit : marqué tel quel, et
 *      le site n'est PAS vérifié.
 *   2. TROUVER, pour une fiche sans site ou au site non conforme (sauf
 *      `--sans-recherche`), SANS service payant : d'abord le site d'un
 *      HOMONYME de source ouverte déjà intégrée (ligne `media` du même nom,
 *      source autre que `naf-extract`, site non deviné : wikidata, ARCOM,
 *      CPPAP, listes presse…), puis des adresses tirées du nom
 *      (`SiteMedia::candidats`). `--candidats` au plus (4 ; 6 au plus) ;
 *      jamais un domaine générique, ni un domaine déjà site d'une AUTRE
 *      fiche. Chaque candidat passe la MÊME vérification ; le premier qui
 *      passe est retenu : `trouve-verifie`.
 *
 * ── CE QUI EST ÉCRIT, ET RIEN D'AUTRE ────────────────────────────────────
 *
 *   - `companies.metadata.site_media` = {statut, url, motif?, le, v}
 *     (`SiteMedia`) — aucun texte de la page ; `updated_at` n'est pas touché ;
 *   - un site TROUVÉ est écrit dans `media.website` des lignes vivantes de la
 *     fiche SEULEMENT SI ELLES N'EN ONT PAS (`website_status = found`,
 *     `website_method = nom-verifie`). Une valeur existante n'est JAMAIS
 *     écrasée ni effacée, même fausse : elle est marquée, pas supprimée.
 * JAMAIS `companies.website`, ni la relation, ni la nature, ni aucune
 * suppression de fiche ou de contact.
 *
 * ── PRUDENCE ─────────────────────────────────────────────────────────────
 *
 * ESSAI À BLANC PAR DÉFAUT : les sites sont lus, chaque paquet est ANNULÉ.
 * `--appliquer` écrit. Par paquets (`--paquet`, 40), reprenable
 * (`--depuis-id`), borné (`--limite`). IDEMPOTENTE : une fiche déjà vérifiée
 * par la version courante des règles est sautée (sauf `--reverifier`).
 * Journal : des COMPTEURS seulement (dépôt et journaux publics).
 */
class CrmPresseVerifierSites extends Command
{
    protected $signature = 'crm:presse:verifier-sites
                            {--appliquer : Écrire (sans cette option : essai à blanc, rien n\'est écrit)}
                            {--dry-run : Essai à blanc explicite (c\'est déjà le défaut)}
                            {--depuis-id= : Reprendre à la fiche d\'identifiant N (incluse)}
                            {--limite= : Ne traiter que les N premières fiches}
                            {--paquet=40 : Fiches traitées par transaction}
                            {--perimetre=tout : tout | presse | media-possible}
                            {--concurrence=4 : Requêtes HTTP simultanées au plus (1 à 8)}
                            {--delai-domaine-ms=1000 : Attente minimale entre deux requêtes d\'un même domaine}
                            {--timeout=6 : Délai d\'attente d\'une requête, en secondes (1 à 15)}
                            {--candidats=4 : Adresses candidates essayées au plus par fiche (1 à 6)}
                            {--partage-max=3 : Au-delà de N fiches, un domaine est « partagé »}
                            {--sans-recherche : Vérifier seulement, ne rien chercher}
                            {--reverifier : Revérifier aussi les fiches déjà vérifiées par la version courante}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Vérifie que le site d\'un média porte son nom, et en cherche un (sans service payant) quand il n\'en a pas ou un faux.';

    public const PERIMETRES = ['tout', 'presse', 'media-possible'];

    private const CACHE_MAX = 5000;

    /** @var array<string, int> */
    private array $bilan = [];

    private string $workspaceId = '';

    /**
     * Hôte → [nombre de fiches dont c'est le site, une de ces fiches].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    private array $hotes = [];

    /** @var array<string, array{statut: string, zones: array<string, string>, structure: array{articles: int, dates: int}, code?: int, finale?: string}> */
    private array $cache = [];

    public function handle(): int
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
        $candidats = $this->entierOption('candidats');
        $partageMax = $this->entierOption('partage-max');
        if ($limite === false || $depuis === false || $paquet === false || $paquet === null
            || $concurrence === false || $concurrence === null || $concurrence > 8
            || $delai === false || $delai === null || $timeout === false || $timeout === null || $timeout > 15
            || $candidats === false || $candidats === null || $candidats > 6 || $partageMax === false || $partageMax === null) {
            $this->error('Options numériques invalides (--concurrence 1 à 8, --timeout 1 à 15, --candidats 1 à 6, entiers positifs).');

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

        $lecteur = app()->bound(LecturePageAccueil::class)
            ? app(LecturePageAccueil::class)
            : new LecturePageAccueil($concurrence, $timeout, $delai);
        $reverifier = (bool) $this->option('reverifier');
        $rechercher = ! (bool) $this->option('sans-recherche');

        $this->cache = [];
        $this->bilan = array_fill_keys([
            'fiches_lues', 'paquets', 'sites_existants', 'sites_verifies', 'a_confirmer', 'non_conformes_nom', 'non_conformes_partage', 'non_conformes_redirection', 'non_conformes_parking',
            'non_conformes_liste_noire', 'non_conformes_sans_mot', 'sites_injoignables', 'robots_interdits',
            'sites_illisibles', 'sans_site', 'recherches', 'candidats_essayes', 'sites_trouves',
            'medias_site_ecrit', 'marqueurs_ecrits', 'marqueurs_inchanges',
        ], 0);

        $dernier = null;
        $interruption = null;
        try {
            WorkspaceContext::run($this->workspaceId, function () use ($depuis, $limite, $paquet, $appliquer, $perimetre, $reverifier, $rechercher, $candidats, $partageMax, $lecteur, &$dernier): void {
                $this->hotes = $this->carteDesHotes();
                $curseur = ($depuis ?? 1) - 1;
                while (true) {
                    $restant = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['fiches_lues']);
                    if ($restant <= 0) {
                        break;
                    }
                    $fiches = $this->selection($curseur, $restant, $perimetre, $reverifier);
                    if ($fiches === []) {
                        break;
                    }
                    $this->traiterPaquet($fiches, $appliquer, $rechercher, $candidats, $partageMax, $lecteur);
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
                Log::error('crm:presse:verifier-sites interrompu', ['exception' => $interruption]);

                throw new RuntimeException('crm:presse:verifier-sites interrompu (détail masqué : --compteurs-seulement, voir le journal du serveur).');
            }

            throw $interruption;
        }

        Log::info('crm.presse.verifier_sites', $this->bilan + ['appliquer' => $appliquer]);
        $this->info($appliquer ? 'Vérification appliquée.' : '[À BLANC] rien n\'a été écrit (--appliquer pour écrire).');
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
     * Pour chaque hôte, le nombre de fiches (ayant une ligne `media` vivante)
     * dont c'est le site — par `media.website` ou `companies.website`.
     *
     * @return array<string, array{0: int, 1: int}>
     */
    private function carteDesHotes(): array
    {
        $hote = <<<'SQL'
            lower(regexp_replace(btrim(s.w), '^([a-z][a-z0-9+.-]*://)?(www\.)?([^/:?#]+).*$', '\3', 'i'))
            SQL;
        $carte = [];
        foreach (DB::select(
            "SELECT {$hote} AS hote, count(DISTINCT s.cid) AS n, min(s.cid) AS premier
               FROM (
                    SELECT m.company_id AS cid, m.website AS w
                      FROM media m JOIN companies c ON c.id = m.company_id
                     WHERE m.workspace_id = ? AND m.deleted_at IS NULL AND c.deleted_at IS NULL
                       AND m.website IS NOT NULL AND btrim(m.website) <> ''
                    UNION ALL
                    SELECT c.id, c.website
                      FROM companies c
                     WHERE c.workspace_id = ? AND c.deleted_at IS NULL
                       AND c.website IS NOT NULL AND btrim(c.website) <> ''
                       AND EXISTS (SELECT 1 FROM media m2 WHERE m2.company_id = c.id AND m2.deleted_at IS NULL)
               ) s
              GROUP BY 1",
            [$this->workspaceId, $this->workspaceId],
        ) as $l) {
            $carte[(string) $l->hote] = [(int) $l->n, (int) $l->premier];
        }

        return $carte;
    }

    /** @return list<stdClass> */
    private function selection(int $curseur, int $combien, string $perimetre, bool $reverifier): array
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
        $v = "(c.metadata -> '" . SiteMedia::CLE . "' ->> 'v')";
        $deja = $reverifier ? ''
            : " AND COALESCE(CASE WHEN {$v} ~ '^[0-9]+$' THEN {$v}::int END, 0) < " . SiteMedia::VERSION;
        $liaisons[] = $combien;

        $fiches = DB::select(
            "SELECT c.id, c.denomination, c.website, c.metadata -> '" . SiteMedia::CLE . "' AS marqueur
               FROM companies c
              WHERE c.workspace_id = ?
                AND c.deleted_at IS NULL
                AND c.id > ?
                AND EXISTS (SELECT 1 FROM media m WHERE m.company_id = c.id AND m.deleted_at IS NULL)
                AND {$condition}{$deja}
              ORDER BY c.id
              LIMIT ?",
            $liaisons,
        );
        if ($fiches === []) {
            return [];
        }

        $medias = [];
        foreach (DB::table('media')->whereIn('company_id', array_map(static fn (stdClass $f): int => (int) $f->id, $fiches))
            ->whereNull('deleted_at')->orderBy('id')->get(['company_id', 'name', 'website']) as $m) {
            $medias[(int) $m->company_id][] = $m;
        }
        foreach ($fiches as $f) {
            $f->medias = $medias[(int) $f->id] ?? [];
            $noms = [];
            foreach (array_merge([$f->denomination], array_map(static fn (stdClass $m): mixed => $m->name, $f->medias)) as $nom) {
                if (is_string($nom) && trim($nom) !== '') {
                    $noms[trim($nom)] = true;
                }
            }
            $f->noms = array_keys($noms);
        }

        return array_values($fiches);
    }

    /** @param  list<stdClass>  $fiches */
    private function traiterPaquet(array $fiches, bool $appliquer, bool $rechercher, int $maxCandidats, int $partageMax, LecturePageAccueil $lecteur): void
    {
        $delta = [];

        // 1. VÉRIFIER le site existant (réseau HORS transaction).
        $aLire = [];
        foreach ($fiches as $f) {
            $this->compter($delta, 'fiches_lues');
            $f->decision = null;
            $f->existante = null;
            foreach ($f->medias as $m) {
                $f->existante ??= LecturePageAccueil::cible(is_string($m->website) ? $m->website : null);
            }
            $f->existante ??= LecturePageAccueil::cible(is_string($f->website) ? $f->website : null);
            if ($f->existante === null) {
                continue;
            }
            $this->compter($delta, 'sites_existants');
            $hote = (string) SiteMedia::hote($f->existante);
            if (SiteMedia::estGenerique($hote)) {
                $f->decision = [SiteMedia::NON_CONFORME, $f->existante, SiteMedia::MOTIF_LISTE_NOIRE];
            } elseif (($this->hotes[$hote][0] ?? 0) > $partageMax && ! SiteMedia::aUnChemin($f->existante)
                && SiteMedia::partageSansCorrespondance($f->noms, $hote)) {
                $f->decision = [SiteMedia::NON_CONFORME, $f->existante, SiteMedia::MOTIF_PARTAGE];
            } else {
                $aLire[] = $f->existante;
            }
        }
        $this->lire($aLire, $lecteur);
        foreach ($fiches as $f) {
            if ($f->existante === null || $f->decision !== null) {
                continue;
            }
            $f->decision = $this->juger($f->noms, $f->existante);
        }

        // 2. TROUVER un site aux fiches sans site ou au site non conforme.
        if ($rechercher) {
            $homonymes = $this->homonymes($fiches);
            $aLire = [];
            foreach ($fiches as $f) {
                $f->candidats = [];
                if ($f->existante !== null && ($f->decision[0] ?? null) !== SiteMedia::NON_CONFORME) {
                    continue;
                }
                $this->compter($delta, 'recherches');
                $exclu = $f->existante !== null ? SiteMedia::hote($f->existante) : null;
                $vus = [];
                $sources = [];
                foreach ($f->noms as $nom) {
                    foreach ($homonymes[mb_strtolower($nom)] ?? [] as $url) {
                        $sources[] = $url;
                    }
                }
                foreach (array_merge($sources, SiteMedia::candidats($f->noms, $maxCandidats)) as $url) {
                    $cible = LecturePageAccueil::cible($url);
                    $hote = $cible === null ? null : SiteMedia::hote($cible);
                    if ($cible === null || $hote === null || isset($vus[$hote]) || $hote === $exclu
                        || SiteMedia::estGenerique($hote) || $this->prisParUneAutre($hote, (int) $f->id)) {
                        continue;
                    }
                    $vus[$hote] = true;
                    $f->candidats[] = $cible;
                    $aLire[] = $cible;
                    if (count($f->candidats) >= $maxCandidats) {
                        break;
                    }
                }
            }
            $this->lire($aLire, $lecteur);
            foreach ($fiches as $f) {
                foreach ($f->candidats as $cible) {
                    $this->compter($delta, 'candidats_essayes');
                    $hote = (string) SiteMedia::hote($cible);
                    if ($this->prisParUneAutre($hote, (int) $f->id)) {
                        continue;
                    }
                    if ($this->juger($f->noms, $cible)[0] === SiteMedia::VERIFIE) {
                        $f->decision = [SiteMedia::TROUVE_VERIFIE, $cible, null];
                        // Réservé : une autre fiche ne peut plus le prendre.
                        $this->hotes[$hote] = [($this->hotes[$hote][0] ?? 0) + 1, (int) $f->id];
                        $this->compter($delta, 'sites_trouves');
                        break;
                    }
                }
            }
        }

        // 3. Écrire, dans UNE transaction par paquet.
        DB::beginTransaction();
        try {
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            foreach ($fiches as $f) {
                $this->ecrire($f, $delta);
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

    /**
     * Le jugement d'une adresse déjà lue : [statut, url, motif].
     *
     * @param  list<string>  $noms
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function juger(array $noms, string $cible): array
    {
        $lu = $this->cache[$cible] ?? null;
        $statut = $lu['statut'] ?? LecturePageAccueil::STATUT_INJOIGNABLE;

        if ($statut === LecturePageAccueil::STATUT_LU && $lu !== null) {
            // Code 2xx, arrivée sur le même domaine, pas de parking, nom,
            // indice de média : une seule définition (`SiteMedia::juger`).
            return SiteMedia::juger($noms, $cible, $lu);
        }

        return match ($statut) {
            LecturePageAccueil::STATUT_ROBOTS => [SiteMedia::ROBOTS_INTERDIT, $cible, null],
            LecturePageAccueil::STATUT_ILLISIBLE => [SiteMedia::ILLISIBLE, $cible, null],
            default => [SiteMedia::INJOIGNABLE, $cible, null],
        };
    }

    /** @param  list<string>  $cibles */
    private function lire(array $cibles, LecturePageAccueil $lecteur): void
    {
        $nouvelles = array_values(array_filter(array_unique($cibles), fn (string $c): bool => ! isset($this->cache[$c])));
        if ($nouvelles === []) {
            return;
        }
        if (count($this->cache) > self::CACHE_MAX) {
            $this->cache = [];
        }
        $this->cache = $lecteur->lire($nouvelles) + $this->cache;
    }

    /**
     * Les sites des HOMONYMES de sources ouvertes déjà intégrées : lignes
     * `media` vivantes du même nom (casse ignorée), source autre que
     * `naf-extract`, site non deviné.
     *
     * @param  list<stdClass>  $fiches
     * @return array<string, list<string>> nom en minuscules → sites
     */
    private function homonymes(array $fiches): array
    {
        $noms = [];
        foreach ($fiches as $f) {
            if ($f->existante === null || ($f->decision[0] ?? null) === SiteMedia::NON_CONFORME) {
                foreach ($f->noms as $nom) {
                    $noms[mb_strtolower($nom)] = true;
                }
            }
        }
        if ($noms === []) {
            return [];
        }
        $sortie = [];
        foreach (DB::table('media')
            ->where('workspace_id', $this->workspaceId)
            ->whereNull('deleted_at')
            ->where('source', '<>', MediaIncertain::SOURCE_NAF)
            ->whereNotNull('website')
            ->where(static fn ($q) => $q->whereNull('website_method')->orWhereNotIn('website_method', ['guess', 'guess2']))
            ->whereIn(DB::raw('lower(btrim(name))'), array_keys($noms))
            ->orderBy('id')
            ->limit(500)
            ->get(['name', 'website']) as $m) {
            $sortie[mb_strtolower(trim((string) $m->name))][] = (string) $m->website;
        }

        return $sortie;
    }

    /** Cet hôte est-il déjà le site d'une AUTRE fiche ? */
    private function prisParUneAutre(string $hote, int $companyId): bool
    {
        $connu = $this->hotes[$hote] ?? null;

        return $connu !== null && ($connu[0] > 1 || $connu[1] !== $companyId);
    }

    /** @param  array<string, int>  $delta */
    private function ecrire(stdClass $f, array &$delta): void
    {
        [$statut, $url, $motif] = $f->decision ?? [SiteMedia::SANS_SITE, null, null];
        $compteur = match ($statut) {
            SiteMedia::VERIFIE => 'sites_verifies',
            SiteMedia::A_CONFIRMER => 'a_confirmer',
            SiteMedia::NON_CONFORME => 'non_conformes_' . match ($motif) {
                SiteMedia::MOTIF_PARTAGE => 'partage',
                SiteMedia::MOTIF_LISTE_NOIRE => 'liste_noire',
                SiteMedia::MOTIF_SANS_MOT => 'sans_mot',
                SiteMedia::MOTIF_REDIRECTION => 'redirection',
                SiteMedia::MOTIF_PARKING => 'parking',
                default => 'nom',
            },
            SiteMedia::INJOIGNABLE => 'sites_injoignables',
            SiteMedia::ROBOTS_INTERDIT => 'robots_interdits',
            SiteMedia::ILLISIBLE => 'sites_illisibles',
            SiteMedia::SANS_SITE => 'sans_site',
            default => null,
        };
        if ($compteur !== null) {
            $this->compter($delta, $compteur);
        }

        if ($statut === SiteMedia::TROUVE_VERIFIE) {
            // Seulement les lignes SANS site : jamais d'écrasement.
            $n = DB::update(
                "UPDATE media SET website = ?, website_status = 'found', website_method = ?, website_checked_at = now()
                  WHERE company_id = ? AND workspace_id = ? AND deleted_at IS NULL
                    AND (website IS NULL OR btrim(website) = '')",
                [$url, SiteMedia::METHODE, (int) $f->id, $this->workspaceId],
            );
            $this->compter($delta, 'medias_site_ecrit', $n);
        }

        $valeur = ['statut' => $statut, 'url' => $url] + ($motif !== null ? ['motif' => $motif] : []) + ['v' => SiteMedia::VERSION];
        $avant = is_string($f->marqueur) ? json_decode($f->marqueur, true) : null;
        if (is_array($avant) && ($avant['statut'] ?? null) === $statut && ($avant['url'] ?? null) === $url
            && ($avant['motif'] ?? null) === $motif && ($avant['v'] ?? null) === SiteMedia::VERSION) {
            $this->compter($delta, 'marqueurs_inchanges');

            return;
        }
        DB::update(
            "UPDATE companies SET metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object(?::text, ?::jsonb)
              WHERE id = ? AND workspace_id = ? AND deleted_at IS NULL",
            [SiteMedia::CLE, json_encode($valeur + ['le' => now()->toDateString()], JSON_THROW_ON_ERROR), (int) $f->id, $this->workspaceId],
        );
        $this->compter($delta, 'marqueurs_ecrits');
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
