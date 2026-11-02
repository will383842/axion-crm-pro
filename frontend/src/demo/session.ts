/**
 * Session de la démo : déconnecté, code attendu, connecté. Aucun compte réel :
 * l'adresse, le mot de passe et le code de démonstration sont affichés dans le
 * bandeau (`IDENTIFIANTS_DEMO`).
 */
export type EtatSession = 'deconnecte' | 'code_attendu' | 'connecte';
export const CLE_SESSION_DEMO = 'axion-demo:session';

export function lireSession(): EtatSession {
  try {
    const v = globalThis.sessionStorage?.getItem(CLE_SESSION_DEMO);
    return v === 'code_attendu' || v === 'connecte' ? v : 'deconnecte';
  } catch {
    return 'deconnecte';
  }
}

export function ecrireSession(etat: EtatSession): void {
  try {
    if (etat === 'deconnecte') globalThis.sessionStorage?.removeItem(CLE_SESSION_DEMO);
    else globalThis.sessionStorage?.setItem(CLE_SESSION_DEMO, etat);
  } catch {
    // stockage indisponible : la session ne survit pas au rechargement.
  }
}
