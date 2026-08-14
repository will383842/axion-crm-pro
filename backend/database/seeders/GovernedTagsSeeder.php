<?php

namespace Database\Seeders;

use App\Crm\Taxonomy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Référentiel VERSIONNÉ des tags gouvernés (plan CRM §2.2c).
 *
 * Règle de gouvernance : un tag ne se crée JAMAIS « à la volée » pendant une
 * campagne. Il entre par ce référentiel (donc par une PR), avec son namespace
 * et sa catégorie. C'est la seule façon de rester lisible à 100 000+ fiches.
 * Les tags posés ici sont `is_locked = true` : les non-admins ne peuvent ni les
 * modifier ni les supprimer.
 *
 * Ce seeder est IDEMPOTENT (upsert sur `(workspace_id, slug)`) et ne supprime
 * jamais rien : un tag retiré du référentiel doit être traité par une revue
 * explicite, pas par un `php artisan db:seed`.
 *
 * ⚠️ Trois namespaces ne sont PAS énumérables et ne figurent donc pas ici :
 *   - `geo:`        (geo:dept-38, geo:region-ara…) — posé par l'auto-tagger
 *                   depuis les codes INSEE de la fiche ;
 *   - `sect:`       (sect:btp, sect:sante…) — dérivé du code NAF ;
 *   - `cand-offre:` / `cand-zone:` — dérivés des offres publiées et des zones
 *                   saisies par les candidats.
 * Leur GOUVERNANCE est le namespace lui-même (contrôlé), pas la liste.
 */
class GovernedTagsSeeder extends Seeder
{
    /**
     * Tags de l'univers BUSINESS.
     *
     * @var array<string, array{name: string, category: string}>
     */
    private const BUSINESS_TAGS = [
        // Source d'acquisition — SITE (un tag par point de capture de l'audit 1.1)
        'src:site-formulaire-audit' => ['name' => 'Formulaire — audit', 'category' => 'intent'],
        'src:site-formulaire-implementation' => ['name' => 'Formulaire — implémentation', 'category' => 'intent'],
        'src:site-formulaire-formation' => ['name' => 'Formulaire — formation', 'category' => 'intent'],
        'src:site-formulaire-un-a-un' => ['name' => 'Formulaire — un à un', 'category' => 'intent'],
        'src:site-formulaire-devis' => ['name' => 'Formulaire — devis', 'category' => 'intent'],
        'src:site-formulaire-partenariat' => ['name' => 'Formulaire — partenariat', 'category' => 'intent'],
        'src:site-formulaire-presse' => ['name' => 'Formulaire — presse', 'category' => 'intent'],
        'src:site-formulaire-speaker' => ['name' => 'Formulaire — conférence', 'category' => 'intent'],
        'src:site-formulaire-investisseur' => ['name' => 'Formulaire — investisseur', 'category' => 'intent'],
        'src:site-formulaire-support-client' => ['name' => 'Formulaire — support client', 'category' => 'intent'],
        'src:site-formulaire-autre' => ['name' => 'Formulaire — autre', 'category' => 'intent'],
        'src:site-formulaire-podcast' => ['name' => 'Formulaire — podcast', 'category' => 'intent'],
        // Manquaient au premier jet : le namespace `src:` n'est PAS dérivable
        // (à la différence de `geo:` / `sect:`), donc un slug absent d'ici est
        // silencieusement IGNORÉ par `SiteSyncIngestService::resolveTagId()` —
        // la fiche est bien créée, mais elle perd sa provenance, et rien ne le
        // signale. Le test « chaque type de formulaire a un tag de provenance »
        // rougit désormais si l'un des deux référentiels bouge seul.
        'src:site-formulaire-recrutement' => ['name' => 'Formulaire — recrutement', 'category' => 'intent'],
        'src:site-formulaire-simulateur-roi' => ['name' => 'Simulateur de gains', 'category' => 'intent'],
        'src:calendly' => ['name' => 'RDV Calendly', 'category' => 'intent'],
        'src:newsletter' => ['name' => 'Newsletter', 'category' => 'intent'],
        'src:chatbot' => ['name' => 'Chatbot', 'category' => 'intent'],
        'src:avis-client' => ['name' => 'Avis client', 'category' => 'intent'],

        // Source d'acquisition — COLLECTE (multi-valué : une fiche touchée par
        // trois collecteurs porte les trois tags).
        'src:scraping-insee' => ['name' => 'Collecte — INSEE', 'category' => 'intent'],
        'src:scraping-annuaire' => ['name' => 'Collecte — annuaire administration', 'category' => 'intent'],
        'src:scraping-mentions-legales' => ['name' => 'Collecte — mentions légales', 'category' => 'intent'],
        'src:scraping-gplaces' => ['name' => 'Collecte — Google Places', 'category' => 'intent'],
        'src:scraping-gmaps' => ['name' => 'Collecte — Google Maps', 'category' => 'intent'],
        'src:scraping-pagesjaunes' => ['name' => 'Collecte — Pages Jaunes', 'category' => 'intent'],
        'src:scraping-google-search' => ['name' => 'Collecte — Google Search', 'category' => 'intent'],

        // Service d'intérêt
        'svc:audit' => ['name' => 'Intérêt — audit', 'category' => 'intent'],
        'svc:formation' => ['name' => 'Intérêt — formation', 'category' => 'intent'],
        'svc:implementation' => ['name' => 'Intérêt — implémentation', 'category' => 'intent'],
        'svc:conference' => ['name' => 'Intérêt — conférence', 'category' => 'intent'],

        // Taille (doublon volontaire du champ `size_category` : champ pour les
        // règles, tag pour les facettes du constructeur d'audiences).
        'taille:tpe' => ['name' => 'TPE', 'category' => 'size'],
        'taille:pme' => ['name' => 'PME', 'category' => 'size'],
        'taille:eti' => ['name' => 'ETI', 'category' => 'size'],
        'taille:ge' => ['name' => 'Grande entreprise', 'category' => 'size'],
    ];

