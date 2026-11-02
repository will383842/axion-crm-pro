/**
 * Mes tâches — onglet du tableau de bord (maquette `taches.html`, validée par Will).
 *
 * Trois groupes : en retard, aujourd'hui, cette semaine ; puis les tâches
 * faites. Les tâches viennent de `activities` (type `task`) ; celles créées par
 * une réponse d'Envoi portent leur origine. Le rappel du matin est un réglage.
 * « Aujourd'hui » vient du serveur : jamais de l'horloge du navigateur.
 */
import { useState, type FormEvent, type ReactNode } from 'react';
import { AlertTriangle, BellRing, CalendarDays, CheckCheck, ListChecks, Plus } from 'lucide-react';
import { Button, Card, EmptyState, KpiCard, Modal, PageHeader, QueryErrorState, Skeleton, cn } from '@/components/ui';
import { OngletsTableauDeBord } from '@/features/dashboard/OngletsTableauDeBord';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { useAjouterTache, useCocherTache, useRappelMatin, useTaches } from './api';
import { dateCourte, grouper } from './groupes';
import type { Tache } from './types';

function phrase(aujourdhui: number, enRetard: number): string {
  const jour = aujourdhui + enRetard;
  const taches = jour === 1 ? '1 tâche' : `${jour} tâches`;
  return `${taches} aujourd’hui, ${enRetard} en retard.`;
}

function LigneTache({ tache, retard, avecDate }: { tache: Tache; retard?: boolean; avecDate?: boolean }) {
  const cocher = useCocherTache();
  const detail = [tache.contexte, avecDate ? dateCourte(tache.echeance) : null, tache.origine === 'reponse' ? 'créée par une réponse' : null]
    .filter((x): x is string => x !== null && x !== '')
    .join(' · ');
  return (
    <li>
      <label className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 py-2 hover:bg-slate-50 md:min-h-9">
        <input
          type="checkbox"
          className="h-5 w-5 shrink-0 rounded border-slate-300 md:h-4 md:w-4"
          checked={tache.faite}
          disabled={cocher.isPending}
          onChange={(e) => cocher.mutate({ id: tache.id, faite: e.target.checked })}
        />
        <span className="min-w-0 flex-1">
          <span className={cn('block text-sm font-medium text-slate-900', tache.faite && 'text-slate-500 line-through')}>{tache.titre}</span>
          {detail !== '' ? <span className="block text-xs text-slate-500">{detail}</span> : null}
        </span>
        {retard ? (
          <span className="shrink-0 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-medium text-rose-700">En retard</span>
        ) : tache.heure !== null ? (
          <span className="shrink-0 rounded-full bg-sky-50 px-2 py-0.5 text-xs font-medium text-sky-700">{tache.heure}</span>
        ) : null}
      </label>
    </li>
  );
}

function Groupe({ id, titre, mot, children }: { id: string; titre: string; mot?: string; children: ReactNode }) {
  return (
    <section aria-labelledby={id} className="flex flex-col gap-2">
      <div className="flex items-baseline justify-between gap-2">
        <h2 id={id} className="text-lg font-bold text-slate-900">
          {titre}
        </h2>
        {mot ? <span className="text-xs text-slate-500">{mot}</span> : null}
      </div>
      <Card padding="sm">
        <ul className="flex flex-col">{children}</ul>
      </Card>
    </section>
  );
}

function RappelDuMatin({ actif }: { actif: boolean }) {
  const rappel = useRappelMatin();
  return (
    <Card padding="lg" className="flex items-start gap-3">
      <BellRing className="mt-0.5 h-5 w-5 shrink-0 text-produit" aria-hidden />
      <div className="min-w-0 flex-1">
        <h2 className="text-lg font-bold text-slate-900">Rappel du matin</h2>
        <label htmlFor="rappel-matin" className="mt-1 block text-sm font-medium text-slate-900">
          Recevoir mes tâches à 08:00
        </label>
        <p className="text-xs text-slate-500">Par e-mail, jours ouvrés.</p>
      </div>
      <input
        id="rappel-matin"
        type="checkbox"
        role="switch"
        className="mt-1 h-5 w-5 shrink-0"
        checked={actif}
        disabled={rappel.isPending}
        onChange={(e) => rappel.mutate(e.target.checked)}
      />
    </Card>
  );
}

