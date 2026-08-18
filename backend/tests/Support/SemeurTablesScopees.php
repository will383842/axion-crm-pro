<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * SEMEUR — une ligne réelle dans CHAQUE table portant `workspace_id`.
 *
 * ── Pourquoi c'est le vrai travail ──────────────────────────────────────────
 *
 * Un contrôle d'étanchéité qui ne trouve rien à voir passe toujours. Le plan
 * cite nommément un test d'étanchéité antérieur qui PRÉ-INSÉRAIT la ligne qu'il
 * devait faire produire : il ne testait rien. Le piège symétrique est celui-ci —
 * « aucune ligne visible sans contexte » est vrai par vacuité sur une table
 * vide. Prouver l'étanchéité de 55 tables demande donc d'abord d'y écrire, avec
 * leurs clés étrangères et leurs colonnes obligatoires.
 *
 * ── Comment les lignes sont écrites ─────────────────────────────────────────
 *
 * Par la connexion `pgsql_owner`, en AUTO-COMMIT : la transaction de
 * `RefreshDatabase` n'enveloppe que la connexion par défaut, et des lignes
 * non committées seraient invisibles depuis `pgsql_app`, qui est une AUTRE
 * session. Corollaire non négociable : `nettoyer()` doit être appelé dans un
 * `finally`, sinon ces lignes survivent au test et rougissent n'importe quel
 * autre fichier qui compte des lignes (piège déjà rencontré le 2026-08-16 dans
 * `RlsTest`).
 *
 * On pose aussi `app.current_workspace_id` sur la connexion propriétaire avant
 * d'écrire. Aujourd'hui c'est inutile (le propriétaire `axion` est SUPERUSER,
 * donc BYPASSRLS) ; le jour où il cessera de l'être, `FORCE ROW LEVEL SECURITY`
 * lui appliquera les policies et ce semis échouerait sans cette ligne.
 */
final class SemeurTablesScopees
{
    /**
     * Ordre d'insertion. Les parents d'abord : chaque table n'apparaît qu'après
     * celles dont ses clés étrangères dépendent. Le nettoyage parcourt cette
     * liste à l'envers.
     *
     * ⚠️ Cette liste est comparée au scan de `EtancheiteWorkspace` par le test
     * « le semeur couvre TOUTES les tables scopées » : une table ajoutée par une
     * migration future et non semée ici fait ROUGIR ce test. C'est le seul
     * moyen qu'une table nouvelle ne devienne pas un trou silencieux.
     *
     * @var list<string>
     */
    public const ORDRE = [
        // ── Racines ──────────────────────────────────────────────────────────
        'companies',
        'contacts',
        'tags',
        'candidates',
        'crm_pipelines',
        'pipeline_stages',
        'crm_lost_reasons',
        'deals',
        'campaigns',
        'email_templates',
        'email_sends',
        'email_audiences',
        'linkedin_accounts',
        'media',
        // ── Feuilles ─────────────────────────────────────────────────────────
        'activities',
        'ai_act_register',
        'analytics_attribution',
        'analytics_cohorts',
        'analytics_daily_rollups',
        'analytics_funnels',
        'analytics_kpis',
        'audience_members',
        'business_events',
        'candidate_tag',
        'company_tag',
        'coverage_zones',
        'crm_notes',
        'crm_tasks',
        'dnc_lists',
        'duplicate_flags',
        'email_events',
        'email_inboxes',
        'email_threads',
        'email_verification_logs',
        'email_warmup_pools',
        'health_practitioners',
        'invitations',
        'journalists',
        'linkedin_invitations',
        'linkedin_messages',
        'linkedin_profiles_cache',
        'linkedin_sequences',
        'llm_usage',
        'llm_use_cases',
        'notifications',
        'proxy_providers_config',
        'proxy_usage_log',
        'rgpd_requests',
        'rotations',
        'saved_views',
        'scraper_runs',
        'scraping_campaigns',
        'strategic_keywords',
        'unsubscribes',
        'web_vital_samples',
    ];

