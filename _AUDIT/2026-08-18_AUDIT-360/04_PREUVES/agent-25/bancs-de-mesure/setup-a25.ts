/**
 * AGENT 25 — complément de socle, POUR LA MESURE SEULEMENT.
 * Ne modifie aucun fichier du produit ni `tests/setup.ts`.
 *
 * jsdom n'implémente pas `URL.createObjectURL` ; `maplibre-gl` l'appelle au
 * chargement du module (maplibre-gl.js:34) et fait échouer la COLLECTE du
 * fichier de test — donc `/coverage` serait « non vérifiable » pour une
 * lacune de l'environnement, pas pour un défaut du produit.
 */
if (typeof URL.createObjectURL !== 'function') {
  // @ts-expect-error — on comble une lacune de jsdom
  URL.createObjectURL = () => 'blob:agent25';
}
if (typeof URL.revokeObjectURL !== 'function') {
  // @ts-expect-error — idem
  URL.revokeObjectURL = () => {};
}
if (typeof globalThis.HTMLCanvasElement !== 'undefined') {
  HTMLCanvasElement.prototype.getContext = (() => null) as unknown as typeof HTMLCanvasElement.prototype.getContext;
}
