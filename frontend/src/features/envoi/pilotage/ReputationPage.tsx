/**
 * Réputation par fournisseur (R18) — lecture et saisie. Un constat peut freiner, jamais libérer :
 * « bonne » ne lève aucune pause. Tant que rien n'est relevé, le niveau reste « inconnue ».
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { isAxiosError } from 'axios';
import { Button, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';
import { formaterDateHeure } from './composants/formats';
import { nomFournisseur } from './composants/TemoinsFournisseurs';

type Niveau = 'bonne' | 'moyenne' | 'mauvaise' | 'inconnue';
type Reputation = { fournisseurs: { fournisseur: string; niveau: Niveau; mesure_le: string | null; source: string | null }[] };
const NIVEAU: Record<Niveau, string> = { bonne: 'Bonne', moyenne: 'Moyenne', mauvaise: 'Mauvaise', inconnue: 'Inconnue' };

export function ReputationPage() {
  const qc = useQueryClient();
  const requete = useQuery({ queryKey: ['envoi', 'reputation'], queryFn: async () => (await api.get<Reputation>('/envoi/reputation')).data });
  const [fournisseur, setFournisseur] = useState('');
  const [niveau, setNiveau] = useState<Exclude<Niveau, 'inconnue'>>('bonne');
  const saisie = useMutation({
    mutationFn: async () => api.post('/envoi/reputation/saisies', { fournisseur, niveau }, { headers: { 'Idempotency-Key': crypto.randomUUID() } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['envoi', 'reputation'] }),
  });
  const lignes = requete.data?.fournisseurs;
  const envoyer = (e: FormEvent) => { e.preventDefault(); if (fournisseur !== '') saisie.mutate(); };
  const refus = saisie.isError && isAxiosError(saisie.error) && saisie.error.response?.status === 403;

  return (
    <div>
      <PageHeader title="Réputation" surTitre="Envoi" subtitle="Un constat peut freiner les envois, il ne lève jamais une pause." />
      <OngletsSante />
      <div className="mt-4 space-y-6">
        {requete.isLoading ? <Chargement libelle="Chargement de la réputation" /> : requete.isError || lignes === undefined ? (
          <Panne titre="Réputation inconnue" description="Chiffres illisibles. Par prudence, rien n’est considéré comme bon." onReessayer={() => void requete.refetch()} />
        ) : lignes.length === 0 ? (
          <Vide titre="Aucune réputation relevée" description="Dès qu’un constat est saisi, il apparaît ici." />
        ) : (
          <>
            <div className="overflow-x-auto">
              <table className="w-full min-w-[420px] text-left text-sm">
                <caption className="sr-only">Réputation par fournisseur</caption>
                <thead className="text-xs text-slate-600"><tr><th scope="col" className="py-2 pr-3">Fournisseur</th><th scope="col" className="py-2 pr-3">Niveau</th><th scope="col" className="py-2">Relevé</th></tr></thead>
                <tbody>{lignes.map((l) => (
                  <tr key={l.fournisseur} className="border-t border-slate-100"><td className="py-2 pr-3 font-medium">{nomFournisseur(l.fournisseur)}</td><td className="py-2 pr-3">{NIVEAU[l.niveau]}</td><td className="py-2 text-slate-600">{l.mesure_le === null ? 'jamais relevé' : `${formaterDateHeure(l.mesure_le)}${l.source ? ` · ${l.source}` : ''}`}</td></tr>
                ))}</tbody>
              </table>
            </div>
            <form onSubmit={envoyer} className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4" aria-label="Saisir un constat">
              <label className="text-sm">Messagerie
                <select className="mt-1 block min-h-11 rounded-lg border border-slate-300 px-2 md:min-h-9" value={fournisseur} onChange={(e) => setFournisseur(e.target.value)}>
                  <option value="">Choisir…</option>
                  {lignes.map((l) => <option key={l.fournisseur} value={l.fournisseur}>{nomFournisseur(l.fournisseur)}</option>)}
                </select>
              </label>
              <label className="text-sm">Niveau constaté
                <select className="mt-1 block min-h-11 rounded-lg border border-slate-300 px-2 md:min-h-9" value={niveau} onChange={(e) => setNiveau(e.target.value as typeof niveau)}>
                  <option value="bonne">Bonne</option><option value="moyenne">Moyenne</option><option value="mauvaise">Mauvaise</option>
                </select>
              </label>
              <Button type="submit" variant="primary" className="min-h-11 md:min-h-9" loading={saisie.isPending} disabled={fournisseur === ''}>Enregistrer la réputation</Button>
            </form>
            {saisie.isSuccess ? <p role="status" className="text-sm text-emerald-700">Réputation enregistrée. Elle peut freiner les envois, jamais les libérer.</p> : null}
            {saisie.isError ? <p role="alert" className="text-sm text-red-700">{refus ? 'Seul le propriétaire peut saisir une réputation.' : 'Enregistrement impossible. Réessayez dans un instant.'}</p> : null}
          </>
        )}
      </div>
    </div>
  );
}
