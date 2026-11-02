/** DM2-INF — « Configuration » : versions, appliquer, retour automatique. */
import { useState } from 'react';
import { Button, EmptyState, StatusPill } from '@/components/ui';
import { useAppliquerConfiguration, useConfiguration, useRevenirConfiguration, type Configuration, type VersionConfiguration } from './composants/api';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE, MessageErreur } from './composants/Formulaire';
import { dateHeure, messageErreur } from './composants/format';
import { OngletsServeurs } from './OngletsServeurs';

const RESULTAT: Record<VersionConfiguration['resultat'], { mot: string; ton: 'success' | 'neutral' | 'warning' | 'info' }> = {
  prete: { mot: 'Prête', ton: 'info' },
  appliquee: { mot: 'Appliquée', ton: 'success' },
  remplacee: { mot: 'Remplacée', ton: 'neutral' },
  retour_automatique: { mot: 'Retour automatique', ton: 'warning' },
};
const SIGNE = { ajout: '+', retrait: '−', modification: '~' } as const;
const SORTE = { ajout: 'Ajout', retrait: 'Retrait', modification: 'Changement' } as const;

function Contenu({ config }: { config: Configuration }) {
  const appliquer = useAppliquerConfiguration();
  const revenir = useRevenirConfiguration();
  const [erreur, setErreur] = useState<string | null>(null);
  const geste = async (f: () => Promise<unknown>) => {
    setErreur(null);
    try {
      await f();
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };
  return (
    <div className="space-y-4">
      {config.prete ? (
        <section aria-labelledby="prete-titre" className="rounded-xl bg-sky-50 p-4 ring-1 ring-sky-200">
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h2 id="prete-titre" className="text-lg font-bold text-slate-900">Version {config.prete.numero} prête</h2>
            <StatusPill tone="info">Prête</StatusPill>
          </div>
          <p className="mt-1 text-sm text-slate-700">Différences en clair ci-dessous. En cas d’échec, la version précédente revient toute seule.</p>
          <ul className="mt-3 space-y-1 text-sm">
            {config.prete.changements.map((c) => (
              <li key={c.libelle} className="flex gap-2"><span aria-hidden className="font-bold">{SIGNE[c.sorte]}</span><span className="sr-only">{SORTE[c.sorte]} : </span><span>{c.libelle}</span></li>
            ))}
          </ul>
          <div className="mt-3">
            <Button variant="primary" className={BOUTON_TACTILE} loading={appliquer.isPending} onClick={() => void geste(() => appliquer.mutateAsync())}>Appliquer la version {config.prete.numero}</Button>
          </div>
        </section>
      ) : (
        <EmptyState title="Aucune version n’attend" description="Ajoutez une adresse IP ou changez un réglage : une version prête apparaît ici, à relire avant de l’appliquer." />
      )}
      {erreur ? <MessageErreur>{erreur}</MessageErreur> : null}
      <section aria-labelledby="histo-titre" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 id="histo-titre" className="text-lg font-bold text-slate-900">Historique</h2>
        <div className="relative mt-2 overflow-x-auto">
          <table className="w-full min-w-[32rem] text-left text-sm">
            <caption className="sr-only">Versions de la configuration</caption>
            <thead><tr className="text-xs text-slate-500">
              <th scope="col" className="py-1 pr-3 font-medium">Version</th><th scope="col" className="py-1 pr-3 font-medium">Quand</th><th scope="col" className="py-1 pr-3 font-medium">Par</th><th scope="col" className="py-1 pr-3 font-medium">Résultat</th><th scope="col" className="py-1 font-medium"><span className="sr-only">Action</span></th>
            </tr></thead>
            <tbody>
              {config.historique.map((v) => (
                <tr key={v.numero} className="border-t border-slate-100">
                  <th scope="row" className="py-2 pr-3 font-semibold text-slate-900">{v.numero}</th>
                  <td className="py-2 pr-3">{dateHeure(v.appliquee_le ?? v.creee_le)}</td>
                  <td className="py-2 pr-3">{v.par ?? '—'}</td>
                  <td className="py-2 pr-3"><StatusPill tone={RESULTAT[v.resultat].ton}>{RESULTAT[v.resultat].mot}</StatusPill></td>
                  <td className="py-2 text-right">
                    {v.resultat === 'appliquee' ? null : (
                      <Button variant="ghost" size="sm" className="max-md:h-11" loading={revenir.isPending} onClick={() => void geste(() => revenir.mutateAsync(v.numero))} aria-label={`Revenir à la version ${v.numero}`}>Revenir à cette version</Button>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

export function ConfigurationPage() {
  const requete = useConfiguration();
  const c = requete.data;
  return (
    <div>
      <Entete
        title="Configuration"
        phrase={c?.courante ? <>Version {c.courante.numero} en service{c.prete ? <> · version {c.prete.numero} prête</> : null}.</> : undefined}
      />
      <OngletsServeurs />
      <div className="mt-4">
        <Etats
          requete={requete}
          contexte="la configuration du serveur"
          estVide={(d) => !d.courante}
          vide={<EmptyState title="Aucune version de configuration" description="La première version apparaîtra ici dès le premier réglage du serveur." />}
        >
          {(config) => <Contenu config={config} />}
        </Etats>
      </div>
    </div>
  );
}
