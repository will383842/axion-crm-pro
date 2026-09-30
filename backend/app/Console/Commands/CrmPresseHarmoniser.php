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
 * ── Ce que fait un passage, média par média (ordre des identifiants) ─────
 *
 *  - média AVEC fiche : la fiche passe par la porte commune (funnel
 *    `ScrapedRecordIngestService`, source `presse-2026`) — backfill-only :
 *    le site, le téléphone, l'adresse de rédaction du média complètent la
 *    fiche sans rien remplacer ; le tag de provenance VERROUILLÉ
 *    `src:scraping-presse-2026` la PROTÈGE (`FichesProtegees`). Puis nature
 *    `media` et relation `presse_media` selon la règle de
 *    `QualificationPresse` (jamais une relation posée à la main) ;
 *  - média SANS fiche (titres CPPAP, services en ligne, agences sans SIREN,
 *    émissions) : une fiche naît, ancrée sur (`FR`, `media:<id>`), et
 *    `media.company_id` la relie. Une ÉMISSION rattachée à une chaîne
 *    (`parent_media_id`) va sur la fiche de sa chaîne — la rédaction qu'on
 *    joint —, harmonisée d'abord s'il le faut ;
 *  - étiquettes : `media-type:`, `media-zone:`, `media-theme:` (dérivées des
 *    lignes `media`, `EtiquettesMedia`), plus `nature-media` ;
 *  - chaque journaliste vivant, non opposé, jamais retiré : un CONTACT de la
 *    fiche (prénom, nom, fonction, e-mail et téléphone s'ils existent,
 *    LinkedIn), base `legitimate_interest_b2b` (funnel), référence
 *    `journaliste:<id>`, rubrique / porte d'accès / média dans `metadata`, et
 *    `journalists.contact_id` pour garder le lien.
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
 *  - un journaliste OPPOSÉ (`opt_out`), à la corbeille, dont le contact a été
 *    supprimé ou effacé (référence `journaliste:<id>` à la corbeille,
 *    `harmonise_le` posé sans contact, homonyme à la corbeille sur la fiche,
 *    registre `contacts_retires` — par l'ancre de la fiche ET celles des
 *    fiches qu'elle a absorbées).
 *
 * ── La porte d'accès (`journalists.acces`) ──────────────────────────────
 * Seule `email_redaction` est diffusable par e-mail (migration du 25/08) :
 * l'adresse d'un journaliste qu'on atteint par la production, par LinkedIn
 * ou « à qualifier » n'est PAS recopiée sur son contact — elle reste sur la
 * ligne source. La porte est gardée dans `metadata.acces`.
 *
 * ── La production audiovisuelle ─────────────────────────────────────────
 * Décision de Will du 14/07 : la production audiovisuelle (NAF 59.11/59.12,
 * ≈ 25 000 sociétés) SORT de la base presse sans être supprimée. Ce sont des
 * entreprises, souvent des prospects : par défaut, leur fiche reçoit ses
 * étiquettes `media-type:production` (visables, excluables) et RIEN d'autre —
 * ni nature, ni relation, ni protection, ni contact. `--inclure-production`
 * les traite comme la presse, en connaissance de cause.
 *
 * ── Par PAQUETS, reprenable ─────────────────────────────────────────────
 * Chaque paquet (`--paquet`, 500 par défaut) est validé seul, un point de
 * sauvegarde par média à l'intérieur (même raison que les fédérations : les
 * verrous d'identifiant de transaction restent bornés par le paquet).
 * `updated_at` des fiches n'est pas touché (`app.conserver_updated_at`) :
 * qualifier n'est pas modifier. Une interruption laisse les paquets validés
 * en base ; `--depuis-id=N` reprend au média N, et relancer depuis le début
 * est sans danger (idempotente : même contenu = même `run_id`, rien
 * n'est réécrit).
 *
 * ── Essai à blanc HONNÊTE ───────────────────────────────────────────────
 * `--dry-run` passe par le MÊME chemin, paquet par paquet, et ANNULE chaque
 * paquet. Limite dite en sortie : un média ne voit pas ce qu'un paquet
 * PRÉCÉDENT aurait créé (la fiche d'une chaîne créée dans un paquet annulé est
 * recréée pour l'émission d'un paquet suivant, et comptée deux fois).
 *
 * `--compteurs-seulement` : journaux publics des workflows (dépôt PUBLIC) —
 * que des nombres, ni nom, ni adresse, ni identifiant. Sans cette option, la
 * sortie ne cite jamais de nom ni d'adresse non plus : des compteurs, des
 * motifs, et le dernier identifiant de média validé (pour reprendre).
 */
class CrmPresseHarmoniser extends Command
{
    protected $signature = 'crm:presse:harmoniser
                            {--dry-run : Tout parcourir paquet par paquet, annuler chaque paquet, et afficher le bilan}
                            {--depuis-id= : Reprendre au média d\'identifiant N (inclus)}
                            {--limite= : Ne traiter que les N premiers médias (passage par étapes)}
                            {--paquet=500 : Médias validés par transaction : borne les verrous tenus}
                            {--inclure-production : Traiter aussi la production audiovisuelle comme de la presse}
                            {--compteurs-seulement : N\'afficher que des nombres (journaux publics des workflows)}';

    protected $description = 'Harmonise la presse : chaque média devient une fiche, chaque journaliste un contact.';

    /** Pays de l'ancre `foreign_id` d'un média sans fiche. */
    private const PAYS = 'FR';

    /** Département accepté par le schéma pivot (`ScrapedRecord`). */
    private const MOTIF_DEPARTEMENT = '/^(0[1-9]|1\d|2[1-9AB]|[3-8]\d|9[0-5]|97[1-6])$/';

    /** Profondeur maximale de la remontée émission → chaîne. */
    private const PROFONDEUR_MAX = 3;

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
            'medias_lus', 'medias_rejetes', 'paquets',
            'fiches_creees', 'fiches_existantes', 'emissions_sur_la_chaine',
            'natures_posees', 'natures_conservees', 'relations_posees', 'relations_conservees',
            'production_etiquetee', 'production_sans_fiche_ignoree',
            'emails_grand_public_non_poses',
            'journalistes_convertis', 'journalistes_deja_harmonises', 'journalistes_opposes',
            'journalistes_retires_ignores', 'journalistes_sans_nom', 'journalistes_non_retrouves',
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
                    $restant = $limite === null ? $paquet : min($paquet, $limite - $this->bilan['medias_lus']);
                    if ($restant <= 0) {
                        break;
                    }
                    $ids = array_values(array_map('intval', DB::table('media')->where('workspace_id', $this->workspaceId)
                        ->whereNull('deleted_at')->where('id', '>', $curseur)->orderBy('id')->limit($restant)->pluck('id')->all()));
                    if ($ids === []) {
                        break;
                    }

                    $this->traiterPaquet($ids, $dryRun, $discret);
                    $curseur = max($ids);
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
            $this->line('MESURÉ paquet par paquet, chaque paquet annulé : un média ne voit pas ce qu\'un paquet PRÉCÉDENT aurait créé (une chaîne créée pour une émission peut être comptée deux fois).');
        }
        if (! $discret && $dernierValide !== null) {
            $this->line("Dernier média traité : {$dernierValide} (reprendre avec --depuis-id=" . ($dernierValide + 1) . ').');
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
     * Un paquet : une transaction, un point de sauvegarde par média, validée
     * (ou annulée à blanc) d'un bloc. Ses compteurs ne sont reportés qu'à sa
     * fermeture.
     *
     * @param  list<int>  $ids
     */
    private function traiterPaquet(array $ids, bool $dryRun, bool $discret): void
    {
        /** @var list<string> $rejetsDuPaquet */
        $rejetsDuPaquet = [];
        DB::beginTransaction();
        try {
            // Qualifier n'est pas modifier : `updated_at` des fiches reste celui
            // d'avant (déclencheur `trg_set_updated_at`, `companies` et `tags`).
            DB::statement("SET LOCAL app.conserver_updated_at = 'on'");
            foreach ($ids as $id) {
                $this->enCours['medias_lus'] = ($this->enCours['medias_lus'] ?? 0) + 1;
                try {
                    $delta = [];
                    DB::transaction(function () use ($id, &$delta): void {
                        $delta = [];
                        $this->harmoniserMedia($id, $delta, 0);
                    });
                    foreach ($delta as $cle => $n) {
                        $this->enCours[$cle] = ($this->enCours[$cle] ?? 0) + $n;
                    }
                } catch (InvalidArgumentException $e) {
                    $rejetsDuPaquet[] = $e->getMessage();
                } catch (ScrapeIngestRejection $e) {
                    // Le MESSAGE du funnel peut citer une valeur : seul son code sort.
                    $rejetsDuPaquet[] = 'pivot_' . $e->errorCode;
                } catch (QueryException $e) {
                    $rejetsDuPaquet[] = 'erreur_base';
                    Log::warning('crm:presse:harmoniser : media refuse par la base', [
                        'media_id' => $discret ? null : $id,
                        'sqlstate' => $e->getCode(),
                    ]);
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
     * Harmonise UN média ; rend la fiche qui le porte (null : pas de fiche).
     *
     * @param  array<string, int>  $delta
     */
    private function harmoniserMedia(int $mediaId, array &$delta, int $profondeur): ?int
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
                $fiche = DB::table('companies')->where('id', $m->company_id)->whereNull('deleted_at')->value('id');
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

        // ── La fiche qui portera ce média ─────────────────────────────────
        $ancre = null;
        $surLaChaine = false;
        // La fiche DÉJÀ connue (celle du média, ou celle de sa chaîne) : c'est
        // sous son ancre que le registre des retraits est interrogé.
        $ficheConnue = null;
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
            $this->compter($delta, 'fiches_existantes');
        } elseif ($m->harmonise_le !== null) {
            // Déjà harmonisé, et sa fiche a disparu depuis : supprimée par Will.
            throw new InvalidArgumentException('fiche_supprimee_non_recreee');
        } else {
            $chaine = null;
            // Une émission rejoint la fiche de sa CHAÎNE — jamais celle d'une
            // société de production (hors presse par défaut).
            $parent = null;
            if ($m->media_type === 'tv_emission' && $m->parent_media_id !== null && (int) $m->parent_media_id !== $mediaId) {
                $parent = DB::table('media')->where('workspace_id', $this->workspaceId)->where('id', $m->parent_media_id)
                    ->whereNull('deleted_at')->first(['id', 'media_type', 'media_family']);
            }
            if ($parent !== null && $profondeur < self::PROFONDEUR_MAX
                && $parent->media_type !== 'production_audiovisuelle' && $parent->media_family !== 'audiovisual_production') {
                try {
                    $chaine = $this->harmoniserMedia((int) $m->parent_media_id, $delta, $profondeur + 1);
                } catch (InvalidArgumentException $e) {
                    if ($e->getMessage() === 'fiche_a_la_corbeille' || $e->getMessage() === 'fiche_supprimee_non_recreee') {
                        // La rédaction qu'on joindrait a été écartée par Will :
                        // l'émission ne recrée pas une fiche à sa place.
                        throw new InvalidArgumentException('chaine_a_la_corbeille');
                    }
                    $chaine = null;
                }
            }

            if ($chaine !== null) {
                $fiche = DB::table('companies')->where('id', $chaine)->first(['id', 'siren', 'country_code', 'foreign_id', 'deleted_at']);
                $ancre = $fiche === null ? null : $this->ancreDeFiche($fiche);
                $surLaChaine = $ancre !== null;
                $ficheConnue = $surLaChaine ? $chaine : null;
            }

            if (! $surLaChaine) {
                $ancre = ['foreign_id' => 'media:' . $mediaId, 'country' => self::PAYS];
                $existante = DB::table('companies')->where('workspace_id', $this->workspaceId)
                    ->where('country_code', self::PAYS)->where('foreign_id', $ancre['foreign_id'])
                    ->first(['id', 'deleted_at']);
                if ($existante !== null && $existante->deleted_at !== null) {
                    throw new InvalidArgumentException('fiche_a_la_corbeille');
                }
            }
        }

        // ── Les journalistes à faire entrer ──────────────────────────────
        [$personnes, $journalistes] = $this->journalistes($m, $ficheConnue, $delta);

        // ── Porte commune ────────────────────────────────────────────────
        $message = $this->message($m, $ancre, $surLaChaine, $personnes, $delta);
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
        }

        QualificationPresse::etiqueter($companyId);

        return $companyId;
    }

    /**
     * Les journalistes du média qu'on peut faire entrer, et ceux qu'on écarte
     * (comptés). Un journaliste déjà harmonisé dont le contact est vivant
     * n'est pas renvoyé au funnel : son lien est seulement vérifié.
     *
     * @param  array<string, int>  $delta
     * @return array{0: list<array<string, string>>, 1: list<array{id: int, prenom: ?string, nom: string, email: ?string, metadata: array<string, scalar|null>}>}
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
        foreach ($lignes as $j) {
            if ((bool) $j->opt_out) {
                $this->compter($delta, 'journalistes_opposes');

                continue;
            }
            $prenom = $this->texte($j->first_name);
            $nom = $this->texte($j->last_name);

            $parReference = DB::table('contacts')->where('workspace_id', $this->workspaceId)
                ->where('external_ref', 'journaliste:' . $j->id)->first(['id', 'deleted_at']);
            if ($parReference !== null && $parReference->deleted_at === null) {
                if ((int) ($j->contact_id ?? 0) !== (int) $parReference->id) {
                    DB::table('journalists')->where('id', $j->id)->update(['contact_id' => (int) $parReference->id, 'harmonise_le' => now()]);
                }
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

            // La porte d'accès : seule `email_redaction` (ou aucune porte
            // posée) laisse l'adresse partir sur le contact.
            $email = $this->email($j->email);
            if ($email !== null && $j->acces !== null && $j->acces !== 'email_redaction') {
                $email = null;
                $this->compter($delta, 'emails_journalistes_retenus_par_acces');
            }

            $personnes[] = array_filter([
                'kind' => 'person',
                'first_name' => $prenom,
                'last_name' => $nom,
                'role' => $this->texte($j->role),
                'email' => $email,
                'phone' => $this->texte($j->phone),
                'linkedin_url' => $this->linkedin($j),
            ], static fn ($v): bool => $v !== null);
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

        return [$personnes, $retenus];
    }

    /**
     * Le message du schéma pivot pour ce média.
     *
     * @param  array{siren?: string, foreign_id?: string, country: string}  $ancre
     * @param  list<array<string, string>>  $personnes
     * @param  array<string, int>  $delta
     * @return array<string, mixed>
     */
    private function message(\stdClass $m, array $ancre, bool $surLaChaine, array $personnes, array &$delta): array
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
        // Le MÊME contenu rejoué = le même run : rien n'est réécrit.
        $message['run_id'] = QualificationPresse::SOURCE . ':media:' . $m->id . ':'
            . substr(hash('sha256', json_encode($message, JSON_THROW_ON_ERROR)), 0, 16);

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
