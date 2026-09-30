/**
 * Bloc « Relation » de la fiche entreprise (chantier B, 2026-10-01) : le type
 * de relation et l'étape, modifiables À LA MAIN (`PUT /companies/{id}/relation`,
 * droit `companies.update`, tracé côté serveur), et la joignabilité calculée
 * (chantier D).
 *
 * Une saisie manuelle marque la fiche : l'import du statut de relation
 * (`crm:relations:importer`) ne la touchera plus.
 *
 * L'appelant la monte avec une `key` tirée des valeurs lues : une fiche
 * rechargée remet les deux listes à l'état de la base.
 */
import { useState } from 'react';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';

import { Button, Card, CardEyebrow, CardHeader, CardTitle } from '@/components/ui';
import { api } from '@/lib/api';
import { JOIGNABILITES } from '@/lib/referentiels.generated';
import {
  LIFECYCLE_LABELS,
  RELATION_TYPE_LABELS,
  type LifecycleStage,
  type RelationType,
} from '@/features/crm-console/types';

const SELECT_CLASSES =
  'w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700';

export interface RelationCardProps {
  companyId: number;
  relationType: RelationType;
  lifecycleStage: LifecycleStage;
  saisieManuelleLe: string | null | undefined;
  joignabilite: string | null | undefined;
}

function statutErreur(err: unknown): number | undefined {
  return (err as { response?: { status?: number } }).response?.status;
}

export function RelationCard({ companyId, relationType, lifecycleStage, saisieManuelleLe, joignabilite }: RelationCardProps) {
  const qc = useQueryClient();
  const [type, setType] = useState<RelationType>(relationType);
  const [etape, setEtape] = useState<LifecycleStage>(lifecycleStage);

  const enregistrer = useMutation({
    mutationFn: async (): Promise<void> => {
      await api.put(`/companies/${companyId}/relation`, { relation_type: type, lifecycle_stage: etape });
    },
    onSuccess: () => {
      toast.success('Relation enregistrée');
      void qc.invalidateQueries({ queryKey: ['company', String(companyId)] });
    },
    onError: (err: unknown) => {
      if (statutErreur(err) === 403) {
        toast.error('Droit insuffisant (lecture seule)');
        return;
      }
      toast.error('Enregistrement impossible');
    },
  });

  const modifie = type !== relationType || etape !== lifecycleStage;
  const libelleJoignabilite = JOIGNABILITES.find((j) => j.code === joignabilite)?.libelle ?? 'Non calculée';

  return (
    <Card padding="md">
      <CardHeader>
        <div>
          <CardEyebrow>Relation</CardEyebrow>
          <CardTitle>Type et étape</CardTitle>
        </div>
      </CardHeader>
      <div className="space-y-3 text-sm">
        <label className="block">
          <span className="mb-1 inline-block text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Type de relation
          </span>
          <select
            aria-label="Type de relation"
            className={SELECT_CLASSES}
            value={type}
            onChange={(e) => setType(e.target.value as RelationType)}
          >
            {(Object.keys(RELATION_TYPE_LABELS) as RelationType[]).map((code) => (
              <option key={code} value={code}>{RELATION_TYPE_LABELS[code]}</option>
            ))}
          </select>
        </label>
        <label className="block">
          <span className="mb-1 inline-block text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Étape
          </span>
          <select
            aria-label="Étape"
            className={SELECT_CLASSES}
            value={etape}
            onChange={(e) => setEtape(e.target.value as LifecycleStage)}
          >
            {(Object.keys(LIFECYCLE_LABELS) as LifecycleStage[]).map((code) => (
              <option key={code} value={code}>{LIFECYCLE_LABELS[code]}</option>
            ))}
          </select>
        </label>
        <p className="text-[11px] text-slate-500 dark:text-slate-400">
          {saisieManuelleLe
            ? `Posée à la main le ${new Date(saisieManuelleLe).toLocaleString('fr-FR')} : l’import ne la modifiera plus.`
            : 'Jamais posée à la main : l’import du site peut la faire progresser, jamais reculer.'}
        </p>
        <Button
          variant="primary"
          size="md"
          full
          disabled={!modifie}
          loading={enregistrer.isPending}
          onClick={() => enregistrer.mutate()}
        >
          Enregistrer
        </Button>
        <div className="border-t border-slate-100 pt-3 dark:border-slate-800">
          <span className="text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:text-slate-400">
            Joignabilité
          </span>
          <div className="mt-1 text-slate-900 dark:text-white">{libelleJoignabilite}</div>
        </div>
      </div>
    </Card>
  );
}
