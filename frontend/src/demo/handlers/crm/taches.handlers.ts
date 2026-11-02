/**
 * Démo — Mes tâches. Les tâches sont des `activities` de type `task` : la
 * collection de la zone « accueil » les porte, rien d'autre (pas de `crm_tasks`).
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { NouvelleTache, ReponseTaches, Tache } from '@/features/crm/taches/types';
import { zoneAccueil } from '../../donnees/crm/affaires';
import { jourIso, maintenant } from '../../horloge';
import { api, delai, erreur, repondreEtatForce } from '../../outils';

const zone = zoneAccueil;

function corps(): ReponseTaches {
  const { taches, reglages } = zone();
  return {
    aujourdhui: jourIso(0),
    data: [...taches.tous()],
    rappel_matin: reglages.trouver('rappel-matin')?.actif ?? false,
  };
}

export const handlers: HttpHandler[] = [
  http.get(api('/crm/taches'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { aujourdhui: jourIso(0), data: [], rappel_matin: false });
    if (force) return force;
    return HttpResponse.json(corps());
  }),

  http.post(api('/crm/taches'), async ({ request }) => {
    await delai();
    const saisie = (await request.json().catch(() => ({}))) as Partial<NouvelleTache>;
    const titre = typeof saisie.titre === 'string' ? saisie.titre.trim() : '';
    if (titre === '') return erreur(422, 'titre_manquant', 'Indiquez l’intitulé de la tâche.');
    const { taches } = zone();
    const nouvelle: Tache = {
      id: `tache-${taches.compter() + 1}`,
      titre,
      contexte: typeof saisie.contexte === 'string' ? saisie.contexte : '',
      origine: 'manuelle',
      genre: 'tache',
      echeance: typeof saisie.echeance === 'string' && saisie.echeance !== '' ? saisie.echeance : maintenant().toISOString().slice(0, 10),
      heure: typeof saisie.heure === 'string' && saisie.heure !== '' ? saisie.heure : null,
      faite: false,
      faite_le: null,
    };
    return HttpResponse.json(taches.ajouter(nouvelle), { status: 201 });
  }),

  http.put(api('/crm/taches/rappel-matin'), async ({ request }) => {
    await delai();
    const { actif } = (await request.json().catch(() => ({}))) as { actif?: unknown };
    zone().reglages.modifier('rappel-matin', { actif: actif === true });
    return HttpResponse.json({ actif: actif === true });
  }),

  http.patch(api('/crm/taches/:id'), async ({ request, params }) => {
    await delai();
    const { faite } = (await request.json().catch(() => ({}))) as { faite?: unknown };
    const { taches } = zone();
    const cible = taches.trouver(String(params['id']));
    if (!cible) return erreur(404, 'tache_introuvable', 'Cette tâche n’existe plus.');
    const faiteOui = faite === true;
    return HttpResponse.json(taches.modifier(cible.id, { faite: faiteOui, faite_le: faiteOui ? maintenant().toISOString() : null }));
  }),
];
