import { useMutation } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Button } from '@/components/ui';
import { SELECTION_MAX, exporterSelection, proposerFichier } from './exporterSelection';

/** « Exporter la sélection » : un CSV des seules fiches cochées. */
export function ExporterLaSelection({ ids }: { ids: readonly number[] }) {
  const export_ = useMutation({
    mutationFn: () => exporterSelection(ids),
    onSuccess: (fichier) => {
      proposerFichier(fichier, `entreprises-selection-${ids.length}.csv`);
      toast.success(`Export prêt : ${ids.length} fiche${ids.length > 1 ? 's' : ''}.`);
    },
    onError: () => toast.error('L’export a échoué. Réessayez dans un instant.'),
  });
  return (
    <Button
      size="sm"
      variant="secondary"
      loading={export_.isPending}
      disabled={ids.length === 0 || ids.length > SELECTION_MAX}
      onClick={() => export_.mutate()}
    >
      Exporter la sélection
    </Button>
  );
}
