import { useEffect, useState } from 'react';

/**
 * Délai d'anti-rebond des champs de recherche, en millisecondes.
 *
 * ── Pourquoi 300 et pas 500 ────────────────────────────────────────────────
 * Le dépôt portait DÉJÀ un anti-rebond, écrit à la main dans
 * `src/features/audiences/AudienceBuilderPage.tsx:170` : `setTimeout(…, 500)`.
 * Il porte sur un APERÇU de comptage — un chiffre qu'on regarde une fois, dont
 * l'attente d'une demi-seconde ne gêne personne.
 *
 * Un champ de recherche de liste n'est pas dans ce cas : c'est la liste
 * elle-même qui doit suivre la frappe. 500 ms après la DERNIÈRE touche se
 * ressent comme une hésitation. 300 ms est le seuil au-dessous duquel on ne
 * perçoit plus l'attente, et il suffit largement : à 80 ms par touche (frappe
 * rapide), « boulangerie » (11 caractères) ne déclenche qu'UNE requête au lieu
 * de 11.
 *
 * ⚠️ Ce n'est PAS le budget INP de `AGENTS.md` (≤ 100 ms). L'INP mesure la
 * latence entre la touche et le rendu de la LETTRE — elle reste immédiate ici,
 * puisque le champ garde la valeur non différée. Ce délai-ci ne retarde que le
 * départ de la requête réseau.
 */
export const DELAI_ANTI_REBOND_MS = 300;

/**
 * Rend une valeur qui ne suit `valeur` qu'après `delaiMs` de calme.
 *
 * ── Le défaut réparé (G42-010, S1) ─────────────────────────────────────────
 * Huit écrans plaçaient l'état d'un champ de saisie DIRECTEMENT dans la
 * `queryKey` de React Query. Une touche = une clé nouvelle = une requête. Sur
 * `/companies`, la table porte 4,29 M de fiches et chaque requête est un
 * `LIKE` : taper « boulangerie » lançait 11 recherches serveur dont 10
 * n'auraient jamais dû partir.
 *
 * ── Le contrat ─────────────────────────────────────────────────────────────
 *  · au PREMIER rendu, la valeur différée est la valeur initiale — pas
 *    `undefined`, pas de chaîne vide : un écran ouvert avec un filtre déjà
 *    posé (URL, état restauré) ne doit pas afficher la liste non filtrée
 *    pendant 300 ms puis sauter ;
 *  · le minuteur précédent est ANNULÉ à chaque changement — sinon la frappe
 *    empilerait un minuteur par touche et n'économiserait rien ;
 *  · l'appelant garde la valeur IMMÉDIATE pour le `value` du champ. Le champ
 *    reste vif ; c'est la seule requête qui attend.
 *
 * ⚠️ NE PAS différer un objet de filtres ENTIER. Une liste déroulante ou une
 * case à cocher se règle d'un geste : la retarder de 300 ms se voit. On ne
 * diffère que les champs de SAISIE LIBRE.
 */
export function useAntiRebond<T>(valeur: T, delaiMs: number = DELAI_ANTI_REBOND_MS): T {
  const [differee, setDifferee] = useState<T>(valeur);

  useEffect(() => {
    const minuteur = setTimeout(() => setDifferee(valeur), delaiMs);
    return () => {
      clearTimeout(minuteur);
    };
  }, [valeur, delaiMs]);

  return differee;
}
