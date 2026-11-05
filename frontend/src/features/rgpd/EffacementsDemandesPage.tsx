/**
 * EFFACEMENTS DEMANDÉS — maquette validée `maquettes-crm/effacements.html`.
 *
 * RÈGLE DE FER : rien n'est supprimé. Quand une personne demande l'effacement,
 * elle est mise à l'écart de tous les envois dès la réception ; ses données sont
 * gardées. Il n'existe AUCUN bouton pour supprimer. Le seul geste humain est
 * « Marquer comme traité (mise à l’écart faite) » : la route EXISTANTE
 * `POST /rgpd/requests/{id}/process`, réservée au propriétaire (`rgpd.handle`).
 * Viewer et admin voient la liste (adresses masquées par le serveur) avec le
 * bouton grisé et sa raison.
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ShieldCheck } from 'lucide-react';
import { Button, Card, Modal, PageHeader, SegmentedControl, StatusPill, TableScroll } from '@/components/ui';
import { api } from '@/lib/api';
import { estRefuse, lire } from '@/features/origines/api';
import { useDroits } from '@/features/origines/droits';
import { AccesRefuse, Chargement, Erreur, Vide } from '@/features/origines/Etats';
import { COMPTEURS_A_TRAITER_KEY } from '@/features/a-traiter/compteurs';

type Groupe = 'ouverte' | 'traitee' | 'toutes';
type Statut = 'pending' | 'processing' | 'done' | 'rejected' | 'expired';

interface Demande {
  id: number;
  type: string;
  status: Statut;
  /** Masqué ou absent (null) pour tout rôle sauf owner/admin (rgpd.view_pii). */
  subject_email?: string | null;
  requested_at: string;
  processed_at?: string | null;
  /** Facultatifs : le plan ne les prévoit pas encore (voir PR) ; affichés seulement s'ils sont envoyés. */
  personne_id?: number | null;
  origine?: string | null;
}

/** Réponse paginée de Laravel (25 par page) ; `degraded` = le serveur n'a pas pu lire la base. */
interface ReponseListe {
  data: Demande[];
  degraded?: boolean;
  current_page?: number;
  last_page?: number;
  meta?: { current_page?: number; last_page?: number };
}

export const LIBELLE_ADRESSE_MASQUEE = 'adresse masquée (réservée à l’administrateur)';
export const LIBELLE_ACTION = 'Marquer comme traité (mise à l’écart faite)';
const STATUTS: Record<Statut, string> = { pending: 'À traiter', processing: 'En cours', done: 'Traitée', rejected: 'Rejetée', expired: 'Expirée' };
const OUVERTES: Statut[] = ['pending', 'processing'];
const reference = (id: number): string => `EFF-${String(id).padStart(4, '0')}`;
const quand = (iso: string): string => {
  const d = new Date(iso);
  return `${d.toLocaleDateString('fr-FR', { timeZone: 'Europe/Paris' })} · ${d.toLocaleTimeString('fr-FR', { timeZone: 'Europe/Paris', hour: '2-digit', minute: '2-digit' })}`;
};

/** Adresse affichée : le serveur masque ou retire l'adresse hors owner/admin ; jamais de plantage. */
const adresse = (d: Demande, voir: boolean): string =>
  voir && typeof d.subject_email === 'string' && d.subject_email !== '' ? d.subject_email : LIBELLE_ADRESSE_MASQUEE;

