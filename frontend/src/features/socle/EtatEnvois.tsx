/**
 * DM0-COQ — L'ÉTAT GLOBAL DES ENVOIS (feu de l'en-tête d'Envoi) ET LES
 * COMPTEURS DE SA BARRE.
 *
 * Contrat : `backend/openapi/envoi/socle.yaml` (DM0-DEMO) —
 * `GET /envoi/etat` → `EtatEnvois`, `GET /envoi/compteurs` → `CompteursEnvoi`.
 * Les types sont recopiés ici, au plus juste, tant que les types générés
 * (`features/envoi/api/socle.d.ts`) ne sont pas sur `main`.
 *
 * ⚠️ Le feu dit un ÉTAT : il prend les couleurs de SENS (vert, ambre, rouge,
 * bleu, gris), JAMAIS la couleur d'identité du produit (ADR-13).
 */
import { useQuery } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { api } from '@/lib/api';
import { cn } from '@/components/ui';

export type FeuEnvois = 'vert' | 'ambre' | 'rouge' | 'bleu' | 'gris';

export interface EtatEnvois {
  etat: 'actif' | 'arrete';
  feu: FeuEnvois;
  depuis: string;
  par: string | null;
  motif: string | null;
}

export interface CompteursEnvoi {
  envoyes_aujourdhui: number;
  plafond_jour: number;
  en_file: number;
  reponses_a_traiter: number;
  alertes_ouvertes: number;
  campagnes_en_cours: number;
  boites_pretes: number;
  boites_total: number;
}

export const CLE_ETAT_ENVOIS = ['envoi', 'etat'] as const;
export const CLE_COMPTEURS_ENVOI = ['envoi', 'compteurs'] as const;

/** `actif` : la requête ne part que si Envoi est visible (jamais en production sans Envoi). */
export function useEtatEnvois(actif: boolean) {
  return useQuery<EtatEnvois>({
    queryKey: CLE_ETAT_ENVOIS,
    queryFn: async () => (await api.get<EtatEnvois>('/envoi/etat')).data,
    enabled: actif,
    retry: false,
    staleTime: 30_000,
  });
}

export function useCompteursEnvoi(actif: boolean) {
  return useQuery<Partial<CompteursEnvoi>>({
    queryKey: CLE_COMPTEURS_ENVOI,
    queryFn: async () => (await api.get<Partial<CompteursEnvoi>>('/envoi/compteurs')).data,
    enabled: actif,
    retry: false,
    staleTime: 30_000,
  });
}

/** Le mot de chaque feu : la couleur seule ne dit rien à qui ne la voit pas. */
export const MOT_DU_FEU: Record<FeuEnvois, string> = {
  vert: 'normaux',
  ambre: 'à surveiller',
  rouge: 'arrêtés',
  bleu: 'en montée',
  gris: 'inconnus',
};

const COULEUR_DU_FEU: Record<FeuEnvois, string> = {
  vert: 'bg-emerald-500',
  ambre: 'bg-amber-500',
  rouge: 'bg-rose-500',
  bleu: 'bg-sky-500',
  gris: 'bg-slate-400',
};

export function feuAffiche(etat: EtatEnvois | undefined): FeuEnvois {
  if (etat === undefined) return 'gris';
  if (etat.etat === 'arrete') return 'rouge';
  return etat.feu in MOT_DU_FEU ? etat.feu : 'gris';
}

export function libelleDuFeu(feu: FeuEnvois): string {
  return `État des envois : ${MOT_DU_FEU[feu]}`;
}

/** Le point de feu (rond : c'est une pastille d'ÉTAT), nommé pour les lecteurs d'écran. */
export function PointDeFeu({ feu, className }: { feu: FeuEnvois; className?: string }) {
  return (
    <span
      role="img"
      aria-label={libelleDuFeu(feu)}
      title={libelleDuFeu(feu)}
      data-pastille-etat
      className={cn('inline-block h-2 w-2 shrink-0 rounded-full', COULEUR_DU_FEU[feu], className)}
    />
  );
}

/** Le feu de l'en-tête d'Envoi : mène à « Santé des envois ». */
export function FeuDesEnvois() {
  const { data } = useEtatEnvois(true);
  const feu = feuAffiche(data);
  return (
    <Link
      to="/envoi/sante"
      aria-label={libelleDuFeu(feu)}
      className="inline-flex h-8 shrink-0 items-center gap-1.5 rounded-lg px-2 text-xs font-medium text-sidebar-fg transition hover:bg-white/10 hover:text-white"
    >
      <span aria-hidden className={cn('inline-block h-2 w-2 rounded-full', COULEUR_DU_FEU[feu])} />
      <span className="hidden xl:inline">Envois : {MOT_DU_FEU[feu]}</span>
    </Link>
  );
}
