/**
 * Socle de démo — état global des envois : feu, « Tout arrêter »
 * (`arret-general`, la reprise est une commande locale), compteurs de l'en-tête d'Envoi. Contrat : `backend/openapi/envoi/socle.yaml`.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/socle';
import { demoDb } from '../../base';
import { maintenant } from '../../horloge';
import { api, delai } from '../../outils';

type CompteursEnvoi = components['schemas']['CompteursEnvoi'];

export function compteursEnvoi(): CompteursEnvoi {
  const boites = demoDb.boites.tous();
  const arrete = demoDb.etatEnvois.lire().etat === 'arrete';
  const enCours = demoDb.campagnes.filtrer((c) => c.statut === 'en_cours');
  return {
    envoyes_aujourdhui: boites.reduce((n, b) => n + b.envoyes_aujourdhui, 0),
    plafond_jour: boites.reduce((n, b) => n + b.quota_jour, 0),
    en_file: arrete ? 0 : enCours.reduce((n, c) => n + (c.total - c.envoyes), 0),
    reponses_a_traiter: demoDb.reponses.filtrer((r) => !r.traitee).length,
    alertes_ouvertes: demoDb.notifications.filtrer((n) => n.produit === 'envoi' && n.read_at === null).length,
    campagnes_en_cours: enCours.length,
    boites_pretes: boites.filter((b) => b.statut === 'prete').length,
    boites_total: boites.length,
  };
}

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/etat'), async () => {
    await delai();
    return HttpResponse.json(demoDb.etatEnvois.lire());
  }),

  http.post(api('/envoi/arret-general'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { motif?: unknown };
    const etat = demoDb.etatEnvois.modifier({
      etat: 'arrete',
      feu: 'rouge',
      depuis: maintenant().toISOString(),
      par: demoDb.utilisateurs.tous()[0]?.name ?? null,
      motif: typeof corps.motif === 'string' && corps.motif.trim() !== '' ? corps.motif.trim() : 'Tout arrêter',
    });
    return HttpResponse.json(etat);
  }),

  http.get(api('/envoi/compteurs'), async () => {
    await delai();
    return HttpResponse.json(compteursEnvoi());
  }),
];
