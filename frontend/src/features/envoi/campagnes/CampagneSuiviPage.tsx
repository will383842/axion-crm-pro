/**
 * DM2-CAM — suivi d'une campagne : progression, ce qui se passe maintenant,
 * résultats par message et par boîte, état de chaque destinataire, test A/B.
 *
 * Les réponses sont l'indicateur principal. Aucun clic n'est suivi et aucune
 * ouverture n'est affichée (décision produit).
 */
import { useState, type ReactNode } from 'react';
import { useParams } from '@tanstack/react-router';
import { Card, EmptyState, KpiCard, PageHeader, QueryErrorState, Skeleton, StatusPill, Tabs } from '@/components/ui';
import { useCampagne, type FicheCampagne } from './api';
import { BarreProgression } from './composants/BarreProgression';
import { GestesCampagne } from './composants/GestesCampagne';
import { TableauDestinataires } from './composants/TableauDestinataires';
import { dateRelative, jourEtMois, nombre, pourcentage, STATUT_LIBELLE, STATUT_TON } from './composants/libelles';

type Onglet = 'progression' | 'destinataires' | 'ab' | 'message' | 'boite';

const jourCourt = new Intl.DateTimeFormat('fr-FR', { timeZone: 'Europe/Paris', weekday: 'short' });

function Tuile({ id, children }: { id: string; children: ReactNode }) {
  return <div data-testid={id}>{children}</div>;
}

function Progression({ fiche }: { fiche: FicheCampagne }) {
  const { campagne: c, compteurs, par_jour: parJour, derniers_evenements: evenements, maintenant } = fiche;
  const maxJour = Math.max(1, ...parJour.map((j) => j.envoyes));
  return (
    <div className="space-y-6">
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Tuile id="tuile-envoyes">
          <KpiCard label="Envoyés" value={nombre(c.envoyes)} sublabel={`sur ${nombre(c.total)} · ${pourcentage(c.total === 0 ? 0 : (c.envoyes / c.total) * 100, 0)}`} />
        </Tuile>
        <Tuile id="tuile-reponses">
          <KpiCard label="Réponses" value={nombre(c.reponses)} sublabel={`${nombre(c.reponses_interessees)} ${c.reponses_interessees > 1 ? 'intéressées' : 'intéressée'}`} tone="emerald" />
        </Tuile>
        <Tuile id="tuile-refus">
          <KpiCard label="Refusés" value={nombre(compteurs.refuses)} sublabel={`${pourcentage(compteurs.taux_refus)} · limite ${pourcentage(compteurs.limite_refus, 0)}`} tone={compteurs.taux_refus > compteurs.limite_refus ? 'rose' : 'slate'} />
        </Tuile>
        <Tuile id="tuile-desinscriptions">
          <KpiCard label="Désinscriptions" value={nombre(compteurs.desinscrits)} sublabel={pourcentage(compteurs.taux_desinscription)} tone="slate" />
        </Tuile>
      </div>

      <Card className="p-4">
        <h2 className="text-lg font-bold text-slate-900">Progression</h2>
        <div className="mt-3">
          <BarreProgression envoyes={c.envoyes} total={c.total} nom={c.nom} />
        </div>
        <h3 className="mt-6 text-sm font-semibold text-slate-900">Envoyés par jour</h3>
        <ul className="mt-3 flex h-28 items-end gap-2" aria-label="Messages envoyés ces sept derniers jours">
          {parJour.map((j) => (
            <li key={j.jour} className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
              <span className="text-xs tabular-nums text-slate-600">{j.envoyes}</span>
              <span className="w-full max-w-10 rounded-t bg-produit" style={{ height: `${Math.max(2, (j.envoyes / maxJour) * 100)}%` }} aria-hidden />
              <span className="text-xs text-slate-500">{jourCourt.format(new Date(`${j.jour}T12:00:00.000Z`))}</span>
            </li>
          ))}
        </ul>
      </Card>

      <Card className="p-4">
        <h2 className="text-lg font-bold text-slate-900">Ce qui se passe</h2>
        <ul className="mt-3 space-y-2 text-sm text-slate-700">
          {c.statut === 'en_pause' ? <li>La campagne est en pause : rien ne part tant que vous ne la reprenez pas.</li> : null}
          {(c.statut === 'en_cours' || c.statut === 'programmee') && c.prochain_envoi_le !== null ? (
            <li>
              <span className="font-medium text-slate-900">Prochain départ :</span> {dateRelative(c.prochain_envoi_le, maintenant)}
              {fiche.fin_prevue_le !== null ? ` · fin prévue le ${jourEtMois(fiche.fin_prevue_le)}` : ''}
            </li>
          ) : null}
          {compteurs.arretes_entreprise > 0 ? (
            <li>
              Campagne arrêtée pour {compteurs.arretes_entreprise} {compteurs.arretes_entreprise > 1 ? 'entreprises' : 'entreprise'} : un contact a répondu, les relances s’arrêtent pour toute l’entreprise.
            </li>
          ) : null}
          {compteurs.en_pause_absence > 0 ? (
            <li>
              {compteurs.en_pause_absence} {compteurs.en_pause_absence > 1 ? 'personnes sont absentes du bureau' : 'personne est absente du bureau'} : la suite est en pause, reprise au retour.
            </li>
          ) : null}
          {evenements.map((e) => (
            <li key={String(e.id)} className="flex flex-wrap items-baseline gap-x-2">
              <span className="text-xs tabular-nums text-slate-500">{dateRelative(e.survenu_le, maintenant)}</span>
              <span>
                <span className="font-medium text-slate-900">{e.adresse}</span> : {libelleEvenement(e)}
              </span>
            </li>
          ))}
        </ul>
      </Card>
    </div>
  );
}

