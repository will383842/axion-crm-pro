/**
 * Connexion au CRM (DM2-CNF) : l'adaptateur actif (CRM Pro), la santé de la
 * connexion, ce qui passe et ne passe pas. Maquette : `maquettes/reglages-envoi.html`.
 */
import { PlugZap, RefreshCw } from 'lucide-react';
import { Button, PageHeader, StatusPill, type StatusTone } from '@/components/ui';
import { useConnexionCrm, useTesterConnexionCrm, type ConnexionCrm } from '@/features/envoi/conformite/composants/api';
import { Bloc, CLASSE_BOUTON_MOBILE, Etat } from '@/features/envoi/conformite/composants/commun';
import { dateEtHeure } from '@/features/envoi/conformite/composants/format';
import { OngletsReglages } from './OngletsReglages';

const ADAPTATEUR: Record<ConnexionCrm['adaptateur'], string> = { interne: 'CRM de l’application (interne)', crm_pro: 'CRM Pro', generique: 'CRM générique', aucun: 'Aucun CRM (carnet d’Envoi)' };
const SANTE: Record<ConnexionCrm['etat'], { libelle: string; ton: StatusTone }> = {
  vert: { libelle: 'Connexion en bonne santé', ton: 'success' },
  ambre: { libelle: 'Connexion à surveiller', ton: 'warning' },
  rouge: { libelle: 'Connexion interrompue', ton: 'danger' },
  bleu: { libelle: 'Connexion en cours', ton: 'info' },
  gris: { libelle: 'Connexion inconnue', ton: 'neutral' },
};

function Liste({ titre, lignes }: { titre: string; lignes: string[] }) {
  return (
    <Bloc titre={titre}>
      <ul className="list-disc space-y-1 pl-5 text-sm text-slate-700">
        {lignes.map((l) => (
          <li key={l}>{l}</li>
        ))}
      </ul>
    </Bloc>
  );
}

export function ConnexionCrmPage() {
  const requete = useConnexionCrm();
  const tester = useTesterConnexionCrm();
  return (
    <div>
      <PageHeader
        title="Connexion au CRM"
        surTitre="Envoi"
        subtitle="Envoi se branche sur un CRM par un adaptateur ; le CRM Pro est le premier."
        actions={
          <Button iconLeft={<RefreshCw className="h-4 w-4" aria-hidden />} loading={tester.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => tester.mutate()}>
            Tester la connexion
          </Button>
        }
      />
      <OngletsReglages />
      <Etat requete={requete} contexte="la connexion au CRM">
        {(c) => (
          <div className="space-y-4">
            <Bloc titre="Adaptateur actif" icone={<PlugZap className="h-5 w-5" />} droite={<StatusPill tone={SANTE[c.etat].ton}>{SANTE[c.etat].libelle}</StatusPill>}>
              <p className="text-2xl font-semibold text-slate-900 md:text-3xl">{ADAPTATEUR[c.adaptateur]}</p>
              <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
                <div><dt className="text-xs text-slate-500">Dernière synchronisation</dt><dd className="text-slate-800">{c.derniere_synchro_le ? dateEtHeure(c.derniere_synchro_le) : 'Jamais'}</dd></div>
                <div><dt className="text-xs text-slate-500">Contacts vus</dt><dd className="text-slate-800">{c.contacts_vus}</dd></div>
              </dl>
            </Bloc>
            <div className="grid gap-4 lg:grid-cols-2">
              <Liste titre="Ce qui passe" lignes={c.ce_qui_passe} />
              <Liste titre="Ce qui ne passe pas" lignes={c.ce_qui_ne_passe_pas} />
            </div>
          </div>
        )}
      </Etat>
    </div>
  );
}
