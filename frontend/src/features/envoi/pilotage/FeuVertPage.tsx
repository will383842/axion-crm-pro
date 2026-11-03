/** Feu vert (DM2-PIL) — les 15 critères avant le premier envoi réel, avec leur état. */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Check, Circle } from 'lucide-react';
import { Button, Card, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';

interface Point { code: string; libelle: string; fait: boolean; detail: string | null }
interface FeuVert { points: Point[]; faits: number; total: number }

export function FeuVertPage() {
  const qc = useQueryClient();
  const requete = useQuery({ queryKey: ['envoi', 'feu-vert'], queryFn: async () => (await api.get<FeuVert>('/envoi/feu-vert')).data });
  const marquer = useMutation({
    mutationFn: async (code: string) => (await api.post<Point>(`/envoi/feu-vert/${code}/fait`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['envoi', 'feu-vert'] }),
  });
  const f = requete.data;
  const restants = f ? f.total - f.faits : 0;

  return (
    <div>
      <PageHeader title="Feu vert" surTitre="Envoi" subtitle={f ? `${f.faits} critères sur ${f.total} avant le premier envoi.` : undefined} />
      <OngletsSante />
      <div className="mt-4">
        {requete.isLoading ? <Chargement libelle="Chargement du feu vert" /> : requete.isError && f === undefined ? <Panne onReessayer={() => void requete.refetch()} /> : !f || f.points.length === 0 ? <Vide /> : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <h2 className="mb-3 text-lg font-bold text-slate-900">{f.total} critères</h2>
              <ul className="divide-y divide-slate-100">
                {f.points.map((p) => (
                  <li key={p.code} className="flex min-h-11 flex-wrap items-center gap-3 py-2">
                    {p.fait ? <Check className="h-5 w-5 text-emerald-600" aria-hidden /> : <Circle className="h-5 w-5 text-amber-600" aria-hidden />}
                    <span className="min-w-0 flex-1 text-sm text-slate-900">
                      {p.libelle}
                      <span className="block text-xs text-slate-600">{p.fait ? 'Fait' : 'À faire'}{p.detail ? ` · ${p.detail}` : ''}</span>
                    </span>
                    {!p.fait && p.detail ? (
                      <Button variant="secondary" size="sm" className="min-h-11 md:min-h-8" aria-label={`Marquer fait : ${p.libelle}`} loading={marquer.isPending && marquer.variables === p.code} onClick={() => marquer.mutate(p.code)}>Marquer fait</Button>
                    ) : null}
                  </li>
                ))}
              </ul>
            </Card>
            <Card>
              <h2 className="text-lg font-bold text-slate-900">Avant le premier envoi</h2>
              <p className="mt-1 text-sm text-slate-700">{f.faits} / {f.total}</p>
              <p className="mt-3 text-sm"><strong>{restants === 0 ? 'Prêt' : 'Presque prêt'}</strong><span className="block text-slate-600">{restants === 0 ? 'Tous les critères sont remplis.' : `${restants} inscription${restants > 1 ? 's' : ''} à faire`}</span></p>
              {restants > 0 ? <Link to="/envoi/reglages" className="mt-4 inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium md:min-h-9">Faire les {restants} inscription{restants > 1 ? 's' : ''}</Link> : null}
            </Card>
          </div>
        )}
      </div>
    </div>
  );
}
