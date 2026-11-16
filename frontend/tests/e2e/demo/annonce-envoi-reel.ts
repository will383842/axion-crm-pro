/**
 * Ce qu'aucun écran de la démo ne doit annoncer : un message parti pour de vrai. Le motif couvre les accords
 * (« partis », « envoyées »), l'ordre des mots (« en réel » avant ou après) et les formules d'état de l'envoi réel.
 * Une négation dans la même phrase (« pas parti en réel », « aucun message n’est parti… ») n'est pas une annonce.
 * Garde : tests/lib/annonce-envoi-reel.test.ts.
 */
/** Négation ou exclusion (mot entier, ou « n’ » élidé) : une phrase qui la porte n'annonce PAS un envoi réel. */
const NEGATION = String.raw`(?:(?<!\p{L})(?:pas|jamais|aucune?s?|rien|nulle?|sans|ne)(?!\p{L})|(?<!\p{L})n['’])`;

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

// Le lookbehind refuse toute annonce précédée, dans la même phrase (40 caractères au plus), d'une négation.
export const PARTI_EN_REEL = new RegExp(`(?<!${NEGATION}[^.!?\\n]{0,40})(?:${ANNONCES.join('|')})`, 'iu');
