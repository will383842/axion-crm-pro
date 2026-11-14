/**
 * Réputation par fournisseur (R18) — lecture et saisie, sur le contrat R17 (`reputation.yaml`).
 * Un constat « mauvaise » peut freiner (pause) ; aucun constat ne lève jamais une pause.
 */
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useState, type FormEvent } from 'react';
import { isAxiosError } from 'axios';
import { Button, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components } from '@/features/envoi/api/reputation';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';

type Constat = components['schemas']['ConstatReputation'];
type Valeur = components['schemas']['ValeurReputation'];
type Source = components['schemas']['SourceReputation'];

const SOURCES: Record<Source, string> = { google_postmaster: 'Google Postmaster', microsoft_snds: 'Microsoft SNDS', microsoft_jmrp: 'Microsoft JMRP', orange: 'Orange' };
const VALEURS: Record<Valeur, string> = { bonne: 'Bonne', moyenne: 'Moyenne', mauvaise: 'Mauvaise', inconnue: 'Inconnue' };

export function ReputationPage() {
  const qc = useQueryClient();
  const requete = useQuery({ queryKey: ['envoi', 'reputation'], queryFn: async () => (await api.get<{ data: Constat[] }>('/envoi/reputation')).data.data });
  const [source, setSource] = useState<Source | ''>('');
  const [ressourceType, setRessourceType] = useState<'ip' | 'domaine'>('domaine');
  const [ressource, setRessource] = useState('');
  const [valeur, setValeur] = useState<Valeur>('bonne');
  const [preuve, setPreuve] = useState('');
  const saisie = useMutation({
    mutationFn: async () => api.post('/envoi/reputation/constats',
      { source, ressource_type: ressourceType, ressource: ressource.trim(), jour: new Date().toISOString().slice(0, 10), reputation: valeur, preuve: preuve.trim() },
      { headers: { 'Idempotency-Key': crypto.randomUUID() } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['envoi', 'reputation'] }),
  });
  const constats = requete.data;
  const pret = source !== '' && ressource.trim().length >= 3 && preuve.trim().length >= 10;
  const envoyer = (e: FormEvent) => { e.preventDefault(); if (pret) saisie.mutate(); };
  const statut = saisie.isError && isAxiosError(saisie.error) ? saisie.error.response?.status : undefined;

  return (
    <div>
      <PageHeader title="Réputation" surTitre="Envoi" subtitle="Un constat peut freiner les envois, il ne lève jamais une pause." />
      <OngletsSante />
      <div className="mt-4 space-y-6">
        {requete.isLoading ? <Chargement libelle="Chargement de la réputation" /> : requete.isError || constats === undefined ? (
          <Panne titre="Réputation inconnue" description="Chiffres illisibles. Par prudence, rien n’est considéré comme bon." onReessayer={() => void requete.refetch()} />
        ) : (
          <>
            {constats.length === 0 ? <Vide titre="Aucune réputation relevée" description="Dès qu’un constat est saisi, il apparaît ici." /> : (
              <div className="overflow-x-auto">
                <table className="w-full min-w-[480px] text-left text-sm">
                  <caption className="sr-only">Constats de réputation</caption>
                  <thead className="text-xs text-slate-600"><tr><th scope="col" className="py-2 pr-3">Source</th><th scope="col" className="py-2 pr-3">Ressource</th><th scope="col" className="py-2 pr-3">Jour</th><th scope="col" className="py-2">Réputation</th></tr></thead>
                  <tbody>{constats.map((c) => (
                    <tr key={c.id} className="border-t border-slate-100"><td className="py-2 pr-3 font-medium">{SOURCES[c.source]}</td><td className="py-2 pr-3">{c.ressource}</td><td className="py-2 pr-3 text-slate-600">{c.jour}</td><td className="py-2">{VALEURS[c.reputation]}{c.pause_ouverte ? ' · pause ouverte' : ''}</td></tr>
                  ))}</tbody>
                </table>
              </div>
            )}
            <form onSubmit={envoyer} className="flex flex-wrap items-end gap-3 rounded-xl border border-slate-200 bg-white p-4" aria-label="Saisir un constat">
              <label className="text-sm">Source
                <select className="mt-1 block min-h-11 rounded-lg border border-slate-300 px-2 md:min-h-9" value={source} onChange={(e) => setSource(e.target.value as Source | '')}>
                  <option value="">Choisir…</option>
                  {(Object.keys(SOURCES) as Source[]).map((s) => <option key={s} value={s}>{SOURCES[s]}</option>)}
                </select>
              </label>
              <label className="text-sm">Type de ressource
                <select className="mt-1 block min-h-11 rounded-lg border border-slate-300 px-2 md:min-h-9" value={ressourceType} onChange={(e) => setRessourceType(e.target.value as 'ip' | 'domaine')}>
                  <option value="domaine">Domaine</option><option value="ip">Adresse IP</option>
                </select>
              </label>
              <label className="text-sm">Ressource
                <input className="mt-1 block min-h-11 rounded-lg border border-slate-300 px-2 md:min-h-9" value={ressource} onChange={(e) => setRessource(e.target.value)} maxLength={253} />
              </label>
              <label className="text-sm">Niveau constaté
                <select className="mt-1 block min-h-11 rounded-lg border border-slate-300 px-2 md:min-h-9" value={valeur} onChange={(e) => setValeur(e.target.value as Valeur)}>
                  {(Object.keys(VALEURS) as Valeur[]).map((v) => <option key={v} value={v}>{VALEURS[v]}</option>)}
                </select>
              </label>
              <label className="w-full text-sm">Ce qui a été lu dans la console (10 à 500 caractères, sans adresse e-mail)
                <textarea className="mt-1 block w-full rounded-lg border border-slate-300 px-2 py-1" rows={2} value={preuve} onChange={(e) => setPreuve(e.target.value)} maxLength={500} />
              </label>
              <Button type="submit" variant="primary" className="min-h-11 md:min-h-9" loading={saisie.isPending} disabled={!pret}>Enregistrer la réputation</Button>
            </form>
            {saisie.isSuccess ? <p role="status" className="text-sm text-emerald-700">Réputation enregistrée. Elle peut freiner les envois, jamais les libérer.</p> : null}
            {saisie.isError ? <p role="alert" className="text-sm text-red-700">{statut === 403 ? 'Seul le propriétaire peut saisir une réputation.' : statut === 422 ? 'Constat refusé : vérifiez la ressource et la preuve (aucune adresse e-mail).' : 'Enregistrement impossible. Réessayez dans un instant.'}</p> : null}
          </>
        )}
      </div>
    </div>
  );
}
