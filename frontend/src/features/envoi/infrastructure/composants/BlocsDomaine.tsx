/** DM2-INF — blocs de la fiche d'un domaine : DNS, DMARC progressif, listes noires. */
import { Check, CircleAlert, ExternalLink } from 'lucide-react';
import { Button, StatusPill } from '@/components/ui';
import { useAvancerDmarc, type Domaine, type EnregistrementDns, type ListeNoire, type PolitiqueDmarc } from './api';
import { BOUTON_TACTILE, MessageErreur } from './Formulaire';
import { LIBELLE_DMARC, dateCourte, dateHeure, messageErreur } from './format';
import { useState } from 'react';

const ROLE: Record<EnregistrementDns['role'], string> = {
  spf: 'SPF',
  dkim: 'DKIM',
  dmarc: 'DMARC',
  ptr: 'Retour (PTR)',
  mta_sts: 'MTA-STS et rapports TLS',
};

export function dnsVerts(dns: EnregistrementDns[]): number {
  return dns.filter((l) => l.etat === 'ok').length;
}

export function TableauDns({ dns }: { dns: EnregistrementDns[] }) {
  return (
    <ul className="divide-y divide-slate-100 rounded-xl ring-1 ring-slate-200" aria-label="Enregistrements DNS">
      {dns.map((l) => (
        <li key={l.role} className="grid gap-1 p-3 text-sm md:grid-cols-[10rem_1fr_auto] md:items-start md:gap-3">
          <div className="font-medium text-slate-900">{ROLE[l.role]}</div>
          <div className="min-w-0 text-xs text-slate-600">
            <span className="font-semibold text-slate-700">{l.type}</span> <span className="break-all">{l.nom}</span>
            <code className="mt-1 block break-all rounded bg-slate-50 px-2 py-1 text-xs text-slate-700">{l.valeur}</code>
          </div>
          <div>
            {l.etat === 'ok' ? (
              <StatusPill tone="success"><Check className="h-3 w-3" aria-hidden />En place</StatusPill>
            ) : (
              <StatusPill tone="warning"><CircleAlert className="h-3 w-3" aria-hidden />{l.etat === 'absent' ? 'À créer' : 'Différent'}</StatusPill>
            )}
          </div>
        </li>
      ))}
    </ul>
  );
}

const ETAPES: readonly PolitiqueDmarc[] = ['none', 'quarantine', 'reject'];

export function BlocDmarc({ domaine }: { domaine: Domaine }) {
  const avancer = useAvancerDmarc(domaine.id);
  const [erreur, setErreur] = useState<string | null>(null);
  const prochaine = domaine.dmarc_prochaine_etape;
  const courant = domaine.dmarc === null ? -1 : ETAPES.indexOf(domaine.dmarc);

  const agir = async (corps: { politique: PolitiqueDmarc; rappel_le?: string }) => {
    setErreur(null);
    try {
      await avancer.mutateAsync(corps);
    } catch (e) {
      setErreur(messageErreur(e));
    }
  };
  const reporter = () => {
    if (!prochaine) return;
    const d = new Date(prochaine.le);
    d.setUTCDate(d.getUTCDate() + 7);
    void agir({ politique: prochaine.politique, rappel_le: d.toISOString() });
  };

  return (
    <section aria-labelledby="dmarc-titre" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
      <h2 id="dmarc-titre" className="text-lg font-bold text-slate-900">DMARC progressif</h2>
      <ol className="mt-3 flex flex-wrap gap-2" aria-label="Étapes du DMARC">
        {ETAPES.map((e, i) => (
          <li key={e}>
            <StatusPill tone={i === courant ? 'success' : i < courant ? 'neutral' : 'pending'}>
              {i === courant ? 'Actuel : ' : ''}{e === 'none' ? 'Aucune (none)' : e === 'quarantine' ? 'Quarantaine' : 'Rejet (reject)'}
            </StatusPill>
          </li>
        ))}
      </ol>
      {prochaine ? (
        <p className="mt-3 text-sm text-slate-700">
          Prochaine étape le <strong>{dateCourte(prochaine.le)}</strong>, si aucun envoi inconnu n’est signalé.
        </p>
      ) : (
        <p className="mt-3 text-sm text-slate-700">Politique la plus stricte atteinte : rien d’autre à faire.</p>
      )}
      <div className="mt-3 flex flex-wrap gap-2">
        {prochaine ? (
          <>
            <Button variant="secondary" className={BOUTON_TACTILE} loading={avancer.isPending} onClick={() => void agir({ politique: prochaine.politique })}>
              Passer à « {LIBELLE_DMARC[prochaine.politique]} » maintenant
            </Button>
            <Button variant="ghost" className={BOUTON_TACTILE} disabled={avancer.isPending} onClick={reporter}>Reporter d’une semaine</Button>
          </>
        ) : null}
      </div>
      {erreur ? <div className="mt-3"><MessageErreur>{erreur}</MessageErreur></div> : null}
    </section>
  );
}

export function BlocListesNoires({ listes }: { listes: ListeNoire[] }) {
  return (
    <div className="space-y-3">
      {listes.map((l) => (
        <section key={l.liste} aria-label={l.liste} className={`rounded-xl p-4 ring-1 ${l.present ? 'bg-amber-50 ring-amber-200' : 'bg-white ring-slate-200'}`}>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <h3 className="text-sm font-semibold text-slate-900">{l.liste}</h3>
            {l.present ? <StatusPill tone="warning"><CircleAlert className="h-3 w-3" aria-hidden />Présent sur la liste</StatusPill> : <StatusPill tone="success"><Check className="h-3 w-3" aria-hidden />Propre</StatusPill>}
          </div>
          <p className="mt-1 text-xs text-slate-600">Vérifié le {dateHeure(l.verifie_le)}</p>
          {l.present && l.que_faire ? (
            <div className="mt-3 space-y-2 text-sm text-slate-800">
              <p><strong>Que faire :</strong> {l.que_faire}</p>
              {l.lien_retrait ? (
                <a href={l.lien_retrait} target="_blank" rel="noopener noreferrer" className="inline-flex min-h-11 items-center gap-1 font-medium text-produit underline md:min-h-0">
                  Demander le retrait de la liste <ExternalLink className="h-3.5 w-3.5" aria-hidden />
                </a>
              ) : null}
            </div>
          ) : null}
        </section>
      ))}
    </div>
  );
}
