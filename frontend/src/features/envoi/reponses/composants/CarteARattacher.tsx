import { useState } from 'react';
import { UserPlus } from 'lucide-react';
import { Button, QueryErrorState, Skeleton } from '@/components/ui';
import { formaterDate, initiales } from './libelles';
import { Initiales, PastilleClassement } from './PastilleClassement';
import { ModaleNouvelleFiche } from './ModaleNouvelleFiche';
import { useDetailReponse, useRattacher, type ReponseApresGeste, type ReponseResume } from './requetes';

interface Props {
  reponse: ReponseResume;
  onTerminee: (message: string, r: ReponseApresGeste) => void;
}

/** Une réponse venue d'une adresse inconnue : fiches proposées, rattachement, nouvelle personne. */
export function CarteARattacher({ reponse, onTerminee }: Props) {
  const id = String(reponse.id);
  const detail = useDetailReponse(id);
  const rattacher = useRattacher();
  const [ouverte, setOuverte] = useState(false);
  const propositions = detail.data?.suggestions_rattachement ?? [];

  return (
    <article aria-label={`Réponse de ${reponse.de}`} className="space-y-4 rounded-xl border border-slate-200 bg-white p-4 md:p-5">
      <header className="flex flex-wrap items-start gap-3">
        <Initiales texte={initiales(reponse)} />
        <div className="min-w-0 flex-1">
          <h2 className="break-words text-lg font-bold text-slate-900">{reponse.de}</h2>
          <p className="text-xs text-slate-500">Adresse inconnue · {formaterDate(reponse.recue_le)}</p>
        </div>
        <PastilleClassement classement="a_rattacher" />
      </header>
      <blockquote className="rounded-lg bg-slate-50 p-3 text-sm text-slate-800">{reponse.extrait}</blockquote>

      <section aria-label="Choisir une fiche">
        <h3 className="mb-2 text-sm font-semibold text-slate-900">
          {detail.isSuccess ? `${propositions.length} ${propositions.length > 1 ? 'fiches proposées' : 'fiche proposée'}` : 'Fiches proposées'}
        </h3>
        {detail.isPending ? (
          <Skeleton className="h-16 w-full" />
        ) : detail.isError ? (
          <QueryErrorState error={detail.error} contexte="les fiches proposées" onRetry={() => void detail.refetch()} />
        ) : (
          <ul aria-label="Fiches proposées" className="space-y-2">
            {propositions.map((p) => (
              <li key={`${p.type}-${p.reference}`} aria-label={`${p.libelle}, ${p.raison}`} className="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 p-3">
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-semibold text-slate-900">{p.libelle}</p>
                  <p className="text-xs text-slate-500">{p.raison}</p>
                </div>
                <Button
                  variant="secondary"
                  className="max-md:h-11"
                  loading={rattacher.isPending && rattacher.variables?.reference === p.reference}
                  onClick={() =>
                    rattacher.mutate(
                      { id, type: p.type, reference: p.reference },
                      { onSuccess: (r) => onTerminee(`Rattachée à ${p.libelle} : la réponse est maintenant dans « À traiter ».`, r) },
                    )
                  }
                >
                  Rattacher
                </Button>
              </li>
            ))}
          </ul>
        )}
        {rattacher.isError ? <p role="alert" className="mt-2 text-sm text-rose-700">Le rattachement n’a pas pu être enregistré. Réessayez dans un instant.</p> : null}
      </section>

      <div className="border-t border-slate-100 pt-4">
        <Button variant="secondary" className="max-md:h-11" iconLeft={<UserPlus className="h-4 w-4" aria-hidden />} onClick={() => setOuverte(true)}>
          Créer une nouvelle fiche dans le CRM
        </Button>
      </div>
      {ouverte ? (
        <ModaleNouvelleFiche
          reponse={reponse}
          ouverte={ouverte}
          onFermer={() => setOuverte(false)}
          onCreee={(r) => {
            setOuverte(false);
            onTerminee('Nouvelle fiche créée dans le CRM : la réponse est maintenant dans « À traiter ».', r);
          }}
        />
      ) : null}
    </article>
  );
}
