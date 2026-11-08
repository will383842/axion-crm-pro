/**
 * Faux serveur de la section « Technique » (DM1-TEC) : vivier Roumanie,
 * historique des collectes (annuler, relancer), santé du système, moteurs d'IA,
 * serveurs relais (tester), rotation des accès, registre de l'IA.
 *
 * Toutes les routes existent déjà sur le vrai serveur : la démo ne fait que les
 * servir. Seule la carte « Sauvegardes » est nouvelle ; elle ne s'affiche que
 * si le résumé de santé porte `sauvegardes` (ce que le vrai serveur n'envoie pas).
 *
 * Rien ne se supprime : annuler pose le statut `cancelled`, relancer ajoute un passage.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import {
  genererTechniqueDemo,
  type CasUsageIa,
  type EntreeRegistreIa,
  type FournisseurRelais,
  type LigneRoumanie,
  type PassageCollecte,
  type RotationAcces,
  type ReperesSante,
} from '../../donnees/crm/technique';
import { maintenant } from '../../horloge';
import { api, delai, erreur, paginer, repondreEtatForce } from '../../outils';

/** Branche la zone `technique` sur `demoDb` (une seule fois ; les gestes de session sont rejoués). */
function zone(): void {
  demoDb.etendre('technique', (alea) => genererTechniqueDemo(alea));
}
zone();

const roumanie = () => demoDb.zone<LigneRoumanie>('technique', 'roumanie');
const passages = () => demoDb.zone<PassageCollecte>('technique', 'passages');
const casUsage = () => demoDb.zone<CasUsageIa>('technique', 'casUsage');
const relais = () => demoDb.zone<FournisseurRelais>('technique', 'relais');
const rotations = () => demoDb.zone<RotationAcces>('technique', 'rotations');
const registre = () => demoDb.zone<EntreeRegistreIa>('technique', 'registre');
const sante = () => demoDb.zone<ReperesSante & { id: number }>('technique', 'sante');

const SANS_ARCHIVAGE: ReperesSante['archivages'] = {};

