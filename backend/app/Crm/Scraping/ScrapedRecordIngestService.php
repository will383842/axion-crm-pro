<?php

namespace App\Crm\Scraping;

use App\Crm\Identite\CleDePersonne;
use App\Models\Company;
use App\Services\Tags\AutoTaggerService;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * FUNNEL D'INGESTION UNIQUE de la collecte (lot L3, audit scraping §C.3) —
 * « réparer une fois, servir tous ».
 *
 * Trois portes convergent ici, aucun collecteur ne touche JAMAIS la base
 * directement :
 *   1. `POST /internal/scraper-result` (workers Node, HMAC) — ENFIN branché
 *      sur un service au lieu de logger ;
 *   2. les étapes PHP du waterfall — refactor progressif, au fil de l'eau ;
 *   3. `php artisan scraping:ingest-file` — imports one-shot JSONL.
 *
 * Ordre d'application, IDENTIQUE pour tous (c'est ce qui rend le comportement
 * prévisible et testable) :
 *   1. source au REGISTRE (`scraping_sources`) : inconnue = 422, coupée = 503 ;
 *   2. IDEMPOTENCE : le run a-t-il déjà été ingéré (`scraper_runs.dedup_key`) ?
 *   3. rattachement entreprise : SIREN sinon FILE D'ARBITRAGE (pending_match)
 *      — on ne rattache JAMAIS par dénomination seule (4,29 M fiches en face) ;
 *   4. écriture entreprise BACKFILL-ONLY + `field_origins` : « le DÉCLARÉ
 *      gagne » à l'envers — le collecté ne remplace JAMAIS ni une valeur
 *      existante ni un champ d'origine `declared` ;
 *   5. personnes : opposition (anti-réinsertion) → validation MX → dédup
 *      (email puis nom+entreprise) → écriture backfill-only, TÉLÉPHONES posés
 *      sur les fiches personnes (répare la perte constatée en A.4) ;
 *   6. tag de provenance `src:scraping-<slug>` (multi-valué, cumulatif) ;
 *   7. `scraper_runs` + activité `scraped` dans la timeline.
 *
 * DEUX RÈGLES D'OR héritées de l'harmonisation (audit §B.2/B.6) :
 *   - un scrapé naît FROID (`prospect` / `nouveau` / intérêt légitime B2B) ;
 *   - le froid ne se réchauffe JAMAIS tout seul : ce funnel ne touche ni
 *     `relation_type`, ni `lifecycle_stage`, ni `legal_basis` d'une fiche
 *     existante — seul un événement ENTRANT (lot L2) les fait bouger.
 */
final class ScrapedRecordIngestService
{
    public function __construct(private readonly EmailMxValidator $mx) {}

    /**
     * @throws ScrapeIngestRejection
     */
    public function ingest(ScrapedRecord $record, bool $dryRun = false): ScrapeIngestOutcome
    {
        $source = DB::table('scraping_sources')->where('slug', $record->source)->first();

        if ($source === null) {
            throw ScrapeIngestRejection::invalid(
                'unknown_source',
                "Source hors registre : « {$record->source} ». Une source entre par le registre (seeder), jamais à la volée.",
            );
        }

        if (! (bool) $source->enabled) {
            // 503 et non 422 : couper une source au registre est un kill-switch
            // TEMPORAIRE — le producteur garde ses données et rejouera.
            throw ScrapeIngestRejection::unavailable(
                'source_disabled',
                "Source coupée au registre : « {$record->source} ».",
            );
        }

        $workspaceId = $this->resolveWorkspaceId();

        try {
            return WorkspaceContext::run(
                $workspaceId,
                fn (): ScrapeIngestOutcome => DB::transaction(function () use ($record, $workspaceId, $dryRun): ScrapeIngestOutcome {
                    $outcome = $this->apply($record, $workspaceId);
                    if ($dryRun) {
                        // Le dry-run parcourt le VRAI funnel (validation, dédup,
                        // écritures) puis annule tout : c'est la seule façon de
                        // prédire fidèlement ce qu'un import ferait.
                        throw new DryRunRollback($outcome);
                    }

                    return $outcome;
                }),
            );
        } catch (DryRunRollback $rollback) {
            return $rollback->outcome;
        }
    }