    /**
     * Référentiels sans `workspace_id` dont les clés étrangères ont besoin.
     * Créés une fois, idempotents, nettoyés à la fin.
     *
     * @return array{user_id: string, department_code: string, region_code: string, country_code: string}
     */
    public static function assurerReferentiels(Connection $owner): array
    {
        $userId = (string) Str::uuid();
        $owner->table('users')->insert([
            'id' => $userId,
            'email' => 'zz-etancheite-' . Str::random(10) . '@test.invalid',
            'name' => 'ZZ Étanchéité',
        ]);

        // `coverage_zones.department` référence `departments.code`, qui référence
        // `regions.code`, qui référence `countries.code_iso2`. Ces TROIS
        // référentiels sont peuplés par un SEEDER, pas par une migration : sur
        // une base fraîchement migrée ils sont VIDES, et la chaîne de clés
        // étrangères casse au premier maillon.
        $countryCode = 'ZZ';
        $regionCode = 'ZZ';
        $departmentCode = 'ZZ';

        // Code pays JETABLE (« ZZ », réservé par l'ISO 3166 aux usages privés) :
        // créer un vrai « FR » ici polluerait un référentiel que d'autres tests
        // s'attendent à trouver vide ou peuplé par le seeder officiel.
        $owner->table('countries')->insertOrIgnore([
            'code_iso2' => $countryCode,
            'code_iso3' => 'ZZZ',
            'name_fr' => 'ZZ Pays de test',
            'name_en' => 'ZZ Test country',
        ]);
        $owner->table('regions')->insertOrIgnore([
            'code' => $regionCode,
            'country_code' => $countryCode,
            'name' => 'ZZ Région de test',
        ]);
        $owner->table('departments')->insertOrIgnore([
            'code' => $departmentCode,
            'region_code' => $regionCode,
            'name' => 'ZZ Département de test',
        ]);

        return [
            'user_id' => $userId,
            'department_code' => $departmentCode,
            'region_code' => $regionCode,
            'country_code' => $countryCode,
        ];
    }

