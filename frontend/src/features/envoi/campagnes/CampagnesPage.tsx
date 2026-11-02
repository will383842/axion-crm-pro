/**
 * DM2-CAM — liste des campagnes d'Envoi : filtres par statut, tuiles, et les
 * gestes de chaque ligne (Pause / Reprendre, Changer la date, Dupliquer, Terminer).
 * Les réponses sont l'indicateur principal : aucune mesure de clic ici.
 */
import { useDeferredValue, useMemo, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { Plus } from 'lucide-react';
import { Button, EmptyState, KpiCard, PageHeader, QueryErrorState, SearchInput, Skeleton, Tabs } from '@/components/ui';
import { OngletsCampagnes } from './OngletsCampagnes';
import { useCampagnes, type StatutCampagne } from './api';
import { TableauCampagnes } from './composants/TableauCampagnes';
import { dateRelative, nombre } from './composants/libelles';

type Filtre = 'toutes' | 'en_cours' | 'programmee' | 'a_valider' | 'en_pause' | 'terminees' | 'brouillon';

const STATUTS_DU_FILTRE: Record<Exclude<Filtre, 'toutes'>, readonly StatutCampagne[]> = {
  en_cours: ['en_cours'],
  programmee: ['programmee'],
  a_valider: ['a_valider'],
  en_pause: ['en_pause'],
  terminees: ['terminee', 'arretee'],
  brouillon: ['brouillon'],
};

const LIBELLE_FILTRE: Record<Filtre, string> = {
  toutes: 'Toutes',
  en_cours: 'En cours',
  programmee: 'Programmées',
  a_valider: 'À valider',
  en_pause: 'En pause',
  terminees: 'Terminées',
  brouillon: 'Brouillons',
};

export function CampagnesPage() {
  const navigate = useNavigate();
  const [recherche, setRecherche] = useState('');
  const [filtre, setFiltre] = useState<Filtre>('toutes');
  const requete = useCampagnes(useDeferredValue(recherche));
  const donnees = requete.data;

  const compte = (f: Filtre): number => {
    if (!donnees) return 0;
    if (f === 'toutes') return Object.values(donnees.par_statut).reduce((n, v) => n + v, 0);
    return STATUTS_DU_FILTRE[f].reduce((n, s) => n + (donnees.par_statut[s] ?? 0), 0);
  };

  const visibles = useMemo(() => {
    if (!donnees) return [];
    return filtre === 'toutes' ? donnees.data : donnees.data.filter((c) => STATUTS_DU_FILTRE[filtre].includes(c.statut));
  }, [donnees, filtre]);

  const tuiles = useMemo(() => {
    const toutes = donnees?.data ?? [];
    const enCours = toutes.filter((c) => c.statut === 'en_cours');
    const programmees = toutes.filter((c) => c.statut === 'programmee').sort((a, b) => (a.prochain_envoi_le ?? '').localeCompare(b.prochain_envoi_le ?? ''));
    const aValider = toutes.filter((c) => c.statut === 'a_valider');
    return {
      enCours,
      envoyes: enCours.reduce((n, c) => n + c.envoyes, 0),
      total: enCours.reduce((n, c) => n + c.total, 0),
      prochaine: programmees[0],
      programmees: programmees.length,
      aValider,
      reponses: toutes.reduce((n, c) => n + c.reponses, 0),
      interessees: toutes.reduce((n, c) => n + c.reponses_interessees, 0),
    };
  }, [donnees]);

  const items = (Object.keys(LIBELLE_FILTRE) as Filtre[]).map((f) => ({ id: f, label: LIBELLE_FILTRE[f], count: compte(f) }));
  const aucune = donnees !== undefined && compte('toutes') === 0 && recherche.trim() === '';

  return (
    <div>
      <PageHeader
        title="Campagnes"
        surTitre="Envoi"
        subtitle="Vos envois en cours, programmés et terminés. Les réponses sont l’indicateur qui compte."
        actions={
          <Button iconLeft={<Plus className="h-4 w-4" aria-hidden />} className="max-md:h-11" onClick={() => void navigate({ to: '/envoi/campagnes/nouvelle' })}>
            Nouvelle campagne
          </Button>
        }
      />
      <OngletsCampagnes />

      {requete.isPending ? (
        <div role="status" aria-busy="true" aria-label="Chargement des campagnes" className="mt-4 space-y-2">
          <Skeleton className="h-24 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
          <Skeleton className="h-12 w-full" />
        </div>
      ) : requete.isError ? (
        <div className="mt-4">
          <QueryErrorState error={requete.error} contexte="la liste des campagnes" onRetry={() => void requete.refetch()} />
        </div>
      ) : aucune ? (
        <div className="mt-4">
          <EmptyState title="Aucune campagne pour l’instant" description="Choisissez une étiquette, une audience ou une liste, puis créez votre première campagne." />
        </div>
      ) : (
        <>
          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <KpiCard
              label="En cours"
              value={tuiles.enCours.length}
              sublabel={tuiles.enCours.length > 0 ? `${nombre(tuiles.envoyes)} / ${nombre(tuiles.total)} envoyés` : 'Aucun envoi en cours'}
            />
            <KpiCard
              label="Programmées"
              value={tuiles.programmees}
              sublabel={tuiles.prochaine?.prochain_envoi_le ? `Prochaine : ${dateRelative(tuiles.prochaine.prochain_envoi_le, donnees!.maintenant)}` : 'Rien de programmé'}
            />
            <KpiCard label="À valider" value={tuiles.aValider.length} sublabel={tuiles.aValider[0]?.nom ?? 'Rien à relire'} />
            <KpiCard
              label="Réponses (30 j)"
              value={nombre(tuiles.reponses)}
              sublabel={`${nombre(tuiles.interessees)} intéressées`}
            />
          </div>

          <div className="mt-6 flex flex-wrap items-center justify-between gap-3">
            <div className="max-w-full overflow-x-auto">
              <Tabs items={items} value={filtre} onChange={setFiltre} variant="pills" />
            </div>
            <SearchInput value={recherche} onChange={setRecherche} label="Chercher une campagne" placeholder="Chercher une campagne" />
          </div>

          <div className="mt-4">
            {visibles.length === 0 ? (
              <EmptyState
                title={recherche.trim() !== '' ? 'Aucune campagne ne correspond' : 'Aucune campagne dans cette catégorie'}
                description="Changez de filtre ou de recherche : rien n’a disparu."
              />
            ) : (
              <TableauCampagnes campagnes={visibles} maintenant={donnees!.maintenant} />
            )}
          </div>
        </>
      )}
    </div>
  );
}
