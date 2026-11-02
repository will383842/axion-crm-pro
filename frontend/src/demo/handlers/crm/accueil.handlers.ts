/**
 * Démo — tableau de bord du CRM : `GET /dashboard/stats`, calculé sur le jeu
 * factice (jamais de chiffre inventé en dur : les tuiles suivent les données).
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { api, delai, repondreEtatForce } from '../../outils';

function statistiques() {
  const entreprises = demoDb.entreprises.tous();
  const total = entreprises.length;
  const parTaille: Record<string, number> = { tpe: 0, pme: 0, eti: 0, grand_groupe: 0 };
  for (const e of entreprises) parTaille[e.size_category] = (parTaille[e.size_category] ?? 0) + 1;
  const enrichies = entreprises.filter((e) => e.enriched_at !== null).length;
  const joignables = entreprises.filter((e) => e.email_generic !== '').length;
  const qualite = total === 0 ? null : Math.round(entreprises.reduce((s, e) => s + e.quality_score, 0) / total);
  return {
    companies_total: total,
    size_distribution: parTaille,
    companies_enriched: enrichies,
    prospects_joignables: joignables,
    prospects_joignables_idf: entreprises.filter((e) => e.email_generic !== '' && ['75', '77', '78', '91', '92', '93', '94', '95'].includes(e.department_code)).length,
    quality_avg: qualite,
    quality_a_recalculer_pct: 0,
  };
}

export const handlers: HttpHandler[] = [
  http.get(api('/dashboard/stats'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { ...statistiques(), companies_total: 0, size_distribution: {} });
    if (force) return force;
    return HttpResponse.json(statistiques());
  }),
];
