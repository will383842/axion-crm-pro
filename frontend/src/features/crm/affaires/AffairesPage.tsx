/**
 * Affaires — onglet du tableau de bord (maquette `affaires.html`, validée par Will).
 *
 * Sept colonnes, les étapes de Will : à contacter, contacté, répondu,
 * rendez-vous, devis, gagné, perdu. Une carte se déplace au clavier par sa
 * liste « Déplacer vers » (pas de glisser-déposer obligatoire). « Perdu »
 * demande la raison dans une liste courte. Le montant total de chaque colonne
 * est affiché ; la chance de réussite et la signature prévue sont sur la carte.
 */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Briefcase, CalendarClock, CheckCheck, TrendingUp } from 'lucide-react';
import { Button, Card, EmptyState, KpiCard, Modal, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { OngletsTableauDeBord } from '@/features/dashboard/OngletsTableauDeBord';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { useAffaires, useDeplacerAffaire } from './api';
import { dateLongue, euros, resumer, totalColonne } from './calculs';
import { ETAPES, RAISONS_DE_PERTE, type Affaire, type EtapeAffaire } from './etapes';

const CLASSE_CHAMP = 'h-11 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm md:h-9';

function CarteAffaire({ affaire, onDeplacer }: { affaire: Affaire; onDeplacer: (affaire: Affaire, etape: EtapeAffaire) => void }) {
  return (
    <li className="flex flex-col gap-2 rounded-xl bg-white p-3 ring-1 ring-slate-200/70 shadow-[var(--shadow-card)]">
      {affaire.entreprise_id !== null ? (
        <Link to="/companies/$companyId" params={{ companyId: String(affaire.entreprise_id) }} className="text-sm font-semibold text-slate-900 hover:underline">
          {affaire.entreprise}
        </Link>
      ) : (
        <span className="text-sm font-semibold text-slate-900">{affaire.entreprise}</span>
      )}
      <div className="flex items-baseline justify-between gap-2">
        <strong className="text-sm tabular-nums text-slate-900">{euros(affaire.montant)}</strong>
        <span className="text-xs text-slate-500">{affaire.chance} % de chance</span>
      </div>
      {affaire.signature_prevue !== null ? (
        <p className="text-xs text-slate-500">Signature prévue le {dateLongue(affaire.signature_prevue)}</p>
      ) : (
        <p className="text-xs text-slate-500">Signature prévue : à définir</p>
      )}
      {affaire.etape === 'perdu' && affaire.raison_perte !== null ? (
        <p className="text-xs text-rose-700">Raison : {affaire.raison_perte}</p>
      ) : null}
      <select
        aria-label={`Déplacer ${affaire.entreprise} vers…`}
        className={CLASSE_CHAMP}
        value=""
        onChange={(e) => {
          if (e.target.value !== '') onDeplacer(affaire, e.target.value as EtapeAffaire);
        }}
      >
        <option value="">Déplacer vers…</option>
        {ETAPES.filter((e) => e.valeur !== affaire.etape).map((e) => (
          <option key={e.valeur} value={e.valeur}>
            {e.libelle}
          </option>
        ))}
      </select>
    </li>
  );
}

function FenetreRaison({ affaire, onFermer }: { affaire: Affaire | null; onFermer: () => void }) {
  const deplacer = useDeplacerAffaire();
  const [raison, setRaison] = useState('');
  function fermer() {
    setRaison('');
    onFermer();
  }
  return (
    <Modal open={affaire !== null} onClose={fermer} title="Perdre cette affaire ?" description={affaire ? `${affaire.entreprise} passera en « Perdu ». Rien n’est supprimé.` : undefined}>
      <div className="flex flex-col gap-3">
        <label className="flex flex-col gap-1 text-sm font-medium text-slate-900">
          Raison de la perte
          <select className={CLASSE_CHAMP} value={raison} onChange={(e) => setRaison(e.target.value)}>
            <option value="">Choisir une raison…</option>
            {RAISONS_DE_PERTE.map((r) => (
              <option key={r} value={r}>
                {r}
              </option>
            ))}
          </select>
        </label>
        {deplacer.isError ? <p role="alert" className="text-sm text-rose-700">Le déplacement n’a pas pu être enregistré. Réessayez.</p> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={fermer}>
            Annuler
          </Button>
          <Button
            type="button"
            variant="destructive"
            disabled={raison === '' || deplacer.isPending}
            onClick={() => {
              if (affaire) deplacer.mutate({ id: affaire.id, etape: 'perdu', raison_perte: raison }, { onSuccess: fermer });
            }}
          >
            Confirmer la perte
          </Button>
        </div>
      </div>
    </Modal>
  );
}

export function AffairesPage() {
  const produit = useProduitCourant();
  const { data, isLoading, error, refetch } = useAffaires();
  const deplacer = useDeplacerAffaire();
  const [aPerdre, setAPerdre] = useState<Affaire | null>(null);

  function onDeplacer(affaire: Affaire, etape: EtapeAffaire) {
    if (etape === 'perdu') setAPerdre(affaire);
    else deplacer.mutate({ id: affaire.id, etape });
  }

  const resume = data ? resumer(data.data, data.aujourdhui) : null;
  const vide = data !== undefined && data.data.length === 0;

  return (
    <div className="flex min-w-0 flex-col gap-6">
      <PageHeader
        title="Affaires"
        surTitre={NOM_DU_PRODUIT[produit]}
        subtitle={resume ? `${resume.ouvertes} affaires ouvertes · ${euros(resume.enJeu)} en jeu` : undefined}
        className="mb-0"
      />
      <OngletsTableauDeBord />

      {isLoading ? (
        <div data-testid="affaires-squelette" role="status" aria-label="Chargement en cours" className="flex flex-col gap-4">
          <div className="grille-auto gap-4 [--grille-min:11rem]">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-24 w-full" />
            ))}
          </div>
          <Skeleton className="h-72 w-full" />
        </div>
      ) : error !== null && data === undefined ? (
        <QueryErrorState error={error} contexte="vos affaires" onRetry={() => void refetch()} />
      ) : vide ? (
        <Card padding="lg">
          <EmptyState title="Aucune affaire" description="Passez une entreprise en « Opportunité » pour créer une affaire." icon={<Briefcase />} />
        </Card>
      ) : data !== undefined && resume !== null ? (
        <>
          <div className="grille-auto gap-4 [--grille-min:11rem]">
            <KpiCard label="Affaires ouvertes" tone="sky" value={resume.ouvertes} icon={<Briefcase className="h-4 w-4" aria-hidden />} />
            <KpiCard label="Montant en jeu" tone="violet" value={euros(resume.enJeu)} sublabel={`chance pondérée : ${euros(resume.pondere)}`} icon={<TrendingUp className="h-4 w-4" aria-hidden />} />
            <KpiCard label="Signature prévue ce mois" tone="amber" value={resume.signatureCeMois} icon={<CalendarClock className="h-4 w-4" aria-hidden />} />
            <KpiCard label="Gagnées ce trimestre" tone="emerald" value={resume.gagneesTrimestre} icon={<CheckCheck className="h-4 w-4" aria-hidden />} />
          </div>

          {/* Téléphone : les colonnes s'empilent (aucun défilement horizontal de la page). */}
          <div className="grid grid-cols-1 gap-4 md:auto-cols-[16rem] md:grid-flow-col md:overflow-x-auto md:pb-2">
            {ETAPES.map((etape) => {
              const cartes = data.data.filter((a) => a.etape === etape.valeur);
              const id = `colonne-${etape.valeur}`;
              return (
                <section key={etape.valeur} aria-labelledby={id} className="flex min-w-0 flex-col gap-3 rounded-2xl bg-slate-50 p-3 ring-1 ring-slate-200/70">
                  <div>
                    <h2 id={id} className="flex items-baseline justify-between gap-2 text-lg font-bold text-slate-900">
                      <span>{etape.libelle}</span>
                      <span className="text-xs font-medium text-slate-500">{cartes.length}</span>
                    </h2>
                    <p className="text-xs text-slate-500">
                      Montant total : <span className="tabular-nums">{euros(totalColonne(data.data, etape.valeur))}</span>
                    </p>
                  </div>
                  <ul className="flex flex-col gap-3">
                    {cartes.map((a) => (
                      <CarteAffaire key={a.id} affaire={a} onDeplacer={onDeplacer} />
                    ))}
                  </ul>
                </section>
              );
            })}
          </div>
        </>
      ) : null}

      <FenetreRaison affaire={aPerdre} onFermer={() => setAPerdre(null)} />
    </div>
  );
}
