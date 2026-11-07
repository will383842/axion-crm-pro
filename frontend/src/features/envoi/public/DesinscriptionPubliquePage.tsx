/**
 * Page PUBLIQUE de désinscription (DM2-CNF), HORS coquille : un clic, option
 * « Toute mon organisation », aucune connexion, aucun lien vers le site de
 * l'éditeur, « Pourquoi ce message ? ». Maquette : `maquettes/desinscription-publique.html`.
 */
import { useId, useState } from 'react';
import { useParams } from '@tanstack/react-router';
import { CheckCircle2 } from 'lucide-react';
import { Button } from '@/components/ui';
import { useConfirmerDesinscription, usePageDesinscription } from '@/features/envoi/conformite/composants/api';
import { Chargement, messageDErreur } from '@/features/envoi/conformite/composants/commun';
import { produitsDe, useConsoleFeaturesQuery } from '@/features/crm-console/useConsoleFeatures';
import { PagePubliqueEnAttente } from '@/features/socle/PageEnAttente';

/**
 * Garde de production : le CRM réel est déployé à chaque fusion, et la route
 * serveur `/public/desinscription/{jeton}` n'existe pas encore. Tant que le
 * serveur n'annonce pas Envoi (`/config/features` → `produits.envoi`), la page
 * reste exactement celle d'avant (« en préparation ») et AUCUN appel ne part.
 */
export function DesinscriptionPubliquePage() {
  const features = useConsoleFeaturesQuery();
  if (features.isPending) {
    return (
      <main id="main" className="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-4 py-10">
        <Chargement lignes={3} />
      </main>
    );
  }
  if (produitsDe(features.data).envoi === 'absent') return <PagePubliqueEnAttente title="Désinscription" lot="DM2-CNF" />;
  return <PageDesinscription />;
}

function PageDesinscription() {
  const jeton = useParams({ strict: false }).jeton ?? '';
  const idCase = useId();
  const page = usePageDesinscription(jeton);
  const confirmer = useConfirmerDesinscription(jeton);
  const [organisation, setOrganisation] = useState(false);
  const [fait, setFait] = useState(false);

  const donnees = page.data;
  const termine = fait || donnees?.deja_desinscrit === true;

  return (
    <main id="main" data-produit="envoi" className="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-4 py-10">
      <div className="rounded-2xl bg-white p-6 text-center shadow-sm ring-1 ring-slate-200 sm:p-8">
        {page.isError ? (
          <p role="alert" className="text-sm text-slate-700">
            Ce lien n’est plus valable. Si vous recevez encore nos messages, répondez simplement « STOP » à l’un d’eux.
          </p>
        ) : donnees === undefined ? (
          <Chargement lignes={3} />
        ) : (
          <>
            <p className="text-xs text-slate-600">
              Message de la part de <strong className="font-semibold text-slate-900">{donnees.expediteur}</strong>
            </p>
            <h1 className="my-3 text-2xl font-semibold tracking-tight text-slate-900 md:text-3xl">Se désinscrire</h1>
            {termine ? (
              <p role="status" className="flex flex-col items-center gap-2 text-sm text-slate-800">
                <CheckCircle2 className="h-8 w-8 text-emerald-600" aria-hidden />
                <span className="break-all">{donnees.adresse_masquee} ne recevra plus rien de notre part.</span>
                <span className="text-xs text-slate-600">C’est fait. Vous pouvez fermer cette page.</span>
              </p>
            ) : (
              <>
                <p className="mb-4 break-all text-sm text-slate-800">
                  Confirmez pour que {donnees.adresse_masquee} ne reçoive plus rien de notre part.
                </p>
                {donnees.organisation !== null ? (
                  <label htmlFor={idCase} className="mb-4 flex min-h-11 cursor-pointer items-center justify-center gap-2 text-sm text-slate-800">
                    <input id={idCase} type="checkbox" checked={organisation} onChange={(e) => setOrganisation(e.target.checked)} className="h-5 w-5 accent-[var(--color-produit,#0b7285)]" />
                    Toute mon organisation
                  </label>
                ) : null}
                <Button
                  size="lg"
                  full
                  loading={confirmer.isPending}
                  className="max-md:h-11"
                  onClick={() => confirmer.mutate(organisation, { onSuccess: () => setFait(true) })}
                >
                  Confirmer la désinscription
                </Button>
                {confirmer.isError ? (
                  <p role="alert" className="mt-3 text-sm text-rose-700">
                    {messageDErreur(confirmer.error, 'La désinscription n’a pas pu être enregistrée. Réessayez dans un instant.')}
                  </p>
                ) : null}
                <p className="mt-3 text-xs text-slate-600">Un clic suffit. Aucune connexion demandée.</p>
              </>
            )}
            <details className="mt-5 text-left text-xs text-slate-600">
              <summary className="min-h-9 cursor-pointer font-medium text-slate-800 max-md:min-h-11">Pourquoi ce message ?</summary>
              <p data-testid="pourquoi-ce-message" className="mt-2">{donnees.pourquoi_ce_message}</p>
            </details>
          </>
        )}
      </div>
    </main>
  );
}
