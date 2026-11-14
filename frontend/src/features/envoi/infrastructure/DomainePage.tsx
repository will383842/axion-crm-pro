/** DM2-INF — fiche d’un domaine sur 30 jours : volume, refus, plaintes, DNS, DMARC, listes noires. */
import { useParams } from '@tanstack/react-router';
import { Button, KpiCard, StatusPill } from '@/components/ui';
import { useDomaine, useVerifierDns, type FicheDomaine } from './composants/api';
import { BlocDmarc, BlocListesNoires, TableauDns, dnsVerts } from './composants/BlocsDomaine';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE } from './composants/Formulaire';
import { dateCourte, nombre, phraseDmarc, pourcent } from './composants/format';
import { GraphiqueJours, totaux } from './composants/GraphiqueJours';
import { PastilleFeu } from './composants/Pastilles';

function Contenu({ fiche }: { fiche: FicheDomaine }) {
  const t = totaux(fiche.jours);
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard label="Envoyés (30 j)" value={nombre(t.envoyes)} tone="sky" />
        <KpiCard label="Refusés" value={pourcent(t.tauxRefus)} sublabel="limite 2 %" tone={t.tauxRefus > 2 ? 'amber' : 'emerald'} />
        <KpiCard label="Plaintes" value={t.plaintes} sublabel="limite 0,1 %" tone={t.tauxPlaintes > 0.1 ? 'rose' : 'emerald'} />
        <KpiCard label="Expire le" value={fiche.expire_le ? dateCourte(fiche.expire_le) : 'Non renseignée'} sublabel="à renouveler chez l’hébergeur du domaine" tone="slate" />
      </div>
      <section aria-labelledby="d30" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 id="d30" className="mb-2 text-lg font-bold text-slate-900">Historique 30 jours</h2>
        <GraphiqueJours jours={fiche.jours} libelle={fiche.domaine.nom} />
        <dl className="mt-3 grid grid-cols-3 gap-2 text-sm">
          <div><dt className="text-xs text-slate-500">Refus moyen</dt><dd className="font-semibold text-slate-900">{pourcent(t.tauxRefus)}</dd></div>
          <div><dt className="text-xs text-slate-500">Plaintes</dt><dd className="font-semibold text-slate-900">{t.plaintes}</dd></div>
          <div><dt className="text-xs text-slate-500">Réponses</dt><dd className="font-semibold text-slate-900">{t.reponses}</dd></div>
        </dl>
      </section>
      <section aria-labelledby="dns-titre" className="space-y-2">
        <h2 id="dns-titre" className="text-lg font-bold text-slate-900">DNS <span className="text-sm font-normal text-slate-600">{dnsVerts(fiche.dns)}/{fiche.dns.length} en place</span></h2>
        <TableauDns dns={fiche.dns} />
      </section>
      <BlocDmarc domaine={fiche.domaine} />
      <section aria-labelledby="ln-titre" className="space-y-2">
        <h2 id="ln-titre" className="text-lg font-bold text-slate-900">Listes noires</h2>
        <BlocListesNoires listes={fiche.listes_noires} />
      </section>
    </div>
  );
}

export function DomainePage() {
  const { domaineId = '' } = useParams({ strict: false });
  const requete = useDomaine(domaineId);
  const verifier = useVerifierDns(domaineId);
  const fiche = requete.data;
  return (
    <div>
      <Entete
        title={fiche?.domaine.nom ?? 'Domaine'}
        phrase={
          fiche ? (
            <span className="inline-flex flex-wrap items-center gap-2">
              <PastilleFeu etat={fiche.domaine.etat} />
              <span>{fiche.domaine.boites} boîte{fiche.domaine.boites > 1 ? 's' : ''}</span>
              <span>DNS {dnsVerts(fiche.dns)}/{fiche.dns.length}</span>
              <StatusPill tone={fiche.domaine.dmarc_prochaine_etape ? 'warning' : 'success'}>
                <span className="sr-only">DMARC : </span>
                {phraseDmarc(fiche.domaine.dmarc_prochaine_etape)}
              </StatusPill>
            </span>
          ) : undefined
        }
        actions={<Button variant="primary" className={BOUTON_TACTILE} loading={verifier.isPending} onClick={() => verifier.mutate()}>Vérifier les DNS</Button>}
      />
      <Etats requete={requete} contexte="ce domaine">{(f) => <Contenu fiche={f} />}</Etats>
    </div>
  );
}
