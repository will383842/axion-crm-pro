<?php

namespace App\Services\Audiences;

use App\Crm\Campagnes\AdressePresseFiable;
use App\Crm\Campagnes\GardePresse;
use App\Crm\Campagnes\Segments;
use App\Crm\FichesProtegees;
use App\Crm\Listes\ListesManuelles;
use App\Crm\ProvenanceTiers\ProvenanceTiers;
use App\Crm\Sites\QuarantaineSite;
use App\Crm\Sites\SiteFiable;
use App\Jobs\RefreshAudienceChunkJob;
use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Services\Triage\TriageAutoService;
use App\Support\AuditLogger;
use App\Support\WorkspaceContext;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AudienceBuilderService
{
    /**
     * `entity_nature` (2026-09-28, chantier « référentiels ») : viser « les
     * associations » ou « les CCI » passait jusqu'ici par l'étiquette
     * `nature-*`, qui n'est posée qu'à l'enrichissement. La colonne est
     * désormais renseignée sur toutes les fiches (les fiches INSEE portent
     * `entreprise`), indexée, et ses valeurs sont celles de
     * `Taxonomy::ENTITY_NATURES`.
     *
     * Les types, zones et thèmes de média (harmonisation de la presse,
     * 2026-09-30) se visent par leurs étiquettes (`tags` / `contains_any` :
     * `media-type:radio`, `media-zone:regional`…) ; la relation, par
     * `relation_type` (ajouté par le chantier B).
     */
    public const WHITELIST_FIELDS = [
        'prospection_status', 'department_code', 'region_code', 'commune_code',
        'size_category', 'sector_main', 'entity_nature', 'priority', 'quality_score',
        'tags', 'has_email', 'enriched_at', 'best_email_confidence',
        // 2026-10-01 — statut de la relation (chantier B : exclure les clients,
        // partenaires… d'une prospection, cf. `RelationsProspection`), pays
        // (chantier C : « étranger » = `country_code` ≠ FR, colonne NOT NULL)
        // et joignabilité calculée (chantier D, `Joignabilite`). Colonnes
        // ordinaires de `companies` : mêmes opérateurs, même sémantique NULL.
        'relation_type', 'lifecycle_stage', 'country_code', 'joignabilite',
        // 2026-09-30 — membres d'une LISTE MANUELLE (`in` / `not_in`, valeur =
        // identifiants de listes) : « membres de la liste X », « sauf liste Y ».
        self::CHAMP_LISTE_MANUELLE,
        // 2026-10-01 — « audience presse » (`segment eq presse`, bloc `all`).
        self::CHAMP_SEGMENT,
        // 2026-10-03 — quarantaine des adresses de sites devinés non vérifiés
        // (lot N5, `QuarantaineSite`) : `eq` seulement, valeur booléenne.
        self::CHAMP_EMAIL_HORS_QUARANTAINE, self::CHAMP_SITE_NON_VERIFIE,
    ];

    /**
     * « A au moins une adresse joignable HORS QUARANTAINE » (lot N5,
     * 03/10/2026) : `has_email`, moins les adresses que `QuarantaineSite` met
     * en quarantaine (générique d'une fiche au site deviné non vérifié,
     * personne relevée sur ce site ou sur son domaine). C'est le « joignable »
     * des audiences par défaut et du compteur de l'accueil. `eq` seulement.
     *
     * Même règle que l'envoi pour le motif tiers (N12, `ProvenanceTiers`) :
     * une personne apportée par un tiers dont l'information est insuffisante
     * ne rend pas sa fiche joignable — elle ne part jamais, elle ne compte
     * donc pas. Sous-requête par personne, lue par l'index
     * `idx_contacts_provenances_tiers_contact` ; rien n'est réécrit.
     */
    public const CHAMP_EMAIL_HORS_QUARANTAINE = 'email_hors_quarantaine';

    /**
     * « Le site de la fiche est deviné et non vérifié » (`SiteFiable`, lot
     * N4) : sous `not`, il retire de « Confiance email A (domaine = site) »
     * les fiches dont le « domaine = site » est un domaine deviné — une note A
     * calculée avant le lot N5 y reste écrite, rien n'est réécrit. `eq`
     * seulement.
     */
    public const CHAMP_SITE_NON_VERIFIE = 'site_non_verifie';

    /** Les champs calculés (jamais une colonne : EXISTS / expression jamais NULL). */
    private const CHAMPS_CALCULES = [
        'tags', 'has_email', self::CHAMP_LISTE_MANUELLE, self::CHAMP_SEGMENT,
        self::CHAMP_EMAIL_HORS_QUARANTAINE, self::CHAMP_SITE_NON_VERIFIE,
    ];

    /**
     * L'AUDIENCE PRESSE (ouverture de la presse, décision de Will du
     * 01/10/2026) : `{"field": "segment", "op": "eq", "value": "presse"}`,
     * dans le bloc `all` seulement.
     *
     * Une audience qui le porte vise la presse harmonisée — et SEULEMENT
     * elle (tag `FichesProtegees::TAG_PRESSE`, jamais une fiche « média
     * possible ») ; les autres critères (type, zone, thème, public, format de
     * média, département…) l'affinent. Ses membres, son aperçu, ses comptes et
     * ses destinataires ne retiennent QUE des adresses de provenance fiable
     * (`AdressePresseFiable`). Une audience SANS ce critère n'aspire jamais
     * la presse (`GardePresse`). Segment presse fermé
     * (`crm.segments_ouverts`) : le critère est REFUSÉ.
     */
    public const CHAMP_SEGMENT = 'segment';

    /**
     * Le critère « membre d'une liste manuelle » (2026-09-30).
     *
     * Une organisation en est membre si elle y est cochée, OU si l'une de ses
     * personnes y est cochée (`ListesManuelles::organisationsMembres`). Seuls
     * `in` et `not_in` ont un sens ; la valeur est une liste d'identifiants
     * de listes VIVANTES de l'espace — une liste inconnue ou à la corbeille
     * est REFUSÉE (jamais ignorée : ignorer élargirait l'audience).
     *
     * 🔴 Les FICHES PROTÉGÉES (`FichesProtegees`) n'entrent dans aucune
     * audience… sauf par une seule porte, explicite : être MEMBRE d'une liste
     * manuelle exigée par le bloc `all` (`liste_manuelle in [X]`). Cocher une
     * fiche une à une, ou la rapprocher d'un fichier, c'est le choix humain que
     * `FichesProtegees` réserve (« ni audience PAR DÉFAUT ») ; un critère
     * général (secteur, région, tag…) ne les fait JAMAIS entrer.
     */
    public const CHAMP_LISTE_MANUELLE = 'liste_manuelle';

    public const WHITELIST_OPS = [
        'eq', 'neq', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte',
        'contains_any', 'is_null', 'is_not_null',
    ];

    private const REFRESH_CHUNK_SIZE = 500;

    /** 1 000 lignes × 5 colonnes = 5 000 paramètres, loin des 65 535 de Postgres. */
    public const MEMBRES_PAR_INSERTION = 1000;

    /**
     * Sprint H5 — Au delà de ce seuil, on bascule en Bus::batch parallèle
     * (10 workers Horizon supervisor audiences-refresh). En dessous, refresh
     * inline (fast path, évite overhead batch).
     *
     * Valeurs par défaut ; `crm.audiences.seuil_lot` / `crm.audiences.taille_lot`
     * les remplacent (les tests les abaissent pour jouer le chemin batch avec
     * trois fiches au lieu de 5 001).
     */
    private const BATCH_THRESHOLD = 5000;

    private const BATCH_CHUNK_SIZE = 5000;

    private static function seuilLot(): int
    {
        return max(1, (int) config('crm.audiences.seuil_lot', self::BATCH_THRESHOLD));
    }

    private static function tailleLot(): int
    {
        return max(1, (int) config('crm.audiences.taille_lot', self::BATCH_CHUNK_SIZE));
    }

    /**
     * Pour une audience presse, `presse_ecartees` : les adresses écartées par
     * leur provenance (`AdressePresseFiable::MOTIFS`).
     *
     * @return array{companies: int, contacts: int, presse_ecartees?: array<string, int>}
     */
    public function preview(string $workspaceId, array $criteria): array
    {
        $query = $this->buildQuery($workspaceId, $criteria);
        if (self::estAudiencePresse($criteria)) {
            return $this->previewPresse($query);
        }

        $companies = $query->count();
        // Sprint H8 — contacts contactables (valid|catchall|unknown) + comptage
        // additionnel des companies ayant un email_generic (sans contact dédié)
        $contactableCompanyIds = (clone $query)->select('id');
        $contacts = DB::table('contacts')
            ->whereIn('company_id', $contactableCompanyIds)
            ->whereIn('email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
            ->whereRaw(GardePresse::conditionContactsSql('contacts'))
            ->count();
        $companyOnlyEmails = (clone $query)
            ->whereNotNull('email_generic')
            // Même garde par contact que `refresh()` : une fiche dont les
            // seuls contacts joignables sont de la presse compte par son
            // adresse générique, comme elle entre au rafraîchissement.
            ->whereDoesntHave('contacts', fn ($q) => $q->whereIn(
                'email_status',
                TriageAutoService::CONTACTABLE_EMAIL_STATUSES,
            )->whereRaw(GardePresse::conditionContactsSql('contacts')))
            ->count();

        return [
            'companies' => $companies,
            'contacts' => $contacts + $companyOnlyEmails,
        ];
    }

    /**
     * L'aperçu d'une audience presse : seules les adresses de provenance
     * fiable comptent, comme au rafraîchissement (`lignesMembres`).
     *
     * `presse_ecartees` a la MÊME définition que celle de
     * `ResolveurDestinataires` (relecture A09) : parmi les adresses
     * CANDIDATES — la boîte générique de chaque fiche et l'adresse de chaque
     * personne de la presse (vivante) —, les adresses DISTINCTES (normalisées)
     * écartées par leur provenance, jugées sur toutes leurs occurrences : un
     * journaliste retiré derrière elle l'écarte ; sinon une occurrence fiable
     * suffit ; sinon `journaliste_sans_acces` si une personne de la presse la
     * porte, `site_devine` autrement.
     *
     * @param  Builder<Company>  $query
     * @return array{companies: int, contacts: int, presse_ecartees: array<string, int>}
     */
    private function previewPresse(Builder $query): array
    {
        $companies = (clone $query)->count();
        $ids = (clone $query)->select('companies.id');

        // Les membres, comme `lignesMembres` : personnes de la presse fiables
        // et joignables, sinon la boîte générique fiable de la fiche.
        $personnes = DB::table('contacts as ct')
            ->join('companies as c', 'c.id', '=', 'ct.company_id')
            ->whereIn('ct.company_id', $ids)
            ->whereNull('ct.deleted_at')
            ->whereNotNull('ct.email')
            ->whereIn('ct.email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
            ->whereRaw(GardePresse::estContactPresseSql('ct'))
            ->whereRaw(AdressePresseFiable::contactFiableSql('ct', 'c.id', 'c'))
            ->count();
        $generiques = DB::table('companies as c')
            ->whereIn('c.id', (clone $query)->select('companies.id'))
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.email_generic')
            ->whereRaw(AdressePresseFiable::generiqueFiableSql('c.id', 'c'))
            ->whereRaw('NOT EXISTS (SELECT 1 FROM contacts ct WHERE ct.company_id = c.id AND ct.deleted_at IS NULL AND ct.email IS NOT NULL'
                . " AND ct.email_status IN ('" . implode("','", TriageAutoService::CONTACTABLE_EMAIL_STATUSES) . "')"
                . ' AND ' . GardePresse::estContactPresseSql('ct')
                . ' AND ' . AdressePresseFiable::contactFiableSql('ct', 'c.id', 'c') . ')')
            ->count();

        // Les écartées : adresses distinctes, verdict sur toutes leurs occurrences.
        $idsSql = (clone $query)->select('companies.id');
        $occGeneriques = DB::table('companies as c')
            ->whereIn('c.id', $idsSql)
            ->whereNull('c.deleted_at')
            ->whereNotNull('c.email_generic')
            ->selectRaw(AdressePresseFiable::cleSql('c.email_generic') . ' AS adresse, CASE WHEN '
                . AdressePresseFiable::generiqueFiableSql('c.id', 'c') . " THEN NULL ELSE '" . AdressePresseFiable::SITE_DEVINE . "' END AS motif");
        $occPersonnes = DB::table('contacts as ct')
            ->join('companies as c', 'c.id', '=', 'ct.company_id')
            ->whereIn('ct.company_id', (clone $query)->select('companies.id'))
            ->whereNull('ct.deleted_at')
            ->whereNotNull('ct.email')
            ->whereRaw(GardePresse::estContactPresseSql('ct'))
            ->selectRaw(AdressePresseFiable::cleSql('ct.email') . ' AS adresse, '
                . AdressePresseFiable::motifContactSql('ct', 'c.id', 'c') . ' AS motif');
        $retire = AdressePresseFiable::JOURNALISTE_RETIRE;
        $sansAcces = AdressePresseFiable::JOURNALISTE_SANS_ACCES;
        $parAdresse = DB::query()
            ->fromSub($occGeneriques->unionAll($occPersonnes), 'occ')
            ->where('occ.adresse', '<>', '')
            ->groupBy('occ.adresse')
            ->selectRaw("CASE WHEN bool_or(occ.motif = '{$retire}') THEN '{$retire}'"
                . ' WHEN bool_or(occ.motif IS NULL) THEN NULL'
                . " WHEN bool_or(occ.motif = '{$sansAcces}') THEN '{$sansAcces}'"
                . " ELSE '" . AdressePresseFiable::SITE_DEVINE . "' END AS verdict");
        $comptes = DB::query()->fromSub($parAdresse, 'v')->whereNotNull('v.verdict')
            ->groupBy('v.verdict')->selectRaw('v.verdict, COUNT(*) AS n')->pluck('n', 'verdict')->all();
        $ecartees = [];
        foreach (AdressePresseFiable::MOTIFS as $m) {
            $ecartees[$m] = (int) ($comptes[$m] ?? 0);
        }

        return [
            'companies' => $companies,
            'contacts' => $personnes + $generiques,
            'presse_ecartees' => $ecartees,
        ];
    }

    /**
     * Lignes de `audience_members` insérées par tranches de
     * `MEMBRES_PAR_INSERTION` (2026-10-02).
     *
     * Postgres refuse une requête de plus de 65 535 paramètres, et chaque
     * ligne en porte cinq : au-delà de 13 107 lignes, un seul
     * `insertOrIgnore` échoue. Un lot de 5 000 fiches en produit une par
     * PERSONNE — mesuré en production : 13 375 lignes (66 875 paramètres)
     * pour l'audience 1 à l'offset 195 000, 13 472 pour l'audience 3 à
     * l'offset 125 000. Ces deux lots échouaient à chaque essai ; pire, la
     * capture Sentry de l'exception sérialisait les 66 875 paramètres de
     * chaque cadre de la pile et tuait le worker (mémoire épuisée), si bien
     * que le lot n'était jamais marqué en échec et que le rappel `finally`
     * n'arrivait pas.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insererMembres(array $rows): void
    {
        foreach (array_chunk($rows, self::MEMBRES_PAR_INSERTION) as $tranche) {
            DB::table('audience_members')->insertOrIgnore($tranche);
        }
    }

    /**
     * Les lignes `audience_members` de ces fiches — UNE définition, partagée
     * par `refresh()` et `RefreshAudienceChunkJob`.
     *
     * Audience ordinaire : les contacts joignables (jamais un journaliste,
     * `GardePresse`), sinon une ligne « fiche » (adresse générique).
     * Audience presse : les seules PERSONNES DE LA PRESSE de provenance fiable
     * (`AdressePresseFiable`) — jamais un autre contact de la fiche (GOFAB,
     * organisateur, prospection), même quand la fiche porte aussi un autre
     * tag protégé —, sinon une ligne « fiche » SEULEMENT si son adresse
     * générique est fiable — une fiche sans adresse fiable n'entre pas.
     *
     * (La lecture `DB::table('contacts')` ci-dessous est celle qui vivait dans
     * `RefreshAudienceChunkJob` jusqu'au 2026-10-01.)
     *
     * @param  array<int>  $companyIds
     * @return list<array{audience_id: int, company_id: int, contact_id: int|null, workspace_id: string, added_at: mixed}>
     */
    public function lignesMembres(EmailAudience $audience, array $companyIds): array
    {
        if ($companyIds === []) {
            return [];
        }
        $criteres = $audience->getAttribute('criteria');
        $presse = self::estAudiencePresse(is_array($criteres) ? $criteres : []);

        $contacts = DB::table('contacts')
            ->whereIn('contacts.company_id', $companyIds)
            ->whereIn('contacts.email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES);
        $generiquesFiables = [];
        if ($presse) {
            // Relecture A09 : dans une audience presse, SEULES les personnes de
            // la presse entrent — jamais un contact GOFAB, organisateur ou de
            // prospection d'une fiche presse qui porte aussi un autre tag
            // protégé (la protection générale est levée pour CETTE fiche, pas
            // pour toutes ses personnes).
            $contacts->join('companies as apf_c', 'apf_c.id', '=', 'contacts.company_id')
                ->whereNull('contacts.deleted_at')
                ->whereNotNull('contacts.email')
                ->whereRaw(GardePresse::estContactPresseSql('contacts'))
                ->whereRaw(AdressePresseFiable::contactFiableSql('contacts', 'apf_c.id', 'apf_c'));
            $generiquesFiables = DB::table('companies as apf_c')
                ->whereIn('apf_c.id', $companyIds)
                ->whereNotNull('apf_c.email_generic')
                ->whereRaw(AdressePresseFiable::generiqueFiableSql('apf_c.id', 'apf_c'))
                ->pluck('apf_c.id')
                ->map(static fn ($id): int => (int) $id)
                ->flip()
                ->all();
        } else {
            $contacts->whereRaw(GardePresse::conditionContactsSql('contacts'));
        }
        $parFiche = $contacts->select('contacts.id', 'contacts.company_id')->get()->groupBy('company_id');

        $rows = [];
        foreach ($companyIds as $companyId) {
            $siens = $parFiche->get($companyId, collect());
            if ($siens->isEmpty()) {
                if ($presse && ! isset($generiquesFiables[$companyId])) {
                    continue;
                }
                $rows[] = [
                    'audience_id' => (int) $audience->id,
                    'company_id' => (int) $companyId,
                    'contact_id' => null,
                    'workspace_id' => (string) $audience->workspace_id,
                    'added_at' => now(),
                ];

                continue;
            }
            foreach ($siens as $contact) {
                $rows[] = [
                    'audience_id' => (int) $audience->id,
                    'company_id' => (int) $companyId,
                    'contact_id' => (int) $contact->id,
                    'workspace_id' => (string) $audience->workspace_id,
                    'added_at' => now(),
                ];
            }
        }

        return $rows;
    }

    /**
     * Sprint H5 — Recalcule tous les members.
     *
     * Si total companies > BATCH_THRESHOLD (5000) → bascule en Bus::batch
     * parallèle (10 workers via supervisor audiences-refresh).
     * Sinon → refresh inline chunkById 500 (fast path).
     *
     * Idempotent : delete all + reinsert (chunk parallèle avec ON CONFLICT DO NOTHING).
     */
    public function refresh(EmailAudience $audience): void
    {
        Log::info('Audience refresh start', ['audience_id' => $audience->id]);

        $query = $this->buildQuery($audience->workspace_id, $audience->criteria ?? []);
        $total = (clone $query)->count();

        if ($total > self::seuilLot()) {
            $this->refreshViaBatch($audience, $total);

            return;
        }

        // ── ATOMIQUE (relecture A09 de #282, 2026-10-02) ───────────────────
        //
        // Avant : la suppression des anciens membres était validée SEULE, puis
        // le remplissage partait par lots hors transaction. Une coupure entre
        // les deux — délai SQL de la requête web, processus tué, erreur sur
        // un lot — laissait une audience À MOITIÉ remplie, présentée comme
        // rafraîchie. Désormais suppression, remplissage et compteur forment
        // UNE transaction : ou la nouvelle composition entière, ou l'ancienne
        // intacte. Jamais d'audience partielle.
        //
        // La route porte `delai-sql:300` ; un dépassement annule tout et
        // remonte en 503 (cf. `AudiencesController::refresh`).
        DB::transaction(function () use ($audience, $query): void {
            AudienceMember::where('audience_id', $audience->id)->delete();

            $query->chunkById(self::REFRESH_CHUNK_SIZE, function ($companies) use ($audience) {
                $companyIds = array_map(static fn ($id): int => (int) $id, $companies->pluck('id')->all());
                // Une seule définition des membres (`lignesMembres`), partagée
                // avec `RefreshAudienceChunkJob`.
                $this->insererMembres($this->lignesMembres($audience, $companyIds));
            });

            $audience->update([
                'member_count' => AudienceMember::where('audience_id', $audience->id)->count(),
                'refreshed_at' => now(),
            ]);
        });

        Log::info('Audience refresh done', ['audience_id' => $audience->id, 'members' => $audience->member_count]);

        AuditLogger::log('audience.refreshed', [
            'workspace_id' => $audience->workspace_id,
            'resource_type' => 'audience',
            'resource_id' => (string) $audience->id,
            'member_count' => $audience->member_count,
            'name' => $audience->name,
        ]);
    }

    /**
     * Sprint H5 — Path Bus::batch pour audiences > 5K companies.
     * Dispatch N jobs en parallèle, finalize callback update member_count.
     */
    private function refreshViaBatch(EmailAudience $audience, int $total): void
    {
        Log::info('Audience refresh via Bus::batch', [
            'audience_id' => $audience->id,
            'total' => $total,
        ]);

        DB::transaction(function () use ($audience) {
            AudienceMember::where('audience_id', $audience->id)->delete();
            $audience->update(['refreshed_at' => null, 'member_count' => 0]);
        });

        $taille = self::tailleLot();
        $chunks = (int) ceil($total / $taille);
        $jobs = [];
        for ($i = 0; $i < $chunks; $i++) {
            // B11-002 : `Bus::batch` ne passe pas par `dispatch()`, on pose
            // donc l'espace sur l'instance avant de l'empiler.
            $jobs[] = (new RefreshAudienceChunkJob(
                audienceId: $audience->id,
                offset: $i * $taille,
                limit: $taille,
            ))->pourEspace((string) $audience->workspace_id);
        }

        // ⚠️ Le rappel `finally` ne capture QUE des scalaires (2026-10-02).
        //
        // Laravel range ce rappel, sérialisé, dans `job_batches.options`, et
        // le désérialise à CHAQUE lecture du lot — y compris dans le worker,
        // AVANT que le moindre contexte d'espace soit posé (`$this->batch()`
        // en tête de `RefreshAudienceChunkJob::handle`, puis l'enregistrement
        // du lot terminé, après `handle`). Un modèle capturé (`use
        // ($audience)`) y est RECHARGÉ depuis la base à ce moment-là : sous
        // `axion_app` et la RLS forcée de `email_audiences`, sans contexte, la
        // ligne est invisible, le rechargement échoue, et
        // `DatabaseBatchRepository::unserialize` avale l'erreur en rendant
        // des options VIDES. Le rappel disparaissait alors sans bruit :
        // `refreshed_at` restait NULL et `member_count` à 0 pour toujours.
        // Un identifiant et un espace ne se rechargent pas : ils passent.
        $audienceId = (int) $audience->id;
        $espace = (string) $audience->workspace_id;

        Bus::batch($jobs)
            ->name("audience-refresh-{$audience->id}")
            ->onQueue('audiences-refresh')
            ->allowFailures()
            ->finally(static function (Batch $batch) use ($audienceId, $espace): void {
                // Lot 3 (2026-10-02) : ce rappel tourne dans un worker, APRÈS le
                // dernier lot, sans contexte d'espace. Sous la RLS forcée de
                // `email_audiences`, la mise à jour ci-dessous touchait ZÉRO
                // ligne : `refreshed_at` restait NULL (« jamais ») alors que les
                // membres étaient bien recalculés. On pose le contexte.
                WorkspaceContext::run($espace, static function () use ($batch, $audienceId): void {
                    $audience = EmailAudience::find($audienceId);
                    if ($audience === null) {
                        Log::warning('Audience refresh via Bus::batch : audience disparue avant la fin du lot', [
                            'audience_id' => $audienceId,
                            'batch_id' => $batch->id,
                        ]);

                        return;
                    }
                    $audience->update([
                        'refreshed_at' => now(),
                        'member_count' => AudienceMember::where('audience_id', $audience->id)->count(),
                    ]);
                    AuditLogger::log(
                        $batch->hasFailures() ? 'audience.refresh.failed' : 'audience.refreshed',
                        [
                            'workspace_id' => $audience->workspace_id,
                            'resource_type' => 'audience',
                            'resource_id' => (string) $audience->id,
                            'member_count' => $audience->member_count,
                            'name' => $audience->name,
                            'batch_id' => $batch->id,
                            'failed_jobs' => $batch->failedJobs,
                        ],
                    );
                });
            })
            ->dispatch();
    }

    /**
     * Sprint H5 — Exposition publique du builder pour RefreshAudienceChunkJob.
     * Pas d'override de la logique, juste accès au DSL criteria builder.
     */
    public function buildPublicQuery(string $workspaceId, array $criteria): Builder
    {
        return $this->buildQuery($workspaceId, $criteria);
    }

    /**
     * Pour une company donnée, retourne les IDs des audiences (is_active) dont les criteria matchent.
     * Utilisé par WaterfallOrchestrator step12_auto_segment.
     *
     * @return list<int>
     */
    public function evaluateForCompany(Company $company): array
    {
        // La presse harmonisée n'entre dans aucune audience : elle ne part que
        // par son segment (`GardePresse`), quel que soit le chemin.
        if (! GardePresse::admissible((int) $company->id)) {
            return [];
        }

        $audiences = EmailAudience::query()
            ->where('workspace_id', $company->workspace_id)
            ->where('is_active', true)
            ->where('auto_refresh', true)
            ->get(['id', 'criteria']);

        $matched = [];
        foreach ($audiences as $audience) {
            $criteria = is_array($audience->criteria) ? $audience->criteria : [];
            if ($this->companyMatchesCriteria($company, $criteria)) {
                $matched[] = $audience->id;
            }
        }

        return $matched;
    }

    /**
     * Build query Eloquent à partir d'un DSL criteria pour le workspace donné.
     */
    /** Les SEULS blocs de premier niveau que le DSL connait. */
    private const BLOCS = ['all', 'any', 'not'];

    /**
     * Refuse un jeu de critères mal formé — AVANT de construire quoi que ce soit
     * (constat D26-001, S1).
     *
     * Chaque `return;` silencieux de `applyCondition()`, chaque `is_array()`
     * non satisfait de `buildQuery()`, chaque clé de premier niveau inconnue
     * EFFAÇAIT le critère. Ce qui restait, c'était `where workspace_id = ?` :
     * l'audience devenait le workspace ENTIER. Une garde mesurée le 2026-08-20
     * le chiffre — sur une base de 3 fiches dont 1 seule visée, un critère
     * effacé en rendait 3.
     *
     * On valide en UN seul endroit, en amont, plutôt que de rendre bruyant
     * chaque point de chute : c'est le seul moyen qu'aucune branche future
     * n'oublie de l'être.
     *
     * ⚠️ Ce que cette méthode ne refuse PAS, et pourquoi :
     *  - des critères VIDES (`[]`) : « toute la base » est une audience
     *    légitime, explicitement demandée. Ce n'est pas une forme cassée.
     *  - `tags` avec un opérateur non supporté : `buildPositive()` rend déjà
     *    `1 = 0`, c'est-à-dire PERSONNE. C'est fermé, pas ouvert — et un test
     *    du dépôt (« tags avec un operateur non supporte ne vise personne »)
     *    garde cette symétrie avec l'évaluateur en mémoire. On ne casse pas un
     *    correctif existant pour appliquer une règle à un cas qui ne saigne pas.
     *
     * @param  array<mixed>  $criteria
     *
     * @throws CritereAudienceInvalide
     */
    public static function validerCriteres(array $criteria): void
    {
        foreach (array_keys($criteria) as $cle) {
            if (! in_array($cle, self::BLOCS, true)) {
                // Cas très réaliste : une faute de frappe (`alll`, `filters`,
                // `conditions`). Le bloc n'était alors jamais lu, et la requête
                // sortait SANS AUCUN critère.
                throw CritereAudienceInvalide::parce(
                    sprintf('bloc inconnu %s (attendus : %s)', self::citer($cle), implode(', ', self::BLOCS)),
                );
            }
        }

        foreach (self::BLOCS as $bloc) {
            if (! array_key_exists($bloc, $criteria)) {
                continue;
            }

            $conditions = $criteria[$bloc];
            if (! is_array($conditions)) {
                throw CritereAudienceInvalide::parce(
                    sprintf('le bloc %s doit etre une liste de conditions', self::citer($bloc)),
                );
            }

            foreach ($conditions as $rang => $cond) {
                // Une clé de tableau PHP est toujours int ou string : le repère
                // de position ne peut pas échouer.
                $ou = sprintf('%s[%s]', $bloc, (string) $rang);
                if (! is_array($cond)) {
                    throw CritereAudienceInvalide::parce($ou . ' n est pas une condition');
                }
                self::validerCondition($ou, $cond, $bloc);
            }
        }
    }

    /**
     * @param  array<mixed>  $cond
     *
     * @throws CritereAudienceInvalide
     */
    private static function validerCondition(string $ou, array $cond, string $bloc = 'all'): void
    {
        $field = $cond['field'] ?? null;
        $op = $cond['op'] ?? null;

        if (! is_string($field) || ! in_array($field, self::WHITELIST_FIELDS, true)) {
            throw CritereAudienceInvalide::parce(
                $ou . ' : champ ' . self::citer($field) . ' hors liste blanche',
            );
        }

        if (! is_string($op) || ! in_array($op, self::WHITELIST_OPS, true)) {
            throw CritereAudienceInvalide::parce(
                $ou . ' : operateur ' . self::citer($op) . ' hors liste blanche',
            );
        }

        $value = $cond['value'] ?? null;

        // `in` / `not_in` sur une valeur simple : le front qui envoie « 75 » au
        // lieu de [« 75 »]. `buildPositive()` rendait null, la condition
        // disparaissait, et « dans ces départements » devenait « tout le monde ».
        if (in_array($op, ['in', 'not_in'], true) && ! is_array($value)) {
            throw CritereAudienceInvalide::parce(
                $ou . ' : ' . self::citer($op) . ' exige une liste de valeurs, recu ' . gettype($value),
            );
        }

        if ($field === self::CHAMP_LISTE_MANUELLE) {
            if (! in_array($op, ['in', 'not_in'], true)) {
                throw CritereAudienceInvalide::parce(
                    $ou . ' : le champ liste_manuelle n admet que in et not_in, recu ' . self::citer($op),
                );
            }
            // `in` / `not_in` exigent déjà une liste (contrôle ci-dessus).
            $ids = ListesManuelles::entiers($value);
            if ($ids === [] || count($ids) !== count($value) || count($ids) > ListesManuelles::MAX_LISTES_PAR_CRITERE) {
                throw CritereAudienceInvalide::parce(
                    $ou . ' : liste_manuelle exige de 1 a ' . ListesManuelles::MAX_LISTES_PAR_CRITERE
                    . ' identifiants de listes distincts (entiers positifs)',
                );
            }
        }

        // L'audience presse : `segment eq presse`, dans `all` seulement, et
        // seulement si le segment presse est ouvert (`crm.segments_ouverts`).
        if ($field === self::CHAMP_SEGMENT) {
            if ($bloc !== 'all' || $op !== 'eq' || $value !== Segments::PRESSE) {
                throw CritereAudienceInvalide::parce(
                    $ou . ' : le champ segment n admet que segment eq "' . Segments::PRESSE . '" dans le bloc all',
                );
            }
            if (! Segments::ouvert(Segments::PRESSE)) {
                throw CritereAudienceInvalide::parce(
                    $ou . ' : le segment presse est ferme (crm.segments_ouverts) — audience presse refusee',
                );
            }
        }

        // `has_email` n'admet que `eq`. Avec tout autre opérateur,
        // `buildPositive()` rendait null — et « ceux qui ont un e-mail »
        // devenait « tout le monde », fiches sans aucune adresse comprises.
        if ($field === 'has_email' && $op !== 'eq') {
            throw CritereAudienceInvalide::parce(
                $ou . ' : le champ has_email n admet que l operateur eq, recu ' . self::citer($op),
            );
        }

        // Quarantaine (lot N5) : `eq` et une valeur booléenne, rien d'autre —
        // « tout sauf » se dit avec le bloc `not`.
        if (in_array($field, [self::CHAMP_EMAIL_HORS_QUARANTAINE, self::CHAMP_SITE_NON_VERIFIE], true)
            && ($op !== 'eq' || ! is_bool($value))) {
            throw CritereAudienceInvalide::parce(
                $ou . ' : le champ ' . $field . ' n admet que eq avec true ou false',
            );
        }
    }

    /**
     * Rend une valeur fautive lisible dans un message de refus, sans jamais
     * lever elle-même : un message d'erreur qui plante masquerait l'erreur.
     */
    private static function citer(mixed $valeur): string
    {
        if (is_scalar($valeur)) {
            return '"' . (string) $valeur . '"';
        }

        return '(' . gettype($valeur) . ')';
    }

    private function buildQuery(string $workspaceId, array $criteria): Builder
    {
        // 🔴 Le refus arrive AVANT toute construction — et donc, dans
        // `refresh()`, avant la transaction qui supprime les membres. Un
        // critère cassé ne doit ni élargir l'audience ni la VIDER : les deux
        // sont des pertes, la seconde silencieuse elle aussi.
        self::validerCriteres($criteria);

        $query = Company::query()->where('workspace_id', $workspaceId);

        // Une liste manuelle citée doit exister, vivante, dans CET espace.
        $listes = self::listesCitees($criteria);
        $inconnues = array_values(array_diff($listes['toutes'], ListesManuelles::existantes($workspaceId, $listes['toutes'])));
        if ($inconnues !== []) {
            throw CritereAudienceInvalide::parce(
                'liste(s) manuelle(s) inconnue(s) ou a la corbeille : ' . implode(', ', $inconnues),
            );
        }

        // Les fiches protégées n'entrent dans AUCUNE audience : les écrire
        // passera par un flux dédié, décidé par Will — jamais par une audience
        // générale où le triage les aurait rangées (`ready_for_outreach`).
        // Seule porte : être membre d'une liste manuelle EXIGÉE (bloc `all`),
        // c'est-à-dire choisie à la main (cf. `CHAMP_LISTE_MANUELLE`).
        if (self::estAudiencePresse($criteria)) {
            // AUDIENCE PRESSE : la presse harmonisée, et SEULEMENT elle (le
            // critère `segment` pose le tag, `buildPositive`) — jamais une
            // fiche « média possible », restée prospect. C'est la seule porte
            // par laquelle une fiche de presse entre dans une audience.
            $query->whereRaw('NOT EXISTS (SELECT 1 FROM company_tag mp_ct JOIN tags mp_t ON mp_t.id = mp_ct.tag_id'
                . " WHERE mp_ct.company_id = companies.id AND mp_t.slug LIKE 'media-possible:%')");
        } elseif ($listes['exigees'] === []) {
            FichesProtegees::exclure($query);
            GardePresse::exclure($query);
        } else {
            $exigees = $listes['exigees'];
            $query->where(function (Builder $q) use ($exigees): void {
                FichesProtegees::exclure($q);
                $q->orWhereIn('companies.id', ListesManuelles::organisationsMembres($exigees));
            });
            // Et la presse harmonisée, par SA garde (`GardePresse`), HORS de
            // la porte ci-dessus : être membre d'une liste manuelle exigée
            // lève la protection générale, JAMAIS celle de la presse (qui
            // n'entre que par une audience presse ou son segment).
            GardePresse::exclure($query);
        }

        $all = $criteria['all'] ?? [];
        if (is_array($all)) {
            foreach ($all as $cond) {
                $this->applyCondition($query, $cond, 'and');
            }
        }

        $any = $criteria['any'] ?? [];
        if (is_array($any) && ! empty($any)) {
            $query->where(function ($q) use ($any) {
                foreach ($any as $cond) {
                    $this->applyCondition($q, $cond, 'or');
                }
            });
        }

        $not = $criteria['not'] ?? [];
        if (is_array($not)) {
            foreach ($not as $cond) {
                $this->applyCondition($query, $cond, 'not');
            }
        }

        return $query;
    }

    /**
     * Les listes manuelles citées par des critères : `toutes`, et celles
     * qu'EXIGE le bloc `all` (`liste_manuelle in [...]`) — les seules qui
     * ouvrent la porte aux fiches protégées, et dont les personnes cochées
     * peuvent restreindre les destinataires (`ResolveurDestinataires`).
     *
     * @param  array<mixed>  $criteria
     * @return array{toutes: list<int>, exigees: list<int>}
     */
    public static function listesCitees(array $criteria): array
    {
        $toutes = [];
        $exigees = [];
        foreach (self::BLOCS as $bloc) {
            $conditions = $criteria[$bloc] ?? [];
            if (! is_array($conditions)) {
                continue;
            }
            foreach ($conditions as $cond) {
                if (! is_array($cond) || ($cond['field'] ?? null) !== self::CHAMP_LISTE_MANUELLE) {
                    continue;
                }
                $ids = is_array($cond['value'] ?? null) ? ListesManuelles::entiers($cond['value']) : [];
                foreach ($ids as $id) {
                    $toutes[$id] = $id;
                    if ($bloc === 'all' && ($cond['op'] ?? null) === 'in') {
                        $exigees[$id] = $id;
                    }
                }
            }
        }

        return ['toutes' => array_values($toutes), 'exigees' => array_values($exigees)];
    }

    /**
     * L'audience porte-t-elle le critère « audience presse »
     * (`segment eq presse` dans le bloc `all`) ?
     *
     * @param  array<mixed>  $criteria
     */
    public static function estAudiencePresse(array $criteria): bool
    {
        $all = $criteria['all'] ?? [];
        if (! is_array($all)) {
            return false;
        }
        foreach ($all as $cond) {
            if (is_array($cond) && ($cond['field'] ?? null) === self::CHAMP_SEGMENT
                && ($cond['op'] ?? null) === 'eq' && ($cond['value'] ?? null) === Segments::PRESSE) {
                return true;
            }
        }

        return false;
    }

    private function applyCondition($query, array $cond, string $combinator): void
    {
        $field = $cond['field'] ?? null;
        $op = $cond['op'] ?? null;
        $value = $cond['value'] ?? null;

        // 🔴 Ici se trouvaient DEUX `return;` — le coeur du constat D26-001.
        // Un champ ou un opérateur hors liste blanche faisait sortir la méthode
        // sans rien ajouter à la requête : le critère était EFFACÉ, et il ne
        // restait que `where workspace_id = ?`. `validerCriteres()` refuse
        // désormais en amont ; on garde ces deux gardes en second rideau, mais
        // elles LÈVENT. Un chemin d'appel futur qui contournerait `buildQuery()`
        // échouera bruyamment au lieu d'élargir l'audience en silence.
        if (! is_string($field) || ! in_array($field, self::WHITELIST_FIELDS, true)) {
            throw CritereAudienceInvalide::parce(
                'champ ' . self::citer($field) . ' hors liste blanche',
            );
        }
        if (! is_string($op) || ! in_array($op, self::WHITELIST_OPS, true)) {
            throw CritereAudienceInvalide::parce(
                'operateur ' . self::citer($op) . ' hors liste blanche',
            );
        }

        // On construit le prédicat POSITIF (closure), PUIS on applique le
        // combinateur de façon uniforme. Fix audit 2026-07-14 : auparavant le
        // combinateur `not` sur un champ direct appliquait la condition en
        // POSITIF (bug → membres jamais retirés / audience incohérente avec la
        // version in-memory). Désormais where / orWhere / whereNot sont
        // symétriques et cohérents avec companyMatchesCriteria().
        $positive = $this->buildPositive($field, $op, $value);
        if ($positive === null) {
            // Même correction : « op/valeur incompatible → ignorée » voulait dire
            // « → tout le workspace ». `validerCriteres()` couvre les deux seuls
            // cas où `buildPositive()` rend null (`in`/`not_in` sur une valeur
            // simple, `has_email` avec un autre opérateur que `eq`) ; ce rideau
            // reste, et il lève.
            throw CritereAudienceInvalide::parce(sprintf(
                'condition inconstructible sur le champ %s avec l operateur %s',
                self::citer($field),
                self::citer($op),
            ));
        }

        match ($combinator) {
            'or' => $query->orWhere($positive),
            'not' => $query->where($this->negate($positive, $field, $op)),
            default => $query->where($positive),
        };
    }

    /**
     * Ops dont le prédicat vaut UNKNOWN (et non FALSE) quand la colonne est
     * NULL. Sous `where` cela ne se voit pas — UNKNOWN élimine la ligne, comme
     * FALSE. Sous `NOT`, en revanche, `NOT UNKNOWN` reste UNKNOWN : la ligne
     * est éliminée **alors qu'elle aurait dû être gardée**.
     *
     * `evalCondition()` répond FALSE pour tous ces cas (`eq`/`in` par
     * comparaison lâche, les quatre comparateurs par leur garde explicite
     * `$actual !== null &&`). Cette liste est donc le miroir exact de la
     * version en mémoire, pas un choix de confort.
     *
     * `neq` et `not_in` en sont ABSENTS à dessein : en mémoire ils répondent
     * TRUE sur NULL, et `NOT UNKNOWN` élimine la ligne — les deux évaluateurs
     * s'accordent déjà. `is_null` / `is_not_null` ne sont jamais UNKNOWN.
     */
    private const NULL_SENSITIVE_OPS = ['eq', 'in', 'gt', 'lt', 'gte', 'lte'];

    /**
     * Négation d'un prédicat, alignée sur `companyMatchesCriteria()` : une
     * fiche est retirée par `not` si et seulement si `evalCondition()` répond
     * VRAI pour elle.
     *
     * @param  \Closure(\Illuminate\Contracts\Database\Query\Builder): void  $positive
     * @return \Closure(\Illuminate\Contracts\Database\Query\Builder): void
     */
    private function negate(\Closure $positive, string $field, string $op): \Closure
    {
        // `tags` et `has_email` ne sont pas des colonnes : leurs prédicats sont
        // bâtis sur EXISTS, qui vaut toujours TRUE ou FALSE, jamais UNKNOWN.
        $isRealColumn = ! in_array($field, self::CHAMPS_CALCULES, true);

        if ($isRealColumn && in_array($op, self::NULL_SENSITIVE_OPS, true)) {
            return function ($q) use ($positive, $field) {
                $q->whereNot($positive)->orWhereNull($field);
            };
        }

        return fn ($q) => $q->whereNot($positive);
    }

    /**
     * Construit la closure du prédicat POSITIF d'une condition (à appliquer via
     * where / orWhere / whereNot selon le combinateur), ou null si la condition
     * est invalide. Centralise la logique SQL pour qu'elle reste STRICTEMENT
     * alignée avec la version in-memory evalCondition().
     *
     * @return (\Closure(\Illuminate\Contracts\Database\Query\Builder): void)|null
     */
    private function buildPositive(string $field, string $op, mixed $value): ?\Closure
    {
        // Field "tags" : pivot via whereHas (négation gérée par negate() au caller).
        //
        // 🔴 Une condition `tags` inexploitable ne vise PERSONNE — elle n'est
        // pas ignorée. Rendre `null` ici la ferait retirer de la requête, et
        // « porte un de ces tags » avec une liste vide rendrait alors TOUT le
        // workspace : l'envoi partirait à tout le monde. `evalCondition()`
        // répond déjà FALSE dans ce cas ; le SQL doit dire la même chose.
        if ($field === 'tags') {
            $slugs = $op === 'contains_any' && is_array($value)
                ? array_values(array_filter($value, 'is_string'))
                : [];

            if (empty($slugs)) {
                return fn ($q) => $q->whereRaw('1 = 0');
            }

            return function ($q) use ($slugs) {
                $q->whereHas('tags', fn ($t) => $t->whereIn('slug', $slugs));
            };
        }

        // Liste manuelle : `companies.id IN (membres)` — jamais UNKNOWN (la
        // sous-requête ne rend aucun NULL), donc `negate()` n'a rien à corriger.
        if ($field === self::CHAMP_LISTE_MANUELLE) {
            $ids = is_array($value) ? ListesManuelles::entiers($value) : [];
            if ($ids === [] || ! in_array($op, ['in', 'not_in'], true)) {
                return null;
            }

            return function ($q) use ($ids, $op) {
                if ($op === 'in') {
                    $q->whereIn('companies.id', ListesManuelles::organisationsMembres($ids));
                } else {
                    $q->whereNotIn('companies.id', ListesManuelles::organisationsMembres($ids));
                }
            };
        }

        // Audience presse : les fiches qui portent le tag de la presse
        // harmonisée. Jamais UNKNOWN (EXISTS).
        if ($field === self::CHAMP_SEGMENT) {
            if ($op !== 'eq' || $value !== Segments::PRESSE) {
                return null;
            }

            return function ($q) {
                $q->whereExists(function ($sub): void {
                    $sub->selectRaw('1')
                        ->from('company_tag as sg_ct')
                        ->join('tags as sg_t', 'sg_t.id', '=', 'sg_ct.tag_id')
                        ->whereColumn('sg_ct.company_id', 'companies.id')
                        ->where('sg_t.slug', FichesProtegees::TAG_PRESSE);
                });
            };
        }

        // Field "has_email" : Sprint H8 — email contactable (contact
        // valid|catchall|unknown OU company.email_generic). La négation
        // (has_email=false, ou condition sous `not`) est gérée par whereNot au
        // caller → symétrique avec la version in-memory.
        if ($field === 'has_email') {
            if ($op !== 'eq') {
                return null;
            }
            $wantsEmail = (bool) $value;
            $contactSub = function ($q) {
                $q->select(DB::raw(1))
                    ->from('contacts')
                    ->whereColumn('contacts.company_id', 'companies.id')
                    ->whereIn('contacts.email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES);
            };

            return function ($q) use ($wantsEmail, $contactSub) {
                if ($wantsEmail) {
                    $q->where(function ($qq) use ($contactSub) {
                        $qq->whereExists($contactSub)->orWhereNotNull('email_generic');
                    });
                } else {
                    $q->whereNotExists($contactSub)->whereNull('email_generic');
                }
            };
        }

        // Quarantaine (lot N5, `QuarantaineSite`) : expressions jamais NULL,
        // évaluées sur les fiches que les autres critères ont déjà retenues.
        if ($field === self::CHAMP_SITE_NON_VERIFIE) {
            if ($op !== 'eq' || ! is_bool($value)) {
                return null;
            }
            $sql = 'COALESCE(' . SiteFiable::nonVerifieSql('companies') . ', false)';

            return fn ($q) => $value ? $q->whereRaw($sql) : $q->whereRaw('NOT ' . $sql);
        }
        if ($field === self::CHAMP_EMAIL_HORS_QUARANTAINE) {
            if ($op !== 'eq' || ! is_bool($value)) {
                return null;
            }
            $joignable = function ($qq): void {
                $qq->where(function ($g): void {
                    // Une chaîne vide n'est pas une adresse — même lecture que
                    // le miroir en mémoire (relecture #311).
                    $g->whereRaw("NULLIF(btrim(companies.email_generic), '') IS NOT NULL")
                        ->whereRaw('NOT ' . QuarantaineSite::generiqueSql('companies'));
                })->orWhereExists(function ($sub): void {
                    $sub->select(DB::raw(1))
                        ->from('contacts')
                        ->whereColumn('contacts.company_id', 'companies.id')
                        ->whereIn('contacts.email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
                        ->whereNotNull('contacts.email')
                        ->whereNull('contacts.deleted_at')
                        ->whereRaw('NOT ' . QuarantaineSite::personneSql('contacts', 'companies'))
                        // Motif tiers (`EligibiliteAdresse`, 0 ter) : jamais
                        // envoyée, donc jamais comptée joignable.
                        ->whereRaw('NOT ' . ProvenanceTiers::informationInsuffisanteSql('contacts'));
                });
            };

            return fn ($q) => $value ? $q->where($joignable) : $q->whereNot($joignable);
        }

        // Opérateurs tableau : valeur doit être un tableau, sinon condition ignorée.
        if (in_array($op, ['in', 'not_in'], true) && ! is_array($value)) {
            return null;
        }

        // Champs directs sur companies.
        //
        // 🔴 `neq` et `not_in` acceptent EXPLICITEMENT les colonnes NULL.
        //
        // En SQL, `colonne != 'x'` vaut UNKNOWN quand la colonne est NULL, et
        // UNKNOWN élimine la ligne. `evalCondition()` — l'évaluateur EN MÉMOIRE
        // des mêmes critères (chemin waterfall step12) — répond l'inverse :
        // `null != 'x'` est VRAI en PHP, donc la fiche est gardée.
        //
        // Une audience « tout ce qui n'est pas X » perdait donc EN SILENCE
        // toutes les fiches dont le champ n'est pas renseigné — c'est-à-dire
        // l'essentiel d'une base de prospection collectée. Pire : le contenu de
        // l'audience dépendait du chemin qui l'avait calculée, `refresh()` en
        // SQL n'en retenant pas les mêmes que step12 en mémoire.
        //
        // On aligne le SQL sur la mémoire, comme l'engagement écrit plus haut
        // l'exige (« STRICTEMENT alignée avec evalCondition »). Sémantique
        // retenue : NULL vaut « inconnu », et « inconnu ≠ x » est VRAI.
        //
        // ⚠️ Cela ÉLARGIT les audiences bâties sur `neq` / `not_in` : les fiches
        // au champ vide y entrent désormais. C'est la bonne réponse à « tout
        // sauf X » — mais c'en est une, et elle est assumée ici plutôt que
        // subie selon le chemin de calcul.
        //
        // Sous le combinateur `not`, rien ne change : `negate()` enveloppe ce
        // prédicat, `NOT (… OR … IS NULL)` rend FALSE sur NULL, et la version
        // en mémoire exclut elle aussi. Un test garde cette symétrie.
        return function ($q) use ($field, $op, $value) {
            switch ($op) {
                case 'eq':          $q->where($field, '=', $value);
                    break;
                case 'neq':         $q->where(fn ($qq) => $qq->where($field, '!=', $value)->orWhereNull($field));
                    break;
                case 'in':          $q->whereIn($field, $value);
                    break;
                case 'not_in':      $q->where(fn ($qq) => $qq->whereNotIn($field, $value)->orWhereNull($field));
                    break;
                case 'gt':          $q->where($field, '>', $value);
                    break;
                case 'lt':          $q->where($field, '<', $value);
                    break;
                case 'gte':         $q->where($field, '>=', $value);
                    break;
                case 'lte':         $q->where($field, '<=', $value);
                    break;
                case 'is_null':     $q->whereNull($field);
                    break;
                case 'is_not_null': $q->whereNotNull($field);
                    break;
            }
        };
    }

    /**
     * Évalue en mémoire si une company matche les criteria (pour step12 waterfall, perf-critical).
     * Implémentation simple : pour chaque condition all → check direct sur les attributs.
     */
    private function companyMatchesCriteria(Company $company, array $criteria): bool
    {
        // 🔴 Découvert en écrivant la garde de D26-001, non signalé par
        // l'audit : la version EN MÉMOIRE ferme sur `all` et sur `any` (une
        // condition fausse fait échouer le bloc) mais OUVRE sur `not` —
        // `evalCondition()` répond faux pour un champ inconnu, la fiche n'est
        // donc pas exclue, et elle est retenue. Une audience dont le seul
        // critère est une exclusion mal écrite rattachait ainsi CHAQUE fiche
        // enrichie, en silence, par le chemin waterfall step12.
        //
        // Ici on ne LÈVE pas : `evaluateForCompany()` est appelé par fiche
        // pendant l'enrichissement, et une seule audience mal saisie tuerait
        // l'enrichissement du workspace entier. On ferme (aucun rattachement)
        // et on le DIT dans le journal. Le SQL, lui, refuse : c'est là que la
        // décision d'envoi se prend.
        try {
            self::validerCriteres($criteria);
        } catch (CritereAudienceInvalide $e) {
            Log::warning('Audience aux criteres invalides ignoree en memoire', [
                'company_id' => $company->id,
                'raison' => $e->getMessage(),
            ]);

            return false;
        }

        $all = $criteria['all'] ?? [];
        if (is_array($all)) {
            foreach ($all as $cond) {
                if (! $this->evalCondition($company, $cond)) {
                    return false;
                }
            }
        }

        $any = $criteria['any'] ?? [];
        if (is_array($any) && ! empty($any)) {
            $anyMatch = false;
            foreach ($any as $cond) {
                if ($this->evalCondition($company, $cond)) {
                    $anyMatch = true;
                    break;
                }
            }
            if (! $anyMatch) {
                return false;
            }
        }

        $not = $criteria['not'] ?? [];
        if (is_array($not)) {
            foreach ($not as $cond) {
                if ($this->evalCondition($company, $cond)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function evalCondition(Company $company, array $cond): bool
    {
        $field = $cond['field'] ?? null;
        $op = $cond['op'] ?? null;
        $value = $cond['value'] ?? null;

        if (! is_string($field) || ! in_array($field, self::WHITELIST_FIELDS, true)) {
            return false;
        }

        if ($field === 'tags') {
            if ($op !== 'contains_any' || ! is_array($value)) {
                return false;
            }
            $companySlugs = $company->tags->pluck('slug')->all();

            return ! empty(array_intersect($value, $companySlugs));
        }
        if ($field === self::CHAMP_LISTE_MANUELLE) {
            $ids = is_array($value) ? ListesManuelles::entiers($value) : [];
            if ($ids === [] || ! in_array($op, ['in', 'not_in'], true)) {
                return false;
            }
            $membre = ListesManuelles::organisationEstMembre((int) $company->id, $ids);

            return $op === 'in' ? $membre : ! $membre;
        }
        if ($field === self::CHAMP_SEGMENT) {
            return $op === 'eq' && $value === Segments::PRESSE
                && in_array(FichesProtegees::TAG_PRESSE, $company->tags->pluck('slug')->all(), true);
        }
        if ($field === 'has_email') {
            // Sprint H8 — élargi : tout email contactable OU email_generic
            $hasContact = $company->contacts()
                ->whereIn('email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
                ->exists();
            $hasGeneric = ! empty($company->email_generic);
            $hasEmail = $hasContact || $hasGeneric;

            return $hasEmail === (bool) $value;
        }
        if ($field === self::CHAMP_SITE_NON_VERIFIE) {
            return $op === 'eq' && is_bool($value)
                && QuarantaineSite::ficheNonVerifiee($company->website_method, $company->getRawOriginal('metadata')) === $value;
        }
        if ($field === self::CHAMP_EMAIL_HORS_QUARANTAINE) {
            if ($op !== 'eq' || ! is_bool($value)) {
                return false;
            }
            // Miroir de `buildPositive()` (même règle : `QuarantaineSite`,
            // puis le motif tiers de `ProvenanceTiers`).
            $nonVerifiee = QuarantaineSite::ficheNonVerifiee($company->website_method, $company->getRawOriginal('metadata'));
            $joignable = trim((string) $company->email_generic) !== '' && ! $nonVerifiee;
            if (! $joignable) {
                foreach ($company->contacts()->whereIn('email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
                    ->whereNotNull('email')->select(['email', 'discovery_source'])
                    ->selectRaw(ProvenanceTiers::informationInsuffisanteSql('contacts') . ' AS information_tiers_insuffisante')
                    ->get() as $c) {
                    $source = is_string($c->discovery_source) ? $c->discovery_source : null;
                    if (! QuarantaineSite::personne($nonVerifiee, $source, (string) $c->email, $company->website)
                        && ! (bool) $c->getAttribute('information_tiers_insuffisante')) {
                        $joignable = true;
                        break;
                    }
                }
            }

            return $joignable === $value;
        }

        $actual = $company->{$field} ?? null;

        return match ($op) {
            'eq' => $actual == $value,
            'neq' => $actual != $value,
            'in' => is_array($value) && in_array($actual, $value, false),
            'not_in' => is_array($value) && ! in_array($actual, $value, false),
            'gt' => $actual !== null && $actual > $value,
            'lt' => $actual !== null && $actual < $value,
            'gte' => $actual !== null && $actual >= $value,
            'lte' => $actual !== null && $actual <= $value,
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            default => false,
        };
    }
}