export function EffacementsDemandesPage() {
  const droits = useDroits();
  const qc = useQueryClient();
  const [groupe, setGroupe] = useState<Groupe>('ouverte');
  const [aTraiter, setATraiter] = useState<Demande | null>(null);
  const [page, setPage] = useState(1);
  const choisirGroupe = (g: Groupe) => { setGroupe(g); setPage(1); };

  const q = useQuery({
    queryKey: ['rgpd-requests', 'effacements', groupe, page],
    queryFn: () => lire<ReponseListe>('/rgpd/requests', { statut: groupe, type: 'erasure', page }),
    retry: false,
  });

  const traiter = useMutation({
    mutationFn: async (id: number) => (await api.post<{ request: Demande; result: unknown }>(`/rgpd/requests/${id}/process`)).data,
    onSuccess: () => {
      toast.success('Demande marquée comme traitée : la personne reste à l’écart, rien n’est supprimé.');
      setATraiter(null);
      void qc.invalidateQueries({ queryKey: ['rgpd-requests'] });
      void qc.invalidateQueries({ queryKey: COMPTEURS_A_TRAITER_KEY });
    },
    onError: () => toast.error('Le traitement n’a pas pu aboutir (déjà traitée ou droit manquant).'),
  });

  // Même ordre que le serveur, sur TOUTES les pages : « Ouvertes » = la plus ancienne en haut
  // (délai légal de réponse) ; « Traitées » et « Toutes » = la plus récente en haut.
  const ancienneEnHaut = groupe === 'ouverte';
  const lignes = useMemo(
    () => (q.data?.data ?? []).filter((d) => d.type === 'erasure').sort((a, b) => (Date.parse(a.requested_at) - Date.parse(b.requested_at)) * (ancienneEnHaut ? 1 : -1)),
    [q.data, ancienneEnHaut],
  );
  const pageCourante = q.data?.current_page ?? q.data?.meta?.current_page ?? page;
  const derniere = q.data?.last_page ?? q.data?.meta?.last_page ?? pageCourante;

  let corps;
  if (q.isLoading) corps = <Chargement texte="Chargement des demandes d’effacement…" />;
  else if (q.error !== null && q.data === undefined) {
    corps = estRefuse(q.error) ? (
      <AccesRefuse>
        Vous n’avez aucun droit sur les demandes d’effacement. Le propriétaire les voit et les traite ; les rôles de lecture (viewer, operator, admin) peuvent seulement consulter la liste. Si vous pensez que c’est une erreur, parlez-en au propriétaire.
      </AccesRefuse>
    ) : (
      <Erreur onRetry={() => void q.refetch()} />
    );
  } else if (q.data?.degraded === true) {
    corps = <Vide titre="Données momentanément indisponibles" description="Le serveur n’a pas pu lire les demandes. Cela ne veut pas dire qu’il n’y en a aucune : réessayez dans un instant." />;
  } else if (lignes.length === 0 && groupe === 'ouverte' && page === 1) {
    corps = <Vide titre="Aucune demande d’effacement ouverte" description="Quand une personne en fera une, elle sera mise à l’écart des envois et vous serez alerté ici. Rien n’est supprimé." />;
  } else {
    corps = (
      <Card padding="none" className="overflow-hidden">
        <p className="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">{ancienneEnHaut
          ? 'Tri : date de réception croissante sur toutes les pages, la plus ancienne en haut, à cause du délai légal de réponse.'
          : 'Tri : la plus récente en haut, sur toutes les pages.'} 25 demandes par page.</p>
        <TableScroll template="8rem 1fr 1fr 9rem 22rem">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="px-4 py-2">Demande</th><th>Personne</th><th>Origine</th><th>Statut</th><th>Action</th></tr></thead>
            <tbody>
              {lignes.length === 0 && <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-500">Aucune demande dans ce statut.</td></tr>}
              {lignes.map((d) => {
                const ouverte = OUVERTES.includes(d.status);
                return (
                  <tr key={d.id} className="border-t border-slate-100 align-top dark:border-slate-800">
                    <td className="px-4 py-2"><div className="font-medium">{reference(d.id)}</div><div className="text-xs text-slate-500">reçue le {quand(d.requested_at)}</div></td>
                    <td>
                      {adresse(d, droits.voirAdresseRgpd)}
                      <div className="text-xs text-slate-500">Effacement demandé</div>
                    </td>
                    <td>{d.origine ?? '—'}</td>
                    <td><StatusPill tone={ouverte ? 'warning' : 'neutral'}>{STATUTS[d.status] ?? d.status}</StatusPill></td>
                    <td className="py-2 pr-4">
                      {ouverte ? (
                        <div className="flex flex-col gap-1">
                          <Button variant="primary" size="sm" iconLeft={<ShieldCheck className="h-3.5 w-3.5" />} disabled={!droits.traiter} onClick={() => setATraiter(d)}>
                            {LIBELLE_ACTION}
                          </Button>
                          {!droits.traiter && <span className="text-xs text-slate-500">Lecture seule : seul le propriétaire (droit rgpd.handle) peut traiter une demande.</span>}
                        </div>
                      ) : (
                        <span className="text-xs text-slate-500">{d.processed_at ? `Traitée le ${new Date(d.processed_at).toLocaleDateString('fr-FR')}` : '—'}</span>
                      )}
                      {d.personne_id != null && (
                        <a className="mt-1 inline-block text-xs underline" href={`/console/lettre-et-guide/${d.personne_id}`}>Ouvrir la fiche</a>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </TableScroll>
        <div className="flex items-center justify-between px-4 py-3 text-xs text-slate-500">
          <span>Page {pageCourante} sur {Math.max(derniere, 1)}</span>
          <span className="flex gap-2">
            <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Précédent</Button>
            <Button size="sm" variant="secondary" disabled={pageCourante >= derniere} onClick={() => setPage(page + 1)}>Suivant</Button>
          </span>
        </div>
      </Card>
    );
  }

  return (
    <div>
      <PageHeader surTitre="À traiter" title="Effacements demandés" subtitle="Les personnes qui ont demandé que leurs données soient effacées (hors simples « stop »)." />
      <div role="note" className="mb-4 rounded-lg bg-sky-50 px-4 py-3 text-sm text-sky-900 ring-1 ring-sky-200 dark:bg-sky-950 dark:text-sky-100 dark:ring-sky-800">
        <strong>Rien n’est supprimé. Jamais.</strong> Un « stop » n’est pas une demande d’effacement : il coupe les envois et met la personne à l’écart, sans rien demander d’autre ; il n’apparaît pas dans cette liste. Quand une personne demande l’effacement, elle est mise à l’écart de tous les envois tout de suite : plus aucun message ne lui part. Ses données sont gardées (corbeille douce). Le propriétaire reçoit toujours une notification dans la cloche ; une alerte regroupée (cloche et e-mail) part en plus si les alertes sont allumées.
      </div>
      <div className="mb-4">
        <SegmentedControl
          options={[{ id: 'ouverte', label: 'Ouvertes' }, { id: 'traitee', label: 'Traitées' }, { id: 'toutes', label: 'Toutes' }]}
          value={groupe}
          onChange={choisirGroupe}
        />
        <p className="mt-1 text-xs text-slate-500">« Ouverte » = à traiter + en cours. « Traitée » = traitée + rejetée + expirée.</p>
      </div>
      {corps}
      <Modal
        open={aTraiter !== null}
        onClose={() => setATraiter(null)}
        title="Marquer cette demande comme traitée ?"
        description="La personne reste à l’écart de tous les envois et ses données sont gardées. Rien n’est supprimé : cette action indique seulement que la mise à l’écart est faite. Une demande déjà traitée ne peut pas l’être une deuxième fois."
        footer={
          <>
            <Button variant="secondary" onClick={() => setATraiter(null)}>Annuler</Button>
            <Button variant="primary" loading={traiter.isPending} onClick={() => aTraiter && traiter.mutate(aTraiter.id)}>{LIBELLE_ACTION}</Button>
          </>
        }
      >
        {null}
      </Modal>
    </div>
  );
}
