/**
 * Santé des envois (DM2-PIL) — feu par domaine et par boîte, rythme par
 * messagerie destinataire, test du matin des boîtes témoins. Un domaine mène à sa fiche
 * (écrite par DM2-INF : ici seulement le lien).
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from '@tanstack/react-router';
import { FlaskConical } from 'lucide-react';
import { Button, Card, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components as commun } from '@/features/envoi/api/commun';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';
import { Feu, libelleFeu, type EtatFeu } from './composants/Feu';
import { formaterHeure, formaterNombre, formaterPourcent, part } from './composants/formats';

type Sante = {
  feu: EtatFeu; boites: commun['schemas']['BoiteResume'][]; domaines: commun['schemas']['DomaineResume'][];
  serveurs: commun['schemas']['ServeurResume'][]; test_du_matin: { le: string; temoins_ok: number; temoins_total: number; etat: EtatFeu };
  alertes: number; verifie_le: string; volume_jour: { possibles: number; envoyes: number };
  rythme_messageries: { messagerie: string; envoyes: number; par_heure: number; etat: 'normal' | 'freine' }[];
  envoyes_14_jours: { jour: string; envoyes: number }[];
};

const PHRASE: Record<EtatFeu, string> = { vert: 'Envois normaux', ambre: 'Envois à surveiller', rouge: 'Envois arrêtés', bleu: 'Envois en montée', gris: 'État des envois inconnu' };

function Jauge({ libelle, valeur, texte }: { libelle: string; valeur: number; texte: string }) {
  return (
    <div role="meter" aria-label={libelle} aria-valuemin={0} aria-valuemax={100} aria-valuenow={Math.round(valeur)} aria-valuetext={texte} className="mt-2">
      <div className="flex justify-between text-xs text-slate-600"><span>{libelle}</span><span>{texte}</span></div>
      <div className="mt-1 h-2 rounded-full bg-slate-100"><div className="h-2 rounded-full bg-slate-600" style={{ width: `${Math.min(100, Math.max(0, valeur))}%` }} /></div>
    </div>
  );
}

export function SantePage() {
  const qc = useQueryClient();
  const requete = useQuery({ queryKey: ['envoi', 'sante'], queryFn: async () => (await api.get<Sante>('/envoi/sante')).data });
  const test = useMutation({
    mutationFn: async () => (await api.post<{ le: string }>('/envoi/sante/test')).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['envoi', 'sante'] }),
  });
  const s = requete.data;
  const vide = s !== undefined && s.domaines.length === 0 && s.boites.length === 0;
  const ralenties = s?.boites.filter((b) => b.statut === 'ralentie') ?? [];
  const pretes = s?.boites.filter((b) => b.statut === 'prete').length ?? 0;
  const maxJour = Math.max(1, ...(s?.envoyes_14_jours.map((j) => j.envoyes) ?? [1]));

  return (
    <div>
      <PageHeader
        title="Santé des envois"
        surTitre="Envoi"
        subtitle={s ? `${libelleFeu(s.feu)} · ${ralenties.length === 0 ? 'aucune boîte ralentie' : `${ralenties.length} boîte${ralenties.length > 1 ? 's' : ''} ralentie${ralenties.length > 1 ? 's' : ''}`}` : undefined}
        actions={<Button variant="primary" className="min-h-11 md:min-h-9" iconLeft={<FlaskConical className="h-4 w-4" aria-hidden />} loading={test.isPending} onClick={() => test.mutate()}>Lancer un test</Button>}
      />
      <OngletsSante />
      <div className="mt-4">
        {requete.isLoading ? <Chargement libelle="Chargement de la santé des envois" /> : requete.isError && s === undefined ? (
          <Panne titre="État des envois inconnu" description="Chiffres illisibles. Par prudence, pas de vert." onReessayer={() => void requete.refetch()} />
        ) : vide || !s ? (
          <Vide titre="Aucun envoi pour l’instant" description="Ajoutez un domaine pour commencer." action={<Link to="/envoi/domaines" className="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-medium md:min-h-9">Ajouter un domaine</Link>} />
        ) : (
          <div className="space-y-6">
            <div role="status" className="flex flex-wrap items-center gap-3 rounded-xl border border-slate-200 bg-white p-4">
              <Feu etat={s.feu} />
              <div className="min-w-0 flex-1">
                <strong className="text-sm text-slate-900">{PHRASE[s.feu]}</strong>
                <span className="block text-xs text-slate-600">{pretes} boîtes prêtes · {ralenties.length} ralentie{ralenties.length > 1 ? 's' : ''} · test du matin {s.test_du_matin.temoins_ok === s.test_du_matin.temoins_total ? 'réussi' : 'à revoir'}</span>
              </div>
              <span className="text-xs text-slate-600">Vérifié à {formaterHeure(s.verifie_le)}</span>
            </div>
            {test.isSuccess ? <p role="status" className="text-sm text-emerald-700">Test lancé à {formaterHeure(test.data.le)} : les boîtes témoins ont répondu.</p> : null}

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <Card><div className="text-xs text-slate-600">Boîtes prêtes</div><div className="text-2xl font-bold">{pretes}<small className="text-sm font-normal text-slate-600"> / {s.boites.length}</small></div><Jauge libelle="Prêtes" valeur={part(pretes, s.boites.length)} texte={formaterPourcent(part(pretes, s.boites.length))} /></Card>
              <Card><div className="text-xs text-slate-600">Volume du jour</div><div className="text-2xl font-bold">{formaterNombre(s.volume_jour.possibles)}<small className="text-sm font-normal text-slate-600"> possibles</small></div><Jauge libelle="Envoyés" valeur={part(s.volume_jour.envoyes, s.volume_jour.possibles)} texte={`${s.volume_jour.envoyes} envoyés`} /></Card>
              <Card><div className="text-xs text-slate-600">Test du matin</div><div className="text-2xl font-bold">{s.test_du_matin.temoins_ok}<small className="text-sm font-normal text-slate-600"> / {s.test_du_matin.temoins_total} témoins</small></div><div className="mt-2"><Feu etat={s.test_du_matin.etat} /></div></Card>
              <Card><div className="text-xs text-slate-600">Alertes ouvertes</div><div className="text-2xl font-bold">{s.alertes}</div><div className="mt-2 text-xs text-slate-600">dans la cloche et par e-mail</div></Card>
            </div>

            <section aria-labelledby="h-domaines">
              <h2 id="h-domaines" className="mb-3 text-lg font-bold text-slate-900">Feux par domaine</h2>
              <div className="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                {s.domaines.map((d) => (
                  <Link key={d.id} to="/envoi/domaines/$domaineId" params={{ domaineId: d.id }} className="block min-h-11 rounded-xl border border-slate-200 bg-white p-4 hover:border-produit focus-visible:outline-2 focus-visible:outline-offset-2">
                    <strong className="block text-sm text-slate-900">{d.nom}</strong>
                    <span className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-600">
                      <Feu etat={d.etat} />
                      <span>{d.boites} boîte{d.boites > 1 ? 's' : ''}</span>
                      {d.chauffe ? <span>jour {d.chauffe.jour} sur {d.chauffe.sur}</span> : null}
                    </span>
                    <span className="mt-2 block text-xs text-slate-600">DMARC : {d.dmarc === 'none' ? 'aucune' : d.dmarc === 'quarantine' ? 'quarantaine' : 'rejet'}{d.listes_noires > 0 ? ` · ${d.listes_noires} liste${d.listes_noires > 1 ? 's' : ''} noire${d.listes_noires > 1 ? 's' : ''}` : ''}</span>
                  </Link>
                ))}
              </div>
            </section>

            <section aria-labelledby="h-boites">
              <h2 id="h-boites" className="mb-3 text-lg font-bold text-slate-900">Feux par boîte</h2>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[560px] text-left text-sm">
                  <caption className="sr-only">Boîtes d’envoi</caption>
                  <thead className="text-xs text-slate-600"><tr><th scope="col" className="py-2 pr-3">Boîte</th><th scope="col" className="py-2 pr-3">État</th><th scope="col" className="py-2 pr-3 text-right">Aujourd’hui</th><th scope="col" className="py-2 pr-3 text-right">Refus (7 j)</th><th scope="col" className="py-2 text-right">Réponses (7 j)</th></tr></thead>
                  <tbody>{s.boites.map((b) => (
                    <tr key={b.id} className="border-t border-slate-100"><td className="py-2 pr-3 font-medium">{b.adresse}</td><td className="py-2 pr-3"><Feu etat={b.etat} /></td><td className="py-2 pr-3 text-right">{b.envoyes_aujourdhui} / {b.quota_jour}</td><td className="py-2 pr-3 text-right">{formaterPourcent(b.taux_refus)}</td><td className="py-2 text-right">{b.reponses_7j}</td></tr>
                  ))}</tbody>
                </table>
              </div>
            </section>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
              <section aria-labelledby="h-rythme">
                <h2 id="h-rythme" className="mb-3 text-lg font-bold text-slate-900">Rythme par messagerie</h2>
                <div className="overflow-x-auto">
                  <table className="w-full min-w-[420px] text-left text-sm">
                    <caption className="sr-only">Rythme par messagerie destinataire</caption>
                    <thead className="text-xs text-slate-600"><tr><th scope="col" className="py-2 pr-3">Messagerie</th><th scope="col" className="py-2 pr-3 text-right">Envoyés</th><th scope="col" className="py-2 pr-3 text-right">Rythme</th><th scope="col" className="py-2">État</th></tr></thead>
                    <tbody>{s.rythme_messageries.map((r) => (
                      <tr key={r.messagerie} className="border-t border-slate-100"><td className="py-2 pr-3">{r.messagerie}</td><td className="py-2 pr-3 text-right">{r.envoyes}</td><td className="py-2 pr-3 text-right">{r.par_heure} / heure</td><td className="py-2">{r.etat === 'freine' ? 'Freinée' : 'Normal'}</td></tr>
                    ))}</tbody>
                  </table>
                </div>
              </section>
              <section aria-labelledby="h-file">
                <h2 id="h-file" className="mb-3 text-lg font-bold text-slate-900">File d’attente et envoyés sur 14 jours</h2>
                <ul aria-label="Envoyés sur 14 jours" className="flex h-28 items-end gap-1">
                  {s.envoyes_14_jours.map((j) => (
                    <li key={j.jour} className="flex-1" title={`${j.jour} : ${j.envoyes}`}>
                      <div className="rounded-t bg-slate-500" style={{ height: `${Math.max(2, (j.envoyes / maxJour) * 100)}%`, minHeight: 2 }} />
                      <span className="sr-only">{j.jour} : {j.envoyes} envoyés</span>
                    </li>
                  ))}
                </ul>
                <p className="mt-2 text-xs text-slate-600">Du plus ancien (à gauche) à aujourd’hui (à droite).</p>
              </section>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