function FormulaireTache({ ouvert, onFermer }: { ouvert: boolean; onFermer: () => void }) {
  const ajouter = useAjouterTache();
  const [titre, setTitre] = useState('');
  const [echeance, setEcheance] = useState('');
  const [heure, setHeure] = useState('');
  function envoyer(e: FormEvent) {
    e.preventDefault();
    if (titre.trim() === '') return;
    ajouter.mutate(
      { titre: titre.trim(), echeance: echeance === '' ? undefined : echeance, heure: heure === '' ? null : heure },
      {
        onSuccess: () => {
          setTitre('');
          setEcheance('');
          setHeure('');
          onFermer();
        },
      },
    );
  }
  const classeChamp = 'h-11 w-full rounded-lg border border-slate-300 px-3 text-sm md:h-9';
  return (
    <Modal open={ouvert} onClose={onFermer} title="Ajouter une tâche">
      <form onSubmit={envoyer} className="flex flex-col gap-3">
        <label className="flex flex-col gap-1 text-sm font-medium text-slate-900">
          Intitulé
          <input className={classeChamp} value={titre} onChange={(e) => setTitre(e.target.value)} />
        </label>
        <div className="grid grid-cols-2 gap-3">
          <label className="flex flex-col gap-1 text-sm font-medium text-slate-900">
            Échéance
            <input type="date" className={classeChamp} value={echeance} onChange={(e) => setEcheance(e.target.value)} />
          </label>
          <label className="flex flex-col gap-1 text-sm font-medium text-slate-900">
            Heure
            <input type="time" className={classeChamp} value={heure} onChange={(e) => setHeure(e.target.value)} />
          </label>
        </div>
        {ajouter.isError ? <p role="alert" className="text-sm text-rose-700">La tâche n’a pas pu être enregistrée. Réessayez.</p> : null}
        <div className="flex justify-end gap-2">
          <Button type="button" variant="secondary" onClick={onFermer}>
            Annuler
          </Button>
          <Button type="submit" variant="primary" disabled={titre.trim() === '' || ajouter.isPending}>
            Enregistrer
          </Button>
        </div>
      </form>
    </Modal>
  );
}

export function MesTachesPage() {
  const produit = useProduitCourant();
  const { data, isLoading, error, refetch } = useTaches();
  const [formulaire, setFormulaire] = useState(false);

  const groupes = data ? grouper(data.data, data.aujourdhui) : null;
  const sansRien = groupes !== null && groupes.enRetard.length + groupes.aujourdhui.length + groupes.semaine.length === 0;

  return (
    <div className="flex min-w-0 flex-col gap-6">
      <div>
        <PageHeader
          title="Mes tâches"
          surTitre={NOM_DU_PRODUIT[produit]}
          subtitle={groupes ? phrase(groupes.aujourdhui.length, groupes.enRetard.length) : undefined}
          className="mb-0"
          actions={
            groupes ? (
              <Button variant="primary" iconLeft={<Plus className="h-4 w-4" aria-hidden />} onClick={() => setFormulaire(true)}>
                Ajouter une tâche
              </Button>
            ) : null
          }
        />
      </div>
      <OngletsTableauDeBord />

      {isLoading ? (
        <div data-testid="taches-squelette" role="status" aria-label="Chargement en cours" className="flex flex-col gap-4">
          <div className="grille-auto gap-4 [--grille-min:11rem]">
            {Array.from({ length: 4 }).map((_, i) => (
              <Skeleton key={i} className="h-24 w-full" />
            ))}
          </div>
          <Skeleton className="h-64 w-full" />
        </div>
      ) : error !== null && data === undefined ? (
        <QueryErrorState error={error} contexte="vos tâches" onRetry={() => void refetch()} />
      ) : groupes !== null && data !== undefined ? (
        <>
          <div className="grille-auto gap-4 [--grille-min:11rem]">
            <KpiCard label="Aujourd’hui" tone="sky" value={groupes.aujourdhui.length + groupes.enRetard.length} icon={<ListChecks className="h-4 w-4" aria-hidden />} />
            <KpiCard label="En retard" tone="rose" value={groupes.enRetard.length} sublabel="avant aujourd’hui" icon={<AlertTriangle className="h-4 w-4" aria-hidden />} />
            <KpiCard label="Cette semaine" tone="violet" value={groupes.semaine.length} icon={<CalendarDays className="h-4 w-4" aria-hidden />} />
            <KpiCard label="Faites" tone="emerald" value={groupes.faites.length} icon={<CheckCheck className="h-4 w-4" aria-hidden />} />
          </div>

          {sansRien ? (
            <Card padding="lg">
              <EmptyState title="Rien à faire aujourd’hui" description="Toutes vos tâches sont faites." icon={<ListChecks />} />
            </Card>
          ) : null}

          <div className="grid gap-6 lg:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <div className="flex min-w-0 flex-col gap-6">
              {groupes.enRetard.length > 0 ? (
                <Groupe id="titre-retard" titre="En retard" mot={`${groupes.enRetard.length} à rattraper`}>
                  {groupes.enRetard.map((t) => (
                    <LigneTache key={t.id} tache={t} retard />
                  ))}
                </Groupe>
              ) : null}
              {groupes.aujourdhui.length > 0 ? (
                <Groupe id="titre-aujourdhui" titre="Aujourd’hui" mot={`${groupes.aujourdhui.length} à faire`}>
                  {groupes.aujourdhui.map((t) => (
                    <LigneTache key={t.id} tache={t} />
                  ))}
                </Groupe>
              ) : null}
              {groupes.semaine.length > 0 ? (
                <Groupe id="titre-semaine" titre="Cette semaine">
                  {groupes.semaine.map((t) => (
                    <LigneTache key={t.id} tache={t} avecDate />
                  ))}
                </Groupe>
              ) : null}
              {groupes.faites.length > 0 ? (
                <Groupe id="titre-faites" titre="Faites" mot={`${groupes.faites.length}`}>
                  {groupes.faites.map((t) => (
                    <LigneTache key={t.id} tache={t} />
                  ))}
                </Groupe>
              ) : null}
            </div>
            <div className="min-w-0">
              <RappelDuMatin actif={data.rappel_matin} />
            </div>
          </div>
        </>
      ) : null}

      <FormulaireTache ouvert={formulaire} onFermer={() => setFormulaire(false)} />
    </div>
  );
}
