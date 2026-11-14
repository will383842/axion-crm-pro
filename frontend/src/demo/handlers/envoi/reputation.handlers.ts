/**
 * Démo d'Envoi — réputation (R17). Contrat : `backend/openapi/envoi/reputation.yaml`.
 * Constats gardés en mémoire (fixtures `.invalid`) ; la saisie « mauvaise » est le seul geste qui freine,
 * aucune saisie ne lève quoi que ce soit, comme sur le vrai serveur. Aucun envoi.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/reputation';
import { maintenant } from '../../horloge';
import { api, delai, erreur, repondreEtatForce } from '../../outils';

type Constat = components['schemas']['ConstatReputation'];
type Saisie = components['schemas']['SaisieConstatReputation'];

const constats: Constat[] = [];
const rejeux = new Map<string, { empreinte: string; constat: Constat }>();

const SOURCES = ['google_postmaster', 'microsoft_snds', 'microsoft_jmrp', 'orange'];
const VALEURS = ['bonne', 'moyenne', 'mauvaise', 'inconnue'];
const MOTIF_ADRESSE = /[@＠﹫]|arobase/i;

function refus(s: Partial<Saisie>): string | null {
  if (!s.source || !SOURCES.includes(s.source)) return 'Source inconnue.';
  if (!s.reputation || !VALEURS.includes(s.reputation)) return 'Réputation inconnue.';
  if (!s.ressource || MOTIF_ADRESSE.test(s.ressource) || s.ressource.length < 3) return 'La ressource doit être une adresse IP ou un nom de domaine.';
  const preuve = (s.preuve ?? '').trim();
  if (preuve.length < 10 || preuve.length > 500) return 'La preuve doit faire de 10 à 500 caractères.';
  if (MOTIF_ADRESSE.test(preuve)) return 'La preuve ne doit contenir aucune adresse e-mail.';
  return null;
}

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/reputation'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: [...constats].reverse() });
  }),

  http.post(api('/envoi/reputation/constats'), async ({ request }) => {
    await delai();
    const s = (await request.json()) as Partial<Saisie>;
    const message = refus(s);
    if (message !== null) return erreur(422, 'validation', message);
    const empreinte = JSON.stringify([s.source, s.ressource_type, s.ressource, s.jour, s.reputation, s.preuve]);
    const cle = request.headers.get('Idempotency-Key') ?? `auto-${empreinte}`;
    const deja = rejeux.get(cle);
    if (deja) {
      return deja.empreinte === empreinte
        ? HttpResponse.json(deja.constat, { status: 200 })
        : erreur(409, 'conflit', 'Cette clé de rejeu a déjà servi pour un autre contenu.');
    }
    const constat: Constat = {
      id: constats.length + 1,
      source: s.source as Constat['source'],
      ressource_type: s.ressource_type === 'ip' ? 'ip' : 'domaine',
      ressource: String(s.ressource).toLowerCase(),
      jour: s.jour ?? maintenant().toISOString().slice(0, 10),
      reputation: s.reputation as Constat['reputation'],
      taux_plaintes: s.taux_plaintes ?? null,
      erreurs_auth: s.erreurs_auth ?? null,
      pieges: s.pieges ?? null,
      saisie: 'manuelle',
      preuve: String(s.preuve).trim(),
      auteur: 'responsable',
      cree_le: maintenant().toISOString(),
      pause_ouverte: s.reputation === 'mauvaise',
    };
    constats.push(constat);
    rejeux.set(cle, { empreinte, constat });
    return HttpResponse.json(constat, { status: 201 });
  }),
];
