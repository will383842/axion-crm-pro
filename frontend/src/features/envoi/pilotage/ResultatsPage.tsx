/**
 * Résultats (DM2-PIL) — par boîte, domaine, liste, message type. L'indicateur
 * principal est la réponse. Aucun suivi des clics et aucune ouverture
 * estimée : ni calculée, ni renvoyée par le faux serveur, ni affichée.
 */
import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { Button, Card, KpiCard, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components } from '@/features/envoi/api/pilotage';
import { OngletsCampagnes } from '../campagnes/OngletsCampagnes';
import { Chargement, Panne, Vide } from './composants/Etats';
import { formaterNombre, formaterPourcent, part, telechargerCsv } from './composants/formats';

type Ligne = components['schemas']['LigneResultat'];
type Reponse = {
  data: Ligne[]; totaux: Ligne | null; precedent: Ligne | null; periode: { du: string; au: string };
  entonnoir: { envoyes: number; reponses: number; rendez_vous: number; gagnes: number } | null;
};
type Par = 'boite' | 'domaine' | 'liste' | 'message';
const ONGLETS: ReadonlyArray<{ id: Par; libelle: string; colonne: string }> = [
  { id: 'boite', libelle: 'Par boîte', colonne: 'Boîte' },
  { id: 'domaine', libelle: 'Par domaine', colonne: 'Domaine' },
  { id: 'liste', libelle: 'Par liste', colonne: 'Liste' },
  { id: 'message', libelle: 'Par message type', colonne: 'Message type' },
];
const PERIODES = [7, 30, 90] as const;
const refus = (l: Ligne) => part(l.rebonds_durs + l.rebonds_mous, l.envoyes);

function Evolution({ actuel, avant, libelle }: { actuel: number; avant: number; libelle: string }) {
  const ecart = Math.round((actuel - avant) * 10) / 10;
  const texte = ecart === 0 ? 'Stable' : `${ecart > 0 ? '+' : '−'}${String(Math.abs(ecart)).replace('.', ',')} ${libelle}`;
  return <span>{texte} par rapport à la période d’avant</span>;
}