function libelleEvenement(e: FicheCampagne['derniers_evenements'][number]): string {
  switch (e.type) {
    case 'reponse': return 'a répondu, ses relances sont annulées';
    case 'rebond_dur': return `refusé (${e.libelle ?? 'adresse refusée'})`;
    case 'rebond_mou': return `refus temporaire (${e.libelle ?? 'nouvel essai prévu'})`;
    case 'plainte': return 'a signalé le message comme indésirable';
    case 'desinscription': return 'désinscription';
    default: return e.libelle ?? 'message remis';
  }
}

function TestAB({ fiche }: { fiche: FicheCampagne }) {
  const ab = fiche.ab;
  if (ab === null) return <EmptyState title="Pas de test A/B" description="Cette campagne n’a qu’une seule version de l’objet." />;
  const heures = ab.choix_automatique_le === null ? null : Math.max(1, Math.round((new Date(ab.choix_automatique_le).getTime() - new Date(fiche.maintenant).getTime()) / 3_600_000));
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
        {ab.versions.map((v) => (
          <Card key={v.version} className="p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <h2 className="text-lg font-bold text-slate-900">
                Version {v.version} · « {v.objet} »
              </h2>
              {v.en_tete ? <StatusPill tone="success">En tête</StatusPill> : null}
            </div>
            <dl className="mt-4 grid grid-cols-3 gap-3 text-sm">
              <div>
                <dt className="text-xs text-slate-500">Envoyés</dt>
                <dd className="text-lg font-semibold tabular-nums text-slate-900">{nombre(v.envoyes)}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Réponses</dt>
                <dd className="text-lg font-semibold tabular-nums text-slate-900">{nombre(v.reponses)}</dd>
              </div>
              <div>
                <dt className="text-xs text-slate-500">Taux de réponse</dt>
                <dd className="text-lg font-semibold tabular-nums text-slate-900">{pourcentage(v.taux_reponse)}</dd>
              </div>
            </dl>
          </Card>
        ))}
      </div>
      <p className="text-sm text-slate-600">
        {ab.gagnant !== null
          ? `Gagnant : version ${ab.gagnant} (critère : réponses).`
          : `Gagnant choisi seul dans ${heures ?? 0} h (critère : réponses).`}
      </p>
    </div>
  );
}

