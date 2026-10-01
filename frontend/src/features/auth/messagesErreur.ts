/**
 * Ce qu'un échec d'authentification veut DIRE, en une phrase claire.
 *
 * Audit UX du 02/10/2026 (P0-6) — la connexion affichait « Une erreur est
 * survenue. » pour TOUT échec (mot de passe faux, compte verrouillé, trop
 * d'essais, session expirée, panne), et parfois la clé brute `auth.failed`
 * renvoyée par le serveur faute de traduction. Cinq situations, cinq gestes
 * différents, un seul message : ce module les sépare.
 *
 * Règle : on n'affiche JAMAIS une clé de traduction (`auth.failed`,
 * `validation.required`…). Si le serveur en renvoie une, on la traduit ici ; si
 * elle est inconnue, on retombe sur un message générique mais exact.
 */
import { isAxiosError } from 'axios';

/** Une clé de traduction Laravel non résolue : `auth.failed`, `validation.max.string`. */
const CLE_BRUTE = /^[a-z_]+(\.[a-z_]+)+$/;

/** Les clés connues, traduites même si le serveur les renvoie brutes. */
const CLES_CONNUES: Record<string, string> = {
  'auth.failed': 'Adresse e-mail ou mot de passe incorrect.',
  'auth.password': 'Le mot de passe est incorrect.',
  'auth.throttle': 'Trop d’essais. Réessayez dans un instant.',
  'auth.locked':
    'Ce compte est temporairement verrouillé après trop d’essais. Réessayez plus tard ou réinitialisez votre mot de passe.',
};

export const MESSAGE_SESSION_EXPIREE = 'Votre session a expiré. Rechargez la page, puis réessayez.';
export const MESSAGE_SERVEUR_MUET = 'Le serveur ne répond pas. Vérifiez votre connexion, puis réessayez.';
export const MESSAGE_PANNE = 'Le serveur a rencontré un problème. Réessayez dans un instant.';

interface CorpsErreur {
  message?: unknown;
  errors?: Record<string, unknown>;
}

/** Première phrase lisible d'un corps d'erreur Laravel, ou `null`. */
function premierMessage(corps: CorpsErreur | undefined): string | null {
  const champs = corps?.errors;
  if (champs !== undefined && champs !== null && typeof champs === 'object') {
    for (const valeur of Object.values(champs)) {
      const premier: unknown = Array.isArray(valeur) ? valeur[0] : valeur;
      if (typeof premier === 'string' && premier.trim() !== '') return premier.trim();
    }
  }
  if (typeof corps?.message === 'string' && corps.message.trim() !== '') return corps.message.trim();
  return null;
}

/** Traduit une phrase du serveur ; `null` si c'est une clé brute inconnue. */
function lisible(phrase: string | null): string | null {
  if (phrase === null) return null;
  if (phrase in CLES_CONNUES) return CLES_CONNUES[phrase] ?? null;
  if (CLE_BRUTE.test(phrase)) return null;
  return phrase;
}

/** Délai d'attente annoncé par l'en-tête `Retry-After`, en secondes. */
function delaiAnnonce(entetes: unknown): number | null {
  if (entetes === null || typeof entetes !== 'object') return null;
  const brut = (entetes as Record<string, unknown>)['retry-after'];
  const n = typeof brut === 'string' || typeof brut === 'number' ? Number(brut) : Number.NaN;
  return Number.isFinite(n) && n > 0 ? Math.ceil(n) : null;
}

/**
 * Le message à afficher pour un échec de connexion ou d'envoi de lien.
 *
 * @param repli phrase utilisée quand le serveur a refusé sans rien dire de
 *              lisible (422 sans message, ou clé brute inconnue).
 */
export function messageErreurAuth(error: unknown, repli: string): string {
  if (!isAxiosError(error)) return MESSAGE_PANNE;
  const reponse = error.response;
  if (reponse === undefined) return MESSAGE_SERVEUR_MUET;

  const { status } = reponse;
  if (status === 419) return MESSAGE_SESSION_EXPIREE;
  if (status === 429) {
    const secondes = delaiAnnonce(reponse.headers);
    return secondes === null
      ? 'Trop d’essais. Patientez une minute, puis réessayez.'
      : `Trop d’essais. Réessayez dans ${secondes} seconde${secondes > 1 ? 's' : ''}.`;
  }
  if (status >= 500) return MESSAGE_PANNE;

  return lisible(premierMessage(reponse.data as CorpsErreur | undefined)) ?? repli;
}

/** Vrai quand l'échec est un jeton CSRF périmé (419) : un nouvel essai peut réussir. */
export function estSessionExpiree(error: unknown): boolean {
  return isAxiosError(error) && error.response?.status === 419;
}
