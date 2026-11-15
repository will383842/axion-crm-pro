/**
 * Ce qu'aucun écran de la démo ne doit annoncer : un message parti pour de vrai. Le motif couvre les accords
 * (« partis », « envoyées »), l'ordre des mots (« en réel » avant ou après) et les formules d'état de l'envoi réel.
 * Garde : tests/lib/annonce-envoi-reel.test.ts.
 */
export const PARTI_EN_REEL = new RegExp(
  [
    // « message(s) (est/sont/a été/ont été) parti(s)/envoyé(s)/émis/expédié(s) (…) en réel / pour de vrai »
    String.raw`messages?\s+(?:(?:est|sont|a été|ont été)\s+)?(?:parti|envoyé|émis|expédié)e?s?\s+(?:en réel|pour de vrai)`,
    // « envoi réel activé / actif / en cours / effectué / lancé »
    String.raw`envois?\s+réels?\s+(?:activés?|actifs?|en cours|effectués?|lancés?)`,
    // « envoyé(e)(s) pour de vrai », « parti(e)(s) pour de vrai »
    String.raw`(?:envoy|part)(?:é|i)e?s?\s+pour de vrai`,
    // « en réel » après le verbe, avec un mot entre les deux (« envoyés bien en réel »)
    String.raw`(?:parti|envoyé)e?s?\s+\S+\s+en réel`,
  ].join('|'),
  'i',
);