export function ResultatsPage() {
  const [par, setPar] = useState<Par>('boite');
  const [jours, setJours] = useState<(typeof PERIODES)[number]>(30);
  const [export_, setExport] = useState<string | null>(null);
  const requete = useQuery({
    queryKey: ['envoi', 'resultats', par, jours],
    queryFn: async () => (await api.get<Reponse>('/envoi/resultats', { params: { par, jours } })).data,
  });
  const onglet = ONGLETS.find((o) => o.id === par)!;
  const d = requete.data;
  const vide = d !== undefined && d.data.length === 0;

  function exporter() {
    if (!d) return;
    const entete = [onglet.colonne, ...(par === 'message' ? ['Version'] : []), 'Envoyés', 'Refusés (%)', 'Plaintes', 'Désinscriptions', 'Réponses', 'Intéressées'];
    const lignes = d.data.map((l) => [l.libelle, ...(par === 'message' ? [l.version ?? ''] : []), l.envoyes, refus(l).toFixed(1), l.plaintes, l.desinscriptions, l.reponses, l.reponses_interessees]);
    telechargerCsv(`resultats-${par}.csv`, [entete, ...lignes]);
    setExport(`Export prêt : ${d.data.length} ligne${d.data.length > 1 ? 's' : ''}.`);
  }

  return (
    <div>
      <PageHeader
        title="Résultats"
        surTitre="Envoi"
        subtitle={`Comparer boîtes, domaines, listes et messages · ${jours} derniers jours`}
        actions={<Button variant="primary" className="min-h-11 md:min-h-9" iconLeft={<Download className="h-4 w-4" aria-hidden />} onClick={exporter} disabled={!d || vide}>Exporter tout</Button>}
      />
      <OngletsCampagnes />
      <div className="mt-4 flex flex-wrap items-center gap-2" role="group" aria-label="Période">
        {PERIODES.map((p) => (
          <button key={p} type="button" aria-pressed={jours === p} onClick={() => setJours(p)}
            className={`min-h-11 rounded-lg border px-3 text-sm font-medium md:min-h-9 ${jours === p ? 'border-produit bg-produit-pale text-produit' : 'border-slate-200 bg-white text-slate-700'}`}>
            {p} jours
          </button>
        ))}
      </div>

      <div className="mt-4">
        {requete.isLoading ? <Chargement libelle="Chargement des résultats" /> : requete.isError && d === undefined ? <Panne onReessayer={() => void requete.refetch()} /> : vide || !d || !d.totaux ? <Vide /> : (
          <>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <KpiCard label={`Envoyés (${jours} j)`} value={formaterNombre(d.totaux.envoyes)} sublabel={d.precedent && d.precedent.envoyes > 0 ? `${formaterNombre(d.precedent.envoyes)} sur la période d’avant` : ''} />
              <KpiCard label="Taux de réponse" value={formaterPourcent(part(d.totaux.reponses, d.totaux.remis))} sublabel={`${formaterNombre(d.totaux.reponses)} réponses`} tone="emerald" />
              <KpiCard label="Refusés" value={formaterPourcent(refus(d.totaux))} sublabel="limite 2 %" tone={refus(d.totaux) > 2 ? 'rose' : 'slate'} />
              <KpiCard label="Plaintes" value={formaterPourcent(part(d.totaux.plaintes, d.totaux.remis))} sublabel="limite 0,1 %" tone="slate" />
            </div>
            <p className="mt-3 text-xs text-slate-600">
              Les réponses sont l’indicateur principal.
              {d.precedent && d.precedent.remis > 0 ? <> <Evolution actuel={part(d.totaux.reponses, d.totaux.remis)} avant={part(d.precedent.reponses, d.precedent.remis)} libelle="point de taux de réponse" />.</> : <> Aucun envoi sur la période d’avant : pas de comparaison possible.</>}
            </p>

            {d.entonnoir ? (
              <Card className="mt-4">
                <h2 className="mb-3 text-lg font-bold text-slate-900">Entonnoir</h2>
                <ol aria-label="Entonnoir" className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                  {([['Envoyés', d.entonnoir.envoyes], ['Réponses', d.entonnoir.reponses], ['Rendez-vous', d.entonnoir.rendez_vous], ['Gagnés', d.entonnoir.gagnes]] as const).map(([nom, v], i, tous) => (
                    <li key={nom} className="rounded-lg bg-slate-50 p-3">
                      <div className="text-xs text-slate-600">{i + 1}. {nom}</div>
                      <div className="text-2xl font-bold text-slate-900">{formaterNombre(v)}</div>
                      {i > 0 ? <div className="text-xs text-slate-600">{formaterPourcent(part(v, tous[i - 1]![1]))} de l’étape d’avant</div> : null}
                    </li>
                  ))}
                </ol>
              </Card>
            ) : null}

            <div role="tablist" aria-label="Regroupement" className="mt-6 flex flex-wrap gap-1 border-b border-slate-200">
              {ONGLETS.map((o) => (
                <button key={o.id} type="button" role="tab" id={`tab-${o.id}`} aria-selected={par === o.id} aria-controls="panneau-resultats" onClick={() => { setPar(o.id); setExport(null); }}
                  className={`min-h-11 border-b-2 px-3 text-sm font-medium md:min-h-9 ${par === o.id ? 'border-produit text-produit' : 'border-transparent text-slate-600'}`}>
                  {o.libelle}
                </button>
              ))}
            </div>
            <div role="tabpanel" id="panneau-resultats" aria-labelledby={`tab-${par}`} tabIndex={0} className="mt-3">
              <div className="overflow-x-auto">
                <table className="w-full min-w-[640px] text-left text-sm">
                  <caption className="sr-only">{onglet.libelle}</caption>
                  <thead className="text-xs text-slate-600">
                    <tr>
                      <th scope="col" className="py-2 pr-3">{onglet.colonne}</th>
                      {par === 'message' ? <th scope="col" className="py-2 pr-3">Version</th> : null}
                      <th scope="col" className="py-2 pr-3 text-right">Envoyés</th>
                      <th scope="col" className="py-2 pr-3 text-right">Refusés</th>
                      <th scope="col" className="py-2 pr-3 text-right">Plaintes</th>
                      <th scope="col" className="py-2 pr-3 text-right">Réponses</th>
                      <th scope="col" className="py-2 pr-3 text-right">Intéressées</th>
                      {par === 'message' ? <th scope="col" className="py-2 pr-3 text-right">Taux</th> : null}
                    </tr>
                  </thead>
                  <tbody>
                    {d.data.map((l) => (
                      <tr key={l.cle} className="border-t border-slate-100">
                        <td className="py-2 pr-3 font-medium text-slate-900">{l.libelle}</td>
                        {par === 'message' ? <td className="py-2 pr-3">v{l.version}</td> : null}
                        <td className="py-2 pr-3 text-right">{formaterNombre(l.envoyes)}</td>
                        <td className="py-2 pr-3 text-right">{formaterPourcent(refus(l))}</td>
                        <td className="py-2 pr-3 text-right">{l.plaintes}</td>
                        <td className="py-2 pr-3 text-right">{l.reponses}</td>
                        <td className="py-2 pr-3 text-right">{l.reponses_interessees}</td>
                        {par === 'message' ? <td className="py-2 pr-3 text-right">{formaterPourcent(part(l.reponses, l.remis))}</td> : null}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              <div className="mt-3 flex flex-wrap items-center gap-3">
                <Button variant="secondary" className="min-h-11 md:min-h-9" iconLeft={<Download className="h-4 w-4" aria-hidden />} onClick={exporter}>Exporter (CSV)</Button>
                <p role="status" className="text-sm text-slate-700">{export_}</p>
              </div>
            </div>
          </>
        )}
      </div>
    </div>
  );
}