    private function apply(ScrapedRecord $record, string $workspaceId): ScrapeIngestOutcome
    {
        $dedupKey = 'pivot:' . $record->source . ':' . $record->runId;

        $already = DB::table('scraper_runs')
            ->where('workspace_id', $workspaceId)
            ->where('dedup_key', $dedupKey)
            ->exists();

        if ($already) {
            return new ScrapeIngestOutcome(status: ScrapeIngestOutcome::IDEMPOTENT);
        }

        if ($record->status === 'failed') {
            // Un échec de collecte se CONSIGNE (pour l'observabilité et le TTL
            // de retry) mais n'écrit aucune donnée.
            $this->recordRun($record, $workspaceId, null, 'failed', new ScrapeIngestOutcome(status: ScrapeIngestOutcome::SKIPPED_FAILED), $dedupKey);

            return new ScrapeIngestOutcome(status: ScrapeIngestOutcome::SKIPPED_FAILED);
        }

        // ── Rattachement entreprise ─────────────────────────────────────────
        // Deux ancres possibles : le SIREN (France) ou le couple
        // (pays, foreign_id) pour une entité étrangère. Sans l'une des deux,
        // rapprocher par dénomination reste une PROPOSITION, jamais une
        // certitude → file d'arbitrage.
        if ($record->siren === null && $record->foreignId === null) {
            $activityId = $this->recordActivity($record, $workspaceId, null, pendingMatch: true);
            $this->recordRun($record, $workspaceId, null, $record->status, new ScrapeIngestOutcome(status: ScrapeIngestOutcome::PENDING_MATCH), $dedupKey);

            return new ScrapeIngestOutcome(status: ScrapeIngestOutcome::PENDING_MATCH, activityId: $activityId);
        }

        [$companyId, $status, $fieldsWritten] = $this->upsertCompany($record, $workspaceId);

        // ── Personnes ───────────────────────────────────────────────────────
        $created = 0;
        $updated = 0;
        $optedOut = 0;
        $badMx = 0;
        /** @var array<string, int> $skipped motif => nombre de personnes écartées */
        $skipped = [];

        foreach ($record->persons as $person) {
            if (($person['kind'] ?? 'person') === 'service_mailbox') {
                // Une boîte service (contact@, info@) n'est PAS un humain : elle
                // alimente l'email générique de l'entreprise, jamais une fiche
                // personne (faiblesse relevée par l'audit : le scraping actuel
                // fabrique des « contacts » depuis des boîtes génériques).
                $this->backfillGenericEmail($companyId, $person['email'] ?? null, $workspaceId);

                continue;
            }

            $result = $this->upsertContact($record, $workspaceId, $companyId, $person);
            match ($result) {
                'created' => $created++,
                'updated' => $updated++,
                'opted_out' => $optedOut++,
                'bad_mx' => $badMx++,
                // C18-002 — LE `default => null` D'AVANT PERDAIT DES PERSONNES.
                // `upsertContact()` annonce `skipped` dans son propre @return et
                // le rend dans trois cas ; aucun n'était compté nulle part. Une
                // personne collectée pouvait donc disparaître entre le message
                // et le rapport sans qu'un seul chiffre ne bouge.
                //
                // La branche est un FOURRE-TOUT VOLONTAIRE, et pas une
                // énumération : si `upsertContact()` gagne demain un cinquième
                // retour, il sera compté sous son propre nom au lieu de
                // retomber dans le silence. C'est la seule forme qui ne peut pas
                // re-perdre une personne par omission.
                default => $skipped[$result] = ($skipped[$result] ?? 0) + 1,
            };
        }

        $tags = $this->attachSourceTag($record, $workspaceId, $companyId);

        // Le tag `implantation-<pays>` n'est JAMAIS posé à la main : il est
        // DÉRIVÉ de `signals.implantations` par l'AutoTagger (sinon la resync
        // de l'enrichissement le supprimerait, cf. AutoTaggerService). On
        // déclenche la synchro tout de suite pour que la fiche soit classée
        // dès l'import, sans attendre une passe d'enrichissement.
        if ($record->implantations !== [] || $record->entityNature !== null || $record->countryCode !== null) {
            $company = Company::query()->find($companyId);
            if ($company !== null) {
                $tags = array_values(array_unique(array_merge(
                    $tags,
                    (new AutoTaggerService)->syncTags($company)['added'],
                )));
            }
        }

        $activityId = $this->recordActivity($record, $workspaceId, $companyId, pendingMatch: false);

        $outcome = new ScrapeIngestOutcome(
            status: $status,
            companyId: $companyId,
            contactsCreated: $created,
            contactsUpdated: $updated,
            personsSkippedOptOut: $optedOut,
            emailsRejectedMx: $badMx,
            companyFieldsWritten: $fieldsWritten,
            tags: $tags,
            activityId: $activityId,
            personsSkipped: $skipped,
        );

        $this->recordRun($record, $workspaceId, $companyId, $record->status, $outcome, $dedupKey);

        return $outcome;
    }

