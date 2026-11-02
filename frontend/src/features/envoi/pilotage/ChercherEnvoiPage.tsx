/**
 * Chercher un envoi (DM2-PIL) — une adresse donne tous ses messages, avec
 * le code du serveur traduit en français, la raison et la date.
 */
import { useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { Button, Card, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components } from '@/features/envoi/api/commun';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';
import { formaterDateHeure, minuscule, telechargerCsv } from './composants/formats';

type Evenement = components['schemas']['EvenementEnvoi'];
interface Resultat { adresse: string; personne: { contact_id: number; nom: string; organisation: string | null } | null; evenements: Evenement[]; peut_ecrire: boolean; raison: string | null }

/** « Refusé : adresse inexistante (550 5.1.1) » : le code du serveur, dit en clair. */
export function enClair(e: Evenement): string {
  const code = e.code_serveur ? ` (${e.code_serveur})` : '';
  const l = e.libelle ? minuscule(e.libelle) : '';
  switch (e.type) {
    case 'rebond_dur': return `Refusé : ${l || 'adresse refusée'}${code}`;
    case 'rebond_mou': return `Refus temporaire : ${l || 'nouvel essai prévu'}${code}`;
    case 'remis': return 'Accepté par le serveur du destinataire';
    case 'plainte': return 'Signalé comme indésirable par le destinataire';
    case 'desinscription': return e.libelle ?? 'Désinscription';
    case 'reponse': return 'Le destinataire a répondu';
    default: return e.libelle ?? '';
  }
}

export function ChercherEnvoiPage() {
  const [saisie, setSaisie] = useState('');
  const [adresse, setAdresse] = useState<string | null>(null);
  const requete = useQuery({
    queryKey: ['envoi', 'chercher', adresse],
    enabled: adresse !== null,
    queryFn: async () => (await api.get<Resultat>('/envoi/envois/chercher', { params: { adresse } })).data,
  });
  const noms = useQuery({
    queryKey: ['envoi', 'chercher-noms'],
    queryFn: async () => {
      const [c, s] = await Promise.all([
        api.get<{ data: { cle: string; libelle: string }[] }>('/envoi/resultats', { params: { par: 'campagne', jours: 90 } }),
        api.get<{ boites: { id: string; adresse: string }[] }>('/envoi/sante'),
      ]);
      return { campagnes: new Map(c.data.data.map((x) => [x.cle, x.libelle])), boites: new Map(s.data.boites.map((b) => [b.id, b.adresse])) };
    },
  });
  const r = requete.data;

  function chercher(ev: FormEvent) {
    ev.preventDefault();
    if (saisie.trim() !== '') setAdresse(saisie.trim());
  }
  function exporter() {
    if (!r) return;
    telechargerCsv('envois-adresse.csv', [['Quand', 'Campagne', 'Boîte', 'Réponse du serveur', 'En clair'], ...r.evenements.map((e) => [formaterDateHeure(e.survenu_le), noms.data?.campagnes.get(e.campagne_id) ?? '—', noms.data?.boites.get(e.boite_id) ?? '—', e.code_serveur ?? '—', enClair(e)])]);
  }

  return (
    <div>
      <PageHeader title="Chercher un envoi" surTitre="Envoi" subtitle="Une adresse donne tous ses messages et la raison en clair." />
      <OngletsSante />
      <form role="search" onSubmit={chercher} className="mt-4 flex flex-wrap items-end gap-3">
        <label className="min-w-0 flex-1 text-sm font-medium text-slate-800">
          Adresse e-mail
          <input type="search" value={saisie} onChange={(e) => setSaisie(e.target.value)} placeholder="prenom.nom@example.test" autoComplete="off"
            className="mt-1 block h-11 w-full rounded-lg border border-slate-300 px-3 text-sm md:h-9" />
        </label>
        <Button type="submit" variant="primary" className="min-h-11 md:min-h-9" iconLeft={<Search className="h-4 w-4" aria-hidden />}>Chercher</Button>
      </form>

      <div className="mt-6">
        {adresse === null ? <Vide titre="Saisissez une adresse" description="Le résultat montrera chaque message, la réponse du serveur et la raison." /> :
          requete.isLoading ? <Chargement libelle="Recherche en cours" /> :
          requete.isError ? <Panne onReessayer={() => void requete.refetch()} /> :
          !r ? null : r.evenements.length === 0 && r.personne === null ? <Vide titre="Aucun message pour cette adresse" description="Cette adresse n’a reçu aucun message d’Envoi." /> : (
            <div className="space-y-4">
              <Card>
                <h2 className="text-lg font-bold text-slate-900">{r.personne ? `${r.personne.nom}${r.personne.organisation ? ` · ${r.personne.organisation}` : ''}` : r.adresse}</h2>
                <p className="mt-1 text-sm text-slate-700">
                  {r.personne ? <>Fiche CRM : {r.adresse} · </> : null}
                  peut-on lui écrire ? <strong>{r.peut_ecrire ? 'Oui' : 'Non'}</strong>{r.raison ? <> ({minuscule(r.raison)})</> : null}.
                </p>
              </Card>
              <Card>
                <h2 className="mb-3 text-lg font-bold text-slate-900">{r.evenements.length} message{r.evenements.length > 1 ? 's' : ''} pour cette adresse</h2>
                {r.evenements.length === 0 ? <p className="text-sm text-slate-600">Aucun message n’est parti vers cette adresse.</p> : (
                  <div className="overflow-x-auto">
                    <table className="w-full min-w-[640px] text-left text-sm">
                      <caption className="sr-only">Messages</caption>
                      <thead className="text-xs text-slate-600"><tr><th scope="col" className="py-2 pr-3">Quand</th><th scope="col" className="py-2 pr-3">Campagne</th><th scope="col" className="py-2 pr-3">Boîte</th><th scope="col" className="py-2 pr-3">Réponse du serveur</th><th scope="col" className="py-2">En clair</th></tr></thead>
                      <tbody>{r.evenements.map((e) => (
                        <tr key={e.id} className="border-t border-slate-100">
                          <td className="py-2 pr-3 whitespace-nowrap">{formaterDateHeure(e.survenu_le)}</td>
                          <td className="py-2 pr-3">{noms.data?.campagnes.get(e.campagne_id) ?? '—'}</td>
                          <td className="py-2 pr-3">{noms.data?.boites.get(e.boite_id) ?? '—'}</td>
                          <td className="py-2 pr-3">{e.code_serveur ?? '—'}</td>
                          <td className="py-2">{enClair(e)}</td>
                        </tr>
                      ))}</tbody>
                    </table>
                  </div>
                )}
                <div className="mt-3"><Button variant="secondary" className="min-h-11 md:min-h-9" onClick={exporter}>Exporter</Button></div>
              </Card>
            </div>
          )}
      </div>
    </div>
  );
}
