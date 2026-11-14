/**
 * Chercher un envoi (DM2-PIL) — une adresse donne tous ses messages, avec
 * le code du serveur traduit en français, la raison et la date.
 */
import { useState, type FormEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Search } from 'lucide-react';
import { Button, Card, PageHeader } from '@/components/ui';
import { api } from '@/lib/api';
import type { components } from '@/features/envoi/api/pilotage';
import { OngletsSante } from './OngletsSante';
import { Chargement, Panne, Vide } from './composants/Etats';
import { formaterDateHeure, telechargerCsv } from './composants/formats';

type RechercheEnvoi = components['schemas']['RechercheEnvoi'];
type Ligne = RechercheEnvoi['data'][number];

const LIBELLES: Record<Ligne['classe'], string> = {
  reponse_humaine: 'Le destinataire a répondu',
  auto_reponse: 'Réponse automatique (absence, accusé)',
  desinscription: 'Désinscription demandée',
  hostile: 'Signalé comme indésirable ou réponse hostile',
  rebond_dur: 'Refusé : adresse refusée par le serveur du destinataire',
  rebond_mou: 'Refus temporaire : nouvel essai prévu',
};

/** La classe du serveur, dite en clair. */
export function enClair(l: Ligne): string {
  return LIBELLES[l.classe] ?? l.classe;
}

export function ChercherEnvoiPage() {
  const [saisie, setSaisie] = useState('');
  const [adresse, setAdresse] = useState<string | null>(null);
  const requete = useQuery({
    queryKey: ['envoi', 'chercher', adresse],
    enabled: adresse !== null,
    queryFn: async () => (await api.get<RechercheEnvoi>('/envoi/envois/chercher', { params: { adresse } })).data.data,
  });
  const lignes = requete.data;

  function chercher(ev: FormEvent) {
    ev.preventDefault();
    if (saisie.trim() !== '') setAdresse(saisie.trim());
  }
  function exporter() {
    if (!lignes) return;
    telechargerCsv('envois-adresse.csv', [['Quand', 'Boîte', 'En clair'], ...lignes.map((l) => [formaterDateHeure(l.recu_le), l.boite, enClair(l)])]);
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
          !lignes ? null : lignes.length === 0 ? <Vide titre="Aucun message pour cette adresse" description="Cette adresse n’a reçu aucun message d’Envoi, ou elle est inconnue de cet espace." /> : (
            <Card>
              <h2 className="mb-3 text-lg font-bold text-slate-900">{lignes.length} événement{lignes.length > 1 ? 's' : ''} pour cette adresse</h2>
              <div className="overflow-x-auto">
                <table className="w-full min-w-[480px] text-left text-sm">
                  <caption className="sr-only">Événements</caption>
                  <thead className="text-xs text-slate-600"><tr><th scope="col" className="py-2 pr-3">Quand</th><th scope="col" className="py-2 pr-3">Boîte</th><th scope="col" className="py-2">En clair</th></tr></thead>
                  <tbody>{lignes.map((l, i) => (
                    <tr key={`${l.recu_le}-${i}`} className="border-t border-slate-100">
                      <td className="py-2 pr-3 whitespace-nowrap">{formaterDateHeure(l.recu_le)}</td>
                      <td className="py-2 pr-3">{l.boite}</td>
                      <td className="py-2">{enClair(l)}</td>
                    </tr>
                  ))}</tbody>
                </table>
              </div>
              <div className="mt-3"><Button variant="secondary" className="min-h-11 md:min-h-9" onClick={exporter}>Exporter</Button></div>
            </Card>
          )}
      </div>
    </div>
  );
}
