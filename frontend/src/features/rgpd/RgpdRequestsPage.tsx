import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ClipboardList, FilePlus2, ShieldCheck } from 'lucide-react';
import {
  Button,
  Card,
  CompaniesTableSkeleton,
  EmptyState,
  Input,
  Modal,
  PageHeader,
  SegmentedControl,
  StatusPill,
  mapStatusToTone,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

type RgpdType = 'access' | 'portability' | 'erasure' | 'rectification' | 'opposition';
type RgpdStatus = 'pending' | 'processing' | 'done' | 'rejected' | 'expired';

function translateRgpdStatus(status: string): string {
  const map: Record<string, string> = {
    pending: 'En attente',
    processing: 'En traitement',
    done: 'Traitée',
    rejected: 'Rejetée',
    expired: 'Expirée',
  };
  return map[status.toLowerCase()] ?? status;
}

function translateRgpdType(type: string): string {
  const map: Record<string, string> = {
    access: 'Accès',
    portability: 'Portabilité',
    erasure: 'Suppression',
    rectification: 'Rectification',
    opposition: 'Opposition',
  };
  return map[type.toLowerCase()] ?? type;
}

interface RgpdRequest {
  id: number;
  type: RgpdType;
  status: RgpdStatus;
  subject_email: string;
  requested_at: string;
  processed_at?: string | null;
  note?: string | null;
}

const TYPE_OPTIONS: Array<{ id: RgpdType | 'all'; label: string; article?: string }> = [
  { id: 'all', label: 'Toutes' },
  { id: 'access', label: 'Accès', article: 'art. 15' },
  { id: 'portability', label: 'Portabilité', article: 'art. 20' },
  { id: 'erasure', label: 'Suppression', article: 'art. 17' },
  { id: 'rectification', label: 'Rectification', article: 'art. 16' },
  { id: 'opposition', label: 'Opposition', article: 'art. 21' },
];

const GRID = 'minmax(140px,1fr) minmax(240px,1.4fr) 130px 180px 180px 140px';

export function RgpdRequestsPage() {
  const qc = useQueryClient();
  const [typeFilter, setTypeFilter] = useState<RgpdType | 'all'>('all');
  const [newOpen, setNewOpen] = useState(false);
  const [processOpen, setProcessOpen] = useState<RgpdRequest | null>(null);
  const [newType, setNewType] = useState<RgpdType>('erasure');
  const [newEmail, setNewEmail] = useState('');
  const [processNote, setProcessNote] = useState('');

  const list = useQuery({
    queryKey: ['rgpd-requests'],
    queryFn: async () => (await api.get<{ data: RgpdRequest[] }>('/rgpd/requests')).data,
  });

  const createMut = useMutation({
    mutationFn: async () =>
      (await api.post('/rgpd/requests', { type: newType, subject_email: newEmail })).data,
    onSuccess: () => {
      toast.success('Requête RGPD créée');
      setNewOpen(false);
      setNewEmail('');
      setNewType('erasure');
      qc.invalidateQueries({ queryKey: ['rgpd-requests'] });
    },
    onError: () => toast.error('Erreur création requête'),
  });

  const processMut = useMutation({
    mutationFn: async (id: number) =>
      (await api.post(`/rgpd/requests/${id}/process`, { note: processNote })).data,
    onSuccess: () => {
      toast.success('Requête traitée');
      setProcessOpen(null);
      setProcessNote('');
      qc.invalidateQueries({ queryKey: ['rgpd-requests'] });
    },
    onError: () => toast.error('Erreur traitement'),
  });

  const rows = useMemo(() => {
    const all = list.data?.data ?? [];
    return typeFilter === 'all' ? all : all.filter((r) => r.type === typeFilter);
  }, [list.data, typeFilter]);

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Requêtes RGPD"
        subtitle="Articles 15-22 : accès / portabilité / suppression / rectification / opposition."
        actions={
          <Button
            variant="primary"
            iconLeft={<FilePlus2 className="h-3.5 w-3.5" />}
            onClick={() => setNewOpen(true)}
          >
            Nouvelle requête
          </Button>
        }
      />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <SegmentedControl
          options={TYPE_OPTIONS.map((t) => ({
            id: t.id,
            label: t.article ? `${t.label} · ${t.article}` : t.label,
          }))}
          value={typeFilter}
          onChange={(v) => setTypeFilter(v)}
        />
      </div>

      {list.isLoading ? (
        <CompaniesTableSkeleton rows={5} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<ClipboardList className="h-10 w-10" />}
          title="Aucune requête RGPD"
          description="Les requêtes apparaitront ici après création par les sujets concernés."
          action={
            <Button
              variant="primary"
              iconLeft={<FilePlus2 className="h-3.5 w-3.5" />}
              onClick={() => setNewOpen(true)}
            >
              Créer une requête
            </Button>
          }
        />
      ) : (
        <Card padding="none" className="overflow-hidden">
          <div
            role="row"
            className={cn(
              'sticky top-0 z-10 grid items-center gap-3 border-b border-slate-200 bg-slate-50/80 px-4 py-3 text-[11px] font-semibold uppercase tracking-wider text-slate-600 backdrop-blur',
              'dark:border-slate-800 dark:bg-slate-900/80 dark:text-slate-400',
            )}
            style={{ gridTemplateColumns: GRID }}
          >
            <div>Type</div>
            <div>Sujet</div>
            <div>Statut</div>
            <div>Demande</div>
            <div>Traitement</div>
            <div className="text-right">Actions</div>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((r) => (
              <div
                key={r.id}
                role="row"
                className="grid items-center gap-3 px-4 py-3 text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                style={{ gridTemplateColumns: GRID }}
              >
                <div className="font-medium text-slate-900 dark:text-white">
                  {translateRgpdType(r.type)}
                </div>
                <div className="truncate text-slate-700 dark:text-slate-200">{r.subject_email}</div>
                <div>
                  <StatusPill tone={mapStatusToTone(r.status)} pulse={r.status === 'processing'}>
                    {translateRgpdStatus(r.status)}
                  </StatusPill>
                </div>
                <div className="text-xs text-slate-500">
                  {new Date(r.requested_at).toLocaleString('fr-FR')}
                </div>
                <div className="text-xs text-slate-500">
                  {r.processed_at ? new Date(r.processed_at).toLocaleString('fr-FR') : '—'}
                </div>
                <div className="text-right">
                  {r.status === 'pending' ? (
                    <Button
                      variant="primary"
                      size="sm"
                      onClick={() => setProcessOpen(r)}
                    >
                      Traiter
                    </Button>
                  ) : (
                    <span className="text-xs text-slate-400">—</span>
                  )}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      {/* Modal nouvelle requête */}
      <Modal
        open={newOpen}
        onClose={() => setNewOpen(false)}
        title="Nouvelle requête RGPD"
        description="Articles 15-22 RGPD — création manuelle d'une demande au nom d'une personne concernée."
        footer={
          <>
            <Button variant="secondary" onClick={() => setNewOpen(false)}>
              Annuler
            </Button>
            <Button
              variant="primary"
              onClick={() => createMut.mutate()}
              loading={createMut.isPending}
              disabled={!newEmail}
            >
              Créer
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Type</span>
            <select
              value={newType}
              onChange={(e) => setNewType(e.target.value as RgpdType)}
              className="h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
            >
              <option value="access">Accès (art. 15)</option>
              <option value="portability">Portabilité (art. 20)</option>
              <option value="erasure">Suppression (art. 17)</option>
              <option value="rectification">Rectification (art. 16)</option>
              <option value="opposition">Opposition (art. 21)</option>
            </select>
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
              Email du sujet
            </span>
            <Input
              type="email"
              value={newEmail}
              onChange={(e) => setNewEmail(e.target.value)}
              placeholder="personne.concernee@exemple.com"
              required
            />
          </label>
        </div>
      </Modal>

      {/* Modal process */}
      <Modal
        open={!!processOpen}
        onClose={() => {
          setProcessOpen(null);
          setProcessNote('');
        }}
        title="Traiter la requête"
        description={
          processOpen ? `${processOpen.type.toUpperCase()} · ${processOpen.subject_email}` : undefined
        }
        footer={
          <>
            <Button
              variant="secondary"
              onClick={() => {
                setProcessOpen(null);
                setProcessNote('');
              }}
            >
              Annuler
            </Button>
            <Button
              variant="primary"
              iconLeft={<ShieldCheck className="h-3.5 w-3.5" />}
              onClick={() => processOpen && processMut.mutate(processOpen.id)}
              loading={processMut.isPending}
            >
              Marquer comme traitée
            </Button>
          </>
        }
      >
        <label className="block text-sm">
          <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">
            Note interne (optionnelle)
          </span>
          <textarea
            value={processNote}
            onChange={(e) => setProcessNote(e.target.value)}
            rows={5}
            className="w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
            placeholder="Action effectuée, données purgées, motif de rejet…"
          />
        </label>
      </Modal>
    </div>
  );
}
