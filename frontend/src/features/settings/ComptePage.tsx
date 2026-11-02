import { PageHeader } from '@/components/ui';
import { ChangerMotDePasse } from './ChangerMotDePasse';
import { AppareilsDeConfiance } from './securite/AppareilsDeConfiance';
import { CodesDeSecours } from './securite/CodesDeSecours';
import { RaccourcisClavier } from './securite/RaccourcisClavier';

/** Mon compte et sécurité : écran commun aux deux produits (DM1-COM). */
export function ComptePage() {
  return (
    <div>
      <PageHeader
        surTitre="Commun"
        title="Mon compte et sécurité"
        subtitle="Votre mot de passe, vos appareils de confiance et vos codes de secours."
      />
      <div className="space-y-4">
        <ChangerMotDePasse />
        <AppareilsDeConfiance />
        <CodesDeSecours />
        <RaccourcisClavier />
      </div>
    </div>
  );
}
