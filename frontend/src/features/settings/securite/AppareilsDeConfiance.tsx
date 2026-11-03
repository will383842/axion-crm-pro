import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { MonitorSmartphone } from 'lucide-react';
import { Button, Card, CardEyebrow, CardHeader, CardTitle, EmptyState, QueryErrorState, Skeleton, StatusPill } from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';
import { DemandeCode, messageCode } from './DemandeCode';

interface Appareil {
  id: string;
  nom: string;
  lieu: string;
  ip: string;
  ajoute_le: string;
  expire_le: string;
  actuel: boolean;
}

const jour = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long' });

export function AppareilsDeConfiance() {
  const qc = useQueryClient();
  const [aRetirer, setARetirer] = useState<Appareil | null>(null);
  const liste = useQuery({
    queryKey: ['compte', 'appareils'],
    queryFn: async () => (await api.get<{ data: Appareil[] }>('/compte/appareils')).data.data,
  });
  const retirer = useMutation({
    mutationFn: async (p: { id: string; code: string }) => {
      try {
        await api.post(`/compte/appareils/${p.id}/retirer`, { code: p.code });
      } catch (e) {
        throw new Error(messageCode(e));
      }
    },
    onSuccess: () => {
      toast.success('Appareil retiré : il devra redemander un code.');
      setARetirer(null);
      void qc.invalidateQueries({ queryKey: ['compte', 'appareils'] });
    },
  });

  const appareils = liste.data ?? [];

  return (
    <Card>
      <CardHeader>
        <div>
          <CardEyebrow>Sécurité</CardEyebrow>
          <CardTitle className="mt-1 text-lg font-bold">Appareils de confiance</CardTitle>
        </div>
      </CardHeader>
      <p className="mb-4 text-sm text-slate-600">
        Sur ces appareils, le code reçu par e-mail n’est pas redemandé pendant 30 jours. Retirez-en un pour exiger de nouveau le code.
      </p>
      {liste.error !== null && liste.data === undefined ? (
        <QueryErrorState error={liste.error} contexte="les appareils de confiance" onRetry={() => void liste.refetch()} />
      ) : liste.isLoading ? (
        <Skeleton className="h-24 w-full" />
      ) : appareils.length === 0 ? (
        <EmptyState
          icon={<MonitorSmartphone className="h-10 w-10" />}
          title="Aucun appareil de confiance"
          description="Cochez « Faire confiance à cet appareil » à la prochaine connexion pour en ajouter un."
        />
      ) : (
        <ul className="divide-y divide-slate-100">
          {appareils.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center gap-3 py-3">
              <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-slate-900">
                  {a.nom} {a.actuel ? <StatusPill tone="info">Cet appareil</StatusPill> : null}
                </p>
                <p className="text-xs text-slate-500">
                  {a.lieu} · {a.ip} · ajouté le {jour(a.ajoute_le)} · valable jusqu’au {jour(a.expire_le)}
                </p>
              </div>
              <Button variant="secondary" aria-label={`Retirer ${a.nom}`} onClick={() => setARetirer(a)}>
                Retirer
              </Button>
            </li>
          ))}
        </ul>
      )}
      {aRetirer !== null ? (
        <DemandeCode
          titre="Retirer cet appareil ?"
          description={`${aRetirer.nom} devra redemander un code à sa prochaine connexion. Saisissez le code reçu par e-mail pour confirmer.`}
          libelleAction="Confirmer le retrait"
          onClose={() => setARetirer(null)}
          onConfirmer={(code) => retirer.mutateAsync({ id: aRetirer.id, code })}
        />
      ) : null}
    </Card>
  );
}
