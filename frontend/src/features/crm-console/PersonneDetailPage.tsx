/**
 * FICHE D'UNE PERSONNE (LETTRE ET GUIDE) — lot L4-C.
 *
 * Identité, abonnement à la lettre (copie du consentement : la PREUVE reste
 * sur le site), tâches et timeline. Deux gestes :
 *   - poser une TÂCHE ou une relance (avec échéance), puis la terminer ;
 *   - RATTACHER à une entreprise : la personne devient un contact du hub, par
 *     le même dédoublonnage que l'ingestion. Un nom est exigé — on n'en
 *     fabrique jamais depuis une adresse.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link, useParams } from '@tanstack/react-router';
import { toast } from 'sonner';
import { Button, Card, CardTitle, EmptyState, Input, PageHeader, QueryErrorState, StatusPill } from '@/components/ui';
import { api } from '@/lib/api';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import {
  BASE_LEGALE_LABELS,
  NATURE_EMAIL_LABELS,
  SOURCE_PERSONNE_LABELS,
  STATUT_LETTRE_LABELS,
  type PersonneFiche,
} from './types';

function formatDate(value: string | null | undefined): string {
  if (value === null || value === undefined) return '—';
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? '—' : date.toLocaleDateString('fr-FR');
}

export function PersonneDetailPage() {
  return (
    <ConsoleGate>
      <PersonneDetailContent />
    </ConsoleGate>
  );
}

function PersonneDetailContent() {
  const { personneId } = useParams({ from: '/layout/console/lettre-et-guide/$personneId' });
  const queryClient = useQueryClient();

  const fiche = useQuery<PersonneFiche>({
    queryKey: ['crm', 'personne', personneId],
    queryFn: async () => (await api.get<PersonneFiche>(`/crm/personnes/${personneId}`)).data,
  });

  const rafraichir = () => {
    void queryClient.invalidateQueries({ queryKey: ['crm', 'personne', personneId] });
    void queryClient.invalidateQueries({ queryKey: ['crm', 'personnes'] });
  };

  const [titre, setTitre] = useState('');
  const [echeance, setEcheance] = useState('');
  const poserTache = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ deja_consignee: boolean }>(`/crm/personnes/${personneId}/taches`, {
          title: titre.trim(),
          ...(echeance !== '' ? { due_at: echeance } : {}),
        })
      ).data,
    onSuccess: (data) => {
      toast.success(data.deja_consignee ? 'Tâche déjà posée.' : 'Tâche posée.');
      setTitre('');
      setEcheance('');
      rafraichir();
    },
    onError: () => toast.error('Tâche impossible : droit de modification requis.'),
  });

  const terminer = useMutation({
    mutationFn: async (activityId: number) =>
      (await api.post<{ done: boolean }>(`/crm/personnes/${personneId}/taches/${activityId}/terminer`)).data,
    onSuccess: () => {
      toast.success('Tâche terminée.');
      rafraichir();
    },
    onError: () => toast.error('Tâche introuvable ou déjà terminée.'),
  });

  const [companyId, setCompanyId] = useState('');
  const [prenom, setPrenom] = useState('');
  const [nom, setNom] = useState('');
  const rattacher = useMutation({
    mutationFn: async () =>
      (
        await api.post<{ contact_created: boolean }>(`/crm/personnes/${personneId}/rattacher`, {
          company_id: Number.parseInt(companyId, 10),
          ...(prenom.trim() !== '' ? { first_name: prenom.trim() } : {}),
          ...(nom.trim() !== '' ? { last_name: nom.trim() } : {}),
        })
      ).data,
    onSuccess: (data) => {
      toast.success(data.contact_created ? 'Rattachée — fiche contact créée.' : 'Rattachée à une fiche contact existante.');
      rafraichir();
    },
    onError: () =>
      toast.error('Rattachement impossible : identifiant d’entreprise et nom de famille requis, et adresse prospectable.'),
  });

  if (fiche.isLoading) {
    return (
      <div className="px-6 py-6">
        <ConsoleListSkeleton rows={6} />
      </div>
    );
  }

  if (fiche.data === undefined) {
    return (
      <div className="px-6 py-6">
        {fiche.error !== null ? (
          <QueryErrorState error={fiche.error} contexte="la fiche de la personne" onRetry={() => void fiche.refetch()} />
        ) : (
          <EmptyState title="Fiche introuvable" description="Cette personne n’existe pas dans votre univers." />
        )}
      </div>
    );
  }

  const { personne, abonnement, entreprise, taches, timeline } = fiche.data;
  const nomAffiche = [personne.first_name, personne.last_name].filter(Boolean).join(' ') || personne.email || 'Personne';
  const parsedCompanyId = Number.parseInt(companyId, 10);
  const nomConnu = (personne.last_name ?? '') !== '';
  const baseLegale = (code: string | null | undefined) => (code ? (BASE_LEGALE_LABELS[code] ?? code) : '—');

  return (
    <div className="px-6 py-6">
      <PageHeader
        title={nomAffiche}
        subtitle={`${SOURCE_PERSONNE_LABELS[personne.premiere_source] ?? personne.premiere_source} · arrivée le ${formatDate(personne.premiere_source_at)}`}
      />

      {!personne.prospection_autorisee && (
        <div
          role="note"
          className="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200 dark:bg-amber-950 dark:text-amber-100 dark:ring-amber-800"
        >
          {personne.email_nature === 'perso'
            ? 'Adresse personnelle sans consentement : aucune prospection.'
            : 'Aucune adresse connue : aucune prospection.'}
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[340px_1fr]">
        <div className="flex flex-col gap-4">
          <Card>
            <CardTitle>Identité</CardTitle>
            <dl className="mt-2 flex flex-col gap-1 text-xs text-slate-600 dark:text-slate-300">
              <div>{personne.email ?? '—'}</div>
              <div className="text-slate-400">{NATURE_EMAIL_LABELS[personne.email_nature]}</div>
              <div className="text-slate-400">Base légale : {baseLegale(personne.legal_basis)}</div>
              {personne.purge_prevue_le !== null && (
                <div className="text-slate-400">Purge prévue le {formatDate(personne.purge_prevue_le)}</div>
              )}
              <Link
                to="/console/personnes/$personKey"
                params={{ personKey: personne.person_key }}
                className="mt-1 underline decoration-dotted underline-offset-2 hover:text-brand-600"
              >
                Fiche 360° (tous univers)
              </Link>
            </dl>
          </Card>

          <Card>
            <CardTitle>Lettre</CardTitle>
            <div className="mt-2 flex flex-col gap-1 text-xs text-slate-600 dark:text-slate-300">
              <StatusPill tone={abonnement?.statut === 'abonne' ? 'success' : 'neutral'}>
                {STATUT_LETTRE_LABELS[abonnement?.statut ?? 'aucun']}
              </StatusPill>
              {abonnement !== null && (
                <>
                  <div>Base légale : {baseLegale(abonnement.legal_basis)}</div>
                  <div>Consentement : {abonnement.consent_version ?? '—'} · {formatDate(abonnement.consent_at)}</div>
                  <div>Placement : {abonnement.source_slug ?? '—'}</div>
                  {abonnement.desabonne_at !== null && <div>Désabonné le {formatDate(abonnement.desabonne_at)}</div>}
                </>
              )}
              <p className="mt-1 text-[11px] text-slate-400">
                Copie du consentement : la preuve (texte, horodatage, double confirmation) reste sur le site.
              </p>
            </div>
          </Card>

          <Card>
            <CardTitle>Entreprise</CardTitle>
            {entreprise !== null ? (
              <p className="mt-2 text-xs text-slate-600 dark:text-slate-300">
                {entreprise.denomination ?? '—'} · SIREN {entreprise.siren ?? '—'}
              </p>
            ) : !personne.prospection_autorisee ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Non rattachée. Le rattachement est fermé : il ferait entrer la personne dans le hub et les audiences, qui
                servent à la prospection.
              </p>
            ) : (
              <div className="mt-2 flex flex-col gap-2">
                <p className="text-xs text-slate-500 dark:text-slate-400">
                  Non rattachée. Une fois rattachée, elle rejoint le hub de contacts et les audiences.
                </p>
                <Input
                  value={companyId}
                  onChange={(e) => setCompanyId(e.target.value)}
                  placeholder="Identifiant d’entreprise, ex. 1842"
                  inputMode="numeric"
                  aria-label="Identifiant d’entreprise"
                />
                {!nomConnu && (
                  <>
                    <Input value={prenom} onChange={(e) => setPrenom(e.target.value)} placeholder="Prénom (facultatif)" aria-label="Prénom" />
                    <Input value={nom} onChange={(e) => setNom(e.target.value)} placeholder="Nom de famille (requis)" aria-label="Nom de famille" />
                  </>
                )}
                <Button
                  variant="primary"
                  size="sm"
                  disabled={rattacher.isPending || Number.isNaN(parsedCompanyId) || (!nomConnu && nom.trim() === '')}
                  onClick={() => rattacher.mutate()}
                >
                  Rattacher à cette entreprise
                </Button>
              </div>
            )}
          </Card>
        </div>

        <div className="flex flex-col gap-4">
          <Card>
            <CardTitle>Tâches et relances</CardTitle>
            <div className="mt-2 flex flex-wrap items-end gap-2">
              <Input
                value={titre}
                onChange={(e) => setTitre(e.target.value)}
                placeholder="ex. Retrouver l’entreprise (SIREN)"
                aria-label="Titre de la tâche"
                className="min-w-64 flex-1"
              />
              <Input type="date" value={echeance} onChange={(e) => setEcheance(e.target.value)} aria-label="Échéance" className="w-40" />
              <Button variant="primary" size="sm" disabled={poserTache.isPending || titre.trim().length < 3} onClick={() => poserTache.mutate()}>
                Poser
              </Button>
            </div>
            {taches.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Aucune tâche.</p>
            ) : (
              <ul className="mt-3 flex flex-col gap-2">
                {taches.map((t) => (
                  <li key={t.id} className="flex items-center justify-between gap-2 text-xs">
                    <span className={t.done_at !== null ? 'text-slate-400 line-through' : 'text-slate-800 dark:text-slate-100'}>
                      {t.title} {t.due_at !== null && <>· échéance {formatDate(t.due_at)}</>}
                    </span>
                    {t.done_at === null && (
                      <Button variant="secondary" size="sm" disabled={terminer.isPending} onClick={() => terminer.mutate(t.id)}>
                        Terminer
                      </Button>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardTitle>Timeline</CardTitle>
            {timeline.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Aucun touchpoint enregistré.</p>
            ) : (
              <ol className="mt-3 flex flex-col gap-3">
                {timeline.map((entry) => (
                  <li key={entry.id} className="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                    <div className="text-xs text-slate-400">{formatDate(entry.occurred_at)}</div>
                    <div className="text-sm text-slate-900 dark:text-white">{entry.title ?? entry.kind}</div>
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </div>
      </div>
    </div>
  );
}
