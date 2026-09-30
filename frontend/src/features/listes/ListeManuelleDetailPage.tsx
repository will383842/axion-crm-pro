/**
 * UNE LISTE MANUELLE (2026-09-30) — renommer, voir ses fiches, en retirer,
 * importer un fichier, la mettre à la corbeille.
 *
 *  - Retirer une fiche de la liste ne SUPPRIME PAS la fiche (ni la ligne
 *    d'appartenance, qui garde qui l'a retirée et quand).
 *  - Importer : on ANALYSE d'abord (rien n'est écrit), le bilan dit combien de
 *    lignes se rapprochent d'une fiche du CRM et combien sont rejetées, par
 *    motif et par numéro de ligne ; puis on importe. Une adresse absente du
 *    CRM n'entre jamais.
 *  - La corbeille, jamais la suppression — et refusée tant qu'une audience se
 *    sert de la liste.
 */
import { useState } from 'react';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { Building, FileUp, Pencil, Trash2, User } from 'lucide-react';
import { Button, Card, EmptyState, Input, PageHeader, QueryErrorState, Spinner } from '@/components/ui';
import { api } from '@/lib/api';
import {
  MOTIFS_REJET,
  messageServeur,
  retirerDeLaListe,
  type BilanImport,
  type ListeManuelle,
  type MembreListe,
} from './listes';

const PAR_PAGE = 50;

