/** Feu vert (DM2-PIL) — les critères avant le premier envoi réel, par groupe, avec leur preuve. */
import { useId, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { Check, Circle, Clock } from 'lucide-react';
import { Button, Card, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components } from '../api/pilotage';
import { messageErreur } from '../infrastructure/composants/format';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';
import { avertissementDemo, libelleBloquants } from './feuVertLibelles';

type Point = components['schemas']['PointFeuVert'];
type Verrous = components['schemas']['VerrousFeuVert'];
interface FeuVert { points: Point[]; faits: number; total: number; bloquants_restants: number; verrous: Verrous }
interface Geste { code: string; preuve: string; fait: boolean }

const PREUVE_MIN = 10;
const PREUVE_MAX = 500;
const GROUPES: ReadonlyArray<[Point['groupe'], string]> = [
  ['verrous', 'Points sur les verrous'], ['banc', 'Banc'], ['dns', 'DNS'], ['serveur', 'Serveur'],
  ['temoins', 'Témoins'], ['conformite', 'Conformité'], ['exploitation', 'Exploitation'],
];
const ETATS: Record<Point['etat'], string> = { fait: 'Fait', a_faire: 'À faire', attend_serveur_reel: 'Attend le serveur réel' };

const quand = (iso: string) => new Date(iso).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

function Verrou({ nom, valeur }: { nom: string; valeur: string }) {
  return <div className="flex justify-between gap-3 py-1 text-sm"><dt className="text-slate-700">{nom}</dt><dd className="font-medium text-slate-900">{valeur}</dd></div>;
}

function BlocVerrous({ v }: { v: Verrous }) {
  return (
    <section aria-labelledby="titre-verrous">
      <Card>
        <h2 id="titre-verrous" className="text-lg font-bold text-slate-900">Verrous</h2>
        <p className="mt-1 text-sm text-slate-600">Lecture seule : cet écran n’ouvre aucun verrou et ne signe rien.</p>
        <dl className="mt-2 divide-y divide-slate-100">
          <Verrou nom="Envoi réel" valeur={v.envoi_reel_actif ? 'Actif' : 'Inactif'} />
          <Verrou nom="Mode témoins" valeur={v.mode_temoins ? 'Actif' : 'Inactif'} />
          <Verrou nom="Signature" valeur={v.signature_presente ? `présente${v.signature_date ? `, du ${quand(v.signature_date)}` : ''}` : 'absente'} />
          <Verrou nom="Fournisseurs témoins couverts" valeur={`${v.fournisseurs_temoins_couverts} sur ${v.fournisseurs_temoins_requis}`} />
        </dl>
      </Card>
    </section>
  );
}

function SaisiePreuve({ point, defaire = false, envoi, erreur, onEnvoyer, onAnnuler }: { point: Point; defaire?: boolean; envoi: boolean; erreur: string | null; onEnvoyer: (t: string) => void; onAnnuler: () => void }) {
  const [texte, setTexte] = useState('');
  const id = useId();
  const longueur = texte.trim().length;
  return (
    <form
      className="mt-2 w-full space-y-1"
      onSubmit={(e) => { e.preventDefault(); if (longueur >= PREUVE_MIN) onEnvoyer(texte.trim()); }}
      onKeyDown={(e) => { if (e.key === 'Escape') onAnnuler(); }}
    >
      <label htmlFor={id} className="block text-sm font-medium text-slate-900">{defaire ? 'Motif (pourquoi vous retirez cette preuve)' : 'Preuve (ce que vous avez constaté)'}</label>
      <p id={`${id}-aide`} className="text-xs text-slate-600">{defaire ? 'Expliquez pourquoi, entre' : 'Décrivez ce que vous avez vérifié, entre'} {PREUVE_MIN} et {PREUVE_MAX} caractères. N’écrivez JAMAIS d’adresse e-mail.</p>
      <textarea id={id} autoFocus rows={3} maxLength={PREUVE_MAX} value={texte} onChange={(e) => setTexte(e.target.value)} aria-describedby={`${id}-aide ${id}-compteur`} aria-invalid={erreur !== null} className="w-full rounded-lg px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-300 focus:outline-none focus:ring-2" />
      <p id={`${id}-compteur`} className="text-xs text-slate-600">{longueur} / {PREUVE_MAX}</p>
      {erreur ? <p role="alert" className="text-sm text-rose-700">{erreur}</p> : null}
      <div className="flex gap-2">
        <Button type="submit" size="sm" className="min-h-11 md:min-h-8" disabled={longueur < PREUVE_MIN} loading={envoi} aria-label={defaire ? 'Confirmer le retrait' : 'Enregistrer la preuve'} title={point.libelle}>{defaire ? 'Retirer la preuve' : 'Enregistrer'}</Button>
        <Button type="button" variant="secondary" size="sm" className="min-h-11 md:min-h-8" onClick={onAnnuler}>Annuler</Button>
      </div>
    </form>
  );
}

function LignePoint({ p, saisie, confirme, envoi, erreur, onSaisir, onConfirmer, onFermer, onGeste }: {
  p: Point; saisie: boolean; confirme: boolean; envoi: boolean; erreur: string | null;
  onSaisir: () => void; onConfirmer: () => void; onFermer: () => void; onGeste: (g: Omit<Geste, 'code'>) => void;
}) {
  const manuel = p.nature === 'manuel';
  const Icone = p.etat === 'fait' ? Check : p.etat === 'attend_serveur_reel' ? Clock : Circle;
  const attendProuve = manuel && p.etat === 'attend_serveur_reel' && p.preuve !== null;
  const etat = attendProuve ? 'Preuve déposée, attend le serveur réel' : ETATS[p.etat];
  const couleur = p.etat === 'fait' ? 'text-emerald-600' : 'text-amber-600';
  return (
    <li className="flex min-h-11 flex-wrap items-center gap-3 py-2">
      <Icone className={`h-5 w-5 ${couleur}`} aria-hidden />
      <span className="min-w-0 flex-1 text-sm text-slate-900">
        {p.libelle}
        <span className="block text-xs text-slate-600">
          {etat} · {manuel ? 'Manuel' : 'Automatique'}{p.bloquant ? ' · Bloquant' : ''}{p.detail ? ` · ${p.detail}` : ''}
        </span>
        {p.preuve ? <span className="block text-xs text-slate-700">Preuve : {p.preuve.texte} — par {p.preuve.par}, le {quand(p.preuve.le)}</span> : null}
      </span>
      {manuel && p.preuve === null && !saisie ? (
        <Button variant="secondary" size="sm" className="min-h-11 md:min-h-8" aria-label={`Marquer fait : ${p.libelle}`} onClick={onSaisir}>Marquer fait</Button>
      ) : null}
      {manuel && p.preuve !== null && !confirme ? (
        <Button variant="secondary" size="sm" className="min-h-11 md:min-h-8" aria-label={`Défaire : ${p.libelle}`} onClick={onConfirmer}>Défaire</Button>
      ) : null}
      {confirme ? <SaisiePreuve point={p} defaire envoi={envoi} erreur={erreur} onAnnuler={onFermer} onEnvoyer={(t) => onGeste({ fait: false, preuve: t })} /> : null}
      {erreur && !saisie && !confirme ? <p role="alert" className="w-full text-sm text-rose-700">{erreur}</p> : null}
      {saisie ? <SaisiePreuve point={p} envoi={envoi} erreur={erreur} onAnnuler={onFermer} onEnvoyer={(t) => onGeste({ fait: true, preuve: t })} /> : null}
    </li>
  );
}

export function FeuVertPage() {
  const qc = useQueryClient();
  const [saisie, setSaisie] = useState<string | null>(null);
  const [confirme, setConfirme] = useState<string | null>(null);
  const [erreur, setErreur] = useState<{ code: string; message: string } | null>(null);
  const requete = useQuery({ queryKey: ['envoi', 'feu-vert'], queryFn: async () => (await api.get<FeuVert>('/envoi/feu-vert')).data });
  const marquer = useMutation({
    mutationFn: async ({ code, preuve, fait }: Geste) => (await api.post<Point>(`/envoi/feu-vert/${code}/fait`, { preuve, fait })).data,
    onSuccess: () => { setSaisie(null); setConfirme(null); setErreur(null); void qc.invalidateQueries({ queryKey: ['envoi', 'feu-vert'] }); },
    onError: (e, g) => setErreur({ code: g.code, message: messageErreur(e) }),
  });
  const f = requete.data;
  const restants = f ? f.total - f.faits : 0;

  return (
    <div>
      <PageHeader title="Feu vert" surTitre="Envoi" subtitle={f ? `${f.faits} critères sur ${f.total} avant le premier envoi.` : undefined} />
      <OngletsSante />
      {avertissementDemo(import.meta.env.VITE_DEMO === '1') ? <p role="note" className="mt-4 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900">{avertissementDemo(true)}</p> : null}
      <div className="mt-4">
        {requete.isLoading ? <Chargement libelle="Chargement du feu vert" /> : requete.isError && f === undefined ? <Panne onReessayer={() => void requete.refetch()} /> : !f || f.points.length === 0 ? <Vide /> : (
          <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div className="space-y-4 lg:col-span-2">
              {GROUPES.map(([groupe, titre]) => {
                const points = f.points.filter((p) => p.groupe === groupe);
                if (points.length === 0) return null;
                return (
                  <Card key={groupe}>
                    <h2 className="mb-1 text-lg font-bold text-slate-900">{titre}</h2>
                    <ul className="divide-y divide-slate-100">
                      {points.map((p) => (
                        <LignePoint
                          key={p.code} p={p} saisie={saisie === p.code} confirme={confirme === p.code}
                          envoi={marquer.isPending && marquer.variables?.code === p.code}
                          erreur={erreur?.code === p.code ? erreur.message : null}
                          onSaisir={() => { setErreur(null); setConfirme(null); setSaisie(p.code); }}
                          onConfirmer={() => { setErreur(null); setSaisie(null); setConfirme(p.code); }}
                          onFermer={() => { setSaisie(null); setConfirme(null); setErreur(null); }}
                          onGeste={(g) => marquer.mutate({ code: p.code, ...g })}
                        />
                      ))}
                    </ul>
                  </Card>
                );
              })}
            </div>
            <div className="space-y-4">
              <BlocVerrous v={f.verrous} />
              <Card>
                <h2 className="text-lg font-bold text-slate-900">Avant le premier envoi</h2>
                <p className="mt-1 text-sm text-slate-700">{f.faits} / {f.total}</p>
                <p className="mt-3 text-sm"><strong>{restants === 0 ? 'Prêt' : 'Presque prêt'}</strong><span className="block text-slate-600">{libelleBloquants(f)}</span></p>
                {restants > 0 ? <Link to="/envoi/reglages" className="mt-4 inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium md:min-h-9">Voir les réglages d’envoi</Link> : null}
              </Card>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
