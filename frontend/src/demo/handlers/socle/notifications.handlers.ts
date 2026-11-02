/**
 * Socle de démo — `/notifications` : les 6 alertes des deux produits (comme la
 * cloche des maquettes), filtrées selon l'offre « Voir comme ». Chaque lot
 * peut ajouter les siennes dans `demoDb.notifications`.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { maintenant } from '../../horloge';
import { api, delai, erreur } from '../../outils';
import { lireOffre, produitsDeLOffre } from '../../offres';

function visibles() {
  const produits = produitsDeLOffre(lireOffre());
  return demoDb.notifications
    .tous()
    .filter((n) => n.produit === 'commun' || produits[n.produit] === 'actif')
    .slice()
    .sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
}

export const handlers: HttpHandler[] = [
  http.get(api('/notifications'), async ({ request }) => {
    await delai();
    const produit = new URL(request.url).searchParams.get('produit');
    const data = visibles().filter((n) => produit === null || n.produit === produit);
    return HttpResponse.json({ data, unread_count: data.filter((n) => n.read_at === null).length });
  }),

  http.post(api('/notifications/:id/lue'), async ({ params }) => {
    await delai();
    const n = demoDb.notifications.modifier(String(params['id']), { read_at: maintenant().toISOString() });
    if (!n) return erreur(404, 'non_trouve', 'Cette notification n’existe pas.');
    return HttpResponse.json({ ok: true });
  }),
];
