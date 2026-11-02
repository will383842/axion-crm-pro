/**
 * « Envoi aujourd'hui » — la carte de l'accueil du CRM, présente SEULEMENT si
 * Envoi est actif pour l'espace (le CRM seul ne change pas). Elle dit : les
 * réponses à traiter, le feu des envois, et mène à Envoi. Les ouvertures ne
 * sont jamais mesurées : la carte n'en parle pas.
 */
import { Link } from '@tanstack/react-router';
import { Send } from 'lucide-react';
import { Card, Skeleton } from '@/components/ui';
import { PointDeFeu, feuAffiche, libelleDuFeu, useCompteursEnvoi, useEtatEnvois } from '@/features/socle/EtatEnvois';

function reponses(n: number): string {
  return n === 0 ? 'Aucune réponse à traiter' : n === 1 ? '1 réponse à traiter' : `${n.toLocaleString('fr-FR')} réponses à traiter`;
}

export function CarteEnvoiAujourdhui() {
  const etat = useEtatEnvois(true);
  const compteurs = useCompteursEnvoi(true);
  const feu = feuAffiche(etat.data);
  const c = compteurs.data;
  const enAttente = etat.isPending || compteurs.isPending;
  const aTraiter = c?.reponses_a_traiter;

  return (
    <section aria-labelledby="titre-envoi-aujourdhui" data-testid="carte-envoi-aujourdhui" className="flex flex-col gap-3.5">
      <h2 id="titre-envoi-aujourdhui" className="text-lg font-bold text-slate-900">
        Envoi aujourd’hui
      </h2>
      <Card padding="lg" className="flex flex-wrap items-center justify-between gap-4">
        {enAttente ? (
          <Skeleton className="h-10 w-64" />
        ) : (
          <div className="flex min-w-0 flex-col gap-1.5">
            <div className="flex items-center gap-2 text-sm font-semibold text-slate-900">
              <PointDeFeu feu={feu} />
              <span>{libelleDuFeu(feu)}</span>
            </div>
            {typeof aTraiter === 'number' ? (
              <Link to="/envoi/reponses" className="text-sm font-semibold text-produit hover:underline">
                {reponses(aTraiter)}
              </Link>
            ) : (
              <span className="text-sm text-slate-500">Réponses : chiffre indisponible pour le moment</span>
            )}
            {typeof c?.envoyes_aujourdhui === 'number' && typeof c.plafond_jour === 'number' ? (
              <p className="text-xs text-slate-500">
                {c.envoyes_aujourdhui.toLocaleString('fr-FR')} messages envoyés sur {c.plafond_jour.toLocaleString('fr-FR')} prévus aujourd’hui
              </p>
            ) : null}
          </div>
        )}
        <Link
          to="/envoi/campagnes"
          className="inline-flex h-11 items-center gap-2 rounded-lg px-3 text-sm font-semibold text-produit ring-1 ring-produit hover:bg-produit-pale md:h-9"
        >
          <Send className="h-4 w-4" aria-hidden />
          Ouvrir Envoi
        </Link>
      </Card>
    </section>
  );
}
