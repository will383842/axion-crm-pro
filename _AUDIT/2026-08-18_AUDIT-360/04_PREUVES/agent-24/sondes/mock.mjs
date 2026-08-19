// AGENT 24 — jeu de doublures d'API partagé par toutes les sondes.
// Méthode reprise de l'agent 23 (04_PREUVES/agent-23/sondes/) : on mocke
// l'API pour mesurer la NAVIGATION et les PARCOURS, pas la session — la
// console réelle est murée par A07-001 / D22-001 (2FA non enrôlable).
// AUCUN fichier du produit n'est modifié ; serveur = `vite` sur le code de main.

export const USER = {
  id: 'u1', email: 'a24@axion.local', name: 'Agent 24', current_workspace_id: 'ws1',
  totp_enabled_at: '2026-01-01T00:00:00Z',
  first_login_completed_at: '2026-01-01T00:00:00Z',
  onboarding_tour_completed_at: '2026-01-01T00:00:00Z', // visite guidée déjà faite : elle ne gêne pas la mesure
};

const COMPANY = (i) => ({
  id: i, siren: String(100000000 + i), siret: null,
  denomination: `ENTREPRISE ${i} SAS`, name: `ENTREPRISE ${i} SAS`,
  naf_code: '6201Z', naf_label: 'Programmation informatique',
  sector: 'tech', size_bucket: 'pme', employees: 42,
  city: 'GRENOBLE', postal_code: '38000', department_code: '38', region_code: '84',
  country: 'FR', website: `https://ent${i}.fr`, email: `contact@ent${i}.fr`, phone: '0400000000',
  prospection_status: 'nouveau', relation_type: 'prospect', lifecycle_stage: 'nouveau',
  score: 55, enriched_at: '2026-08-01T10:00:00Z', is_obsolete: false,
  created_at: '2026-07-01T10:00:00Z', updated_at: '2026-08-01T10:00:00Z',
  contacts_count: 2, tags: [],
});

const CONTACT = (i) => ({
  id: i, company_id: 1, first_name: `Prenom${i}`, last_name: `NOM${i}`,
  full_name: `Prenom${i} NOM${i}`, email: `p${i}@ent1.fr`, email_status: 'valid', email_score: 90,
  phone: '0600000000', title: 'Directeur', role: 'ceo', person_key: null,
  company: { id: 1, denomination: 'ENTREPRISE 1 SAS', siren: '100000001' },
  created_at: '2026-07-01T10:00:00Z',
});

const page = (items, total) => ({
  data: items,
  meta: { current_page: 1, last_page: Math.ceil(total / 20), per_page: 20, total, from: 1, to: items.length },
  links: {},
});

/** Doublures. Toute route non couverte renvoie un 200 générique vide (jamais un échec réseau,
 *  pour ne PAS re-mesurer D22-002 qui est déjà ouvert). */