export const handlers: HttpHandler[] = [
  // ── Roumanie : le vivier, filtré par nature et par statut ───────────────
  http.get(api('/companies'), async ({ request }) => {
    const url = new URL(request.url);
    // Cette zone ne sert que le vivier Roumanie ; les autres listes ne sont pas les siennes.
    if (url.searchParams.get('filter[country_code]') !== 'RO') return undefined;
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0 } });
    if (force) return force;
    const nature = url.searchParams.get('filter[entity_nature]');
    const statut = url.searchParams.get('filter[prospection_status]');
    const liste = roumanie().filtrer(
      (l) => (nature === null || nature === '' || l.entity_nature === nature) && (statut === null || statut === '' || l.prospection_status === statut),
    );
    const page = paginer(liste, request, 50);
    return HttpResponse.json({ data: page.data, meta: { total: liste.length, current_page: page.meta.current_page, last_page: page.meta.last_page } });
  }),

  // ── Historique des collectes ────────────────────────────────────────────
  http.get(api('/scraper-runs'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0 } });
    if (force) return force;
    const liste = [...passages().tous()].sort((a, b) => b.id - a.id);
    const page = paginer(liste, request, 50);
    return HttpResponse.json({ data: page.data, meta: { total: liste.length, per_page: page.meta.per_page, current_page: page.meta.current_page, last_page: page.meta.last_page } });
  }),

  http.post(api('/scraper-runs/:id/cancel'), async ({ params }) => {
    await delai();
    const passage = passages().trouver(String(params['id']));
    if (!passage) return erreur(404, 'introuvable', 'Ce passage n’existe pas.');
    if (passage.status !== 'pending' && passage.status !== 'running') {
      return erreur(409, 'deja_termine', 'Ce passage est déjà terminé : il ne peut plus être annulé.');
    }
    const modifie = passages().modifier(passage.id, { status: 'cancelled', finished_at: maintenant().toISOString() });
    return HttpResponse.json(modifie);
  }),

  http.post(api('/scraper-runs/:id/retry'), async ({ params }) => {
    await delai();
    const passage = passages().trouver(String(params['id']));
    if (!passage) return erreur(404, 'introuvable', 'Ce passage n’existe pas.');
    if (passage.status === 'pending' || passage.status === 'running') {
      return erreur(409, 'en_cours', 'Ce passage est encore en cours : rien à relancer.');
    }
    const nouveau = passages().ajouter({
      ...passage,
      id: passages().prochainId(),
      status: 'running',
      error: null,
      latency_ms: null,
      started_at: maintenant().toISOString(),
      finished_at: null,
      response_payload: { companies_created: 0, companies_target: passage.response_payload.companies_target },
    });
    return HttpResponse.json(nouveau, { status: 201 });
  }),

  // ── Santé du système (avec la carte Sauvegardes, annoncée par `sauvegardes`) ──
  http.get(api('/observability/summary'), async ({ request }) => {
    await delai();
    const vide = {
      data: {
        waterfall_errors_24h: 0,
        hunter_quota_month: { used: 0, soft_limit: 500, percent: 0 },
        google_places_quota: { used: 0, soft_limit: 12000, percent: 0, pending_companies: 0 },
        archive_reasons: SANS_ARCHIVAGE,
        audience_failures_7d: 0,
        recent_events: [],
      },
    };
    const force = await repondreEtatForce(request, vide);
    if (force) return force;
    const repere = sante().tous()[0];
    if (!repere) return HttpResponse.json(vide);
    return HttpResponse.json({
      data: {
        waterfall_errors_24h: repere.erreurs_24h,
        hunter_quota_month: repere.quota_hunter,
        google_places_quota: repere.quota_places,
        archive_reasons: repere.archivages,
        audience_failures_7d: repere.echecs_audience_7j,
        recent_events: repere.evenements,
        sauvegardes: repere.sauvegardes,
      },
    });
  }),

  // ── Moteurs d'IA ────────────────────────────────────────────────────────
  http.get(api('/llm/use-cases'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: casUsage().tous() });
  }),

  http.get(api('/llm/usage/summary'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { summary: { total_eur: 0, tokens_in: 0, tokens_out: 0, by_provider: {}, by_use_case: {} } });
    if (force) return force;
    const parCas: Record<string, { cost_eur: number; tokens_in: number; tokens_out: number; calls: number }> = {};
    const parFournisseur: Record<string, number> = {};
    let total = 0;
    let entree = 0;
    let sortie = 0;
    for (const u of casUsage().tous()) {
      if (!u.enabled) continue;
      const calls = 180 + u.id * 95;
      const cout = Math.round(u.cost_cap_eur * (0.35 + (u.id % 4) * 0.12) * 100) / 100;
      const tin = calls * 420;
      const tout = calls * 130;
      parCas[u.slug] = { cost_eur: cout, tokens_in: tin, tokens_out: tout, calls };
      parFournisseur[u.primary_provider] = Math.round(((parFournisseur[u.primary_provider] ?? 0) + cout) * 100) / 100;
      total += cout;
      entree += tin;
      sortie += tout;
    }
    return HttpResponse.json({ summary: { total_eur: Math.round(total * 100) / 100, tokens_in: entree, tokens_out: sortie, by_provider: parFournisseur, by_use_case: parCas } });
  }),

  // ── Serveurs relais ─────────────────────────────────────────────────────
  http.get(api('/proxy-providers'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: relais().tous() });
  }),

  http.post(api('/proxy-providers/:id/test'), async ({ params }) => {
    await delai();
    const fournisseur = relais().trouver(String(params['id']));
    if (!fournisseur) return erreur(404, 'introuvable', 'Ce fournisseur n’existe pas.');
    // Le résultat est fixe : le mobile reste indisponible, les autres répondent.
    const sain = fournisseur.enabled && fournisseur.type !== 'mobile';
    relais().modifier(fournisseur.id, { last_health_check_at: maintenant().toISOString(), last_health_status: sain ? 'healthy' : 'unhealthy' });
    return HttpResponse.json({ healthy: sain });
  }),

  // ── Rotation des accès ──────────────────────────────────────────────────
  http.get(api('/rotations'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: rotations().tous() });
  }),

  // ── Registre de l'IA ────────────────────────────────────────────────────
  http.get(api('/ai-act/register'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: registre().tous() });
  }),
];