    /**
     * Sème EXACTEMENT une ligne dans chaque table de `ORDRE`, rattachée à
     * `$workspaceId`.
     *
     * @param  array{user_id: string, department_code: string, region_code: string, country_code: string}  $referentiels
     * @return array<string, int|string> identifiants des lignes parentes, pour diagnostic
     */
    public static function semer(Connection $owner, string $workspaceId, array $referentiels): array
    {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', $workspaceId]);

        $marque = substr(str_replace('-', '', $workspaceId), 0, 8);
        $user = $referentiels['user_id'];
        $ws = $workspaceId;

        $id = [];

        // Sans ce ré-emballage, l'échec dit « SQLSTATE[23502] » sans dire OÙ :
        // sur 55 tables, c'est une heure perdue à chaque évolution de schéma.
        $echec = static function (string $table, Throwable $e): never {
            throw new RuntimeException(
                "Semis impossible dans « {$table} » : " . $e->getMessage()
                . ' — corriger la charge utile dans SemeurTablesScopees::semer(), '
                . 'et surtout ne pas retirer la table du contrôle.',
                0,
                $e,
            );
        };

        $inserer = static function (string $table, array $valeurs) use ($owner, $ws, $echec): void {
            try {
                $owner->table($table)->insert(['workspace_id' => $ws] + $valeurs);
            } catch (Throwable $e) {
                $echec($table, $e);
            }
        };

        // ⚠️ `insertGetId` est réservé aux tables qui ont bien une colonne `id` :
        // `analytics_daily_rollups`, `analytics_kpis`, `candidate_tag`,
        // `company_tag` et `linkedin_profiles_cache` n'en ont pas (clé composite),
        // et Laravel y émettrait un `RETURNING "id"` qui échoue.
        $insererAvecId = static function (string $table, array $valeurs) use ($owner, $ws, $echec): int {
            try {
                return (int) $owner->table($table)->insertGetId(['workspace_id' => $ws] + $valeurs);
            } catch (Throwable $e) {
                $echec($table, $e);
            }
        };

        // ── Racines ──────────────────────────────────────────────────────────
        $id['companies'] = $insererAvecId('companies', [
            'siren' => str_pad((string) random_int(100000000, 999999999), 9, '0'),
            'denomination' => 'ZZ Étanchéité ' . $marque,
        ]);

        $id['contacts'] = $insererAvecId('contacts', [
            'company_id' => $id['companies'],
            'last_name' => 'ZZ Contact ' . $marque,
        ]);

        $id['tags'] = $insererAvecId('tags', [
            'slug' => 'zz-etancheite-' . $marque,
            'name' => 'ZZ Étanchéité ' . $marque,
        ]);

        $id['candidates'] = $insererAvecId('candidates', [
            'last_name' => 'ZZ Candidat ' . $marque,
            'relation_type' => 'candidat_autre',
        ]);

        $id['crm_pipelines'] = $insererAvecId('crm_pipelines', [
            'name' => 'ZZ Pipeline ' . $marque,
            'slug' => 'zz-pipeline-' . $marque,
        ]);

        $id['pipeline_stages'] = $insererAvecId('pipeline_stages', [
            'pipeline_id' => $id['crm_pipelines'],
            'slug' => 'zz-etape-' . $marque,
            'name' => 'ZZ Étape',
        ]);

        $id['crm_lost_reasons'] = $insererAvecId('crm_lost_reasons', [
            'slug' => 'zz-perdu-' . $marque,
            'label' => 'ZZ Motif',
        ]);

        $id['deals'] = $insererAvecId('deals', [
            'company_id' => $id['companies'],
            'pipeline_id' => $id['crm_pipelines'],
            'stage_id' => $id['pipeline_stages'],
        ]);

        $id['campaigns'] = $insererAvecId('campaigns', [
            'name' => 'ZZ Campagne ' . $marque,
            'channel' => 'cold_email',
        ]);

        $id['email_templates'] = $insererAvecId('email_templates', [
            'slug' => 'zz-gabarit-' . $marque,
            'subject' => 'ZZ',
            'body_html' => '<p>ZZ</p>',
            'body_text' => 'ZZ',
        ]);

        $id['email_sends'] = $insererAvecId('email_sends', [
            'contact_id' => $id['contacts'],
            'campaign_id' => $id['campaigns'],
            'template_id' => $id['email_templates'],
            'status' => 'queued',
        ]);

        $id['email_audiences'] = $insererAvecId('email_audiences', [
            'name' => 'ZZ Audience ' . $marque,
        ]);

        $id['linkedin_accounts'] = $insererAvecId('linkedin_accounts', [
            'profile_url' => 'https://linkedin.invalid/in/zz-' . $marque,
        ]);

        $id['media'] = $insererAvecId('media', [
            'name' => 'ZZ Média ' . $marque,
            'media_type' => 'blog',
        ]);

        // ── Feuilles ─────────────────────────────────────────────────────────
        $inserer('activities', [
            'type' => 'note',
            'kind' => 'scraped',
            'contact_id' => $id['contacts'],
            'person_key' => hash('sha256', 'zz-' . $marque),
        ]);

        $inserer('ai_act_register', [
            'system_name' => 'ZZ Système ' . $marque,
            'purpose' => 'ZZ',
            'risk_class' => 'minimal',
        ]);

        $inserer('analytics_attribution', [
            'deal_id' => $id['deals'],
            'touchpoint' => 'zz',
            'occurred_at' => now(),
        ]);

        $inserer('analytics_cohorts', [
            'cohort_period' => now()->startOfMonth()->toDateString(),
            'kind' => 'zz',
            'metric' => 'zz',
        ]);

        $inserer('analytics_daily_rollups', [
            'day' => now()->toDateString(),
            'kind' => 'zz',
        ]);

        $inserer('analytics_funnels', [
            'name' => 'ZZ Entonnoir ' . $marque,
            'steps' => '[]',
        ]);

        $inserer('analytics_kpis', [
            'kpi_key' => 'zz_' . $marque,
        ]);

        $inserer('audience_members', [
            'audience_id' => $id['email_audiences'],
            'company_id' => $id['companies'],
            'contact_id' => $id['contacts'],
        ]);

        $inserer('business_events', [
            'action' => 'zz.etancheite',
            'actor_user_id' => $user,
        ]);

        $inserer('candidate_tag', [
            'candidate_id' => $id['candidates'],
            'tag_id' => $id['tags'],
        ]);

        $inserer('company_tag', [
            'company_id' => $id['companies'],
            'tag_id' => $id['tags'],
        ]);

        $inserer('coverage_zones', [
            'department' => $referentiels['department_code'],
        ]);

        $inserer('crm_notes', [
            'content' => 'ZZ note ' . $marque,
            'contact_id' => $id['contacts'],
            'author_id' => $user,
        ]);

        $inserer('crm_tasks', [
            'title' => 'ZZ tâche ' . $marque,
            'contact_id' => $id['contacts'],
            'assignee_id' => $user,
        ]);

        $inserer('dnc_lists', [
            'name' => 'ZZ Liste ' . $marque,
        ]);

        $inserer('duplicate_flags', [
            'entity_type' => 'company',
            'entity_a_id' => $id['companies'],
            'entity_b_id' => $id['companies'] + 1,
            'similarity' => 0.900,
        ]);

        $inserer('email_events', [
            'email_send_id' => $id['email_sends'],
            'event_type' => 'delivered',
        ]);

        $inserer('email_inboxes', [
            'email' => 'zz-boite-' . $marque . '@test.invalid',
            'provider' => 'zz',
        ]);

        $inserer('email_threads', [
            'contact_id' => $id['contacts'],
            'campaign_id' => $id['campaigns'],
        ]);

        $inserer('email_verification_logs', [
            'email' => 'zz-verif-' . $marque . '@test.invalid',
            'status' => 'valid',
        ]);

        $inserer('email_warmup_pools', [
            'domain' => 'zz-' . $marque . '.test.invalid',
        ]);

        $inserer('health_practitioners', [
            'company_id' => $id['companies'],
            'nom' => 'ZZ Praticien ' . $marque,
            'rpps' => '9' . substr($marque, 0, 9),
        ]);

        $inserer('invitations', [
            'email' => 'zz-invit-' . $marque . '@test.invalid',
            'role_slug' => 'viewer',
            'invited_by' => $user,
            'token_hash' => hash('sha256', 'zz-invit-' . $marque),
            'expires_at' => now()->addDays(7),
        ]);

        $inserer('journalists', [
            'media_id' => $id['media'],
            'company_id' => $id['companies'],
            'last_name' => 'ZZ Journaliste ' . $marque,
        ]);

        $inserer('linkedin_invitations', [
            'account_id' => $id['linkedin_accounts'],
            'contact_id' => $id['contacts'],
            'status' => 'queued',
        ]);

        $inserer('linkedin_messages', [
            'account_id' => $id['linkedin_accounts'],
            'contact_id' => $id['contacts'],
            'kind' => 'message',
            'status' => 'queued',
        ]);

        $inserer('linkedin_profiles_cache', [
            'profile_url' => 'https://linkedin.invalid/in/zz-cache-' . $marque,
            'snapshot' => '{}',
        ]);

        $inserer('linkedin_sequences', [
            'name' => 'ZZ Séquence ' . $marque,
            'steps' => '[]',
        ]);

        $inserer('llm_usage', [
            'use_case_slug' => 'zz-' . $marque,
            'provider' => 'zz',
            'model' => 'zz',
        ]);

        $inserer('llm_use_cases', [
            'slug' => 'zz-' . $marque,
            'primary_provider' => 'zz',
            'model' => 'zz',
        ]);

        $inserer('notifications', [
            'user_id' => $user,
            'type' => 'zz',
            'title' => 'ZZ',
        ]);

        $inserer('proxy_providers_config', [
            'slug' => 'zz-' . $marque,
            'type' => 'datacenter',
        ]);

        $inserer('proxy_usage_log', [
            'provider_slug' => 'zz-' . $marque,
            'endpoint' => 'https://zz.test.invalid',
            'target_host' => 'zz.test.invalid',
        ]);

        $inserer('rgpd_requests', [
            'type' => 'access',
            'subject_email' => 'zz-rgpd-' . $marque . '@test.invalid',
        ]);

        $inserer('rotations', [
            'dimension' => 'proxy',
            'slug' => 'zz-' . $marque,
        ]);

        $inserer('saved_views', [
            'user_id' => $user,
            'entity' => 'companies',
            'name' => 'ZZ Vue ' . $marque,
        ]);

        $inserer('scraper_runs', [
            'source' => 'zz',
            'status' => 'pending',
            'company_id' => $id['companies'],
        ]);

        $inserer('scraping_campaigns', [
            'created_by' => $user,
            'name' => 'ZZ Collecte ' . $marque,
        ]);

        $inserer('strategic_keywords', [
            'keyword' => 'zz-' . $marque,
        ]);

        $inserer('unsubscribes', [
            'email' => 'zz-desabo-' . $marque . '@test.invalid',
            'source' => 'zz',
        ]);

        $inserer('web_vital_samples', [
            'metric' => 'LCP',
            'value' => 1234.5,
            'user_id' => $user,
        ]);

        return $id;
    }

