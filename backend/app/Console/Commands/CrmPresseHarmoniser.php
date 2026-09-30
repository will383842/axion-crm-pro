<?php

namespace App\Console\Commands;

use App\Crm\Doublons\FusionFiches;
use App\Crm\Personnes\NatureEmail;
use App\Crm\Presse\QualificationPresse;
use App\Crm\Scraping\ScrapedRecord;
use App\Crm\Scraping\ScrapedRecordIngestService;
use App\Crm\Scraping\ScrapeIngestOutcome;
use App\Crm\Scraping\ScrapeIngestRejection;
use App\Services\Audit\AuditHashChain;
use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * HARMONISER LA PRESSE : chaque média devient une FICHE, chaque journaliste un
 * CONTACT de cette fiche — le même modèle que tous les autres contacts du CRM
 * (demande de Will, 2026-09-30).
 *
 * Jusqu'ici `media` (≈ 56 000 lignes) et `journalists` (≈ 1 300) vivaient à
 * part : le moteur de campagnes, qui vise des fiches `companies` et leurs
 * `contacts` par nature, relation, taille, étiquettes et audiences, ne pouvait
 * pas les voir.
 *
 * ── Ce que fait un passage, GROUPE par groupe ────────────────────────────
 *
 * Un groupe = un média « tête » (tout média, sauf une émission rattachée à une
 * chaîne) suivi des ÉMISSIONS de cette chaîne. Les groupes sont parcourus dans
 * l'ordre des identifiants de leur tête ; un groupe tient toujours dans UN
 * paquet. Ainsi une chaîne est harmonisée UNE fois par passage, avant ses
 * émissions, et l'essai à blanc compte exactement comme le réel.
 *
 *  - média AVEC fiche : la fiche passe par la porte commune (funnel
 *    `ScrapedRecordIngestService`, source `presse-2026`) — backfill-only :
 *    le site, le téléphone, l'adresse de rédaction du média complètent la
 *    fiche sans rien remplacer ; le tag de provenance VERROUILLÉ
 *    `src:scraping-presse-2026` la PROTÈGE (`FichesProtegees`). Puis nature
 *    `media` et relation `presse_media` selon la règle de
 *    `QualificationPresse` (jamais une relation posée à la main) ;
 *  - média SANS fiche (titres CPPAP, services en ligne, agences sans SIREN) :
 *    une fiche naît, ancrée sur (`FR`, `media:<id>`), et `media.company_id` la
 *    relie ;
 *  - une ÉMISSION va sur la fiche de sa CHAÎNE — la rédaction qu'on joint.
 *    Elle n'apporte à cette fiche que ses personnes, jamais son site, son
 *    téléphone ni son adresse, au premier passage comme aux suivants ;
 *  - étiquettes : `media-type:`, `media-zone:`, `media-theme:` (dérivées des
 *    lignes `media`, `EtiquettesMedia`), plus `nature-media` ;
 *  - chaque journaliste vivant, non opposé, jamais retiré : un CONTACT de la
 *    fiche (prénom, nom, fonction, e-mail et téléphone s'ils existent,
 *    LinkedIn), base `legitimate_interest_b2b` (funnel), référence
 *    `journaliste:<id>`, rubrique / porte d'accès / média dans `metadata`, et
 *    `journalists.contact_id` pour garder le lien. Deux journalistes de MÊME
 *    nom sur la même fiche ne sont jamais fusionnés en un contact : le second
 *    est écarté et compté (`journalistes_homonymes_ecartes`).
 *
 * ── Rien n'est supprimé (ordre permanent de Will) ────────────────────────
 * Aucune ligne `media`, `journalists`, `companies`, `contacts` n'est
 * supprimée ; aucune étiquette n'est retirée hors de la synchro automatique
 * ordinaire (qui ne touche jamais une étiquette manuelle, verrouillée ou
 * `src:`). Tout est additif et réversible (`QualificationPresse`).
 *
 * ── Ce qu'on ne recrée JAMAIS ────────────────────────────────────────────
 *  - une fiche à la CORBEILLE (mise par Will, ou absorbée par une fusion) :
 *    le média est écarté (`fiche_a_la_corbeille`) ;
 *  - une fiche SUPPRIMÉE après un premier passage (`media.harmonise_le` posé,
 *    `company_id` revenu à NULL) : écartée (`fiche_supprimee_non_recreee`) ;
 *    les émissions d'une telle chaîne aussi (`chaine_a_la_corbeille`) ;
 *  - un journaliste OPPOSÉ (`opt_out`), à la corbeille, dont le contact a été
 *    supprimé ou effacé (référence `journaliste:<id>` à la corbeille,
 *    `harmonise_le` posé sans contact, homonyme à la corbeille sur la fiche,
 *    registre `contacts_retires` — par l'ancre de la fiche ET celles des
 *    fiches qu'elle a absorbées).
 *
 * ── La porte d'accès (`journalists.acces`) ──────────────────────────────
 * Seule `email_redaction` est diffusable par e-mail (migration du 25/08). Le
 * REFUS est la règle par défaut (relecture sécurité de #264) : l'adresse
 * n'est recopiée sur le contact QUE si la porte vaut `email_redaction`. Sans
 * porte posée, par la production, par LinkedIn ou « à qualifier », elle reste
 * sur la ligne source. La porte est gardée dans `metadata.acces`.
 *
 * ── La production audiovisuelle ─────────────────────────────────────────
 * Décision de Will du 14/07 : la production audiovisuelle (NAF 59.11/59.12,
 * ≈ 25 000 sociétés) SORT de la base presse sans être supprimée. Ce sont des
 * entreprises, souvent des prospects : par défaut, leur fiche reçoit ses
 * étiquettes `media-type:production` (visables, excluables) et RIEN d'autre —
 * ni nature, ni relation, ni protection, ni contact. `--inclure-production`
 * les traite comme la presse, en connaissance de cause. Une production n'est
 * jamais la « chaîne » d'une émission.
 *
 * ── Par PAQUETS, reprenable, IDEMPOTENTE ────────────────────────────────
 * Chaque paquet (`--paquet` groupes, 500 par défaut) est validé seul, un point
 * de sauvegarde par média à l'intérieur (même raison que les fédérations : les
 * verrous d'identifiant de transaction restent bornés par le paquet).
 * `updated_at` des fiches n'est pas touché (`app.conserver_updated_at`) :
 * qualifier n'est pas modifier. Une interruption laisse les paquets validés
 * en base ; `--depuis-id=N` reprend au groupe dont la tête est N. Le `run_id`
 * de la porte commune est l'EMPREINTE du contenu (coordonnées du média et
 * journalistes admissibles, déjà harmonisés compris) : repasser sur un média
 * inchangé n'écrit rien — ni fiche, ni `scraper_runs`, ni activité.
 *
 * ── Essai à blanc HONNÊTE ───────────────────────────────────────────────
 * `--dry-run` passe par le MÊME chemin, paquet par paquet, et ANNULE chaque
 * paquet. Une chaîne et ses émissions étant dans le même paquet, les compteurs
 * sont ceux du réel.
 *
 * `--compteurs-seulement` : journaux publics des workflows (dépôt PUBLIC) —
 * que des nombres, ni nom, ni adresse, ni identifiant, et un message FIXE en
 * cas d'interruption. Sans cette option, la sortie ne cite jamais de nom ni
 * d'adresse non plus : des compteurs, des motifs, et le dernier identifiant
 * de tête validé (pour reprendre).
 */
class CrmPresseHarmoniser extends Command
{
    protected $signature = 'crm:presse:harmoniser
                            {--dry-run : Tout parcourir paquet par paquet, annuler chaque paquet, et afficher le bilan}
                            {--depuis-id= : Reprendre au groupe dont le média de tête a l\'identifiant N (inclus)}
                            {--limite= : Ne traiter que les N premiers groupes (une chaîne compte avec ses émissions)}
                            {--paquet=500 : Groupes validés par transaction : borne les verrous tenus}
                            {--inclure-production : Traiter aussi la production audiovisuelle comme de la presse}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Harmonise la presse : chaque média devient une fiche, chaque journaliste un contact.';

    /** Pays de l'ancre `foreign_id` d'un média sans fiche. */
    private const PAYS = 'FR';

    /** Département accepté par le schéma pivot (`ScrapedRecord`). */
    private const MOTIF_DEPARTEMENT = '/^(0[1-9]|1\d|2[1-9AB]|[3-8]\d|9[0-5]|97[1-6])$/';

    /** Refus d'une chaîne qui interdisent à ses émissions de créer une fiche à sa place. */
    private const REFUS_DE_CHAINE = ['fiche_a_la_corbeille', 'fiche_supprimee_non_recreee'];

    /** @var array<string, int> */
    private array $bilan = [];

    /** @var array<string, int> motif => nombre */
    private array $rejets = [];

    /** @var array<string, int> compteurs du paquet OUVERT (oubliés s'il est annulé) */
    private array $enCours = [];

    private bool $inclureProduction = false;

    private string $workspaceId = '';

    private ScrapedRecordIngestService $funnel;

    public function handle(AuditHashChain $audit, ScrapedRecordIngestService $funnel): int
    {
        $this->funnel = $funnel;
        $discret = (bool) $this->option('compteurs-seulement');
        $dryRun = (bool) $this->option('dry-run');
        $this->inclureProduction = (bool) $this->option('inclure-production');

        $limite = $this->entierOption('limite');
        $depuis = $this->entierOption('depuis-id');
        $paquet = $this->entierOption('paquet');
        if ($limite === false || $depuis === false || $paquet === false || $paquet === null) {
            $this->error('--limite, --depuis-id et --paquet doivent être des entiers positifs.');

            return self::FAILURE;
        }

        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $workspaceId = DB::table('workspaces')->where('slug', $slug)->whereNull('deleted_at')->value('id');
        if ($workspaceId === null) {
            $this->error($discret ? 'Espace business introuvable.' : "Espace business introuvable : « {$slug} ».");

            return self::FAILURE;
        }
        $this->workspaceId = (string) $workspaceId;

        $source = DB::table('scraping_sources')->where('slug', QualificationPresse::SOURCE)->first();
        if ($source === null || ! (bool) $source->enabled) {
            $this->error('Source `' . QualificationPresse::SOURCE . '` absente du registre ou coupée : migrer d\'abord (2026_10_01_000010).');

            return self::FAILURE;
        }

        $this->bilan = array_fill_keys([
            'groupes', 'medias_lus', 'medias_rejetes', 'paquets',
            'fiches_creees', 'fiches_existantes', 'emissions_sur_la_chaine', 'emissions_deja_sur_la_chaine',
            'natures_posees', 'natures_conservees', 'relations_posees', 'relations_conservees',
            'production_etiquetee', 'production_sans_fiche_ignoree',
            'emails_grand_public_non_poses',
            'journalistes_convertis', 'journalistes_deja_harmonises', 'journalistes_opposes',
            'journalistes_retires_ignores', 'journalistes_sans_nom', 'journalistes_non_retrouves',
            'journalistes_homonymes_ecartes', 'journalistes_homonymes_autre_adresse', 'journalistes_sur_fiche_d_un_segment_ouvert',
            'emails_journalistes_retenus_par_acces',
            'contacts_crees', 'contacts_completes', 'personnes_opposees', 'emails_refuses_mx',
            'chaines_de_fusion_tronquees',
        ], 0);
        $this->rejets = [];
        $this->enCours = [];

        $dernierValide = null;
        $interruption = null;
        try {
            WorkspaceContext::run($this->workspaceId, function () use ($depuis, $limite, $paquet, $dryRun, $discret, &$dernierValide): void {
                $curseur = ($depuis ?? 1) - 1;
                while (true) {
                    $restant = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['groupes']);
                    if ($restant <= 0) {
                        break;
                    }
                    $tetes = $this->tetes($curseur, $restant);
                    if ($tetes === []) {
                        break;
                    }

                    $this->traiterPaquet($tetes, $dryRun, $discret);
                    $curseur = max($tetes);
                    $dernierValide = $curseur;
                }
            });
        } catch (Throwable $e) {
            $interruption = $e;
        }

        if (! $dryRun && ($interruption === null || $this->bilan['paquets'] > 0)) {
            $audit->record([
                'workspace_id' => $this->workspaceId,
                'user_id' => null,
                'method' => 'HARMONISER_PRESSE',
                'path' => 'artisan crm:presse:harmoniser',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', json_encode($this->bilan, JSON_THROW_ON_ERROR)),
            ]);
        }

        if ($interruption !== null) {
            $reprise = $dernierValide === null || $discret ? '' : ' Reprendre avec --depuis-id=' . ($dernierValide + 1) . '.';
            $this->error(
                "INTERROMPU après {$this->bilan['paquets']} paquet(s) validé(s)"
                . ($dryRun ? ' (à blanc : rien n\'a été écrit).' : ' : ils restent en base. Relancer REPREND (idempotente).' . $reprise),
            );

            if ($discret) {
                // Journaux publics : le message d'une exception peut citer une
                // valeur (une requête SQL, un nom). Un message fixe, et le
                // détail au journal du serveur seulement.
                Log::error('crm:presse:harmoniser interrompu', ['exception' => $interruption]);

                throw new RuntimeException('crm:presse:harmoniser interrompu (détail masqué : --compteurs-seulement, voir le journal du serveur).');
            }

            throw $interruption;
        }

        $echec = ($this->rejets['erreur_base'] ?? 0) > 0
            || ($this->bilan['medias_lus'] > 0 && $this->bilan['medias_rejetes'] === $this->bilan['medias_lus']);

        if ($echec) {
            $this->error('ÉCHEC : la base a refusé des médias, ou tous les médias ont été rejetés.');
        } else {
            $this->info($dryRun ? '[À BLANC] rien n\'a été écrit.' : 'Harmonisation appliquée.');
        }
        $this->table(['compteur', 'nombre'], array_map(
            static fn (string $cle, int $n): array => [$cle, $n],
            array_keys($this->bilan),
            array_values($this->bilan),
        ));
        if ($dryRun) {
            $this->line('MESURÉ paquet par paquet, chaque paquet annulé ; une chaîne et ses émissions sont toujours dans le même paquet.');
        }
        if (! $discret && $dernierValide !== null) {
            $this->line("Dernier groupe traité : tête {$dernierValide} (reprendre avec --depuis-id=" . ($dernierValide + 1) . ').');
        }
        if ($this->rejets !== []) {
            ksort($this->rejets);
            $this->warn('Médias écartés, par motif :');
            foreach ($this->rejets as $motif => $n) {
                $this->line("  {$motif} : {$n}");
            }
        }

        return $echec ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Les têtes de groupe suivantes : tout média vivant, sauf une ÉMISSION dont
     * la chaîne (média parent vivant, ni émission, ni production) existe —
     * celle-là est traitée dans le groupe de sa chaîne.
     *
     * @return list<int>
     */
    private function tetes(int $curseur, int $combien): array
    {
        $lignes = DB::select(
            "SELECT m.id
               FROM media m
               LEFT JOIN media p
                      ON p.id = m.parent_media_id
                     AND p.id <> m.id
                     AND p.workspace_id = m.workspace_id
                     AND p.deleted_at IS NULL
                     AND p.media_type NOT IN ('tv_emission', 'production_audiovisuelle')
                     AND p.media_family <> 'audiovisual_production'
              WHERE m.workspace_id = ?
                AND m.deleted_at IS NULL
                AND m.id > ?
                AND NOT (m.media_type = 'tv_emission' AND p.id IS NOT NULL)
              ORDER BY m.id
              LIMIT ?",
            [$this->workspaceId, $curseur, $combien],
        );

        return array_values(array_map(static fn (\stdClass $l): int => (int) $l->id, $lignes));
    }

    /**
     * Les émissions d'une chaîne (vide si la tête n'est pas une chaîne).
     *
     * @return list<int>
     */
    private function emissionsDe(int $teteId): array
    {
        $tete = DB::table('media')->where('workspace_id', $this->workspaceId)->where('id', $teteId)
            ->whereNull('deleted_at')->first(['id', 'media_type', 'media_family']);
        if ($tete === null || in_array($tete->media_type, ['tv_emission', 'production_audiovisuelle'], true)
            || $tete->media_family === 'audiovisual_production') {
            return [];
        }

        return array_values(array_map('intval', DB::table('media')->where('workspace_id', $this->workspaceId)
            ->where('parent_media_id', $teteId)->where('id', '<>', $teteId)->where('media_type', 'tv_emission')
            ->whereNull('deleted_at')->orderBy('id')->pluck('id')->all()));
    }

    /**
     * Un paquet : une transaction, un point de sauvegarde par média, validée
     * (ou annulée à blanc) d'un bloc. Ses compteurs ne sont reportés qu'à sa
     * fermeture.
     *
     * @param  list<int>  $tetes
     */
    private function traiterPaquet(array $tetes, bool $dryRun, bool $discret): void
    {
        /** @var list<string> $rejetsDuPaquet */
        $rejetsDuPaquet = [];
        DB::beginTransaction();
        try {
            // Qualifier n'est pas modifier : `updated_at` des fiches reste celui
            // d'avant (déclencheur `trg_set_updated_at`, `companies` et `tags`).
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            foreach ($tetes as $tete) {
                $this->enCours['groupes'] = ($this->enCours['groupes'] ?? 0) + 1;
                $chaine = $this->traiterMedia($tete, null, $rejetsDuPaquet, $discret);
                foreach ($this->emissionsDe($tete) as $emission) {
                    $this->traiterMedia($emission, $chaine, $rejetsDuPaquet, $discret);
                }
            }

            if ($dryRun) {
                DB::rollBack();
            } else {
                DB::commit();
            }
        } catch (Throwable $e) {
            DB::rollBack();
            $this->enCours = [];

            throw $e;
        }

        foreach ($this->enCours as $cle => $n) {
            $this->bilan[$cle] = ($this->bilan[$cle] ?? 0) + $n;
        }
        foreach ($rejetsDuPaquet as $motif) {
            $this->bilan['medias_rejetes']++;
            $this->rejets[$motif] = ($this->rejets[$motif] ?? 0) + 1;
        }
        $this->enCours = [];
        $this->bilan['paquets']++;
    }

    /**
     * Un média, dans son point de sauvegarde. Rend ce que ses émissions doivent
     * savoir de lui : sa fiche, ou le motif de son refus.
     *
     * @param  array{fiche: ?int, refus: ?string}|null  $chaine
     * @param  list<string>  $rejetsDuPaquet
     * @return array{fiche: ?int, refus: ?string}
     */
    private function traiterMedia(int $id, ?array $chaine, array &$rejetsDuPaquet, bool $discret): array
    {
        $this->enCours['medias_lus'] = ($this->enCours['medias_lus'] ?? 0) + 1;
        $motif = null;
        $fiche = null;
        try {
            $delta = [];
            DB::transaction(function () use ($id, $chaine, &$delta, &$fiche): void {
                $delta = [];
                $fiche = $this->harmoniserMedia($id, $delta, $chaine);
            });
            foreach ($delta as $cle => $n) {
                $this->enCours[$cle] = ($this->enCours[$cle] ?? 0) + $n;
            }
        } catch (InvalidArgumentException $e) {
            $motif = $e->getMessage();
        } catch (ScrapeIngestRejection $e) {
            // Le MESSAGE du funnel peut citer une valeur : seul son code sort.
            $motif = 'pivot_' . $e->errorCode;
        } catch (QueryException $e) {
            $motif = 'erreur_base';
            Log::warning('crm:presse:harmoniser : media refuse par la base', [
                'media_id' => $discret ? null : $id,
                'sqlstate' => $e->getCode(),
            ]);
        }
        if ($motif !== null) {
            $rejetsDuPaquet[] = $motif;
        }

        return ['fiche' => $fiche, 'refus' => $motif];
    }

    /**
     * Harmonise UN média ; rend la fiche qui le porte (null : pas de fiche).
     *
     * @param  array<string, int>  $delta
     * @param  array{fiche: ?int, refus: ?string}|null  $chaine  pour une émission : ce que sa chaîne est devenue
     */
    private function harmoniserMedia(int $mediaId, array &$delta, ?array $chaine): ?int
    {
        $m = DB::table('media')->where('workspace_id', $this->workspaceId)->where('id', $mediaId)
            ->whereNull('deleted_at')->first();
        if ($m === null) {
            throw new InvalidArgumentException('media_introuvable');
        }

        $production = $m->media_type === 'production_audiovisuelle' || $m->media_family === 'audiovisual_production';
        if ($production && ! $this->inclureProduction) {
            $fiche = null;
            if ($m->company_id !== null) {
                $fiche = DB::table('companies')->where('workspace_id', $this->workspaceId)->where('id', $m->company_id)
                    ->whereNull('deleted_at')->value('id');
            }
            if ($fiche === null) {
                $this->compter($delta, 'production_sans_fiche_ignoree');

                return null;
            }
            // Ses seules étiquettes : type (et zone, thème s'ils sont connus).
            QualificationPresse::etiqueter((int) $fiche);
            $this->compter($delta, 'production_etiquetee');

            return (int) $fiche;
        }

        // Une ÉMISSION de groupe : la fiche de sa chaîne est lue EN BASE
        // (`media.company_id` du parent, fiche vivante), jamais déduite du
        // succès de la chaîne pendant ce passage.
        $ficheDeLaChaine = null;
        if ($chaine !== null) {
            $idChaine = DB::table('media as p')
                ->join('companies as c', 'c.id', '=', 'p.company_id')
                ->where('p.workspace_id', $this->workspaceId)->where('p.id', $m->parent_media_id)
                ->whereNull('p.deleted_at')->whereNull('c.deleted_at')
                ->value('c.id');
            $ficheDeLaChaine = $idChaine === null ? null : (int) $idChaine;
            if ($ficheDeLaChaine === null && $m->company_id === null) {
                // Sa chaîne n'a pas de fiche (écartée par Will, refusée,
                // introuvable) : l'émission ne crée JAMAIS une fiche à sa place
                // et ne verse jamais ses coordonnées nulle part.
                throw new InvalidArgumentException(in_array($chaine['refus'], self::REFUS_DE_CHAINE, true)
                    ? 'chaine_a_la_corbeille'
                    : 'chaine_sans_fiche');
            }
        }

        // ── La fiche qui portera ce média ─────────────────────────────────
        // `$ficheConnue` : la fiche DÉJÀ en base (celle du média, ou celle de
        // sa chaîne) — c'est sous son ancre que le registre est interrogé.
        if ($m->company_id !== null) {
            $fiche = DB::table('companies')->where('workspace_id', $this->workspaceId)->where('id', $m->company_id)
                ->first(['id', 'siren', 'country_code', 'foreign_id', 'deleted_at']);
            if ($fiche === null || $fiche->deleted_at !== null) {
                // Corbeille (Will, ou fusion) : on ne la ressuscite pas, et on
                // ne déplace pas le média sans que la fusion le journalise.
                throw new InvalidArgumentException('fiche_a_la_corbeille');
            }
            $ancre = $this->ancreDeFiche($fiche);
            if ($ancre === null) {
                throw new InvalidArgumentException('fiche_sans_ancre');
            }
            $ficheConnue = (int) $fiche->id;
            // Une émission DÉJÀ portée par la fiche de sa chaîne le reste : elle
            // n'apporte que ses personnes, à chaque passage. Lu EN BASE
            // (`parent.company_id = m.company_id`), même si la chaîne a échoué
            // pendant ce passage ou n'est plus traitée comme telle.
            // Le parent est lu corbeille comprise, sciemment : une chaîne mise à
            // la corbeille ne fait pas de ses émissions des fiches autonomes.
            $parent = $m->media_type === 'tv_emission' && $m->parent_media_id !== null
                ? DB::selectOne('SELECT company_id FROM media WHERE workspace_id = ? AND id = ?', [$this->workspaceId, $m->parent_media_id])
                : null;
            $surLaChaine = $parent !== null && $parent->company_id !== null && (int) $parent->company_id === $ficheConnue;
            $this->compter($delta, $surLaChaine ? 'emissions_deja_sur_la_chaine' : 'fiches_existantes');
        } elseif ($m->harmonise_le !== null) {
            // Déjà harmonisé, et sa fiche a disparu depuis : supprimée par Will.
            throw new InvalidArgumentException('fiche_supprimee_non_recreee');
        } elseif ($ficheDeLaChaine !== null) {
            $fiche = DB::table('companies')->where('workspace_id', $this->workspaceId)->where('id', $ficheDeLaChaine)
                ->first(['id', 'siren', 'country_code', 'foreign_id', 'deleted_at']);
            $ancre = $fiche === null || $fiche->deleted_at !== null ? null : $this->ancreDeFiche($fiche);
            if ($ancre === null) {
                throw new InvalidArgumentException('fiche_sans_ancre');
            }
            $ficheConnue = $ficheDeLaChaine;
            $surLaChaine = true;
        } else {
            $ancre = ['foreign_id' => 'media:' . $mediaId, 'country' => self::PAYS];
            $existante = DB::table('companies')->where('workspace_id', $this->workspaceId)
                ->where('country_code', self::PAYS)->where('foreign_id', $ancre['foreign_id'])
                ->first(['id', 'deleted_at']);
            if ($existante !== null && $existante->deleted_at !== null) {
                throw new InvalidArgumentException('fiche_a_la_corbeille');
            }
            $ficheConnue = null;
            $surLaChaine = false;
        }

        // ── Les journalistes à faire entrer ──────────────────────────────
        [$personnes, $journalistes, $empreintes] = $this->journalistes($m, $ficheConnue, $delta);

        // ── Porte commune ────────────────────────────────────────────────
        $message = $this->message($m, $ancre, $surLaChaine, $personnes, $empreintes, $delta);
        $outcome = $this->funnel->ingest(ScrapedRecord::fromArray($message), false);
        if (! in_array($outcome->status, [ScrapeIngestOutcome::CREATED, ScrapeIngestOutcome::UPDATED, ScrapeIngestOutcome::IDEMPOTENT], true)) {
            throw new InvalidArgumentException('pivot_statut_inattendu');
        }
        $this->compter($delta, 'contacts_crees', $outcome->contactsCreated);
        $this->compter($delta, 'contacts_completes', $outcome->contactsUpdated);
        $this->compter($delta, 'personnes_opposees', $outcome->personsSkippedOptOut);
        $this->compter($delta, 'emails_refuses_mx', $outcome->emailsRejectedMx);
        if ($outcome->chainesFusionTronquees > 0) {
            $this->compter($delta, 'chaines_de_fusion_tronquees');
        }

        $companyId = $outcome->companyId ?? $this->ficheParAncre($ancre);
        if ($companyId === null) {
            throw new RuntimeException('fiche_introuvable_apres_ingestion');
        }
        if ($m->company_id === null) {
            $this->compter($delta, $surLaChaine ? 'emissions_sur_la_chaine' : 'fiches_creees');
        }

        $lien = array_filter([
            'company_id' => $m->company_id === null ? $companyId : null,
            'harmonise_le' => $m->harmonise_le === null ? now() : null,
        ], static fn ($v): bool => $v !== null);
        if ($lien !== []) {
            DB::table('media')->where('id', $mediaId)->update($lien);
        }

        foreach (QualificationPresse::qualifier($companyId) as $cle => $n) {
            $this->compter($delta, $cle, $n);
        }

        // ── Le lien journaliste → contact ────────────────────────────────
        $convertis = 0;
        foreach ($journalistes as $j) {
            $contactId = QualificationPresse::contactDe($companyId, $j['prenom'], $j['nom'], $j['email']);
            if ($contactId === null) {
                // Opposé au funnel (e-mail, téléphone), adresse morte sans autre
                // canal : la personne n'est pas créée, ce qui est voulu.
                $this->compter($delta, 'journalistes_non_retrouves');

                continue;
            }
            QualificationPresse::completerContact($contactId, 'journaliste:' . $j['id'], $j['metadata']);
            DB::table('journalists')->where('id', $j['id'])->update(['contact_id' => $contactId, 'harmonise_le' => now()]);
            $this->compter($delta, 'journalistes_convertis');
            $convertis++;
        }
        // Journalistes RÉELLEMENT convertis sur une fiche qui porte AUSSI un
        // segment de campagne ouvert (un groupe de presse qui organise des
        // salons) : ils y sont exclus des envois (`GardePresse`), on le compte.
        if ($convertis > 0 && QualificationPresse::porteUnSegmentOuvert($companyId)) {
            $this->compter($delta, 'journalistes_sur_fiche_d_un_segment_ouvert', $convertis);
        }

        QualificationPresse::etiqueter($companyId);

        return $companyId;
    }

    /**
     * Les journalistes du média qu'on peut faire entrer, et ceux qu'on écarte
     * (comptés). Un journaliste déjà harmonisé dont le contact est vivant
     * n'est pas renvoyé au funnel : son lien est seulement vérifié — mais il
     * entre dans l'EMPREINTE du contenu, pour que repasser n'écrive rien.
     *
     * @param  array<string, int>  $delta
     * @return array{0: list<array<string, string>>, 1: list<array{id: int, prenom: ?string, nom: string, email: ?string, metadata: array<string, scalar|null>}>, 2: list<array<string, string>>}
     */
    private function journalistes(\stdClass $m, ?int $ficheConnue, array &$delta): array
    {
        $lignes = DB::table('journalists')->where('workspace_id', $this->workspaceId)->where('media_id', $m->id)
            ->whereNull('deleted_at')->orderBy('id')->get();

        $ancres = [];
        if ($ficheConnue !== null) {
            $tronquee = false;
            $ancres = array_merge([FusionFiches::ancreDe($this->workspaceId, $ficheConnue)], FusionFiches::ancresAbsorbees($this->workspaceId, $ficheConnue, $tronquee));
            if ($tronquee) {
                $this->compter($delta, 'chaines_de_fusion_tronquees');
            }
        }

        $personnes = [];
        $retenus = [];
        $empreintes = [];
        $nomsDuMessage = [];
        foreach ($lignes as $j) {
            if ((bool) $j->opt_out) {
                $this->compter($delta, 'journalistes_opposes');

                continue;
            }
            $prenom = $this->texte($j->first_name);
            $nom = $this->texte($j->last_name);

            // La porte d'accès : SEULE `email_redaction` laisse l'adresse
            // partir sur le contact. Sans porte, c'est non.
            $email = $this->email($j->email);
            $retenueParLaPorte = $email !== null && $j->acces !== 'email_redaction';
            if ($retenueParLaPorte) {
                $email = null;
            }
            $personne = array_filter([
                'kind' => 'person',
                'first_name' => $prenom,
                'last_name' => $nom,
                'role' => $this->texte($j->role),
                'email' => $email,
                'phone' => $this->texte($j->phone),
                'linkedin_url' => $this->linkedin($j),
            ], static fn ($v): bool => $v !== null);

            $parReference = DB::table('contacts')->where('workspace_id', $this->workspaceId)
                ->where('external_ref', 'journaliste:' . $j->id)->first(['id', 'deleted_at']);
            if ($parReference !== null && $parReference->deleted_at === null) {
                if ((int) ($j->contact_id ?? 0) !== (int) $parReference->id) {
                    DB::table('journalists')->where('id', $j->id)->update(['contact_id' => (int) $parReference->id, 'harmonise_le' => now()]);
                }
                $empreintes[] = $personne + ['journaliste' => (string) $j->id];
                $this->compter($delta, 'journalistes_deja_harmonises');

                continue;
            }
            if ($parReference !== null || ($j->harmonise_le !== null && $j->contact_id === null)) {
                // Son contact a été mis à la corbeille, supprimé ou effacé.
                $this->compter($delta, 'journalistes_retires_ignores');

                continue;
            }
            if ($nom === null) {
                $this->compter($delta, 'journalistes_sans_nom');

                continue;
            }
            if (($ancres !== [] && FusionFiches::personneRetiree($this->workspaceId, $ancres, $prenom, $nom))
                || ($ficheConnue !== null && QualificationPresse::personneALaCorbeille($ficheConnue, $prenom, $nom))) {
                $this->compter($delta, 'journalistes_retires_ignores');

                continue;
            }

            // Deux journalistes de MÊME nom sur une même fiche ne deviennent
            // jamais un seul contact (le funnel les dédoublonne par nom + fiche) :
            // le second est écarté et compté, sa ligne source reste intacte.
            $cleNom = $this->cleNom($prenom, $nom);
            if (isset($nomsDuMessage[$cleNom])
                || ($ficheConnue !== null && QualificationPresse::homonymeJournaliste($ficheConnue, $prenom, $nom, (int) $j->id))) {
                $this->compter($delta, 'journalistes_homonymes_ecartes');

                continue;
            }
            // Un homonyme de la fiche qui porte une AUTRE adresse (venue
            // d'ailleurs) n'est pas réputé être ce journaliste : ni fusion, ni
            // rattachement — compté.
            if ($ficheConnue !== null && QualificationPresse::homonymeAutreAdresse($ficheConnue, $prenom, $nom, $email)) {
                $this->compter($delta, 'journalistes_homonymes_autre_adresse');

                continue;
            }
            $nomsDuMessage[$cleNom] = true;

            if ($retenueParLaPorte) {
                $this->compter($delta, 'emails_journalistes_retenus_par_acces');
            }
            $personnes[] = $personne;
            $empreintes[] = $personne + ['journaliste' => (string) $j->id];
            $retenus[] = [
                'id' => (int) $j->id,
                'prenom' => $prenom,
                'nom' => $nom,
                'email' => $email,
                'metadata' => [
                    'journaliste_id' => (int) $j->id,
                    'media_id' => (int) $m->id,
                    'rubrique' => $this->texte($j->beat),
                    'acces' => $this->texte($j->acces),
                ],
            ];
        }

        return [$personnes, $retenus, $empreintes];
    }

    /**
     * Le message du schéma pivot pour ce média.
     *
     * @param  array{siren?: string, foreign_id?: string, country: string}  $ancre
     * @param  list<array<string, string>>  $personnes  celles à faire entrer
     * @param  list<array<string, string>>  $empreintes  toutes les admissibles (déjà harmonisées comprises)
     * @param  array<string, int>  $delta
     * @return array<string, mixed>
     */
    private function message(\stdClass $m, array $ancre, bool $surLaChaine, array $personnes, array $empreintes, array &$delta): array
    {
        $champs = [];
        // Une émission portée par la fiche de sa chaîne n'apporte QUE ses
        // personnes : son site ou son adresse ne sont pas ceux de la chaîne.
        if (! $surLaChaine) {
            $email = $this->email($m->email);
            if ($email !== null && NatureEmail::de($email) !== 'pro') {
                // Une boîte grand public est celle d'une PERSONNE : elle ne
                // devient pas l'adresse générique d'une fiche.
                $email = null;
                $this->compter($delta, 'emails_grand_public_non_poses');
            }
            $departement = $this->texte($m->department_code);
            $departement = $departement !== null && preg_match(self::MOTIF_DEPARTEMENT, strtoupper($departement)) === 1 ? strtoupper($departement) : null;
            $codePostal = $this->texte($m->postcode);
            $champs = array_filter([
                'denomination' => mb_substr((string) $m->name, 0, 240),
                'website' => $this->lien($m->website),
                'phone' => $this->texte($m->phone),
                'email_generic' => $email,
                'city' => $this->texte($m->city),
                'postcode' => $codePostal !== null && preg_match('/^\d{5}$/', $codePostal) === 1 ? $codePostal : null,
                'department_code' => $departement,
            ], static fn ($v): bool => $v !== null && $v !== '');
        }

        $message = [
            'schema_version' => ScrapedRecord::SCHEMA_VERSION,
            'source' => QualificationPresse::SOURCE,
            'status' => 'success',
            'company' => $ancre + ['nature' => QualificationPresse::NATURE, 'fields' => $champs],
            'persons' => $personnes,
        ];
        // L'empreinte du CONTENU, pas de l'état d'avancement : un journaliste
        // déjà harmonisé n'est plus envoyé, mais il compte toujours. Le même
        // contenu rejoué = le même run : le funnel n'écrit rien (ni fiche, ni
        // `scraper_runs`, ni activité).
        $contenu = ['company' => $message['company'], 'journalistes' => $empreintes];
        $message['run_id'] = QualificationPresse::SOURCE . ':media:' . $m->id . ':'
            . substr(hash('sha256', json_encode($contenu, JSON_THROW_ON_ERROR)), 0, 16);

        return $message;
    }

    /**
     * L'ancre du pivot d'une fiche existante : son SIREN, sinon (pays,
     * `foreign_id`) — null si elle n'a ni l'un ni l'autre.
     *
     * @return array{siren?: string, foreign_id?: string, country: string}|null
     */
    private function ancreDeFiche(\stdClass $fiche): ?array
    {
        $siren = $this->texte($fiche->siren);
        if ($siren !== null && preg_match('/^\d{9}$/', $siren) === 1) {
            return ['siren' => $siren, 'country' => self::PAYS];
        }
        $foreign = $this->texte($fiche->foreign_id);
        $pays = strtoupper((string) $this->texte($fiche->country_code));
        if ($foreign !== null && preg_match('/^[A-Z]{2}$/', $pays) === 1) {
            return ['foreign_id' => $foreign, 'country' => $pays];
        }

        return null;
    }

    /** @param  array{siren?: string, foreign_id?: string, country: string}  $ancre */
    private function ficheParAncre(array $ancre): ?int
    {
        $q = DB::table('companies')->where('workspace_id', $this->workspaceId)->whereNull('deleted_at');
        if (isset($ancre['siren'])) {
            $q->where('siren', $ancre['siren']);
        } else {
            $q->where('country_code', $ancre['country'])->where('foreign_id', $ancre['foreign_id'] ?? '');
        }
        $id = $q->value('id');
        if ($id !== null) {
            return (int) $id;
        }
        // Rejoué (IDEMPOTENT) sur une fiche absorbée depuis par une fusion :
        // elle est à la corbeille, et sa fiche gardée est celle à qualifier.
        $absorbee = DB::table('companies')->where('workspace_id', $this->workspaceId)->whereNotNull('deleted_at');
        if (isset($ancre['siren'])) {
            $absorbee->where('siren', $ancre['siren']);
        } else {
            $absorbee->where('country_code', $ancre['country'])->where('foreign_id', $ancre['foreign_id'] ?? '');
        }
        $absorbee = $absorbee->value('id');
        $renvoi = $absorbee === null ? null : FusionFiches::gardeDe($this->workspaceId, (int) $absorbee);

        return $renvoi === null ? null : (int) $renvoi['garde'];
    }

    /** Le nom normalisé comme la base (`normalize_name`), pour repérer les homonymes. */
    private function cleNom(?string $prenom, string $nom): string
    {
        $ligne = DB::selectOne("SELECT normalize_name(coalesce(?, '') || '_' || ?) AS k", [$prenom, $nom]);

        return (string) ($ligne->k ?? '');
    }

    /** Le profil LinkedIn d'un journaliste : son slug normalisé, sinon le lien de `socials`. */
    private function linkedin(\stdClass $j): ?string
    {
        $slug = $this->texte($j->linkedin_slug ?? null);
        if ($slug !== null && preg_match('/^[A-Za-z0-9%_-]+$/', $slug) === 1) {
            return 'https://www.linkedin.com/in/' . $slug;
        }
        $socials = json_decode(is_string($j->socials ?? null) ? $j->socials : '{}', true);

        return is_array($socials) && is_string($socials['linkedin'] ?? null) ? $this->lien($socials['linkedin']) : null;
    }

    /** @param  array<string, int>  $delta */
    private function compter(array &$delta, string $cle, int $n = 1): void
    {
        if ($n !== 0) {
            $delta[$cle] = ($delta[$cle] ?? 0) + $n;
        }
    }

    private function texte(mixed $valeur): ?string
    {
        if (! is_string($valeur)) {
            return null;
        }
        $valeur = trim($valeur);

        return $valeur === '' ? null : $valeur;
    }

    private function email(mixed $valeur): ?string
    {
        $v = $this->texte($valeur);

        return $v === null || filter_var($v, FILTER_VALIDATE_EMAIL) === false ? null : mb_strtolower($v);
    }

    private function lien(mixed $valeur): ?string
    {
        $v = $this->texte($valeur);

        return $v !== null && preg_match('#^https?://#i', $v) === 1 ? $v : null;
    }

    /** null : option absente ; false : option invalide. */
    private function entierOption(string $nom): int|false|null
    {
        $v = $this->option($nom);
        if ($v === null) {
            return null;
        }
        if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 1) {
            return false;
        }

        return (int) $v;
    }
}
