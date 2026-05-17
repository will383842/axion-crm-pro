import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Mail, Phone, Linkedin, UserCircle2 } from 'lucide-react';
import {
  Avatar,
  Card,
  CompaniesTableSkeleton,
  EmptyState,
  PageHeader,
  SearchInput,
  StatusPill,
  type StatusTone,
  Toolbar,
  cn,
} from '@/components/ui';
import { api } from '@/lib/api';

interface Contact {
  id: number;
  first_name?: string | null;
  last_name: string;
  role?: string | null;
  email?: string | null;
  email_status?: string | null;
  email_score?: number | null;
  phone?: string | null;
  linkedin_url?: string | null;
  discovery_source?: string | null;
  company_id?: number | null;
  company?: { id: number; denomination?: string | null } | null;
}

interface ContactsResponse {
  data: Contact[];
  meta: { total: number };
}

const EMAIL_STATUS_OPTIONS: Array<{ value: string; label: string }> = [
  { value: '', label: 'Tous statuts e-mail' },
  { value: 'valid', label: 'Valide' },
  { value: 'catchall', label: 'Catch-all' },
  { value: 'unknown', label: 'Inconnu' },
  { value: 'invalid', label: 'Invalide' },
  { value: 'role', label: 'Rôle générique' },
  { value: 'disposable', label: 'Jetable' },
];

const EMAIL_TONE: Record<string, StatusTone> = {
  valid: 'success',
  catchall: 'warning',
  unknown: 'neutral',
  invalid: 'danger',
  role: 'info',
  disposable: 'danger',
};

const GRID = 'minmax(220px,1.4fr) minmax(140px,1fr) minmax(220px,1.6fr) 110px minmax(140px,1fr) minmax(140px,1fr)';

function fullName(c: Contact) {
  return [c.first_name, c.last_name].filter(Boolean).join(' ') || c.last_name;
}

function translateEmailStatus(status: string): string {
  const map: Record<string, string> = {
    valid: 'Valide',
    catchall: 'Catch-all',
    unknown: 'Inconnu',
    invalid: 'Invalide',
    role: 'Rôle générique',
    disposable: 'Jetable',
  };
  return map[status.toLowerCase()] ?? status;
}

export function ContactsListPage() {
  const [emailStatus, setEmailStatus] = useState('');
  const [search, setSearch] = useState('');

  const { data, isLoading } = useQuery<ContactsResponse>({
    queryKey: ['contacts', emailStatus, search],
    queryFn: async () => {
      const params = new URLSearchParams({ per_page: '50' });
      if (emailStatus) params.set('filter[email_status]', emailStatus);
      if (search) params.set('filter[last_name]', search);
      return (await api.get<ContactsResponse>(`/contacts?${params}`)).data;
    },
  });

  const total = data?.meta.total;
  const rows = data?.data ?? [];
  const hasFilter = Boolean(emailStatus || search);

  return (
    <div className="px-6 py-6">
      <PageHeader
        title="Contacts"
        subtitle={
          <>
            <span className="font-semibold tabular-nums text-slate-700 dark:text-slate-200">
              {total !== undefined ? total.toLocaleString('fr-FR') : '…'}
            </span>{' '}
            décideurs identifiés (waterfall + Direction Finder)
          </>
        }
      />

      <Toolbar
        left={
          <>
            <SearchInput
              value={search}
              onChange={setSearch}
              placeholder="Nom de famille…"
              className="w-72"
            />
            <select
              value={emailStatus}
              onChange={(e) => setEmailStatus(e.target.value)}
              aria-label="Filtre statut email"
              className="h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 transition focus:outline-none focus:ring-2 focus:ring-slate-300 dark:bg-slate-900 dark:text-white dark:ring-slate-700"
            >
              {EMAIL_STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>
                  {o.label}
                </option>
              ))}
            </select>
          </>
        }
      />

      {isLoading ? (
        <CompaniesTableSkeleton rows={6} />
      ) : rows.length === 0 ? (
        <EmptyState
          icon={<UserCircle2 className="h-10 w-10" />}
          title="Aucun contact"
          description={
            hasFilter
              ? 'Aucun contact ne correspond à ces filtres. Réinitialise pour voir plus de résultats.'
              : "Lance l'enrichissement d'entreprises depuis la liste Entreprises."
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
            <div>Contact</div>
            <div>Rôle</div>
            <div>Email</div>
            <div>Score</div>
            <div>Téléphone</div>
            <div>Source</div>
          </div>
          <div role="rowgroup" className="divide-y divide-slate-100 dark:divide-slate-800">
            {rows.map((c) => {
              const name = fullName(c);
              const scoreTone =
                c.email_score === null || c.email_score === undefined
                  ? null
                  : c.email_score >= 70
                    ? 'success'
                    : c.email_score >= 40
                      ? 'warning'
                      : 'danger';
              return (
                <div
                  key={c.id}
                  role="row"
                  className="grid items-center gap-3 px-4 py-3 text-sm transition hover:bg-slate-50/70 dark:hover:bg-slate-800/30"
                  style={{ gridTemplateColumns: GRID }}
                >
                  <div className="flex min-w-0 items-center gap-3">
                    <Avatar name={name} size="sm" />
                    <div className="min-w-0">
                      <div className="truncate font-medium text-slate-900 dark:text-white">
                        {name}
                      </div>
                      {c.company?.denomination ? (
                        <div className="truncate text-xs text-slate-500 dark:text-slate-400">
                          {c.company.denomination}
                        </div>
                      ) : null}
                    </div>
                  </div>
                  <div className="truncate text-slate-600 dark:text-slate-300">{c.role ?? '—'}</div>
                  <div className="min-w-0">
                    {c.email ? (
                      <div className="flex flex-col gap-1">
                        <a
                          href={`mailto:${c.email}`}
                          className="inline-flex items-center gap-1.5 truncate text-slate-900 hover:underline dark:text-slate-200"
                        >
                          <Mail className="h-3.5 w-3.5 shrink-0 text-slate-400" />
                          <span className="truncate">{c.email}</span>
                        </a>
                        {c.email_status ? (
                          <StatusPill tone={EMAIL_TONE[c.email_status] ?? 'neutral'}>
                            {translateEmailStatus(c.email_status)}
                          </StatusPill>
                        ) : null}
                      </div>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </div>
                  <div>
                    {scoreTone && c.email_score !== null && c.email_score !== undefined ? (
                      <StatusPill tone={scoreTone as StatusTone}>{c.email_score}</StatusPill>
                    ) : (
                      <span className="text-slate-400">—</span>
                    )}
                  </div>
                  <div className="truncate text-slate-600 dark:text-slate-300">
                    {c.phone ? (
                      <a
                        href={`tel:${c.phone}`}
                        className="inline-flex items-center gap-1.5 hover:underline"
                      >
                        <Phone className="h-3.5 w-3.5 shrink-0 text-slate-400" />
                        {c.phone}
                      </a>
                    ) : (
                      '—'
                    )}
                  </div>
                  <div className="flex items-center gap-2 text-xs text-slate-500">
                    {c.linkedin_url ? (
                      <a
                        href={c.linkedin_url}
                        target="_blank"
                        rel="noopener noreferrer"
                        className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-sky-700 hover:bg-sky-50 dark:text-sky-300 dark:hover:bg-sky-950/40"
                        aria-label="Profil LinkedIn"
                      >
                        <Linkedin className="h-3.5 w-3.5" />
                      </a>
                    ) : null}
                    <span className="truncate">{c.discovery_source ?? '—'}</span>
                  </div>
                </div>
              );
            })}
          </div>
        </Card>
      )}
    </div>
  );
}
