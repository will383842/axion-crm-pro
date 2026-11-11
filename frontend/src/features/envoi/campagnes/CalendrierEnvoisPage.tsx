/**
 * DM2-CAM — calendrier des envois : la semaine, toutes campagnes confondues.
 * Un jour = les campagnes qui envoient ce jour-là et combien de messages.
 */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button, EmptyState, KpiCard, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { cn } from '@/components/ui/cn';
import { OngletsCampagnes } from './OngletsCampagnes';
import { useCalendrier } from './api';
import { ajouterJours, jourDe, majuscule, nombre } from './composants/libelles';

const jourLong = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long' });
const jourEntier = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const jourCourt = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'short', day: 'numeric' });
const jourSeul = new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'long' });
const dateJour = (jour: string) => new Date(`${jour}T12:00:00.000Z`);

export function CalendrierEnvoisPage() {
  const [periode, setPeriode] = useState<{ du: string; au: string } | null>(null);
  const requete = useCalendrier(periode?.du ?? null, periode?.au ?? null);
  // On décale depuis la période DEMANDÉE (fonctionnelle), pas depuis les données affichées :
  // deux clics rapides avancent bien de deux semaines.
  const decaler = (n: number) => {
    const base = requete.data?.du;
    setPeriode((p) => {
      const du = p?.du ?? base;
      if (!du) return p;
      return { du: ajouterJours(du, n), au: ajouterJours(du, n + 6) };
    });
  };

  const donnees = requete.data;
  const jours = donnees ? Array.from({ length: 7 }, (_, k) => ajouterJours(donnees.du, k)) : [];
  const parJour = (jour: string) => (donnees?.data ?? []).filter((l) => l.jour === jour);
  const totalJour = (jour: string) => parJour(jour).reduce((n, l) => n + l.prevus, 0);
  const prevus = jours.reduce((n, j) => n + totalJour(j), 0);
  const chargé = jours.reduce<{ jour: string; n: number } | null>((m, j) => (totalJour(j) > (m?.n ?? 0) ? { jour: j, n: totalJour(j) } : m), null);
  const campagnes = new Set((donnees?.data ?? []).map((l) => l.campagne_id)).size;
  const aujourdhui = donnees ? jourDe(donnees.maintenant) : '';

  return (
    <div>
      <PageHeader title="Calendrier" surTitre="Envoi" subtitle="La semaine d’un coup d’œil, toutes campagnes confondues." />
      <OngletsCampagnes />

      {requete.isPending ? (
        <div role="status" aria-busy="true" aria-label="Chargement du calendrier" className="mt-4 space-y-2">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-16 w-full" />
          <Skeleton className="h-16 w-full" />
        </div>
      ) : requete.isError ? (
        <div className="mt-4">
          <QueryErrorState error={requete.error} contexte="le calendrier des envois" onRetry={() => void requete.refetch()} />
        </div>
      ) : (
        <>
          <div className="mt-4 flex flex-wrap items-center justify-between gap-3">
            <p className="text-sm font-medium text-slate-900">
              Semaine du {jourLong.format(dateJour(donnees!.du))} au {jourLong.format(dateJour(donnees!.au))}
            </p>
            <div className="flex flex-wrap items-center gap-2">
              <Button variant="secondary" className="max-md:h-11" iconLeft={<ChevronLeft className="h-4 w-4" aria-hidden />} onClick={() => decaler(-7)}>
                Semaine précédente
              </Button>
              <Button variant="secondary" className="max-md:h-11" onClick={() => setPeriode(null)}>
                Aujourd’hui
              </Button>
              <Button variant="secondary" className="max-md:h-11" iconRight={<ChevronRight className="h-4 w-4" aria-hidden />} onClick={() => decaler(7)}>
                Semaine suivante
              </Button>
            </div>
          </div>

          {donnees!.data.length === 0 ? (
            <div className="mt-4">
              <EmptyState title="Aucun envoi cette semaine" description="Aucune campagne n’envoie sur ces sept jours. Changez de semaine, ou programmez une campagne." />
            </div>
          ) : (
            <>
              <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div data-testid="tuile-prevus">
                  <KpiCard label="Cette semaine" value={nombre(prevus)} sublabel="messages prévus" />
                </div>
                <KpiCard label="Campagnes concernées" value={campagnes} sublabel="qui envoient cette semaine" />
                <KpiCard
                  label="Jour le plus chargé"
                  value={chargé ? majuscule(jourSeul.format(dateJour(chargé.jour))) : '—'}
                  sublabel={chargé ? `${nombre(chargé.n)} messages` : 'Aucun envoi'}
                />
              </div>

              <ol className="mt-6 grid grid-cols-1 gap-3 md:grid-cols-7" aria-label="Les sept jours de la semaine">
                {jours.map((jour) => {
                  const lignes = parJour(jour);
                  const estAujourdhui = jour === aujourdhui;
                  return (
                    <li
                      key={jour}
                      aria-label={jourEntier.format(dateJour(jour))}
                      className={cn('min-w-0 rounded-xl bg-white p-3 shadow-sm ring-1', estAujourdhui ? 'ring-2 ring-produit' : 'ring-slate-200')}
                    >
                      <div className="flex items-baseline justify-between gap-2 border-b border-slate-100 pb-2">
                        <span className="text-sm font-semibold text-slate-900">{majuscule(jourCourt.format(dateJour(jour)))}</span>
                        <span className="text-xs tabular-nums text-slate-500">{totalJour(jour) > 0 ? nombre(totalJour(jour)) : '—'}</span>
                      </div>
                      {estAujourdhui ? <p className="mt-1 text-xs font-medium text-produit">Aujourd’hui</p> : null}
                      {lignes.length === 0 ? (
                        <p className="mt-2 text-xs text-slate-500">Aucun envoi</p>
                      ) : (
                        <ul className="mt-2 space-y-2">
                          {lignes.map((l) => (
                            <li key={l.campagne_id} className="text-xs text-slate-600">
                              <Link to="/envoi/campagnes/$campagneId" params={{ campagneId: l.campagne_id }} className="block break-words text-sm font-medium text-produit hover:underline">
                                {l.nom}
                              </Link>
                              <span>
                                {nombre(l.prevus)} {l.prevus > 1 ? 'messages' : 'message'}
                                {l.envoyes > 0 && l.envoyes < l.prevus ? ` · ${nombre(l.envoyes)} déjà partis` : ''}
                              </span>
                            </li>
                          ))}
                        </ul>
                      )}
                    </li>
                  );
                })}
              </ol>
            </>
          )}
        </>
      )}
    </div>
  );
}
