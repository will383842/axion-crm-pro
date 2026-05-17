import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ShieldCheck, ShieldOff, UserPlus, Users as UsersIcon } from 'lucide-react';
import {
  Avatar,
  Button,
  Card,
  CompaniesTableSkeleton,
  EmptyState,
  Input,
  Modal,
  PageHeader,
  StatusPill,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';
import { toast } from 'sonner';

interface UserRow {
  id: string;
  email: string;
  name: string;
  current_workspace_id?: string | null;
  totp_enabled_at?: string | null;
  first_login_completed_at?: string | null;
  last_login_at?: string | null;
  roles?: string[];
}

const ROLE_OPTIONS = [
  { value: 'viewer', label: 'Lecteur (lecture seule)' },
  { value: 'operator', label: 'Opérateur (édition)' },
  { value: 'admin', label: 'Admin (gestion équipe)' },
  { value: 'owner', label: 'Propriétaire (owner)' },
];

const GRID = 'minmax(220px,1.4fr) minmax(220px,1.6fr) minmax(160px,1fr) 140px 200px';

function roleToneFor(role: string) {
  if (role === 'owner') return 'danger';
  if (role === 'admin') return 'warning';
  if (role === 'operator') return 'info';
  return 'neutral';
}

function roleLabelFor(role: string): string {
  const map: Record<string, string> = {
    owner: 'Propriétaire',
    admin: 'Admin',
    operator: 'Opérateur',
    viewer: 'Lecteur',
  };
  return map[role.toLowerCase()] ?? role;
}

export function UsersPage() {
  const qc = useQueryClient();
  const [open, setOpen] = useState(false);
  const [inviteEmail, setInviteEmail] = useState('');
  const [inviteRole, setInviteRole] = useState('operator');

  const { data, isLoading } = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.get<{ data: UserRow[] }>('/users')).data,
  });

  const inviteMut = useMutation({
    mutationFn: async () =>
      (await api.post('/users/invite', { email: inviteEmail, role: inviteRole })).data,
    onSuccess: () => {
      toast.success('Invitation envoyée');
      setOpen(false);
      setInviteEmail('');
      setInviteRole('operator');
      qc.invalidateQueries({ queryKey: ['users'] });
    },
    onError: () => toast.error("Impossible d'envoyer l'invitation"),
  });

  const rows = data?.data ?? [];

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Utilisateurs"
        subtitle="4 rôles RBAC : owner / admin / operator / viewer (Spatie Permission teams)."
        actions={
          <Button
            variant="primary"
            iconLeft={<UserPlus className="h-3.5 w-3.5" />}
            onClick={() => setOpen(true)}
          >
            Inviter un utilisateur
          </Button>
        }
      />

      {isLoading ? (
        <CompaniesTableSkeleton rows={5} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<UsersIcon className="h-10 w-10" />}
          title="Aucun utilisateur"
          description="Invite ton premier collaborateur."
          action={
            <Button variant="primary" iconLeft={<UserPlus className="h-3.5 w-3.5" />} onClick={() => setOpen(true)}>
              Inviter
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
            <div>Utilisateur</div>
            <div>Email</div>
            <div>Rôles</div>
            <div>2FA</div>
            <div>Dernière connexion</div>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((u) => (
              <div
                key={u.id}
                role="row"
                className="grid items-center gap-3 px-4 py-3 text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                style={{ gridTemplateColumns: GRID }}
              >
                <div className="flex min-w-0 items-center gap-3">
                  <Avatar name={u.name} size="sm" />
                  <div className="min-w-0 truncate font-medium text-slate-900 dark:text-white">
                    {u.name}
                  </div>
                </div>
                <div className="truncate text-slate-600 dark:text-slate-300">{u.email}</div>
                <div className="flex flex-wrap gap-1">
                  {(u.roles ?? []).length === 0 ? (
                    <span className="text-xs text-slate-400">—</span>
                  ) : (
                    (u.roles ?? []).map((r) => (
                      <StatusPill key={r} tone={roleToneFor(r)}>
                        {roleLabelFor(r)}
                      </StatusPill>
                    ))
                  )}
                </div>
                <div>
                  {u.totp_enabled_at ? (
                    <StatusPill tone="success">
                      <ShieldCheck className="-ml-0.5 mr-0.5 h-3 w-3" /> Activé
                    </StatusPill>
                  ) : (
                    <StatusPill tone="warning">
                      <ShieldOff className="-ml-0.5 mr-0.5 h-3 w-3" /> Non activé
                    </StatusPill>
                  )}
                </div>
                <div className="text-xs text-slate-500 dark:text-slate-400">
                  {u.last_login_at
                    ? new Date(u.last_login_at).toLocaleString('fr-FR')
                    : 'Jamais'}
                </div>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Inviter un utilisateur"
        description="Un email d'invitation sera envoyé pour rejoindre le workspace."
        footer={
          <>
            <Button variant="secondary" onClick={() => setOpen(false)}>
              Annuler
            </Button>
            <Button
              variant="primary"
              onClick={() => inviteMut.mutate()}
              loading={inviteMut.isPending}
              disabled={!inviteEmail}
            >
              Envoyer l'invitation
            </Button>
          </>
        }
      >
        <div className="space-y-4">
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Email</span>
            <Input
              type="email"
              value={inviteEmail}
              onChange={(e) => setInviteEmail(e.target.value)}
              placeholder="prenom.nom@exemple.com"
              required
            />
          </label>
          <label className="block text-sm">
            <span className="mb-1 block font-medium text-slate-700 dark:text-slate-300">Rôle</span>
            <select
              value={inviteRole}
              onChange={(e) => setInviteRole(e.target.value)}
              className="h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
            >
              {ROLE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </label>
        </div>
      </Modal>
    </div>
  );
}
