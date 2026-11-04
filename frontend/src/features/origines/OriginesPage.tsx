/**
 * ORIGINES DES CONTACTS — cinq onglets (vue d'ensemble, par réseau, par mot,
 * tendance, personnes). Maquette validée : `maquettes-crm/origines.html`.
 *
 * LECTURE SEULE. Aucune suppression, aucun appel d'écriture. Les chiffres sont
 * ceux du serveur (`/crm/origines/*`) : rien n'est recalculé ici, sauf des parts
 * en pourcentage. Ce que le plan ne fournit pas est dans `PLUS-TARD.md` des
 * maquettes (guides envoyés par mot, +x % vs période précédente, etc.).
 */
import { useMemo, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { Link } from '@tanstack/react-router';
import { toast } from 'sonner';
import { Button, Card, PageHeader, Tabs, TableScroll } from '@/components/ui';
import {
  FILTRES_VIDES, estRefuse, exporterCsv, filtresActifs, lire, parametres, periodeTropLongue,
  type Apercu, type Filtres, type LignePersonne, type LigneMot, type LigneReseau, type PointTendance,
} from './api';
import { raisonExportRefuse, useDroits } from './droits';
import { AccesRefuse, Chargement, Erreur, Vide } from './Etats';
import { FAMILLES, RESEAUX_CHOIX, dateFr, jourCourt, libelleFamille, libelleOrigine, libelleReseau, nombre } from './libelles';

type Onglet = 'ensemble' | 'reseau' | 'mot' | 'tendance' | 'personnes';
const ONGLETS: Array<{ id: Onglet; label: string }> = [
  { id: 'ensemble', label: 'Vue d’ensemble' },
  { id: 'reseau', label: 'Par réseau' },
  { id: 'mot', label: 'Par mot' },
  { id: 'tendance', label: 'Tendance' },
  { id: 'personnes', label: 'Personnes' },
];

const part = (n: number, total: number): string => (total > 0 ? `${Math.round((n / total) * 100)} %` : '—');
const champ = 'h-9 rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-200 dark:bg-slate-900 dark:text-white dark:ring-slate-700';

export function OriginesPage() {
  const [onglet, setOnglet] = useState<Onglet>('ensemble');
  const [filtres, setFiltres] = useState<Filtres>(FILTRES_VIDES);
  const trop = periodeTropLongue(filtres.du, filtres.au);
  const params = useMemo(() => parametres(filtres), [filtres]);
  const maj = (p: Partial<Filtres>) => setFiltres((f) => ({ ...f, ...p }));

  const apercu = useQuery({ queryKey: ['crm', 'origines', 'apercu', params], queryFn: () => lire<Apercu>('/crm/origines/apercu', params), retry: false });

  let corps;
  if (apercu.isLoading) corps = <Chargement texte="Chargement des origines des contacts…" />;
  else if (apercu.error !== null && apercu.data === undefined) {
    corps = estRefuse(apercu.error) ? (
      <AccesRefuse>Cet écran n’est pas disponible dans cet espace : il est réservé à l’espace business. Si vous en avez besoin, demandez l’accès au propriétaire.</AccesRefuse>
    ) : (
      <Erreur onRetry={() => void apercu.refetch()} />
    );
  } else if (apercu.data !== undefined && (apercu.data.actif === false || (apercu.data.demandes === 0 && apercu.data.personnes === 0 && !filtresActifs(filtres)))) {
    corps = <Vide titre="Aucune origine enregistrée pour l’instant" description="Dès qu’une personne demande un guide en commentaire, ou arrive par le site, son origine apparaîtra ici." />;
  } else if (apercu.data !== undefined) {
    corps = (
      <>
        <Filtrage filtres={filtres} maj={maj} trop={trop} reinit={() => setFiltres(FILTRES_VIDES)} />
        <div className="mb-4"><Tabs items={ONGLETS} value={onglet} onChange={setOnglet} variant="pills" /></div>
        {onglet === 'ensemble' && <VueEnsemble apercu={apercu.data} params={params} />}
        {onglet === 'reseau' && <ParReseau params={params} />}
        {onglet === 'mot' && <ParMot params={params} />}
        {onglet === 'tendance' && <Tendance params={params} />}
        {onglet === 'personnes' && <Personnes params={params} filtres={filtres} total={apercu.data.demandes} />}
      </>
    );
  }

  return (
    <div>
      <PageHeader
        surTitre="Ma base"
        title="Origines des contacts"
        subtitle="D’où viennent vos contacts : famille d’origine, réseau, mot en commentaire. Chaque contact garde TOUTES ses origines."
      />
      {corps}
    </div>
  );
}

function Filtrage({ filtres, maj, trop, reinit }: { filtres: Filtres; maj: (p: Partial<Filtres>) => void; trop: boolean; reinit: () => void }) {
  const details = filtres.famille !== '' ? Object.entries(FAMILLES[filtres.famille]?.details ?? {}) : [];
  return (
    <Card className="mb-4">
      <div className="flex flex-wrap items-end gap-3 text-sm">
        <label className="flex flex-col gap-1">Famille
          <select className={champ} value={filtres.famille} onChange={(e) => maj({ famille: e.target.value, detail: '' })}>
            <option value="">Toutes</option>
            {Object.entries(FAMILLES).map(([k, v]) => <option key={k} value={k}>{v.libelle}</option>)}
          </select>
        </label>
        <label className="flex flex-col gap-1">Détail
          <select className={champ} value={filtres.detail} disabled={filtres.famille === ''} onChange={(e) => maj({ detail: e.target.value })}>
            <option value="">Tous</option>
            {details.map(([k]) => <option key={k} value={k}>{libelleOrigine(filtres.famille, k)}</option>)}
          </select>
        </label>
        <label className="flex flex-col gap-1">Réseau
          <select className={champ} value={filtres.reseau} onChange={(e) => maj({ reseau: e.target.value })}>
            <option value="">Tous</option>
            {RESEAUX_CHOIX.map((r) => <option key={r} value={r}>{libelleReseau(r)}</option>)}
          </select>
        </label>
        <label className="flex flex-col gap-1">Mot
          <input className={`${champ} w-32`} value={filtres.mot} placeholder="ex. guide" onChange={(e) => maj({ mot: e.target.value.trim().toLowerCase() })} />
        </label>
        <label className="flex flex-col gap-1">Opposé
          <select className={champ} value={filtres.oppose} onChange={(e) => maj({ oppose: e.target.value as Filtres['oppose'] })}>
            <option value="tous">Tous</option>
            <option value="non">Actifs</option>
            <option value="oui">Mis à l’écart</option>
          </select>
        </label>
        <label className="flex flex-col gap-1">Du
          <input type="date" className={champ} value={filtres.du} onChange={(e) => maj({ du: e.target.value })} />
        </label>
        <label className="flex flex-col gap-1">Au
          <input type="date" className={champ} value={filtres.au} onChange={(e) => maj({ au: e.target.value })} />
        </label>
        <Button variant="secondary" size="sm" onClick={reinit}>Réinitialiser</Button>
      </div>
      {trop ? (
        <p role="alert" className="mt-3 text-sm text-rose-700 dark:text-rose-300">
          Période trop longue : 366 jours au maximum entre « Du » et « Au ». Le serveur refuserait la demande (erreur 422).
        </p>
      ) : (
        <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">Période : 366 jours au maximum. Les filtres s’appliquent à tous les onglets.</p>
      )}
    </Card>
  );
}

function Carte({ titre, valeur, aide, id }: { titre: string; valeur: string; aide: string; id?: string }) {
  return (
    <div data-testid={id} className="rounded-xl bg-white p-4 ring-1 ring-slate-200 dark:bg-slate-900 dark:ring-slate-800">
      <div className="text-xs font-medium text-slate-500 dark:text-slate-400">{titre}</div>
      <div className="mt-1 text-2xl font-semibold text-slate-900 dark:text-white">{valeur}</div>
      <p className="mt-1 text-xs text-slate-500 dark:text-slate-400">{aide}</p>
    </div>
  );
}

function useListe<T>(cle: string, chemin: string, params: Record<string, string | number>) {
  return useQuery({ queryKey: ['crm', 'origines', cle, params], queryFn: () => lire<{ actif: boolean; data: T; meta?: Record<string, unknown> }>(chemin, params), retry: false });
}

function Etat({ q, children }: { q: { isLoading: boolean; error: unknown; data: unknown; refetch: () => unknown }; children: React.ReactNode }) {
  if (q.isLoading) return <Chargement texte="Chargement…" />;
  if (q.error !== null && q.data === undefined) return estRefuse(q.error) ? <AccesRefuse>Cet écran n’est pas disponible dans cet espace : il est réservé à l’espace business.</AccesRefuse> : <Erreur onRetry={() => void q.refetch()} />;
  return <>{children}</>;
}

function VueEnsemble({ apercu, params }: { apercu: Apercu; params: Record<string, string> }) {
  const tendance = useListe<PointTendance[]>('tendance-30', '/crm/origines/tendance', { ...params, jours: 30 });
  const nouveaux = tendance.data?.data.reduce((s, p) => s + p.demandes, 0);
  const total = apercu.par_famille.reduce((s, f) => s + f.demandes, 0);
  return (
    <div className="space-y-4">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <Carte titre="Contacts avec une origine" valeur={nombre(apercu.personnes)} aide="au moins une origine enregistrée" />
        {nouveaux !== undefined && <Carte titre="Nouveaux sur 30 jours" valeur={`+${nombre(nouveaux)}`} aide="origines enregistrées sur 30 jours (voir l’onglet Tendance)" />}
        <Carte id="carte-demandes" titre="Demandes" valeur={nombre(apercu.demandes)} aide="arrivées par un mot, un message privé, une publication ou la page guide" />
        <Carte titre="Accords" valeur={nombre(apercu.accords)} aide="accord pour de futures campagnes" />
        <Carte titre="Mis à l’écart (opposés)" valeur={nombre(apercu.opposees)} aide="effacements demandés et « stop » · données gardées, plus aucun envoi" />
      </div>
      <Card>
        <h2 className="text-sm font-semibold text-slate-900 dark:text-white">Par origine</h2>
        <p className="mb-3 text-xs text-slate-500 dark:text-slate-400">Famille d’origine de chaque demande enregistrée. Total : {nombre(total)}.</p>
        <ul className="space-y-2">
          {apercu.par_famille.map((f) => (
            <li key={f.famille ?? 'non-classe'} className="flex items-center gap-3 text-sm">
              <span className="w-40 shrink-0">{libelleFamille(f.famille)}</span>
              <span className="h-2 flex-1 rounded-full bg-slate-100 dark:bg-slate-800"><span className="block h-2 rounded-full bg-brand-600" style={{ width: part(f.demandes, total).replace(' %', '%') }} /></span>
              <span className="w-28 shrink-0 text-right tabular-nums">{nombre(f.demandes)} · {part(f.demandes, total)}</span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function ParReseau({ params }: { params: Record<string, string> }) {
  const q = useListe<LigneReseau[]>('reseau', '/crm/origines/par-reseau', params);
  // Un réseau peut revenir sur plusieurs canaux ou comptes : on regroupe par réseau.
  const lignes = useMemo(() => {
    const m = new Map<string, { reseau: string | null; demandes: number; personnes: number }>();
    for (const l of q.data?.data ?? []) {
      const k = l.reseau ?? '';
      const a = m.get(k) ?? { reseau: l.reseau, demandes: 0, personnes: 0 };
      a.demandes += l.demandes;
      a.personnes += l.personnes;
      m.set(k, a);
    }
    return [...m.values()].sort((a, b) => b.demandes - a.demandes);
  }, [q.data]);
  const total = lignes.reduce((s, l) => s + l.demandes, 0);
  return (
    <Etat q={q}>
      <Card padding="none">
        <p className="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">Demandes par réseau. Les arrivées par le site, un import ou une collecte n’ont pas de réseau : elles sont comptées à part.</p>
        <TableScroll template="1fr 8rem 8rem 8rem">
          <table className="w-full text-sm">
            <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="px-4 py-2">Réseau</th><th>Demandes</th><th>Contacts</th><th>Part des {nombre(total)}</th></tr></thead>
            <tbody>
              {lignes.map((l) => (
                <tr key={l.reseau ?? 'sans'} className="border-t border-slate-100 dark:border-slate-800">
                  <td className="px-4 py-2">{l.reseau === null ? <><span>Sans réseau</span> <span className="text-xs text-slate-500">site, import, collecte</span></> : libelleReseau(l.reseau)}</td>
                  <td className="tabular-nums">{nombre(l.demandes)}</td><td className="tabular-nums">{nombre(l.personnes)}</td><td>{part(l.demandes, total)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </TableScroll>
        <p className="px-4 pb-3 pt-2 text-xs text-slate-500 dark:text-slate-400">LinkedIn et TikTok : réponse publique seulement, aucun message privé automatique.</p>
      </Card>
    </Etat>
  );
}

function ParMot({ params }: { params: Record<string, string> }) {
  const [page, setPage] = useState(1);
  const q = useListe<LigneMot[]>('mot', '/crm/origines/par-mot', { ...params, page });
  const meta = q.data?.meta as { total?: number; has_more?: boolean; per_page?: number } | undefined;
  return (
    <Etat q={q}>
      <Card padding="none">
        <p className="px-4 pt-3 text-xs text-slate-500 dark:text-slate-400">Trié du plus fréquent au moins fréquent, 25 lignes par page. Total : {nombre(meta?.total ?? 0)} mot(s).</p>
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="px-4 py-2">Mot</th><th>Famille › Détail</th><th>Demandes</th><th>Contacts</th></tr></thead>
          <tbody>
            {(q.data?.data ?? []).map((l, i) => (
              <tr key={`${l.mot}-${l.famille}-${l.detail}-${i}`} className="border-t border-slate-100 dark:border-slate-800">
                <td className="px-4 py-2 font-mono">{(l.mot ?? '—').toUpperCase()}</td><td>{libelleOrigine(l.famille, l.detail)}</td>
                <td className="tabular-nums">{nombre(l.demandes)}</td><td className="tabular-nums">{nombre(l.personnes)}</td>
              </tr>
            ))}
          </tbody>
        </table>
        <div className="flex items-center justify-between px-4 py-3 text-xs text-slate-500">
          <span>Page {page}</span>
          <span className="flex gap-2">
            <Button size="sm" variant="secondary" disabled={page <= 1} onClick={() => setPage(page - 1)}>Précédent</Button>
            <Button size="sm" variant="secondary" disabled={meta?.has_more !== true} onClick={() => setPage(page + 1)}>Suivant</Button>
          </span>
        </div>
      </Card>
    </Etat>
  );
}

function Tendance({ params }: { params: Record<string, string> }) {
  const [jours, setJours] = useState(30);
  const q = useListe<PointTendance[]>(`tendance-${jours}`, '/crm/origines/tendance', { ...params, jours });
  const donnees = q.data?.data ?? [];
  const max = Math.max(1, ...donnees.map((p) => p.demandes));
  const total = donnees.reduce((s, p) => s + p.demandes, 0);
  return (
    <Etat q={q}>
      <Card>
        <div className="mb-3 flex items-center justify-between">
          <p className="text-xs text-slate-500 dark:text-slate-400">Nouvelles origines enregistrées par jour. Les jours sans origine comptent 0.</p>
          <label className="flex items-center gap-2 text-xs">Durée
            <select className={champ} value={jours} onChange={(e) => setJours(Number(e.target.value))}>
              {[7, 30, 60, 90].map((j) => <option key={j} value={j}>{j} jours</option>)}
            </select>
          </label>
        </div>
        <svg role="img" aria-label={`Nouvelles origines par jour, total ${total}`} viewBox={`0 0 ${Math.max(donnees.length, 1) * 10} 100`} preserveAspectRatio="none" className="h-40 w-full">
          {donnees.map((p, i) => <rect key={p.jour} x={i * 10 + 1} width={8} y={100 - (p.demandes / max) * 100} height={(p.demandes / max) * 100} className="fill-brand-600" />)}
        </svg>
        <table className="mt-3 w-full text-sm">
          <thead><tr className="text-left text-xs uppercase text-slate-500"><th>Jour</th><th>Nouvelles origines</th></tr></thead>
          <tbody>
            {donnees.map((p) => <tr key={p.jour} className="border-t border-slate-100 dark:border-slate-800"><td>{jourCourt(p.jour)}</td><td className="tabular-nums">{nombre(p.demandes)}</td></tr>)}
            <tr className="border-t font-semibold"><td>Total</td><td className="tabular-nums">{nombre(total)}</td></tr>
          </tbody>
        </table>
      </Card>
    </Etat>
  );
}

function Personnes({ params, filtres, total }: { params: Record<string, string>; filtres: Filtres; total: number }) {
  const droits = useDroits();
  const [curseurs, setCurseurs] = useState<string[]>([]);
  const curseur = curseurs[curseurs.length - 1];
  const q = useQuery({
    queryKey: ['crm', 'origines', 'personnes', params, curseur ?? null],
    queryFn: () => lire<{ actif: boolean; data: LignePersonne[]; meta: { next_cursor: string | null; has_more: boolean } }>('/crm/origines/personnes', { ...params, ...(curseur !== undefined ? { cursor: curseur } : {}) }),
    retry: false,
  });
  const raison = droits.connus ? raisonExportRefuse(droits) : 'Export impossible : vos droits ne sont pas encore connus.';
  const exporter = useMutation({
    mutationFn: () => exporterCsv(filtres),
    onSuccess: (csv) => {
      const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
      const a = document.createElement('a');
      a.href = url; a.download = 'origines.csv'; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
    },
    onError: () => toast.error('L’export n’a pas pu aboutir. Réessayez dans un instant.'),
  });
  return (
    <Etat q={q}>
      <Card padding="none">
        <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
          <p className="text-xs text-slate-500 dark:text-slate-400">Liste filtrée et paginée. Aucun pseudo, aucun texte de commentaire.</p>
          <Button variant="secondary" size="sm" iconLeft={<Download className="h-3.5 w-3.5" />} disabled={raison !== null} loading={exporter.isPending} onClick={() => exporter.mutate()}>
            Exporter (CSV)
          </Button>
        </div>
        <p className="px-4 pb-2 text-xs text-slate-600 dark:text-slate-300">
          {raison ?? `Le fichier contiendra au plus ${nombre(total)} lignes : les personnes opposées, en quarantaine, de provenance tiers insuffisante ou non diffusibles sont exclues (opposés, quarantaine, provenance tiers, non diffusible).`}
        </p>
        <table className="w-full text-sm">
          <thead><tr className="text-left text-xs uppercase text-slate-500"><th className="px-4 py-2">Personne</th><th>Origine</th><th>Réseau</th><th>Mot</th><th>Date</th><th>Origines</th></tr></thead>
          <tbody>
            {(q.data?.data ?? []).length === 0 && <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-500">Aucune personne ne correspond à ces filtres.</td></tr>}
            {(q.data?.data ?? []).map((p) => {
              const o = p.derniere_origine;
              return (
                <tr key={p.id} className="border-t border-slate-100 dark:border-slate-800">
                  <td className="px-4 py-2">
                    <Link to="/console/lettre-et-guide/$personneId" params={{ personneId: String(p.id) }} className="font-medium hover:underline">
                      {[p.first_name, p.last_name].filter(Boolean).join(' ') || 'Personne'}
                    </Link>
                    <div className="text-xs text-slate-500">{p.email ?? '—'}</div>
                  </td>
                  <td>{libelleOrigine(o?.famille, o?.detail)}</td><td>{libelleReseau(o?.reseau) ?? '—'}</td>
                  <td className="font-mono">{o?.mot?.toUpperCase() ?? '—'}</td><td>{dateFr(o?.date)}</td><td className="tabular-nums">{p.demandes}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
        <div className="flex justify-end gap-2 px-4 py-3">
          <Button size="sm" variant="secondary" disabled={curseurs.length === 0} onClick={() => setCurseurs(curseurs.slice(0, -1))}>Précédent</Button>
          <Button size="sm" variant="secondary" disabled={q.data?.meta.has_more !== true} onClick={() => q.data?.meta.next_cursor && setCurseurs([...curseurs, q.data.meta.next_cursor])}>Suivant</Button>
        </div>
      </Card>
    </Etat>
  );
}