    /**
     * Tags de l'univers VIVIER.
     *
     * @var array<string, array{name: string, category: string}>
     */
    private const VIVIER_TAGS = [
        // Provenance des candidatures. Elles vivent ici et non dans les tags
        // business parce que `run()` seede chaque liste dans SON workspace :
        // placées côté business, elles n'auraient jamais été pré-créées dans le
        // vivier, seul univers où l'ingestion les pose. `src:` reste catégorisé
        // `intent` — la catégorie suit le NAMESPACE, pas l'univers.
        'src:site-candidature-offre' => ['name' => 'Candidature — offre publiée', 'category' => 'intent'],
        'src:site-candidature-commerciale' => ['name' => 'Candidature — tunnel commercial', 'category' => 'intent'],

        'cand-b2b:0' => ['name' => 'B2B — aucune expérience', 'category' => 'candidate'],
        'cand-b2b:1-3' => ['name' => 'B2B — 1 à 3 ans', 'category' => 'candidate'],
        'cand-b2b:3-5' => ['name' => 'B2B — 3 à 5 ans', 'category' => 'candidate'],
        'cand-b2b:5-plus' => ['name' => 'B2B — plus de 5 ans', 'category' => 'candidate'],
        'cand-ia:oui' => ['name' => 'Familier de l’IA', 'category' => 'candidate'],
        'cand-ia:non' => ['name' => 'Non familier de l’IA', 'category' => 'candidate'],
        'cand-dispo:immediate' => ['name' => 'Disponible immédiatement', 'category' => 'candidate'],
        'cand-dispo:1mois' => ['name' => 'Disponible sous 1 mois', 'category' => 'candidate'],
        'cand-dispo:3mois' => ['name' => 'Disponible sous 3 mois', 'category' => 'candidate'],
        'cand-mobilite:permis' => ['name' => 'Permis de conduire', 'category' => 'candidate'],
        'cand-mobilite:vehicule' => ['name' => 'Véhicule personnel', 'category' => 'candidate'],
        'cand-mobilite:deplacement-ok' => ['name' => 'Accepte les déplacements', 'category' => 'candidate'],
    ];

    public function run(): void
    {
        $vivierId = DB::table('workspaces')
            ->where('slug', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->value('id');

        $businessIds = DB::table('workspaces')
            ->where('slug', '!=', Taxonomy::VIVIER_WORKSPACE_SLUG)
            ->whereNull('deleted_at')
            ->pluck('id');

        foreach ($businessIds as $workspaceId) {
            $this->upsert((string) $workspaceId, self::BUSINESS_TAGS);
        }

        if ($vivierId !== null) {
            $this->upsert((string) $vivierId, self::VIVIER_TAGS);
        }
    }

    /**
     * @param  array<string, array{name: string, category: string}>  $tags
     */
    private function upsert(string $workspaceId, array $tags): void
    {
        $now = now();

        foreach ($tags as $slug => $tag) {
            DB::table('tags')->upsert(
                [[
                    'workspace_id' => $workspaceId,
                    'slug' => $slug,
                    'name' => $tag['name'],
                    'category' => $tag['category'],
                    'kind' => 'auto',
                    'rules' => '{}',
                    'is_locked' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]],
                ['workspace_id', 'slug'],
                ['name', 'category', 'kind', 'is_locked', 'updated_at'],
            );
        }
    }

    /**
     * Le référentiel complet, pour les tests de gouvernance.
     *
     * @return array<string, array{name: string, category: string}>
     */
    public static function referential(): array
    {
        return self::BUSINESS_TAGS + self::VIVIER_TAGS;
    }
}
