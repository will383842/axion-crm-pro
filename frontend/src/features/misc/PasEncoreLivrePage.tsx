import { useEffect } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { toast } from 'sonner';

/**
 * L'ADRESSE DES FONCTIONS QUI N'EXISTENT PAS — `/pas-encore-livre`.
 *
 * On n'y arrive que par un ancien signet (`/cold-email`, `/linkedin`, qui y
 * redirigent). L'écran expliquait le chantier (« lot L7 », « hors du périmètre
 * engagé », « retirés le 23 août 2026 ») : du vocabulaire de développement, sans
 * intérêt pour qui utilise la console (audit UX du 2026-10-02, finitions P2).
 *
 * Désormais : retour immédiat au tableau de bord, avec un message court. Le
 * message porte un identifiant fixe : un double montage (mode strict) ne
 * l'affiche qu'une fois.
 */
export const MESSAGE_FONCTION_ABSENTE = 'Cette fonction n’existe pas encore.';

export function PasEncoreLivrePage() {
  const navigate = useNavigate();

  useEffect(() => {
    toast.info(MESSAGE_FONCTION_ABSENTE, { id: 'fonction-absente' });
    void navigate({ to: '/', replace: true });
  }, [navigate]);

  return null;
}
