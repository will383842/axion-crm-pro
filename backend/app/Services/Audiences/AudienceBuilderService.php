<?php

namespace App\Services\Audiences;

use App\Jobs\RefreshAudienceChunkJob;
use App\Models\AudienceMember;
use App\Models\Company;
use App\Models\EmailAudience;
use App\Services\Triage\TriageAutoService;
use App\Support\AuditLogger;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AudienceBuilderService
{
    public const WHITELIST_FIELDS = [
        'prospection_status', 'department_code', 'region_code', 'commune_code',
        'size_category', 'sector_main', 'priority', 'quality_score',
        'tags', 'has_email', 'enriched_at', 'best_email_confidence',
    ];

    public const WHITELIST_OPS = [
        'eq', 'neq', 'in', 'not_in', 'gt', 'lt', 'gte', 'lte',
        'contains_any', 'is_null', 'is_not_null',
    ];

    private const REFRESH_CHUNK_SIZE = 500;

    /**
     * Sprint H5 — Au delà de ce seuil, on bascule en Bus::batch parallèle
     * (10 workers Horizon supervisor audiences-refresh). En dessous, refresh
     * inline (fast path, évite overhead batch).
     */
    private const BATCH_THRESHOLD = 5000;

    private const BATCH_CHUNK_SIZE = 5000;

    /**
     * @return array{companies: int, contacts: int}
     */
    public function preview(string $workspaceId, array $criteria): array
    {
        $query = $this->buildQuery($workspaceId, $criteria);

        $companies = $query->count();
        // Sprint H8 — contacts contactables (valid|catchall|unknown) + comptage
        // additionnel des companies ayant un email_generic (sans contact dédié)
        $contactableCompanyIds = (clone $query)->select('id');
        $contacts = DB::table('contacts')
            ->whereIn('company_id', $contactableCompanyIds)
            ->whereIn('email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
            ->count();
        $companyOnlyEmails = (clone $query)
            ->whereNotNull('email_generic')
            ->whereDoesntHave('contacts', fn ($q) => $q->whereIn(
                'email_status',
                TriageAutoService::CONTACTABLE_EMAIL_STATUSES,
            ))
            ->count();

        return [
            'companies' => $companies,
            'contacts' => $contacts + $companyOnlyEmails,
        ];
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

        if ($total > self::BATCH_THRESHOLD) {
            $this->refreshViaBatch($audience, $total);

            return;
        }

        DB::transaction(function () use ($audience) {
            AudienceMember::where('audience_id', $audience->id)->delete();
        });

        $total = 0;
        $query->chunkById(self::REFRESH_CHUNK_SIZE, function ($companies) use ($audience, &$total) {
            $rows = [];
            $companyIds = $companies->pluck('id')->all();

            // Sprint H8 — élargissement aux contacts contactables (valid|catchall|unknown).
            // Si aucun contact contactable mais email_generic présent → company-only entry.
            $contactsByCompany = DB::table('contacts')
                ->whereIn('company_id', $companyIds)
                ->whereIn('email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
                ->select('id', 'company_id')
                ->get()
                ->groupBy('company_id');

            foreach ($companies as $company) {
                $contacts = $contactsByCompany->get($company->id, collect());
                if ($contacts->isEmpty()) {
                    // Company-level entry (utile si email_generic présent)
                    $rows[] = [
                        'audience_id' => $audience->id,
                        'company_id' => $company->id,
                        'contact_id' => null,
                        'workspace_id' => $audience->workspace_id,
                        'added_at' => now(),
                    ];
                } else {
                    foreach ($contacts as $contact) {
                        $rows[] = [
                            'audience_id' => $audience->id,
                            'company_id' => $company->id,
                            'contact_id' => $contact->id,
                            'workspace_id' => $audience->workspace_id,
                            'added_at' => now(),
                        ];
                    }
                }
            }
            if (! empty($rows)) {
                DB::table('audience_members')->insertOrIgnore($rows);
                $total += count($rows);
            }
        });

        $audience->update([
            'member_count' => AudienceMember::where('audience_id', $audience->id)->count(),
            'refreshed_at' => now(),
        ]);

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

        $chunks = (int) ceil($total / self::BATCH_CHUNK_SIZE);
        $jobs = [];
        for ($i = 0; $i < $chunks; $i++) {
            $jobs[] = new RefreshAudienceChunkJob(
                audienceId: $audience->id,
                offset: $i * self::BATCH_CHUNK_SIZE,
                limit: self::BATCH_CHUNK_SIZE,
            );
        }

        Bus::batch($jobs)
            ->name("audience-refresh-{$audience->id}")
            ->onQueue('audiences-refresh')
            ->allowFailures()
            ->finally(function (Batch $batch) use ($audience) {
                $audience->refresh();
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
                self::validerCondition($ou, $cond);
            }
        }
    }

    /**
     * @param  array<mixed>  $cond
     *
     * @throws CritereAudienceInvalide
     */
    private static function validerCondition(string $ou, array $cond): void
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

        // `has_email` n'admet que `eq`. Avec tout autre opérateur,
        // `buildPositive()` rendait null — et « ceux qui ont un e-mail »
        // devenait « tout le monde », fiches sans aucune adresse comprises.
        if ($field === 'has_email' && $op !== 'eq') {
            throw CritereAudienceInvalide::parce(
                $ou . ' : le champ has_email n admet que l operateur eq, recu ' . self::citer($op),
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
        $isRealColumn = ! in_array($field, ['tags', 'has_email'], true);

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
        if ($field === 'has_email') {
            // Sprint H8 — élargi : tout email contactable OU email_generic
            $hasContact = $company->contacts()
                ->whereIn('email_status', TriageAutoService::CONTACTABLE_EMAIL_STATUSES)
                ->exists();
            $hasGeneric = ! empty($company->email_generic);
            $hasEmail = $hasContact || $hasGeneric;

            return $hasEmail === (bool) $value;
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
