<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * REGISTRE VERSIONNÉ des sources de collecte (lot L3, audit scraping §C.2).
 *
 * Les TTL reprennent EXACTEMENT `DeduplicationService::SOURCE_TTL_DAYS` — le
 * seeder est la nouvelle source de vérité, la constante devient un repli. Les
 * `legal_note` documentent le caractère public de chaque source : c'est
 * l'argumentaire « intérêt légitime B2B » (considérant 47 + doctrine CNIL),
 * écrit UNE fois, prêt pour un contrôle.
 *
 * Idempotent (upsert par slug), ne supprime jamais rien, ne RÉACTIVE jamais
 * une source coupée à la main : `enabled` n'est PAS dans la liste des colonnes
 * mises à jour — un kill-switch posé en prod survit à un re-seed.
 */
class ScrapingSourcesSeeder extends Seeder
{
    /**
     * @var array<string, array{name: string, kind: string, ttl_days: int, dedup_key_pattern: string, legal_note: string, quota_per_day?: int}>
     */
    private const SOURCES = [
        'insee' => [
            'name' => 'INSEE — Sirene',
            'kind' => 'api',
            'ttl_days' => 90,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'Registre public légal (open data Sirene, licence ouverte). Données d\'identification d\'entreprises, aucune PII au-delà des dirigeants publiés au registre.',
        ],
        'annuaire-entreprises' => [
            'name' => 'Annuaire des Entreprises (administration)',
            'kind' => 'api',
            'ttl_days' => 30,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'API publique de l\'administration française (annuaire-entreprises.data.gouv.fr). Représentants légaux publiés au registre : publicité légale.',
        ],
        'bodacc' => [
            'name' => 'BODACC — annonces légales',
            'kind' => 'api',
            'ttl_days' => 30,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'Bulletin officiel des annonces civiles et commerciales : publication légale obligatoire, open data.',
        ],
        'google-maps' => [
            'name' => 'Google Maps (browser)',
            'kind' => 'browser_scrape',
            'ttl_days' => 60,
            'dedup_key_pattern' => 'query',
            'legal_note' => 'Fiches établissement publiées volontairement par les entreprises à des fins de visibilité commerciale.',
        ],
        'pages-jaunes' => [
            'name' => 'Pages Jaunes (browser)',
            'kind' => 'browser_scrape',
            'ttl_days' => 90,
            'dedup_key_pattern' => 'query',
            'legal_note' => 'Annuaire professionnel public : coordonnées publiées volontairement à des fins de prospection commerciale.',
        ],
        'website' => [
            'name' => 'Site web de l\'entreprise (mentions légales)',
            'kind' => 'http_scrape',
            'ttl_days' => 60,
            'dedup_key_pattern' => 'url',
            'legal_note' => 'Mentions légales : publication OBLIGATOIRE (LCEN art. 6-III) des coordonnées de l\'éditeur professionnel.',
        ],
        'google-search' => [
            'name' => 'Google Search (browser)',
            'kind' => 'browser_scrape',
            'ttl_days' => 30,
            'dedup_key_pattern' => 'query',
            'legal_note' => 'Résultats de recherche sur contenus publics indexés.',
        ],
        'direction-finder' => [
            'name' => 'Direction finder (LLM sur pages publiques)',
            'kind' => 'llm_extract',
            'ttl_days' => 90,
            'dedup_key_pattern' => 'url',
            'legal_note' => 'Extraction de dirigeants depuis les pages « équipe / à propos » publiées par l\'entreprise elle-même.',
        ],
        'france-travail' => [
            'name' => 'France Travail — offres',
            'kind' => 'api',
            'ttl_days' => 7,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'API publique des offres d\'emploi : signal de recrutement, aucune PII.',
        ],
        'mesri' => [
            'name' => 'MESRI — établissements',
            'kind' => 'api',
            'ttl_days' => 365,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'Open data enseignement supérieur et recherche.',
        ],
        'crunchbase' => [
            'name' => 'Crunchbase',
            'kind' => 'http_scrape',
            'ttl_days' => 90,
            'dedup_key_pattern' => 'url',
            'legal_note' => 'Profils d\'entreprises publiés à des fins de visibilité (levées de fonds).',
        ],
        'infogreffe' => [
            'name' => 'Infogreffe',
            'kind' => 'http_scrape',
            'ttl_days' => 180,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'Registre du commerce et des sociétés : publicité légale.',
        ],
        'societe-com' => [
            'name' => 'Societe.com',
            'kind' => 'http_scrape',
            'ttl_days' => 180,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'Agrégateur de données légales publiques (RCS, BODACC).',
        ],
        'social-light' => [
            'name' => 'Réseaux sociaux (light)',
            'kind' => 'http_scrape',
            'ttl_days' => 90,
            'dedup_key_pattern' => 'url',
            'legal_note' => 'Pages entreprise publiques, publiées à des fins de visibilité commerciale. Pas de profils personnels.',
        ],
        'mentions-legales' => [
            'name' => 'Mentions légales (waterfall PHP)',
            'kind' => 'http_scrape',
            'ttl_days' => 60,
            'dedup_key_pattern' => 'url',
            'legal_note' => 'Mentions légales : publication OBLIGATOIRE (LCEN art. 6-III) des coordonnées de l\'éditeur professionnel.',
        ],
        'gplaces' => [
            'name' => 'Google Places API',
            'kind' => 'api',
            'ttl_days' => 60,
            'dedup_key_pattern' => 'siren',
            'legal_note' => 'Fiches établissement publiées volontairement par les entreprises. Quota mensuel géré.',
            'quota_per_day' => 300,
        ],
    ];

    public function run(): void
    {
        $now = now();

        foreach (self::SOURCES as $slug => $source) {
            DB::table('scraping_sources')->upsert(
                [[
                    'slug' => $slug,
                    'name' => $source['name'],
                    'kind' => $source['kind'],
                    'enabled' => true,
                    'ttl_days' => $source['ttl_days'],
                    'legal_note' => $source['legal_note'],
                    'dedup_key_pattern' => $source['dedup_key_pattern'],
                    'quota_per_day' => $source['quota_per_day'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['slug'],
                // ⚠️ PAS `enabled` : un kill-switch posé en prod survit au re-seed.
                ['name', 'kind', 'ttl_days', 'legal_note', 'dedup_key_pattern', 'quota_per_day', 'updated_at'],
            );
        }
    }

    /**
     * Le référentiel, pour les tests de gouvernance (même patron que
     * `GovernedTagsSeeder::referential()`).
     *
     * @return array<string, array{name: string, kind: string, ttl_days: int, dedup_key_pattern: string, legal_note: string, quota_per_day?: int}>
     */
    public static function referential(): array
    {
        return self::SOURCES;
    }
}
