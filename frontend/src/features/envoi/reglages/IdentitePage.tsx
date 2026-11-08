/**
 * Identité de l'expéditeur (DM2-CNF) : l'entité d'envoi (SAS française), le
 * bloc d'information des destinataires (art. 14) et le lien de rendez-vous.
 * Maquette : `maquettes/identite.html`.
 */
import { useEffect, useId, useState } from 'react';
import { ShieldCheck, UserRound } from 'lucide-react';
import { Button, PageHeader } from '@/components/ui';
import { useEnregistrerIdentite, useIdentite, type Identite } from '@/features/envoi/conformite/composants/api';
import { Annonce, Bloc, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etat, Etiquette, messageDErreur } from '@/features/envoi/conformite/composants/commun';
import { OngletsReglages } from './OngletsReglages';

function Formulaire({ initiale }: { initiale: Identite }) {
  const id = useId();
  const [brouillon, setBrouillon] = useState(initiale);
  const [message, setMessage] = useState<{ texte: string; ton: 'ok' | 'erreur' } | null>(null);
  const enregistrer = useEnregistrerIdentite();
  useEffect(() => setBrouillon(initiale), [initiale]);

  async function valider() {
    setMessage(null);
    try {
      await enregistrer.mutateAsync(brouillon);
      setMessage({ texte: 'Identité enregistrée.', ton: 'ok' });
    } catch (e) {
      setMessage({ texte: messageDErreur(e, 'L’enregistrement a échoué. Réessayez.'), ton: 'erreur' });
    }
  }

  return (
    <div className="grid gap-4 lg:grid-cols-2">
      <Bloc titre="Qui écrit" icone={<UserRound className="h-5 w-5" />}>
        <div className="space-y-3">
          <div>
            <Etiquette htmlFor={`${id}-ent`}>Entité</Etiquette>
            <input id={`${id}-ent`} value={brouillon.raison_sociale} onChange={(e) => setBrouillon({ ...brouillon, raison_sociale: e.target.value })} className={CLASSE_CHAMP} />
            <p className="mt-1 text-xs text-slate-500">Forme juridique : {brouillon.forme} (société par actions simplifiée, de droit français).</p>
          </div>
          <div>
            <Etiquette htmlFor={`${id}-adr`}>Adresse postale</Etiquette>
            <input id={`${id}-adr`} value={brouillon.adresse_postale} onChange={(e) => setBrouillon({ ...brouillon, adresse_postale: e.target.value })} className={CLASSE_CHAMP} />
          </div>
          <div>
            <Etiquette htmlFor={`${id}-rdv`}>Lien de rendez-vous</Etiquette>
            <input id={`${id}-rdv`} type="url" value={brouillon.lien_rendez_vous ?? ''} onChange={(e) => setBrouillon({ ...brouillon, lien_rendez_vous: e.target.value === '' ? null : e.target.value })} className={CLASSE_CHAMP} />
          </div>
          <div>
            <Etiquette htmlFor={`${id}-sig`}>Signature</Etiquette>
            <input id={`${id}-sig`} value={brouillon.signature} onChange={(e) => setBrouillon({ ...brouillon, signature: e.target.value })} className={CLASSE_CHAMP} />
          </div>
          <Button loading={enregistrer.isPending} className={CLASSE_BOUTON_MOBILE} onClick={() => void valider()}>
            Enregistrer
          </Button>
          <Annonce message={message?.texte ?? null} ton={message?.ton ?? 'ok'} />
        </div>
      </Bloc>

      <Bloc titre="Bloc d’information (art. 14)" icone={<ShieldCheck className="h-5 w-5" />}>
        <p className="mb-2 text-xs font-semibold uppercase tracking-wider text-slate-500">Sous chaque message</p>
        <p className="rounded-lg bg-slate-50 p-3 text-xs text-slate-700">{initiale.bloc_information}</p>
        <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-slate-700">
          <li>Aucun clic n’est suivi : les liens de vos messages ne sont pas modifiés.</li>
        </ul>
      </Bloc>
    </div>
  );
}

export function IdentitePage() {
  const requete = useIdentite();
  return (
    <div>
      <PageHeader title="Identité de l’expéditeur" surTitre="Envoi" subtitle="Ce qui figure sous chaque message." />
      <OngletsReglages />
      <Etat requete={requete} contexte="l’identité de l’expéditeur">
        {(d) => <Formulaire initiale={d} />}
      </Etat>
    </div>
  );
}
