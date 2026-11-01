/**
 * Socle de démo — `/config/features` selon l'offre « Voir comme ».
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { api, delai } from '../../outils';
import { lireOffre, produitsDeLOffre } from '../../offres';

export const handlers: HttpHandler[] = [
  http.get(api('/config/features'), async () => {
    await delai();
    const produits = produitsDeLOffre(lireOffre());
    return HttpResponse.json({
      console_v2: produits.crm === 'actif',
      universes: { business: produits.crm === 'actif', vivier: false },
      produits,
    });
  }),
];
