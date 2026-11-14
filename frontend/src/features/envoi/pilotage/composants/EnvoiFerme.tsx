import { isAxiosError } from 'axios';

/** Code renvoyé par toute route qui ferait partir un message tant que l'envoi réel est fermé (palier 0). */
export const CODE_ENVOI_FERME = 'envoi_ferme_palier_0';

/** Vrai si l'erreur est le refus 409 « envoi fermé » : aucun effet côté serveur. */
export function estEnvoiFerme(erreur: unknown): boolean {
  return isAxiosError(erreur) && erreur.response?.status === 409 && (erreur.response.data as { error?: string } | undefined)?.error === CODE_ENVOI_FERME;
}

/** Message commun à tous les boutons concernés : explique que rien n'est parti et pourquoi. */
export function MessageEnvoiFerme({ erreur }: { erreur: unknown }) {
  if (!estEnvoiFerme(erreur)) return null;
  const serveur = isAxiosError(erreur) ? (erreur.response?.data as { message?: string } | undefined)?.message : undefined;
  return (
    <p role="alert" className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
      <strong>Envois fermés (palier 0).</strong> Rien n’est parti.{serveur ? ` ${serveur}` : ' Ce geste reste indisponible tant que les envois ne sont pas ouverts par le responsable.'}
    </p>
  );
}
