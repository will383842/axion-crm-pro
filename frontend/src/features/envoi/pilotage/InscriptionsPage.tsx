/** Inscriptions (K14-ECRAN) — état des cinq inscriptions de réputation, lu du feu vert. Lecture seule. */
import { useQuery } from '@tanstack/react-query';
import { Card, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components } from '../api/pilotage';
import type { components as reputationApi } from '../api/reputation';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';
import { SERVICES, TITRE_FICHE_K14, libelleEtat, libelleReputation } from './inscriptionsLibelles';

type Point = components['schemas']['PointFeuVert'];
type Constat = reputationApi['schemas']['ConstatReputation'];

const jourFr = (iso: string) => new Date(`${iso}T00:00:00`).toLocaleDateString('fr-FR', { day: 'numeric', month: 'long', year: 'numeric' });

export function InscriptionsPage() {
  const feuVert = useQuery({ queryKey: ['envoi', 'inscriptions', 'feu-vert'], queryFn: async () => (await api.get<{ points: Point[] }>('/envoi/feu-vert')).data.points });
  // Le constat est un « en plus » : sa panne ne cache pas l'état des inscriptions.
  const reputation = useQuery({ queryKey: ['envoi', 'inscriptions', 'reputation'], queryFn: async () => (await api.get<{ data: Constat[] }>('/envoi/reputation')).data.data });
  const points = feuVert.data;
  const constats = reputation.data ?? [];

  return (
    <div className="space-y-4">
      <PageHeader title="Inscriptions" surTitre="Envoi" subtitle="Lecture seule : cet écran n’inscrit rien et n’envoie rien." />
      <OngletsSante />
      {feuVert.isLoading ? <Chargement libelle="Chargement des inscriptions" /> : feuVert.isError || points === undefined ? (
        <Panne onReessayer={() => { void feuVert.refetch(); void reputation.refetch(); }} />
      ) : points.length === 0 ? (
        <Vide titre="Aucune inscription à afficher" description="Le feu vert ne liste encore aucun point : rien à lire pour le moment." />
      ) : (
        <Card>
          <ul className="divide-y divide-slate-100">
            {SERVICES.map((s) => {
              const etat = libelleEtat(points.find((p) => p.code === s.code)?.etat);
              const dernier = constats.filter((c) => s.sources.includes(c.source)).sort((a, b) => b.jour.localeCompare(a.jour) || b.id - a.id)[0];
              return (
                <li key={s.code} className="py-3">
                  <h3 className="text-base font-semibold text-slate-900">{s.nom}</h3>
                  <p className="mt-1 text-sm text-slate-700">Inscription : <strong>{etat}</strong></p>
                  <p className="text-sm text-slate-600">
                    {s.sources.length === 0 ? 'Réputation : aucun constat saisi pour ce service.'
                      : reputation.isError ? 'Réputation : lecture impossible pour le moment.'
                      : dernier ? `Dernier constat : ${libelleReputation(dernier.reputation)}, le ${jourFr(dernier.jour)}.` : 'Réputation : aucun constat.'}
                  </p>
                </li>
              );
            })}
          </ul>
          <p className="mt-3 text-sm text-slate-600">Pas à pas : voir la {TITRE_FICHE_K14} dans la documentation du dépôt.</p>
        </Card>
      )}
    </div>
  );
}
