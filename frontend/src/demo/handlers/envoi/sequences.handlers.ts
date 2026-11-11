/**
 * Démo d'Envoi — séquences de 1 à 3 messages (L8-F03c).
 * Contrat : `backend/openapi/envoi/sequences.yaml`.
 *
 * Aucun corps de message et aucune donnée de prospect : seulement des compteurs
 * par étape. Pause (`users.manage`) et reprise (`workspaces.manage`) ; la reprise
 * est refusée (409) tant qu'un frein, l'arrêt général ou une pause automatique est actif.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/sequences';
import { demoDb } from '../../base';
import { ilYa, maintenant } from '../../horloge';
import { api, delai, erreur, repondreEtatForce } from '../../outils';

type Resume = components['schemas']['SequenceResume'];
type Detail = components['schemas']['SequenceDetail'];
type Etape = components['schemas']['EtapeSequence'];

interface LigneSequence extends Resume {
  etapes: Etape[];
}

const etape = (rang: number, delai_jours: number, objet: string, envoyes: number, reponses: number, rebonds_durs: number, desinscriptions: number): Etape => ({
  rang, delai_jours, objet, envoyes, reponses, rebonds_durs, desinscriptions,
});

function fabrique(): { lignes: LigneSequence[] } {
  const demain = new Date(maintenant().getTime() + 20 * 3_600_000).toISOString();
  return {
    lignes: [
      {
        id: 'seq-01', nom: 'Présentation en trois messages (démo)', statut: 'active', motif_pause: null, reservee_aux_temoins: false, nb_etapes: 3,
        inscriptions: { total: 120, en_cours: 80, terminees: 20, arretees: 12, reportees: 8 }, prochain_envoi: demain, creee_le: ilYa(30, 9, 0),
        etapes: [etape(1, 0, 'Une question sur votre activité', 120, 9, 2, 1), etape(2, 3, 'Je me permets de revenir vers vous', 96, 5, 1, 2), etape(3, 7, 'Dernier message de ma part', 60, 3, 0, 1)],
      },
      {
        id: 'seq-02', nom: 'Relance courte (démo)', statut: 'en_pause', motif_pause: 'manuelle', reservee_aux_temoins: false, nb_etapes: 2,
        inscriptions: { total: 40, en_cours: 0, terminees: 10, arretees: 5, reportees: 25 }, prochain_envoi: null, creee_le: ilYa(18, 9, 0),
        etapes: [etape(1, 0, 'Suite à notre échange', 40, 4, 0, 0), etape(2, 4, 'Avez-vous eu le temps de regarder ?', 15, 1, 0, 1)],
      },
      {
        id: 'seq-03', nom: 'Essai sur les boîtes témoins (démo)', statut: 'active', motif_pause: null, reservee_aux_temoins: true, nb_etapes: 1,
        inscriptions: { total: 6, en_cours: 6, terminees: 0, arretees: 0, reportees: 0 }, prochain_envoi: demain, creee_le: ilYa(5, 9, 0),
        etapes: [etape(1, 0, 'Message d’essai', 6, 0, 0, 0)],
      },
      {
        id: 'seq-04', nom: 'Prospection d’automne (démo)', statut: 'en_pause', motif_pause: 'frein', reservee_aux_temoins: false, nb_etapes: 3,
        inscriptions: { total: 200, en_cours: 0, terminees: 50, arretees: 30, reportees: 120 }, prochain_envoi: null, creee_le: ilYa(40, 9, 0),
        etapes: [etape(1, 0, 'Bonjour et présentation', 200, 14, 4, 3), etape(2, 3, 'Un exemple concret', 150, 8, 2, 2), etape(3, 7, 'Pour conclure', 90, 2, 1, 1)],
      },
    ],
  };
}

const zone = () => (demoDb.etendre('sequences', fabrique), demoDb.zone<LigneSequence & { id: string }>('sequences', 'lignes'));

/** Raison pour laquelle la reprise est refusée, ou nul si elle est permise. */
function raisonRefus(l: LigneSequence): string | null {
  if (demoDb.etatEnvois.lire().etat === 'arrete') return 'L’arrêt général des envois est actif.';
  if (l.statut !== 'en_pause') return 'Cette séquence n’est pas en pause.';
  if (l.motif_pause === 'frein') return 'Un frein est actif : la reprise est refusée tant qu’il dure.';
  if (l.motif_pause === 'arret_general') return 'L’arrêt général des envois est actif.';
  if (l.motif_pause === 'automatique') return 'Une pause automatique est active.';
  return null;
}

const resume = ({ etapes: _etapes, ...r }: LigneSequence): Resume => r;
const detail = (l: LigneSequence): Detail => {
  const raison = raisonRefus(l);
  return { ...resume(l), etapes: l.etapes, peut_reprendre: l.statut === 'en_pause' && raison === null, raison_refus_reprise: l.statut === 'en_pause' ? raison : null };
};

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/sequences'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { sequences: [] });
    if (force) return force;
    return HttpResponse.json({ sequences: zone().tous().map(resume) });
  }),

  http.get(api('/envoi/sequences/:id'), async ({ params }) => {
    await delai();
    const l = zone().trouver(String(params['id']));
    return l ? HttpResponse.json(detail(l)) : erreur(404, 'non_trouve', 'Cette séquence n’existe pas.');
  }),

  http.post(api('/envoi/sequences/:id/pause'), async ({ params }) => {
    await delai();
    const l = zone().trouver(String(params['id']));
    if (!l) return erreur(404, 'non_trouve', 'Cette séquence n’existe pas.');
    const maj = l.statut === 'active' ? zone().modifier(l.id, { statut: 'en_pause', motif_pause: 'manuelle', prochain_envoi: null }) : l;
    return HttpResponse.json(detail(maj!));
  }),

  http.post(api('/envoi/sequences/:id/reprise'), async ({ params }) => {
    await delai();
    const l = zone().trouver(String(params['id']));
    if (!l) return erreur(404, 'non_trouve', 'Cette séquence n’existe pas.');
    const raison = raisonRefus(l);
    if (raison !== null) return erreur(409, 'reprise_refusee', raison);
    const maj = zone().modifier(l.id, { statut: 'active', motif_pause: null, prochain_envoi: new Date(maintenant().getTime() + 20 * 3_600_000).toISOString() });
    return HttpResponse.json(detail(maj!));
  }),
];
