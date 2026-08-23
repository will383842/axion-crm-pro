/**
 * Montants en euros — un seul formateur pour toute l'application.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * D29-005 — POURQUOI CE FICHIER EXISTE
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Mesure du 2026-08-22, `LlmRouterPage.tsx:228` et `:270` :
 *
 *     `${(usage.data?.total_eur ?? 0).toFixed(2)} €`   →  « 12.34 € »
 *
 * `toFixed()` rend TOUJOURS un point décimal anglais, quelle que soit la
 * locale, et l'espace tapée avant le « € » était une espace ordinaire — donc
 * sécable : un montant pouvait se couper en fin de ligne entre le nombre et son
 * symbole. Le contraste était net dans le même fichier, où les jetons passaient
 * déjà par `toLocaleString('fr-FR')` : l'outil était là, il n'était simplement
 * pas employé pour l'argent.
 *
 * `Intl.NumberFormat` corrige les deux d'un coup — virgule décimale et espace
 * insécable — et pose la règle à un seul endroit plutôt qu'à chaque site
 * d'affichage.
 *
 * ⚠️ À SAVOIR AVANT D'ÉCRIRE UNE ASSERTION SUR CE RENDU. L'espace qui sépare le
 * nombre du symbole n'est PAS un espace ordinaire, et sa nature exacte dépend
 * de la version d'ICU embarquée : U+00A0 (insécable) sur les ICU anciennes,
 * U+202F (insécable étroite) sur les récentes. Une comparaison de chaîne
 * exacte écrite à la main (« 12,34 € » tapé au clavier) rougira donc sans que
 * rien ne soit cassé. Compare le nombre et le symbole séparément — c'est ce que
 * fait `tests/lib/monnaie.test.ts`.
 */

/**
 * Instancié UNE fois : construire un `Intl.NumberFormat` est coûteux, et le
 * refaire à chaque rendu d'une liste de fournisseurs se paie en INP.
 */
const FORMATEUR_EUROS = new Intl.NumberFormat('fr-FR', {
  style: 'currency',
  currency: 'EUR',
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
});

/** Rend un montant en euros à la française : « 1 234,50 € », espace insécable comprise. */
export function formaterEuros(montant: number): string {
  return FORMATEUR_EUROS.format(montant);
}
