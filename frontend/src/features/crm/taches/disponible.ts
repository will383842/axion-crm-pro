/**
 * DM1-ACC — Mes tâches, Affaires et la barre d'onglets du tableau de bord.
 *
 * Chaque fusion sur `main` déploie le CRM réel, et le serveur n'a pas encore les
 * routes `/crm/taches` ni `/crm/affaires` (lot serveur du CRM, NR-20). Ces écrans
 * ne vivent donc qu'en démonstration (`VITE_DEMO`, figé au build, comme les
 * commandes des contacts de DM1-CON) : hors démo, /taches et /affaires gardent leur
 * page « en attente » neutre, le tableau de bord n'a pas d'onglets, Ctrl K ne les
 * propose pas, et aucun appel ne part vers une route absente.
 */
export function tachesEtAffairesDisponibles(): boolean {
  return import.meta.env.VITE_DEMO === '1';
}