function ParMessage({ fiche }: { fiche: FicheCampagne }) {
  const total = fiche.sequence.length;
  return (
    <Card className="overflow-hidden">
      <table className="w-full text-left text-sm">
        <caption className="sr-only">Résultats par message de la séquence</caption>
        <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" className="px-4 py-2 font-semibold">Message</th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">Envoyés</th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">Réponses</th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">Refus</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {fiche.sequence.map((s) => (
            <tr key={s.etape}>
              <th scope="row" className="px-4 py-3 font-medium text-slate-900">
                Jour {s.delai_jours === 0 ? '0' : `+${s.delai_jours}`} · {s.etape === 1 ? 'Premier message' : s.etape === total ? 'Dernier mot' : 'Relance'}
              </th>
              <td className="px-4 py-3 text-right tabular-nums">{nombre(s.envoyes)}</td>
              <td className="px-4 py-3 text-right tabular-nums">{s.envoyes === 0 ? '—' : nombre(s.reponses)}</td>
              <td className="px-4 py-3 text-right tabular-nums">{s.envoyes === 0 ? '—' : nombre(s.refus)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

function ParBoite({ fiche }: { fiche: FicheCampagne }) {
  if (fiche.par_boite.length === 0) return <EmptyState title="Aucune boîte choisie" description="Choisissez les boîtes d’envoi avant de lancer la campagne." />;
  return (
    <Card className="overflow-hidden">
      <table className="w-full text-left text-sm">
        <caption className="sr-only">Résultats par boîte d’envoi</caption>
        <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" className="px-4 py-2 font-semibold">Boîte</th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">Envoyés</th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">Réponses</th>
            <th scope="col" className="px-4 py-2 text-right font-semibold">Refus</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-slate-100">
          {fiche.par_boite.map((b) => (
            <tr key={String(b.boite_id)}>
              <th scope="row" className="break-all px-4 py-3 font-medium text-slate-900">{b.adresse}</th>
              <td className="px-4 py-3 text-right tabular-nums">{nombre(b.envoyes)}</td>
              <td className="px-4 py-3 text-right tabular-nums">{nombre(b.reponses)}</td>
              <td className="px-4 py-3 text-right tabular-nums">{nombre(b.refus)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </Card>
  );
}

export function CampagneSuiviPage() {
  const { campagneId } = useParams({ strict: false });
  const [onglet, setOnglet] = useState<Onglet>('progression');
  const requete = useCampagne(campagneId ?? '');

  if (requete.isPending) {
    return (
      <div role="status" aria-busy="true" aria-label="Chargement de la campagne" className="space-y-3">
        <Skeleton className="h-10 w-1/2" />
        <Skeleton className="h-24 w-full" />
        <Skeleton className="h-48 w-full" />
      </div>
    );
  }
  if (requete.isError) {
    return (
      <div>
        <PageHeader title="Suivi de la campagne" surTitre="Envoi" />
        <QueryErrorState error={requete.error} contexte="cette campagne" onRetry={() => void requete.refetch()} />
      </div>
    );
  }

  const fiche = requete.data;
  const c = fiche.campagne;
  const onglets: Array<{ id: Onglet; label: string; count?: number }> = [
    { id: 'progression', label: 'Progression' },
    { id: 'destinataires', label: 'Destinataires', count: c.total },
    ...(fiche.ab !== null ? [{ id: 'ab' as const, label: 'Test A/B' }] : []),
    { id: 'message', label: 'Par message' },
    { id: 'boite', label: 'Par boîte' },
  ];

  return (
    <div>
      <PageHeader
        title={c.nom}
        surTitre="Envoi"
        badge={<StatusPill tone={STATUT_TON[c.statut]} pulse={c.statut === 'en_cours'}>{STATUT_LIBELLE[c.statut]}</StatusPill>}
        subtitle={`${nombre(c.envoyes)} envoyés sur ${nombre(c.total)} · ${nombre(c.reponses)} ${c.reponses > 1 ? 'réponses' : 'réponse'}${fiche.fin_prevue_le !== null ? ` · fin prévue le ${jourEtMois(fiche.fin_prevue_le)}` : ''}`}
        actions={<GestesCampagne campagne={c} maintenant={fiche.maintenant} variante="entete" />}
      />
      <div className="mb-4 max-w-full overflow-x-auto">
        <Tabs items={onglets} value={onglet} onChange={setOnglet} />
      </div>
      {onglet === 'progression' ? <Progression fiche={fiche} /> : null}
      {onglet === 'destinataires' ? <TableauDestinataires fiche={fiche} /> : null}
      {onglet === 'ab' ? <TestAB fiche={fiche} /> : null}
      {onglet === 'message' ? <ParMessage fiche={fiche} /> : null}
      {onglet === 'boite' ? <ParBoite fiche={fiche} /> : null}
    </div>
  );
}
