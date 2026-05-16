import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { FranceCoverageMap, type CoverageMode } from './FranceCoverageMap';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface Cell { code: string; name: string; total: number; complete?: number; partial?: number; lat?: number; lon?: number }

export function CoveragePage() {
  const [mode, setMode] = useState<CoverageMode>('visu');
  const [level, setLevel] = useState<'region' | 'department' | 'city'>('department');
  const [selected, setSelected] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: ['coverage', level],
    queryFn: async () => {
      const r = await api.get<{ cells: Cell[] }>('/coverage', { params: { level } });
      return r.data.cells;
    },
    refetchInterval: 60_000,
  });

  async function launch(dept: string) {
    try {
      await api.post('/coverage/launch', { department: dept, limit: 100 });
      toast.success(`Scraping lancé pour le département ${dept}`);
    } catch {
      toast.error('Erreur lors du lancement');
    }
  }

  return (
    <PageShell
      title="Couverture France"
      subtitle="Carte interactive régions / départements / villes — sélection auto de la prochaine zone."
      actions={
        <div className="flex gap-2">
          {(['visu', 'search', 'action'] as const).map((m) => (
            <button
              key={m}
              onClick={() => setMode(m)}
              className={`rounded-md px-3 py-1.5 text-sm ${mode === m ? 'bg-brand-600 text-white' : 'bg-slate-100 text-slate-700'}`}
            >
              {m === 'visu' ? 'Visu' : m === 'search' ? 'Recherche' : 'Action'}
            </button>
          ))}
        </div>
      }
    >
      <div className="mb-4 flex gap-2">
        {(['region', 'department', 'city'] as const).map((l) => (
          <button
            key={l}
            onClick={() => setLevel(l)}
            className={`rounded-md px-2.5 py-1 text-xs ${level === l ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-600'}`}
          >
            {l}
          </button>
        ))}
        <span className="ml-auto text-sm text-slate-500">
          {isLoading ? 'Chargement…' : `${data?.length ?? 0} zones`}
        </span>
      </div>

      <FranceCoverageMap
        cells={data ?? []}
        mode={mode}
        onZoneClick={(code) => {
          setSelected(code);
          if (mode === 'action') void launch(code);
        }}
      />

      {selected ? (
        <div className="mt-4 rounded-xl border border-slate-200 bg-white p-4">
          <p className="text-sm text-slate-600">
            Département sélectionné : <strong>{selected}</strong>
          </p>
          {mode === 'search' ? (
            <p className="mt-1 text-sm text-slate-500">Filtres entreprises appliqués sur la liste.</p>
          ) : null}
        </div>
      ) : null}
    </PageShell>
  );
}