    /**
     * Retire TOUT ce que `semer()` et `assurerReferentiels()` ont écrit.
     *
     * ⚠️ À appeler dans un `finally`. Les lignes sont committées : un seul
     * chemin qui l'oublie les laisse en place pour le reste de l'exécution, et
     * comme Pest tire un ordre au hasard, ce n'est jamais le même fichier qui
     * tombe — le symptôme ressemble à de la fragilité alors que la cause est ici.
     *
     * @param  list<string>  $workspaceIds
     * @param  array{user_id: string, department_code: string, region_code: string, country_code: string}  $referentiels
     */
    public static function nettoyer(Connection $owner, array $workspaceIds, array $referentiels): void
    {
        $owner->select('SELECT set_config(?, ?, false)', ['app.current_workspace_id', '']);

        foreach (array_reverse(self::ORDRE) as $table) {
            $owner->table($table)->whereIn('workspace_id', $workspaceIds)->delete();
        }

        $owner->table('workspaces')->whereIn('id', $workspaceIds)->delete();
        $owner->table('coverage_zones')->where('department', $referentiels['department_code'])->delete();
        $owner->table('departments')->where('code', $referentiels['department_code'])->delete();
        $owner->table('regions')->where('code', $referentiels['region_code'])->delete();
        $owner->table('countries')->where('code_iso2', $referentiels['country_code'])->delete();
        $owner->table('users')->where('id', $referentiels['user_id'])->delete();
    }
}
