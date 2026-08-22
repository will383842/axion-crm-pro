/**
 * D26-010 — LE COMPTEUR QUI MANQUAIT DEVANT SEPT TRONCATURES SILENCIEUSES.
 *
 * Mesure du 2026-08-22 : `grep -rn maxLength src --include=*.tsx` rendait sept
 * lignes ; AUCUNE n'était accompagnée d'un compteur, d'un texte d'aide ou d'un
 * message. L'attribut HTML `maxLength` tronque un collage SANS RIEN DIRE : on
 * colle un texte de 700 caractères, il en reste 500, et rien à l'écran ne
 * l'annonce. Le message de validation le plus proche
 * (`AudienceBuilderPage`, « Max 120 caractères ») ne pouvait même pas se
 * déclencher, puisque l'attribut tronque AVANT que la valeur n'atteigne 121.
 *
 * On garde la borne et on ajoute la lisibilité : retirer `maxLength` laisserait
 * partir vers l'API une valeur plus longue que la colonne, et échangerait une
 * gêne d'ergonomie contre un échec de création.
 *
 * ⚠️ PAS de `aria-live` ici, volontairement : le compteur change à CHAQUE
 * frappe. Une région d'annonce le lirait à chaque touche et rendrait le champ
 * inutilisable au lecteur d'écran. L'information reste consultable à la demande,
 * rattachée au champ par `id`/`aria-describedby` côté appelant.
 */
import { cn } from './cn';

export interface CompteurDeSaisieProps {
  /** La valeur COURANTE du champ — pas sa longueur : le composant compte. */
  valeur: string;
  /** La borne réelle, celle de l'attribut `maxLength` du champ. */
  max: number;
  /**
   * Part de la borne à partir de laquelle le compteur apparaît. Afficher
   * « 3 / 500 » dès la première lettre serait du bruit ; ce qui compte, c'est
   * d'avertir AVANT d'arriver au mur.
   */
  seuil?: number;
  id?: string;
  className?: string;
}

export function CompteurDeSaisie({ valeur, max, seuil = 0.8, id, className }: CompteurDeSaisieProps) {
  const longueur = valeur.length;
  if (longueur < Math.floor(max * seuil)) return null;

  const limiteAtteinte = longueur >= max;
  return (
    <span
      {...(id ? { id } : {})}
      data-compteur-de-saisie
      className={cn(
        'mt-1 block text-[11px] tabular-nums',
        limiteAtteinte ? 'text-rose-600 dark:text-rose-400' : 'text-slate-500 dark:text-slate-400',
        className,
      )}
    >
      {longueur} / {max}
      {limiteAtteinte ? ' — limite atteinte, la suite du texte collé est coupée' : null}
    </span>
  );
}
