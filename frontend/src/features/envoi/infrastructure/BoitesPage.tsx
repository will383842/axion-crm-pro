/**
 * L8-F10 — « Boîtes d’envoi » : tuiles et plafond de 150 par jour. L'ajout d'une boîte
 * se fait par commande locale au palier 0 (501 au contrat) : bouton désactivé, aucun
 * champ d'identifiant, d'hôte ni de port à l'écran.
 */
import { Link } from '@tanstack/react-router';
import { Button, EmptyState, KpiCard } from '@/components/ui';
import { PLAFOND_PAR_BOITE, useBoites, type Boite } from './composants/api';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE } from './composants/Formulaire';
import { LIBELLE_STATUT, nombre, pourcent } from './composants/format';
import { Jauge, PastilleFeu } from './composants/Pastilles';

function phrase(boites: Boite[]): string {
  const compte = (s: Boite['statut']) => boites.filter((b) => b.statut === s).length;
  const morceaux = [
    [compte('prete'), compte('prete') > 1 ? 'prêtes' : 'prête'],
    [compte('ralentie'), compte('ralentie') > 1 ? 'ralenties' : 'ralentie'],
    [compte('en_chauffe'), 'en chauffe'],
    [compte('suspendue'), compte('suspendue') > 1 ? 'suspendues' : 'suspendue'],
  ] as const;
  return morceaux.filter(([n]) => n > 0).map(([n, mot]) => `${n} ${mot}`).join(', ');
}

function Tuile({ b }: { b: Boite }) {
  const ton = b.etat === 'ambre' ? 'warning' : b.etat === 'rouge' ? 'danger' : b.etat === 'vert' ? 'success' : 'info';
  return (
    <Link
      to="/envoi/boites/$boiteId"
      params={{ boiteId: b.id }}
      className="block rounded-xl bg-white p-4 ring-1 ring-slate-200 transition hover:ring-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-produit"
    >
      <div className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <strong className="block break-all text-sm text-slate-900">{b.adresse}</strong>
          <span className="mt-1 inline-flex items-center gap-2 text-xs text-slate-600">
            <PastilleFeu etat={b.etat} /> {LIBELLE_STATUT[b.statut]}
          </span>
        </div>
      </div>
      <dl className="mt-3 grid grid-cols-2 gap-2 text-sm">
        <div><dt className="text-xs text-slate-500">Envoyés</dt><dd className="font-semibold text-slate-900">{b.envoyes_aujourdhui}/{b.quota_jour}</dd></div>
        <div><dt className="text-xs text-slate-500">Refus</dt><dd className="font-semibold text-slate-900">{b.taux_refus === null ? 'non mesuré' : pourcent(b.taux_refus)}</dd></div>
      </dl>
      <div className="mt-3">
        <Jauge libelle="Quota" valeur={b.envoyes_aujourdhui} max={Math.max(1, b.quota_jour)} texte={`${b.envoyes_aujourdhui} / ${b.quota_jour}`} ton={ton} />
      </div>
    </Link>
  );
}

export function BoitesPage() {
  const requete = useBoites();
  const ajouter = (
    <Button variant="primary" className={BOUTON_TACTILE} disabled>Ajout de boîte : bientôt disponible</Button>
  );
  return (
    <div>
      <Entete
        title="Boîtes d’envoi"
        phrase={requete.data && requete.data.length > 0 ? <><strong>{requete.data.length} boîte{requete.data.length > 1 ? 's' : ''}</strong> · {phrase(requete.data)}.</> : undefined}
        actions={ajouter}
      />
      <Etats
        requete={requete}
        contexte="les boîtes d’envoi"
        estVide={(d) => d.length === 0}
        vide={<EmptyState title="Aucune boîte d’envoi" description="Au palier 0, une boîte s’ajoute par commande locale sur le serveur." />}
      >
        {(boites) => (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
              <KpiCard label="Boîtes" value={boites.length} sublabel={`${new Set(boites.map((b) => b.domaine_id)).size} domaines`} tone="sky" />
              <KpiCard label="Prêtes" value={boites.filter((b) => b.statut === 'prete').length} tone="emerald" />
              <KpiCard label="Ralenties" value={boites.filter((b) => b.statut === 'ralentie').length} tone="amber" />
              <KpiCard label="En chauffe" value={boites.filter((b) => b.statut === 'en_chauffe').length} sublabel="voir la chauffe" tone="sky" />
            </div>
            <p className="mb-3 text-xs text-slate-600">
              Plafond : {PLAFOND_PAR_BOITE} envois par jour et par boîte. Aujourd’hui : {nombre(boites.reduce((n, b) => n + b.envoyes_aujourdhui, 0))} envoyés sur {nombre(boites.reduce((n, b) => n + b.quota_jour, 0))} possibles.
            </p>
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
              {boites.map((b) => <Tuile key={b.id} b={b} />)}
            </div>
          </>
        )}
      </Etats>
    </div>
  );
}
