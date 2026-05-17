import { Avatar, Card, CardHeader, CardTitle, StatusPill, mapStatusToTone } from '@/components/ui';

export interface ContactItem {
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
}

export function ContactsCard({ contacts }: { contacts: ContactItem[] }) {
  return (
    <Card padding="md">
      <CardHeader>
        <CardTitle>Contacts ({contacts.length})</CardTitle>
      </CardHeader>
      {contacts.length === 0 ? (
        <p className="text-sm text-slate-500 dark:text-slate-400">Aucun contact identifié.</p>
      ) : (
        <ul className="divide-y divide-slate-100 dark:divide-slate-800">
          {contacts.map((ct) => {
            const fullName = [ct.first_name, ct.last_name].filter(Boolean).join(' ') || '—';
            const tone = ct.email_status ? mapStatusToTone(ct.email_status) : ct.email_score != null && ct.email_score >= 70 ? 'success' : 'warning';
            return (
              <li key={ct.id} className="flex items-start gap-3 py-3 first:pt-0 last:pb-0">
                <Avatar name={fullName} size="sm" />
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2">
                    <div className="truncate text-sm font-medium text-slate-900 dark:text-white">{fullName}</div>
                    {ct.email_score != null ? (
                      <span className="rounded-full bg-slate-100 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-slate-700 dark:bg-slate-800 dark:text-slate-300">
                        {ct.email_score}
                      </span>
                    ) : null}
                  </div>
                  <div className="truncate text-xs text-slate-500 dark:text-slate-400">
                    {ct.role ?? '—'}
                  </div>
                  {ct.email ? (
                    <div className="mt-1 flex items-center gap-2">
                      <a
                        href={`mailto:${ct.email}`}
                        className="truncate text-xs text-brand-700 hover:underline dark:text-sky-300"
                      >
                        {ct.email}
                      </a>
                      <StatusPill tone={tone}>{ct.email_status ?? 'inconnu'}</StatusPill>
                    </div>
                  ) : null}
                  {ct.linkedin_url ? (
                    <a
                      href={ct.linkedin_url}
                      target="_blank"
                      rel="noopener noreferrer"
                      className="mt-0.5 inline-block text-xs text-brand-600 hover:underline dark:text-sky-400"
                    >
                      LinkedIn →
                    </a>
                  ) : null}
                </div>
              </li>
            );
          })}
        </ul>
      )}
    </Card>
  );
}
