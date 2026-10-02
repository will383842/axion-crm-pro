/**
 * « mis à jour il y a N min » — lot 3 (2026-10-02). Les indicateurs des listes
 * (entreprises, médias, étiquettes) sont servis depuis un cache serveur : un
 * chiffre en cache qui se présente comme instantané est un mensonge
 * d'interface. `null` si la date est absente ou illisible.
 */
export function misAJour(calculeLe: string | null | undefined, maintenant: number = Date.now()): string | null {
  if (typeof calculeLe !== 'string') return null;
  const t = Date.parse(calculeLe);
  if (Number.isNaN(t)) return null;
  const minutes = Math.max(0, Math.floor((maintenant - t) / 60_000));
  if (minutes < 1) return 'mis à jour à l’instant';
  if (minutes < 60) return `mis à jour il y a ${minutes} min`;
  return `mis à jour il y a ${Math.floor(minutes / 60)} h`;
}