    // ── Entreprise ──────────────────────────────────────────────────────────

    /**
     * @return array{0: int, 1: string, 2: list<string>}
     */
    private function upsertCompany(ScrapedRecord $record, string $workspaceId): array
    {
        // Recherche par l'ancre effectivement portée par le message : SIREN
        // (France) ou (pays, foreign_id) pour une entité étrangère. Chercher
        // sur `siren = NULL` ne ramènerait JAMAIS rien et créerait un doublon
        // à chaque passage.
        $lookup = DB::table('companies')->where('workspace_id', $workspaceId);
        if ($record->siren !== null) {
            $lookup->where('siren', $record->siren);
        } else {
            $lookup->where('country_code', $record->countryCode)
                ->where('foreign_id', $record->foreignId);
        }
        $existing = $lookup->first();

        if ($existing === null) {
            // Fiche née de la collecte : FROIDE par définition (règle B.2).
            $insert = [
                'workspace_id' => $workspaceId,
                'siren' => $record->siren,
                'country_code' => $record->countryCode ?? 'FR',
                'foreign_id' => $record->foreignId,
                'entity_nature' => $record->entityNature,
                'discovery_source' => $record->source,
                'quality_score' => 0,
                'signals' => $this->encodeObject($this->implantationSignals($record, $this->channelSignals($record, []))),
                'metadata' => '{}',
                'relation_type' => 'prospect',
                'lifecycle_stage' => 'nouveau',
                'legal_basis' => 'legitimate_interest_b2b',
                'field_origins' => '{}',
                'last_seen_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $origins = [];
            $written = [];
            foreach ($this->companyColumns($record) as $column => $value) {
                $insert[$column] = $value;
                $origins[$column] = 'collected';
                $written[] = $column;
            }
            $insert['field_origins'] = $this->encodeObject($origins);

            $id = (int) DB::table('companies')->insertGetId($insert);

            return [$id, ScrapeIngestOutcome::CREATED, $written];
        }

        // BACKFILL-ONLY : une valeur collectée ne remplace JAMAIS une valeur
        // existante, et ne touche JAMAIS un champ d'origine « declared » —
        // c'est le verrou symétrique du « le DÉCLARÉ gagne » du lot L2.
        // Et le funnel ne touche ni relation_type, ni lifecycle_stage, ni
        // legal_basis : le froid ne se réchauffe pas tout seul.
        $origins = $this->decodeOrigins($existing->field_origins ?? null);
        $update = [];
        $written = [];

        foreach ($this->companyColumns($record) as $column => $value) {
            $current = $existing->{$column} ?? null;
            if ($current !== null && trim((string) $current) !== '') {
                continue;
            }
            if (($origins[$column] ?? null) === 'declared') {
                continue;
            }
            $update[$column] = $value;
            $origins[$column] = 'collected';
            $written[] = $column;
        }

        // La nature de l'entité suit la même règle que le reste :
        // BACKFILL-ONLY. Une fiche déjà qualifiée « association » ne devient
        // pas « entreprise » parce qu'une seconde source en a décidé autrement.
        if ($record->entityNature !== null && ($existing->entity_nature ?? null) === null) {
            $update['entity_nature'] = $record->entityNature;
            $written[] = 'entity_nature';
        }

        $signals = $this->implantationSignals($record, $this->channelSignals($record, $this->decodeSignals($existing->signals ?? null)));
        $update['signals'] = $this->encodeObject($signals);
        $update['field_origins'] = $this->encodeObject($origins);
        $update['last_seen_at'] = now();
        $update['updated_at'] = now();

        DB::table('companies')->where('id', $existing->id)->update($update);

        return [(int) $existing->id, ScrapeIngestOutcome::UPDATED, $written];
    }

    /**
     * Colonnes `companies` que le pivot peut alimenter, valeurs nettoyées.
     * L'email générique passe par la validation MX comme tout email collecté.
     *
     * @return array<string, string>
     */
    private function companyColumns(ScrapedRecord $record): array
    {
        $fields = $record->companyFields;

        $columns = [];
        foreach (['denomination', 'website', 'phone', 'address', 'postcode', 'city', 'linkedin_url'] as $column) {
            if (isset($fields[$column])) {
                $columns[$column] = $fields[$column];
            }
        }
        if (isset($fields['email_generic']) && $this->mx->isDeliverable(mb_strtolower($fields['email_generic']))) {
            $columns['email_generic'] = mb_strtolower($fields['email_generic']);
        }

        return $columns;
    }

    /**
     * La récolte en vrac (`channels`) va dans `signals.contact_channels`,
     * cumulée sans doublon — même emplacement que le scraper mentions légales
     * existant, donc consommable par les mêmes écrans.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    /**
     * Fusionne les implantations à l'étranger dans `signals.implantations`
     * (objet clef = code pays ISO2). Même verrou que le reste du funnel :
     * BACKFILL-ONLY — une valeur déjà présente n'est jamais écrasée, une
     * nouvelle collecte ne fait que compléter les trous.
     *
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function implantationSignals(ScrapedRecord $record, array $signals): array
    {
        if ($record->implantations === []) {
            return $signals;
        }

        $existing = is_array($signals['implantations'] ?? null) ? $signals['implantations'] : [];

        foreach ($record->implantations as $implantation) {
            $country = $implantation['country'];
            $current = is_array($existing[$country] ?? null) ? $existing[$country] : [];
            foreach (['name_local', 'city', 'registry_id'] as $key) {
                if (($current[$key] ?? '') === '' && ($implantation[$key] ?? '') !== '') {
                    $current[$key] = $implantation[$key];
                }
            }
            $existing[$country] = $current === [] ? (object) [] : $current;
        }

        $signals['implantations'] = $existing;

        return $signals;
    }

    /**
     * @param  array<string, mixed>  $signals
     * @return array<string, mixed>
     */
    private function channelSignals(ScrapedRecord $record, array $signals): array
    {
        if ($record->channelEmails === [] && $record->channelPhones === []) {
            return $signals;
        }

        $channels = is_array($signals['contact_channels'] ?? null) ? $signals['contact_channels'] : [];
        $emails = is_array($channels['emails'] ?? null) ? $channels['emails'] : [];
        $phones = is_array($channels['phones'] ?? null) ? $channels['phones'] : [];

        $channels['emails'] = array_values(array_unique(array_merge($emails, array_map('mb_strtolower', $record->channelEmails))));
        $channels['phones'] = array_values(array_unique(array_merge($phones, $record->channelPhones)));
        $signals['contact_channels'] = $channels;

        return $signals;
    }

    private function backfillGenericEmail(int $companyId, ?string $email, string $workspaceId): void
    {
        if ($email === null) {
            return;
        }
        $email = mb_strtolower(trim($email));
        if ($email === '' || ! $this->mx->isDeliverable($email)) {
            return;
        }

        DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->where('id', $companyId)
            ->whereNull('email_generic')
            ->update(['email_generic' => $email, 'updated_at' => now()]);
    }

    // ── Personnes ───────────────────────────────────────────────────────────

    /**
     * Dédup + écriture backfill-only d'une personne. Ordre de dédup : email
     * normalisé, puis `normalized_hash` (nom + entreprise).
     *
     * 🔴 A05-001 (S1) — CE COMMENTAIRE DISAIT LE CONTRAIRE, ET C'ÉTAIT FAUX.
     *
     * Il affirmait : « Pas de `person_key` ici : il est SALÉ côté site, la
     * collecte ne peut pas le calculer ». C'est ce raisonnement qui a produit
     * la mesure du 2026-08-18 : **1 319 567 contacts, 410 481 avec e-mail, 0
     * avec `person_key`** — donc une fiche 360° inatteignable pour 100 % du
     * stock, puisque le hub n'offre le lien que `si person_key !== null`.
     *
     * Lu dans le dépôt du site (`src/lib/security/email-hash.ts` l. 81), la clé
     * est un HMAC-SHA256 de formule publique dont seul le SECRET est côté site.
     * La collecte PEUT donc la calculer, dès lors que le secret lui est donné
     * (`CRM_PERSON_KEY_SECRET`). Sans secret, `CleDePersonne::pour()` rend
     * `null` et on ne pose rien — jamais de clé inventée (cf. `CleDePersonne`).
     *
     * Poser la clé ICI n'est pas un confort : sans elle, le remplissage du
     * stock re-divergerait dès la première collecte suivante.
     *
     * @param  array<string, string>  $person
     * @return 'created'|'updated'|'opted_out'|'bad_mx'|'skipped_no_last_name'|'skipped_insert_failed'|'skipped_no_change'
     *
     * C18-002 — LE `skipped` UNIQUE A ÉTÉ ÉCLATÉ EN TROIS MOTIFS. Il ne s'agit
     * pas de raffinement : agrégés, ces trois cas ne se distinguaient plus, et
     * ils appellent des gestes OPPOSÉS. `skipped_no_change` est la marche
     * normale d'un re-scrape (rien de neuf, tant mieux) ; `skipped_no_last_name`
     * accuse le collecteur, qui ramène des personnes sans nom ; et
     * `skipped_insert_failed` est une anomalie de base à instruire. Les compter
     * ensemble aurait produit un nombre que personne ne saurait lire.
     */
    private function upsertContact(ScrapedRecord $record, string $workspaceId, int $companyId, array $person): string
    {
        $email = isset($person['email']) ? mb_strtolower(trim($person['email'])) : null;
        $lastName = $person['last_name'] ?? null;
        $firstName = $person['first_name'] ?? null;

        // Sans nom de famille, pas de fiche personne : `contacts.last_name` est
        // NOT NULL, et fabriquer un nom depuis l'email est exactement la
        // faiblesse que l'audit demande de NE PAS reproduire.
        if ($lastName === null) {
            return 'skipped_no_last_name';
        }

        if ($email !== null) {
            // ANTI-RÉINSERTION : une personne opposée ne revient jamais par un
            // re-scrape — le hash survit à l'effacement (règle B.6.10).
            $emailHash = hash('sha256', $email);
            $opposed = DB::table('opt_out')
                ->where('scope', 'business')
                ->where('email_hash', $emailHash)
                ->exists();
            if ($opposed) {
                return 'opted_out';
            }

            if (! $this->mx->isDeliverable($email)) {
                // Email invalide : la personne n'est pas créée sur la foi d'une
                // adresse morte. Le nom seul, sans canal, n'a pas de valeur.
                return 'bad_mx';
            }
        }

        $existing = null;
        if ($email !== null) {
            // C21-001 — CETTE RECHERCHE EST JOUÉE À CHAQUE FICHE INGÉRÉE.
            //
            // Elle s'écrivait `->whereRaw('lower(email::text) = ?', [$email])`.
            // `contacts.email` est de type `citext` : la comparaison y est DÉJÀ
            // insensible à la casse (et `$email` est mis en minuscules quelques
            // lignes plus haut). Le `lower()` ne changeait donc rien au
            // résultat — il transformait seulement la colonne en EXPRESSION, ce
            // qui rendait `idx_contacts_email` inutilisable.
            //
            // Mesuré le 2026-08-20 sur 20 000 contacts, par `EXPLAIN` du SQL
            // réellement émis (garde
            // `tests/Feature/Infra/IndexEmailRgpdServentLesRequetesTest.php`) :
            //     AVANT ... Seq Scan on contacts ....... 11,216 ms · 589 tampons
            //     APRÈS ... Index Scan idx_contacts_email  0,046 ms ·   4 tampons
            // Soit 244 fois moins de temps, sur 20 000 lignes seulement.
            // Sur la base de volume, cette dédup est le chemin le plus chaud de
            // l'ingestion : elle est jouée une fois PAR PERSONNE collectée.
            $existing = DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->where('email', $email)
                ->orderByRaw('CASE WHEN company_id = ? THEN 0 ELSE 1 END', [$companyId])
                ->first();
        }

        $existing ??= DB::table('contacts')
            ->where('workspace_id', $workspaceId)
            ->where('normalized_hash', $this->normalizedHash($firstName, $lastName, $companyId))
            ->first();

        if ($existing === null) {
            $id = DB::table('contacts')->insertGetId([
                'workspace_id' => $workspaceId,
                'company_id' => $companyId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'role' => $person['role'] ?? null,
                'email' => $email,
                // Le téléphone est POSÉ sur la fiche personne : c'est la
                // réparation de la perte constatée (audit A.4 : 0 % de
                // téléphones sur les contacts alors que les collecteurs en
                // ramènent).
                'phone' => $person['phone'] ?? null,
                'linkedin_url' => $person['linkedin_url'] ?? null,
                'discovery_source' => $record->source,
                'sources' => json_encode([$record->source], JSON_THROW_ON_ERROR),
                'metadata' => '{}',
                'legal_basis' => 'legitimate_interest_b2b',
                // A05-001 : la clé de rapprochement, posée DÈS LA CRÉATION.
                // `null` si l'adresse est absente ou le secret non configuré —
                // jamais de valeur inventée.
                'person_key' => CleDePersonne::pour($email),
                'field_origins' => json_encode($this->collectedOrigins($person, $email), JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $id > 0 ? 'created' : 'skipped_insert_failed';
        }

        // A05-001 — RATTRAPAGE DE LA CLÉ sur une fiche déjà là qui n'en portait
        // pas. Écrit À PART du bloc backfill-only ci-dessous, et volontairement :
        // l'outcome (`created` / `updated` / `skipped`) décrit ce que la COLLECTE
        // apporte à la fiche. Poser une clé de rapprochement absente est une
        // réparation d'index interne, pas un apport de donnée collectée — la
        // compter comme `updated` fausserait les compteurs d'ingestion sans rien
        // dire de vrai.
        //
        // La clé se dérive de l'adresse DE LA FICHE quand elle en a une : la
        // dédup a pu retrouver cette fiche par `normalized_hash` (nom +
        // entreprise), auquel cas l'adresse entrante n'est pas forcément la
        // sienne.
        if (($existing->person_key ?? null) === null) {
            $cle = CleDePersonne::pour(
                is_string($existing->email ?? null) && $existing->email !== '' ? $existing->email : $email,
            );

            if ($cle !== null) {
                DB::table('contacts')->where('id', $existing->id)->update(['person_key' => $cle]);
            }
        }

        // Backfill-only, comme pour l'entreprise : rien d'existant n'est
        // écrasé, rien de « declared » n'est touché, la provenance se cumule.
        $origins = $this->decodeOrigins($existing->field_origins ?? null);
        $update = [];

        foreach (['email' => $email, 'phone' => $person['phone'] ?? null, 'role' => $person['role'] ?? null, 'linkedin_url' => $person['linkedin_url'] ?? null] as $column => $value) {
            if ($value === null) {
                continue;
            }
            $current = $existing->{$column} ?? null;
            if ($current !== null && trim((string) $current) !== '') {
                continue;
            }
            if (($origins[$column] ?? null) === 'declared') {
                continue;
            }
            $update[$column] = $value;
            $origins[$column] = 'collected';
        }

        $sources = $this->decodeSources($existing->sources ?? null);
        if (! in_array($record->source, $sources, true)) {
            $sources[] = $record->source;
            $update['sources'] = json_encode($sources, JSON_THROW_ON_ERROR);
        }

        if ($update === []) {
            return 'skipped_no_change';
        }

        $update['field_origins'] = $this->encodeObject($origins);
        $update['updated_at'] = now();

        DB::table('contacts')->where('id', $existing->id)->update($update);

        return 'updated';
    }

    // ── Tags, run, timeline ─────────────────────────────────────────────────

    /**
     * Tag de provenance `src:scraping-<slug>` — gouverné par le REGISTRE
     * lui-même : un slug qui a passé la porte du registre est, par
     * construction, une source approuvée (audit §C.2 : « un nouveau slug = son
     * tag créé automatiquement, gouverné car le registre est la liste fermée »).
     *
     * @return list<string>
     */
    private function attachSourceTag(ScrapedRecord $record, string $workspaceId, int $companyId): array
    {
        $slug = 'src:scraping-' . $record->source;

        $tagId = DB::table('tags')
            ->where('workspace_id', $workspaceId)
            ->where('slug', $slug)
            ->value('id');

        if ($tagId === null) {
            $sourceName = (string) DB::table('scraping_sources')->where('slug', $record->source)->value('name');
            DB::table('tags')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'slug' => $slug,
                'name' => 'Collecte — ' . $sourceName,
                'category' => 'intent',
                'kind' => 'auto',
                'rules' => '{}',
                'is_locked' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $tagId = DB::table('tags')->where('workspace_id', $workspaceId)->where('slug', $slug)->value('id');
        }

        if ($tagId === null) {
            return [];
        }

        DB::table('company_tag')->insertOrIgnore([
            'company_id' => $companyId,
            'tag_id' => (int) $tagId,
            'workspace_id' => $workspaceId,
            'assigned_at' => now(),
            'assigned_by' => 'auto-rule',
        ]);

        return [$slug];
    }

    /**
     * ⚠️ ICI, `started_at` ET `finished_at` NE SONT PAS LES DEUX BORNES D'UNE
     * MÊME DURÉE — et leur nom le laisse pourtant croire.
     *
     * `started_at` reçoit le `fetchedAt` du PRODUCTEUR (worker Node, fichier
     * d'import) : l'heure à laquelle la donnée a été collectée à la source.
     * `finished_at` reçoit `now()` : l'heure à laquelle le CRM l'a ingérée.
     * Rien ne garantit leur ordre — un import rejoué, un fichier daté à la
     * main, un producteur mal réglé, et `finished_at < started_at`.
     *
     * Constaté en production le 2026-08-16 : 646 lignes de la collecte
     * `implantations-fr-etranger` portent un `fetchedAt` constant (10:00)
     * antérieur de… −11 320 s à leur ingestion (06:51). Ce n'était pas un
     * horodatage faux, c'était ce contresens.
     *
     * Conséquence en aval, corrigée le même jour : `MonitorCampaignProgressJob`
     * sommait `finished_at - started_at` pour alimenter `duration_seconds_used`
     * — un import pouvait donc RETRANCHER de la durée consommée d'une
     * campagne. Toute nouvelle lecture de ces deux colonnes comme une durée
     * doit borner à zéro.
     */
    private function recordRun(
        ScrapedRecord $record,
        string $workspaceId,
        ?int $companyId,
        string $status,
        ScrapeIngestOutcome $outcome,
        string $dedupKey,
    ): void {
        DB::table('scraper_runs')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'company_id' => $companyId,
            'source' => $record->source,
            'status' => $status,
            'started_at' => $record->fetchedAt,
            'finished_at' => now(),
            'dedup_key' => $dedupKey,
            'response_payload' => json_encode($outcome->toArray(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }

    private function recordActivity(ScrapedRecord $record, string $workspaceId, ?int $companyId, bool $pendingMatch): ?int
    {
        $payload = [
            'source' => $record->source,
            'run_id' => $record->runId,
            'persons_count' => count($record->persons),
        ];
        if ($record->evidence !== []) {
            $payload['evidence'] = $record->evidence;
        }
        if ($record->confidence !== null) {
            $payload['confidence'] = $record->confidence;
        }
        if ($pendingMatch) {
            // Matière première de l'écran d'arbitrage : ce qu'on SAIT de
            // l'entreprise, sans avoir jamais deviné à quelle fiche l'attacher.
            $payload['pending_match'] = $record->matchHint;
        }

        try {
            return (int) DB::table('activities')->insertGetId([
                'workspace_id' => $workspaceId,
                'type' => 'scraped',
                'kind' => 'scraped',
                'occurred_at' => $record->fetchedAt ?? now(),
                'external_ref' => 'scrape:' . $record->source . ':' . $record->runId,
                'subject_type' => $companyId === null ? null : 'company',
                'subject_id' => $companyId,
                'title' => 'Collecte — ' . $record->source,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // L'external_ref est UNIQUE par workspace : une collision (rejeu
            // partiel) ne doit pas faire échouer l'ingestion entière.
            return null;
        }
    }

    // ── Utilitaires ─────────────────────────────────────────────────────────

    private function resolveWorkspaceId(): string
    {
        $slug = (string) config('crm.ingest.business_workspace', 'axion-ia');
        $id = DB::table('workspaces')->where('slug', $slug)->value('id');

        if ($id === null) {
            throw ScrapeIngestRejection::unavailable('workspace_missing', "Workspace de destination introuvable : « {$slug} ».");
        }

        return (string) $id;
    }

    /**
     * Même expression SQL que la colonne générée — jamais une approximation
     * PHP qui divergerait au premier accent (leçon du lot L2).
     */
    private function normalizedHash(?string $firstName, string $lastName, int $companyId): string
    {
        $row = DB::selectOne(
            "SELECT encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex') AS h",
            [$firstName, $lastName, $companyId],
        );

        return (string) ($row->h ?? '');
    }

    /**
     * @param  array<string, string>  $person
     * @return array<string, string>
     */
    private function collectedOrigins(array $person, ?string $email): array
    {
        $origins = ['first_name' => 'collected', 'last_name' => 'collected'];
        if ($email !== null) {
            $origins['email'] = 'collected';
        }
        foreach (['phone', 'role', 'linkedin_url'] as $column) {
            if (isset($person[$column])) {
                $origins[$column] = 'collected';
            }
        }

        return $origins;
    }

    /**
     * Encode un tableau associatif en OBJET JSON — `json_encode([])` produit
     * `[]`, or `signals`/`field_origins` sont des objets JSONB : un `[]` qui
     * remplace un `{}` casserait les requêtes `signals->>'clé'` en silence.
     *
     * @param  array<string, mixed>  $data
     */
    private function encodeObject(array $data): string
    {
        return $data === [] ? '{}' : json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, string> */
    private function decodeOrigins(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $origins = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $origins[$key] = $value;
            }
        }

        return $origins;
    }

    /** @return array<string, mixed> */
    private function decodeSignals(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return list<string> */
    private function decodeSources(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
