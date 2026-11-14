/** DM2-INF — « Serveurs et adresses IP » : santé, file, paliers de chauffe, ajout d’une adresse IP. */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Button, EmptyState, KpiCard } from '@/components/ui';
import { useConfiguration, useDomaines, useLancerTest, useSanteServeur, useServeurs, type Serveur } from './composants/api';
import { useActionDemandee } from './composants/actionDemandee';
import { DialogueAjouterIp } from './composants/DialogueAjouterIp';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE } from './composants/Formulaire';
import { dateHeure, nombre } from './composants/format';
import { Jauge, PastilleFeu } from './composants/Pastilles';
import { OngletsServeurs } from './OngletsServeurs';

const FLUX = { prospection: 'Prospection', lettre: 'Lettre d’information' } as const;
const STATUT = { prete: 'Prête', ralentie: 'Ralentie', en_chauffe: 'En chauffe', suspendue: 'Suspendue' } as const;

function BlocServeur({ serveur }: { serveur: Serveur }) {
  const sante = useSanteServeur(serveur.id);
  const test = useLancerTest();
  const domaines = useDomaines();
  const nomDomaine = (id: string) => domaines.data?.data.find((d) => d.id === id)?.nom ?? id;
  const s = sante.data;
  return (
    <section aria-labelledby={`srv-${serveur.id}`} className="space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id={`srv-${serveur.id}`} className="text-lg font-bold text-slate-900">{serveur.nom}</h2>
        <div className="flex items-center gap-2"><PastilleFeu etat={serveur.etat} /><span className="text-xs text-slate-600">configuration version {serveur.version_configuration}</span></div>
      </div>
      {s ? (
        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <KpiCard label="En ligne depuis" value={s.en_ligne_depuis_jours === null ? 'Non mesuré' : `${s.en_ligne_depuis_jours} jours`} tone="emerald" />
          <KpiCard label="En file" value={s.en_file === null ? 'Non mesuré' : nombre(s.en_file)} sublabel="messages à envoyer" tone="sky" />
          <KpiCard label="Adresses IP" value={serveur.adresses_ip.length} sublabel={`${serveur.adresses_ip.filter((i) => i.statut === 'en_chauffe').length} en chauffe`} tone="slate" />
          {s.test ? (
            <KpiCard label="Test du matin" value={`${s.test.etapes.filter((e) => e.ok).length}/${s.test.etapes.length}`} sublabel={dateHeure(s.test.fait_le)} tone={s.test.etapes.every((e) => e.ok) ? 'emerald' : 'amber'} />
          ) : (
            <KpiCard label="Test du matin" value="Aucun test" tone="slate" />
          )}
        </div>
      ) : null}
      <div className="relative overflow-x-auto">
        <table className="w-full min-w-[34rem] text-left text-sm">
          <caption className="sr-only">Adresses IP de {serveur.nom}</caption>
          <thead><tr className="text-xs text-slate-500">
            <th scope="col" className="py-1 pr-3 font-medium">Adresse</th><th scope="col" className="py-1 pr-3 font-medium">Flux</th><th scope="col" className="py-1 pr-3 font-medium">Domaine</th><th scope="col" className="py-1 pr-3 font-medium">État</th><th scope="col" className="py-1 text-right font-medium">Volume du jour</th>
          </tr></thead>
          <tbody>
            {serveur.adresses_ip.map((ip) => (
              <tr key={ip.adresse} className="border-t border-slate-100">
                <td className="py-2 pr-3 font-medium text-slate-900">{ip.adresse}</td>
                <td className="py-2 pr-3">{FLUX[ip.flux]}</td>
                <td className="py-2 pr-3"><Link to="/envoi/domaines/$domaineId" params={{ domaineId: ip.domaine_id }} className="text-produit underline">{nomDomaine(ip.domaine_id)}</Link></td>
                <td className="py-2 pr-3">{STATUT[ip.statut]}</td>
                <td className="py-2 text-right">{ip.envoyes_aujourdhui}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {s && s.paliers.length > 0 ? (
        <div className="space-y-2">
          <h3 className="text-sm font-semibold text-slate-900">Paliers de chauffe des adresses IP</h3>
          {s.paliers.map((p) => (
            <Jauge key={p.adresse} libelle={p.adresse} valeur={p.jour} max={p.sur} texte={`jour ${p.jour} sur ${p.sur} · ${p.plafond_aujourdhui} par jour`} />
          ))}
        </div>
      ) : null}
      <Button variant="secondary" className={BOUTON_TACTILE} loading={test.isPending} onClick={() => test.mutate(serveur.id)}>Lancer le test</Button>
      {s?.test ? (
        <ul className="text-xs text-slate-600" aria-label={`Dernier test de ${serveur.nom}`}>
          {s.test.etapes.map((e) => <li key={e.libelle}>{e.ok ? 'Réussi' : 'À corriger'} : {e.libelle}</li>)}
        </ul>
      ) : null}
    </section>
  );
}

export function ServeursPage() {
  const requete = useServeurs();
  const config = useConfiguration();
  const [ajout, setAjout] = useState(false);
  useActionDemandee('ajouter-ip', () => setAjout(true));
  const nbIp = (requete.data ?? []).reduce((n, s) => n + s.adresses_ip.length, 0);
  return (
    <div>
      <Entete
        title="Serveurs et adresses IP"
        phrase={requete.data && requete.data.length > 0 ? <>{requete.data.length} serveur{requete.data.length > 1 ? 's' : ''} · {nbIp} adresses IP{config.data?.prete ? <> · version {config.data.prete.numero} prête</> : null}.</> : undefined}
        actions={<Button variant="primary" className={BOUTON_TACTILE} onClick={() => setAjout(true)}>Ajouter une adresse IP</Button>}
      />
      <OngletsServeurs />
      <div className="mt-4 space-y-4">
        <Etats
          requete={requete}
          contexte="les serveurs d’envoi"
          estVide={(d) => d.length === 0}
          vide={<EmptyState title="Aucun serveur d’envoi" description="Le premier serveur d’envoi apparaîtra ici dès qu’il sera relié." />}
        >
          {(serveurs) => <>{serveurs.map((s) => <BlocServeur key={s.id} serveur={s} />)}</>}
        </Etats>
      </div>
      <DialogueAjouterIp ouvert={ajout} fermer={() => setAjout(false)} />
    </div>
  );
}
