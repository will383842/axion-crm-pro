/**
 * Ce qu'aucun écran de la démo ne doit annoncer : un message parti pour de vrai. Le motif couvre les accords
 * (« partis », « envoyées »), l'ordre des mots (« en réel » avant ou après) et les formules d'état de l'envoi réel.
 * Seule une négation collée au verbe (« pas parti en réel », « n’est parti… ») n'est pas une annonce.
 * Garde : tests/lib/annonce-envoi-reel.test.ts.
 */
/**
 * Négation COLLÉE au verbe, rien d'autre entre les deux (ni ponctuation ni autre mot) : « n’est pas », « ne sont pas »,
 * « n’a pas été », « jamais », « pas ». Une négation plus loin (« Sans erreur, … », « Ne fermez pas la page, … »)
 * n'annule rien : en cas de doute la garde détecte.
 */
const NEGATION_COLLEE = String.raw`(?:(?<!\p{L})(?:n['’]\s?|ne\s+)(?:est|sont|a|ont)\s+(?:(?:pas|jamais|plus)\s+)?(?:été\s+)?|(?<!\p{L})(?:pas|jamais)\s+(?:été\s+)?)`;

const ANNONCES = [
  // « message(s) (est/sont/a été/ont été) parti(s)/envoyé(s)/émis/expédié(s) (…) en réel / pour de vrai »
  String.raw`messages?\s+(?:(?:est|sont|a été|ont été)\s+)?(?:parti|envoyé|émis|expédié)e?s?\s+(?:en réel|pour de vrai)`,
  // « envoi réel activé / actif / en cours / effectué / lancé »
  String.raw`envois?\s+réels?\s+(?:activés?|actifs?|en cours|effectués?|lancés?)`,
  // « envoyé(e)(s) pour de vrai », « parti(e)(s) pour de vrai »
  String.raw`(?:envoy|part)(?:é|i)e?s?\s+pour de vrai`,
  // « parti / envoyé (bien, déjà…) en réel », sans sujet (« Tout est parti en réel »)
  String.raw`(?:parti|envoyé|émis|expédié)e?s?\s+(?:(?:bien|déjà|vraiment)\s+)?en réel`,
  // « en réel » après le verbe, avec un mot entre les deux (« envoyés bien en réel »)
  String.raw`(?:parti|envoyé)e?s?\s+\S+\s+en réel`,
];

// Le lookbehind refuse l'annonce uniquement si une négation collée la précède immédiatement.
export const PARTI_EN_REEL = new RegExp(`(?<!${NEGATION_COLLEE})(?:${ANNONCES.join('|')})`, 'iu');