export function ListeManuelleDetailPage() {
  const params = useParams({ strict: false }) as { listeId?: string };
  const id = Number(params.listeId);
  const lisible = Number.isFinite(id) && id > 0;
  const qc = useQueryClient();
  const navigate = useNavigate();
  const [page, setPage] = useState(1);
  const [cochees, setCochees] = useState<Set<number>>(new Set());
  const [edition, setEdition] = useState<{ nom: string; description: string } | null>(null);

  const liste = useQuery({
    queryKey: ['liste-manuelle', id],
    queryFn: async () => (await api.get<{ data: ListeManuelle }>(`/listes-manuelles/${id}`)).data.data,
    enabled: lisible,
  });
  const membres = useQuery({
    queryKey: ['liste-manuelle', id, 'membres', page],
    queryFn: async () =>
      (
        await api.get<{ data: MembreListe[]; meta: { total: number } }>(`/listes-manuelles/${id}/membres`, {
          params: { page, per_page: PAR_PAGE },
        })
      ).data,
    enabled: lisible,
  });

  const rafraichir = () => {
    void qc.invalidateQueries({ queryKey: ['liste-manuelle', id] });
    void qc.invalidateQueries({ queryKey: ['listes-manuelles'] });
  };

  const renommage = useMutation({
    mutationFn: async (valeurs: { nom: string; description: string }) =>
      (await api.put(`/listes-manuelles/${id}`, { nom: valeurs.nom.trim(), description: valeurs.description.trim() || null })).data as unknown,
    onSuccess: () => {
      toast.success('Liste enregistrée.');
      setEdition(null);
      rafraichir();
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Enregistrement impossible.'),
  });

  const retrait = useMutation({
    mutationFn: async () => {
      const lignes = (membres.data?.data ?? []).filter((m) => cochees.has(m.id));
      return retirerDeLaListe(id, {
        company_ids: lignes.filter((m) => m.company_id !== null).map((m) => m.company_id as number),
        contact_ids: lignes.filter((m) => m.contact_id !== null).map((m) => m.contact_id as number),
      });
    },
    onSuccess: (b) => {
      toast.success(`${b.retires} fiche(s) retirée(s) de la liste — les fiches elles-mêmes sont intactes.`);
      setCochees(new Set());
      rafraichir();
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Retrait impossible.'),
  });

  const corbeille = useMutation({
    mutationFn: async () => (await api.delete(`/listes-manuelles/${id}`)).data as unknown,
    onSuccess: () => {
      toast.success('Liste mise à la corbeille (elle peut en sortir).');
      void qc.invalidateQueries({ queryKey: ['listes-manuelles'] });
      void navigate({ to: '/listes' });
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Mise à la corbeille impossible.'),
  });

  if (!lisible) {
    return (
      <div className="px-6 py-6">
        <EmptyState title="Adresse de liste invalide" description="Le lien est probablement tronqué." />
      </div>
    );
  }
  if (liste.isLoading) {
    return (
      <div className="flex h-[60vh] items-center justify-center">
        <Spinner />
      </div>
    );
  }
  if (liste.error !== null || liste.data === undefined) {
    return (
      <div className="px-6 py-6">
        <QueryErrorState error={liste.error} contexte="cette liste" onRetry={() => void liste.refetch()} />
      </div>
    );
  }

  const l = liste.data;
  const lignes = membres.data?.data ?? [];
  const total = membres.data?.meta.total ?? 0;
  const pages = Math.max(1, Math.ceil(total / PAR_PAGE));

  const basculer = (membreId: number) => {
    setCochees((actuelles) => {
      const suivantes = new Set(actuelles);
      if (suivantes.has(membreId)) suivantes.delete(membreId);
      else suivantes.add(membreId);
      return suivantes;
    });
  };

  return (
    <div className="px-6 py-6">
      <PageHeader
        breadcrumbs={[{ label: 'Listes manuelles', to: '/listes' }, { label: l.nom }]}
        title={l.nom}
        subtitle={`${l.organisations.toLocaleString('fr-FR')} organisation(s) · ${l.personnes.toLocaleString('fr-FR')} personne(s)`}
        actions={
          <>
            <Button
              variant="secondary"
              size="md"
              iconLeft={<Pencil className="h-4 w-4" />}
              onClick={() => setEdition({ nom: l.nom, description: l.description ?? '' })}
            >
              Renommer
            </Button>
            <Button
              variant="destructive"
              size="md"
              iconLeft={<Trash2 className="h-4 w-4" />}
              loading={corbeille.isPending}
              onClick={() => {
                if (window.confirm(`Mettre la liste « ${l.nom} » à la corbeille ? Ses fiches ne sont pas touchées, et elle pourra en sortir.`)) {
                  corbeille.mutate();
                }
              }}
            >
              Corbeille
            </Button>
          </>
        }
      />

      {edition !== null ? (
        <Card padding="md" className="mb-6">
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(e) => {
              e.preventDefault();
              if (edition.nom.trim() !== '') renommage.mutate(edition);
            }}
          >
            <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300">
              Nom
              <Input value={edition.nom} maxLength={160} onChange={(e) => setEdition({ ...edition, nom: e.target.value })} className="w-72" />
            </label>
            <label className="flex flex-col gap-1 text-xs font-semibold text-slate-600 dark:text-slate-300">
              Description
              <Input
                value={edition.description}
                maxLength={1000}
                onChange={(e) => setEdition({ ...edition, description: e.target.value })}
                className="w-80"
              />
            </label>
            <Button type="submit" size="md" disabled={edition.nom.trim() === '' || renommage.isPending} loading={renommage.isPending}>
              Enregistrer
            </Button>
            <Button type="button" size="md" variant="ghost" onClick={() => setEdition(null)}>
              Annuler
            </Button>
          </form>
        </Card>
      ) : null}

      <ImportFichier listeId={id} onImporte={rafraichir} />

      <Card padding="none" className="overflow-hidden">
        {cochees.size > 0 ? (
          <div className="flex flex-wrap items-center gap-2 border-b border-slate-200 bg-brand-50/60 px-4 py-2 text-sm dark:border-slate-800 dark:bg-slate-800/60">
            <span className="font-medium text-slate-700 dark:text-slate-200">{cochees.size} cochée(s)</span>
            <Button
              size="sm"
              variant="secondary"
              loading={retrait.isPending}
              onClick={() => {
                if (window.confirm(`Retirer ${cochees.size} fiche(s) de la liste ? Les fiches elles-mêmes ne sont pas supprimées.`)) {
                  retrait.mutate();
                }
              }}
            >
              Retirer de la liste
            </Button>
            <Button size="sm" variant="ghost" onClick={() => setCochees(new Set())}>
              Annuler la sélection
            </Button>
          </div>
        ) : null}
        {membres.isLoading ? (
          <div className="flex h-32 items-center justify-center">
            <Spinner />
          </div>
        ) : membres.error !== null ? (
          <QueryErrorState error={membres.error} contexte="les fiches de cette liste" onRetry={() => void membres.refetch()} />
        ) : lignes.length === 0 ? (
          <div className="p-6 text-center text-sm text-slate-500 dark:text-slate-400">
            Aucune fiche dans cette liste. Cochez des fiches dans{' '}
            <Link to="/companies" className="text-indigo-700 underline">Entreprises</Link> ou importez un fichier.
          </div>
        ) : (
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-left text-[11px] font-semibold uppercase tracking-wider text-slate-500 dark:bg-slate-800/60 dark:text-slate-400">
              <tr>
                <th className="w-10 px-4 py-2.5">
                  <input
                    type="checkbox"
                    aria-label="Cocher toute la page"
                    checked={lignes.every((m) => cochees.has(m.id))}
                    onChange={(e) => setCochees(e.target.checked ? new Set(lignes.map((m) => m.id)) : new Set())}
                  />
                </th>
                <th className="px-4 py-2.5">Fiche</th>
                <th className="px-4 py-2.5">Organisation</th>
                <th className="px-4 py-2.5">Adresse</th>
                <th className="px-4 py-2.5">Ajoutée</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-slate-800">
              {lignes.map((m) => (
                <tr key={m.id} data-testid={`membre-${m.id}`}>
                  <td className="px-4 py-2.5">
                    <input
                      type="checkbox"
                      aria-label={`Cocher ${m.type === 'personne' ? `${m.first_name ?? ''} ${m.last_name ?? ''}` : (m.denomination ?? 'la fiche')}`}
                      checked={cochees.has(m.id)}
                      onChange={() => basculer(m.id)}
                    />
                  </td>
                  <td className="px-4 py-2.5">
                    <span className="inline-flex items-center gap-1.5">
                      {m.type === 'personne' ? <User className="h-3.5 w-3.5 text-slate-400" /> : <Building className="h-3.5 w-3.5 text-slate-400" />}
                      {m.type === 'personne' ? `${m.first_name ?? ''} ${m.last_name ?? ''}`.trim() : 'Organisation'}
                      {m.role !== null && m.role !== '' ? <span className="text-xs text-slate-500">· {m.role}</span> : null}
                    </span>
                  </td>
                  <td className="px-4 py-2.5">
                    {m.organisation_id !== null ? (
                      <Link
                        to="/companies/$companyId"
                        params={{ companyId: String(m.organisation_id) }}
                        className="text-indigo-700 hover:underline dark:text-indigo-300"
                      >
                        {m.denomination ?? `Fiche ${m.organisation_id}`}
                      </Link>
                    ) : (
                      '—'
                    )}
                  </td>
                  <td className="px-4 py-2.5 font-mono text-xs">{(m.type === 'personne' ? m.email : m.email_generic) ?? '—'}</td>
                  <td className="px-4 py-2.5 text-xs text-slate-500">
                    {m.origine === 'import' ? 'importée' : 'cochée'} le {new Date(m.ajoute_le).toLocaleDateString('fr-FR')}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
        {pages > 1 ? (
          <div className="flex items-center justify-between border-t border-slate-100 px-4 py-2 text-xs dark:border-slate-800">
            <span>
              Page {page} / {pages} — {total.toLocaleString('fr-FR')} fiche(s)
            </span>
            <span className="flex gap-2">
              <Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Précédente
              </Button>
              <Button size="sm" variant="ghost" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                Suivante
              </Button>
            </span>
          </div>
        ) : null}
      </Card>
    </div>
  );
}

/**
 * Importer un fichier : ANALYSER (à blanc, rien n'est écrit), lire le bilan,
 * puis IMPORTER. Le bilan ne montre que des numéros de ligne, jamais les
 * valeurs du fichier.
 */
function ImportFichier({ listeId, onImporte }: { listeId: number; onImporte: () => void }) {
  const [fichier, setFichier] = useState<File | null>(null);
  const [bilan, setBilan] = useState<BilanImport | null>(null);

  const envoi = useMutation({
    mutationFn: async (aBlanc: boolean) => {
      if (fichier === null) throw new Error('Aucun fichier choisi.');
      const corps = new FormData();
      corps.append('fichier', fichier);
      corps.append('a_blanc', aBlanc ? '1' : '0');
      return (await api.post<{ data: BilanImport }>(`/listes-manuelles/${listeId}/import`, corps)).data.data;
    },
    onSuccess: (b) => {
      setBilan(b);
      if (!b.a_blanc) {
        toast.success(`${b.rapprochees} ligne(s) rapprochée(s), ${b.rejetees.introuvable + b.rejetees.format_inconnu} rejetée(s).`);
        onImporte();
      }
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'Import impossible.'),
  });

  return (
    <Card padding="md" className="mb-6">
      <div className="mb-2 flex items-center gap-2 text-sm font-semibold text-slate-900 dark:text-white">
        <FileUp className="h-4 w-4 text-slate-400" /> Importer un fichier
      </div>
      <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">
        CSV ou JSONL : une ligne par fiche — SIREN, identifiant CRM (<code>organisation:123</code>, <code>contact:45</code>, colonnes
        <code> company_id</code> / <code>contact_id</code>) ou adresse e-mail DÉJÀ présente dans le CRM. Une ligne qui ne correspond à
        aucune fiche est rejetée : rien n’est jamais créé.
      </p>
      <div className="flex flex-wrap items-center gap-2">
        <input
          type="file"
          accept=".csv,.jsonl,.txt,text/csv,application/json"
          aria-label="Fichier à importer"
          onChange={(e) => {
            setFichier(e.target.files?.[0] ?? null);
            setBilan(null);
          }}
          className="text-xs"
        />
        <Button size="sm" variant="secondary" disabled={fichier === null || envoi.isPending} onClick={() => envoi.mutate(true)}>
          Analyser (rien n’est écrit)
        </Button>
        <Button
          size="sm"
          disabled={fichier === null || bilan === null || !bilan.a_blanc || bilan.rapprochees === 0 || envoi.isPending}
          loading={envoi.isPending}
          onClick={() => envoi.mutate(false)}
        >
          Importer {bilan !== null && bilan.a_blanc ? `${bilan.rapprochees} ligne(s)` : ''}
        </Button>
      </div>
      {bilan !== null ? (
        <div role="status" data-testid="bilan-import" className="mt-3 rounded-lg bg-slate-50 p-3 text-xs text-slate-700 dark:bg-slate-800/40 dark:text-slate-200">
          <div className="font-semibold">{bilan.a_blanc ? 'Analyse (rien n’a été écrit)' : 'Import terminé'}</div>
          <ul className="mt-1 space-y-0.5">
            <li>{bilan.lignes_lues} ligne(s) lue(s), {bilan.rapprochees} rapprochée(s) d’une fiche du CRM</li>
            <li>
              {bilan.organisations_retrouvees} organisation(s) et {bilan.personnes_retrouvees} personne(s) retrouvée(s)
            </li>
            <li>
              Rejetées : {bilan.rejetees.introuvable} {MOTIFS_REJET['introuvable']}, {bilan.rejetees.format_inconnu}{' '}
              {MOTIFS_REJET['format_inconnu']}
            </li>
            {bilan.doublons_dans_le_fichier > 0 ? <li>{bilan.doublons_dans_le_fichier} doublon(s) dans le fichier, comptés une fois</li> : null}
            {bilan.ajout !== undefined ? (
              <li>
                Ajoutées : {bilan.ajout.ajoutes + bilan.ajout.reactives}, déjà présentes : {bilan.ajout.deja_presents}
              </li>
            ) : null}
          </ul>
          {bilan.exemples_rejets.length > 0 ? (
            <div className="mt-2">
              Lignes rejetées :{' '}
              {bilan.exemples_rejets.map((r) => `n° ${r.ligne} (${MOTIFS_REJET[r.motif] ?? r.motif})`).join(', ')}
            </div>
          ) : null}
        </div>
      ) : null}
    </Card>
  );
}