export async function poserLesDoublures(p, { consoleV2 = true, journal = [] } = {}) {
  const j = (m, u) => journal.push(`${m} ${u.replace(/^https?:\/\/[^/]+/, '')}`);

  const rep = (route, body, status = 200) => {
    j(route.request().method(), route.request().url());
    return route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
  };

  await p.route('**/sanctum/csrf-cookie', r => r.fulfill({ status: 204, body: '' }));
  await p.route('**/api/v1/**', async (route) => {
    const url = new URL(route.request().url());
    const path = url.pathname.replace('/api/v1', '');
    const m = route.request().method();

    if (path === '/auth/me') return rep(route, { user: USER, roles: ['owner'] });
    if (path === '/config/features') return rep(route, { console_v2: consoleV2, universes: { business: true, vivier: true } });
    // Forme EXACTE du bouchon réel `routes/api.php:86-99`, valeurs non nulles.
    if (path === '/dashboard/stats') return rep(route, {
      companies_total: 1319, companies_enriched_24h: 42, contacts_qualified: 320,
      scraper_runs_24h: 12, llm_cost_eur_month: 12.3,
      quality_distribution: { complete: 300, partielle: 700, basique: 319 },
      size_distribution: { artisan: 100, tpe: 400, pme: 600, eti: 200, grande_entreprise: 19 },
    });
    if (path === '/referentiels/geo') return rep(route, { departments: [{ code: '38', name: 'Isère' }, { code: '75', name: 'Paris' }], regions: [{ code: '84', name: 'Auvergne-Rhône-Alpes' }], sectors: [{ code: 'tech', label: 'Tech' }], sizes: [] });
    if (path === '/companies/export') return rep(route, { ok: true });
    if (path === '/companies') {
      if (m === 'POST') return rep(route, { data: COMPANY(999) }, 201);
      const per = Number(url.searchParams.get('per_page') ?? 20);
      return rep(route, page(Array.from({ length: Math.min(per, 20) }, (_, k) => COMPANY(k + 1)), 137));
    }
    if (/^\/companies\/\d+$/.test(path)) return rep(route, { data: { ...COMPANY(Number(path.split('/')[2])), contacts: [CONTACT(1), CONTACT(2)] } });
    if (/^\/companies\/\d+\/enrich$/.test(path)) return rep(route, { ok: true });
    if (path === '/companies/tags/bulk') return rep(route, { ok: true, affected: 20 });
    if (path === '/contacts') return rep(route, page(Array.from({ length: 20 }, (_, k) => CONTACT(k + 1)), 320));
    if (/^\/contacts\/\d+$/.test(path)) return rep(route, { data: CONTACT(Number(path.split('/')[2])) });
    // Contrats EXACTS relevés dans frontend/src/features/crm-console/types.ts
    if (path === '/crm/contacts-hub/counts') return rep(route, { total: 42, by_relation_type: { prospect: 30, client: 7, presse_media: 2, partenaire: 1, investisseur: 1, conference: 0, newsletter: 1, fournisseur: 0 }, by_lifecycle_stage: { nouveau: 20, qualifie: 10, opportunite: 3, client: 7, dormant: 2, perdu: 0 }, computed_at: '2026-08-19T10:00:00Z', fresh_for_seconds: 60 });
    if (path === '/crm/contacts-hub') return rep(route, {
      data: Array.from({ length: 10 }, (_, k) => ({
        id: k + 1, siren: String(100000001 + k), denomination: `ENTREPRISE ${k + 1} SAS`,
        relation_type: 'prospect', lifecycle_stage: 'nouveau', legal_basis: 'legitimate_interest_b2b',
        city_name: 'GRENOBLE', department_code: '38', size_category: 'pme',
        email_generic: `contact@ent${k + 1}.fr`, updated_at: '2026-08-10T10:00:00Z', tags: ['sect:tech'],
        contacts: [{ id: k + 1, first_name: 'Prenom1', last_name: 'NOM1', email: 'p1@ent1.fr', phone: null, person_key: null }],
      })),
      meta: { per_page: 25, next_cursor: null, prev_cursor: null, has_more: false },
    });
    if (path === '/crm/candidates/counts') return rep(route, { total: 11, by_relation_type: { candidat_commercial: 4, candidat_video: 2, candidat_tech: 4, candidat_autre: 1 }, by_lifecycle_stage: { nouveau: 5, preselection: 2, entretien: 1, retenu: 0, vivier: 3, refuse: 0 } });
    if (path === '/crm/candidates') return rep(route, { data: [{ id: 1, first_name: 'Cand', last_name: 'IDAT', email: 'c@x.fr', phone: null, relation_type: 'candidat_tech', lifecycle_stage: 'entretien', offer_slug: null, source: 'site', consent_version: 'careers-v2-2026-08-13', consent_vivier_at: '2026-08-01T10:00:00Z', derniere_interaction_at: '2026-08-10T10:00:00Z', purge_prevue_le: '2028-08-01', cv_ref: null, opt_out: false, person_key: null, tags: [] }], meta: { per_page: 25, next_cursor: null, prev_cursor: null, has_more: false } });
    if (path === '/crm/arbitrage') return rep(route, { data: [{ activity_id: 1, kind: 'form_submission', title: 'Formulaire contact', occurred_at: '2026-08-18T09:00:00Z', external_ref: 'site:submission:abc', person_key: null, pending_match: { denomination: 'ACME', postcode: '38000', city: 'GRENOBLE', website: 'https://acme.fr', email: 'x@y.fr', first_name: 'Jean', last_name: 'ACME', phone: '0400000000' } }], meta: { total: 1, per_page: 50 } });
    if (/^\/crm\/persons\/.+\/timeline$/.test(path)) return rep(route, {
      person_key: 'pk-demo',
      universes: { business: { accessible: true, exists: true }, vivier: { accessible: true, exists: false } },
      subjects: [{ universe: 'business', type: 'contact', id: 7, first_name: 'Marie', last_name: 'DUPONT', email: 'marie@dupont.fr', company: { id: 1, denomination: 'ENTREPRISE 1 SAS', siren: '100000001' } }],
      data: [{ id: 1, universe: 'business', kind: 'form_submission', title: 'Formulaire contact', occurred_at: '2026-08-18T09:00:00Z', external_ref: 'site:submission:abc', subject_type: 'contact', subject_id: 7 }],
    });
    // Contrat EXACT : CoveragePage.tsx:7 et :31 → `{ cells: Cell[] }`, Cell = { code, name, total, complete, partial }
    if (path === '/coverage') return rep(route, {
      cells: [
        { code: '38', name: 'Isère', total: 120, complete: 40, partial: 30, lat: 45.19, lon: 5.72 },
        { code: '75', name: 'Paris', total: 900, complete: 500, partial: 200, lat: 48.85, lon: 2.35 },
        { code: '69', name: 'Rhône', total: 300, complete: 100, partial: 80, lat: 45.76, lon: 4.83 },
      ],
    });
    if (path === '/coverage/launch' || path === '/coverage/enrich') return rep(route, { ok: true, run_id: 1 });
    if (path === '/campaigns') {
      if (m === 'POST') return rep(route, { data: { id: 7, name: 'X', status: 'draft' } }, 201);
      return rep(route, page([{ id: 7, name: 'Collecte Isère', status: 'running', budget_eur: 50, spent_eur: 12, created_at: '2026-08-10T10:00:00Z', progress: 40, zones: ['38'], sources: ['pages_jaunes'] }], 1));
    }
    if (/^\/campaigns\/\d+$/.test(path)) return rep(route, { data: { id: 7, name: 'Collecte Isère', status: 'running', budget_eur: 50, spent_eur: 12, zones: ['38'], sources: ['pages_jaunes'], created_at: '2026-08-10T10:00:00Z', runs: [] } });
    if (/^\/campaigns\/\d+\/stats$/.test(path)) return rep(route, { data: { companies_found: 120, runs_total: 3, runs_ok: 2, spent_eur: 12 } });
    if (/^\/campaigns\/\d+\/(start|pause|resume|cancel)$/.test(path)) return rep(route, { ok: true });
    if (path === '/scraper-runs') return rep(route, page([{ id: 1, source: 'pages_jaunes', status: 'completed', started_at: '2026-08-10T10:00:00Z', finished_at: '2026-08-10T10:05:00Z', records_found: 120, department_code: '38' }], 1));
    if (path === '/media') return rep(route, page([{ id: 1, name: 'Le Dauphiné', family: 'presse', type: 'quotidien', department_code: '38', website: 'https://x.fr' }], 1));
    if (/^\/media\/\d+$/.test(path)) return rep(route, { data: { id: 1, name: 'Le Dauphiné', family: 'presse', type: 'quotidien', department_code: '38', website: 'https://x.fr', journalists: [] } });
    if (path === '/journalists') return rep(route, page([{ id: 1, first_name: 'Jean', last_name: 'PRESSE', email: 'j@x.fr', media: { id: 1, name: 'Le Dauphiné' }, opt_out: false }], 1));
    if (path === '/tags') {
      if (m === 'POST') return rep(route, { data: { id: 9, name: 'neuf', category: 'custom' } }, 201);
      return rep(route, { data: [{ id: 1, name: 'sect:tech', category: 'sector', description: '', companies_count: 12 }, { id: 2, name: 'geo:38', category: 'geo', description: '', companies_count: 5 }] });
    }
    if (path === '/audiences') {
      if (m === 'POST') return rep(route, { data: { id: 3, name: 'A' } }, 201);
      return rep(route, page([{ id: 3, name: 'Prospects Isère', members_count: 120, auto_refresh: false, updated_at: '2026-08-10T10:00:00Z', criteria: {} }], 1));
    }
    if (path === '/audiences/preview') return rep(route, { data: { count: 120, sample: [] }, count: 120 });
    if (/^\/audiences\/\d+$/.test(path)) return rep(route, { data: { id: 3, name: 'Prospects Isère', members_count: 120, auto_refresh: false, criteria: {}, updated_at: '2026-08-10T10:00:00Z' } });
    if (/^\/audiences\/\d+\/members$/.test(path)) return rep(route, page([COMPANY(1)], 120));
    if (path === '/users') return rep(route, { data: [{ id: 'u1', name: 'Agent 24', email: 'a24@axion.local', roles: ['owner'], last_login_at: null, first_login_completed_at: '2026-01-01T00:00:00Z' }] });
    if (path === '/workspace') return rep(route, { data: { id: 'ws1', name: 'Axion', slug: 'axion', monthly_budget_eur: 500, settings: {} } });
    if (path === '/notifications') return rep(route, { data: [] }); // conforme au contrôleur réel : liste vide EN DUR
    if (path === '/search') return rep(route, {
      companies: [{ id: 42, siren: '123456789', denomination: 'DUPONT SAS' }],
      contacts: [{ id: 7, first_name: 'Marie', last_name: 'Dupont', email: 'marie@dupont.fr', company_id: 42 }],
      tags: [],
    });
    if (path === '/rgpd/requests') return rep(route, page([{ id: 1, subject_email: 'x@y.fr', article: '17', status: 'pending', created_at: '2026-08-10T10:00:00Z' }], 1));
    if (path === '/ai-act/register') return rep(route, { data: [] });
    // ⚠️ On sert ici la charge AVEC le champ `action`, que le contrôleur réel
    // NE rend JAMAIS (la colonne s'appelle `event_type`). Sans cela l'écran
    // d'accueil plante et AUCUN parcours n'est mesurable — c'est précisément
    // le constat D24-001, mesuré séparément par `accueil-plante.mjs`.
    if (path === '/audit-logs') return rep(route, page([{ id: 1, action: 'company.updated', event_type: 'PUT', path: 'api/v1/companies/1', ip: '127.0.0.1', actor_name: 'Will', severity: 'info', created_at: '2026-08-10T10:00:00Z', current_hash: 'x' }], 1));
    if (path === '/observability/summary') return rep(route, { data: { hunter: { used: 1, limit: 100 }, google_places: { used: 0, limit: 100 }, audience_refresh_failures: [], waterfall_errors: [], archives_by_reason: [], business_events: [] } });
    if (path === '/llm/use-cases') return rep(route, { data: [{ id: 1, slug: 'x', name: 'X', provider: 'openai', model: 'gpt' }] });
    if (path === '/llm/usage/summary') return rep(route, { data: { total_cost_eur: 12.3, by_provider: [] } });
    if (path === '/proxy-providers') return rep(route, { data: [{ id: 1, name: 'P', status: 'ok' }] });
    if (path === '/rotations') return rep(route, { data: [{ id: 1, dimension: 'ip', strategy: 'round_robin' }] });
    if (path === '/saved-views') return rep(route, { data: [] });

    return rep(route, { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 20 } });
  });
}
