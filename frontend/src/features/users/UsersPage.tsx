import { useQuery } from '@tanstack/react-query';
import { PageShell } from '@/components/ui/PageShell';
import { EmptyState } from '@/components/ui/EmptyState';
import { CompaniesTableSkeleton } from '@/components/ui/Skeleton';
import { api } from '@/lib/api';

interface UserRow {
  id: string;
  email: string;
  name: string;
  current_workspace_id?: string|null;
  totp_enabled_at?: string|null;
  first_login_completed_at?: string|null;
  last_login_at?: string|null;
  roles?: string[];
}

export function UsersPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['users'],
    queryFn: async () => (await api.get<{ data: UserRow[] }>('/users')).data,
  });

  return (
    <PageShell title="Utilisateurs" subtitle="4 rôles RBAC : owner / admin / operator / viewer (Spatie Permission teams).">
      {isLoading ? <CompaniesTableSkeleton rows={5} />
        : (data?.data ?? []).length === 0 ? (
          <EmptyState icon="👥" title="Aucun utilisateur" description="Invite ton premier collaborateur depuis Workspace → Inviter." />
        ) : (
          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="min-w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs uppercase text-slate-600">
                <tr>
                  <th className="px-4 py-3">Nom</th>
                  <th>Email</th>
                  <th>Rôles</th>
                  <th>2FA</th>
                  <th>Dernière connexion</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {data!.data.map((u) => (
                  <tr key={u.id}>
                    <td className="px-4 py-2 font-medium">{u.name}</td>
                    <td className="px-4 py-2">{u.email}</td>
                    <td className="px-4 py-2 text-xs">
                      {(u.roles ?? []).map((r) => (
                        <span key={r} className="mr-1 rounded bg-slate-100 px-1.5 py-0.5">{r}</span>
                      ))}
                    </td>
                    <td className="px-4 py-2">
                      {u.totp_enabled_at
                        ? <span className="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-800">Activé</span>
                        : <span className="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Non activé</span>}
                    </td>
                    <td className="px-4 py-2 text-xs text-slate-500">
                      {u.last_login_at ? new Date(u.last_login_at).toLocaleString('fr-FR') : 'Jamais'}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
    </PageShell>
  );
}
