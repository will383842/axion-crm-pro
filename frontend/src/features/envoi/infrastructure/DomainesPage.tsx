/** DM2-INF — « Domaines » : DNS, DMARC progressif, listes noires, expiration, garde anti-dispersion. */
import { useState } from 'react';
import { Link } from '@tanstack/react-router';
import { Button, EmptyState, KpiCard, StatusPill } from '@/components/ui';
import { useDomaines, type Domaine } from './composants/api';
import { useActionDemandee } from './composants/actionDemandee';
import { DialogueAjouterDomaine } from './composants/DialogueAjouterDomaine';
import { DialogueQuiEnvoie } from './composants/DialogueQuiEnvoie';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE } from './composants/Formulaire';
import { dateCourte, phraseDmarc } from './composants/format';
import { PastilleFeu } from './composants/Pastilles';

/** Jours restants avant l'expiration, depuis la date donnée par le serveur de référence (la plus proche du jour). */
function expireBientot(expire: string | undefined, reference: string): boolean {
  if (!expire) return false;
  return (Date.parse(expire) - Date.parse(reference)) / 86_400_000 < 60;
}

function Ligne({ d, expire, reference }: { d: Domaine; expire?: string | undefined; reference: string }) {
  return (
    <Link
      to="/envoi/domaines/$domaineId"
      params={{ domaineId: d.id }}
      className="block rounded-xl bg-white p-4 ring-1 ring-slate-200 transition hover:ring-slate-300 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-produit"
    >
      <div className="flex flex-wrap items-center justify-between gap-2">
        <strong className="break-all text-sm text-slate-900">{d.nom}</strong>
        <PastilleFeu etat={d.etat} />
      </div>
      <p className="mt-1 text-xs text-slate-600">{d.boites} boîte{d.boites > 1 ? 's' : ''}{d.chauffe ? ` · jour ${d.chauffe.jour} sur ${d.chauffe.sur}` : ''}</p>
      <dl className="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div>
          <dt className="text-xs text-slate-500">DMARC</dt>
          <dd><span className="font-medium text-slate-900">{d.dmarc === 'none' ? 'aucune' : d.dmarc === 'quarantine' ? 'quarantaine' : 'rejet'}</span>{d.dmarc_prochaine_etape ? <span className="text-xs text-slate-600"> · {phraseDmarc(d.dmarc_prochaine_etape)}</span> : null}</dd>
        </div>
        <div>
          <dt className="text-xs text-slate-500">Listes noires</dt>
          <dd>{d.listes_noires > 0 ? <StatusPill tone="warning">{d.listes_noires} liste{d.listes_noires > 1 ? 's' : ''}</StatusPill> : <span className="font-medium text-slate-900">Propre</span>}</dd>
        </div>
      </dl>
      {expire ? (
        <p className="mt-2 text-xs text-slate-600">
          Expire le {dateCourte(expire)} {expireBientot(expire, reference) ? <StatusPill tone="warning">à renouveler</StatusPill> : null}
        </p>
      ) : null}
    </Link>
  );
}

export function DomainesPage() {
  const requete = useDomaines();
  const [ajout, setAjout] = useState(false);
  const [quiEnvoie, setQuiEnvoie] = useState(false);
  useActionDemandee('ajouter-domaine', () => setAjout(true));
  const donnees = requete.data;
  const liste = donnees?.data ?? [];
  const reference = liste.reduce((p, d) => (d.ajoute_le > p ? d.ajoute_le : p), '1970-01-01T00:00:00.000Z');
  return (
    <div>
      <Entete
        title="Domaines"
        phrase={donnees && liste.length > 0 ? <><strong>{liste.length} domaine{liste.length > 1 ? 's' : ''}</strong> · {liste.filter((d) => d.listes_noires > 0).length} sur une liste noire.</> : undefined}
        actions={
          <>
            <Button variant="secondary" className={BOUTON_TACTILE} onClick={() => setQuiEnvoie(true)}>Qui envoie en votre nom ?</Button>
            <Button variant="primary" className={BOUTON_TACTILE} onClick={() => setAjout(true)}>Ajouter un domaine</Button>
          </>
        }
      />
      <Etats
        requete={requete}
        contexte="les domaines d’envoi"
        estVide={(d) => d.data.length === 0}
        vide={<EmptyState title="Aucun domaine d’envoi" description="Ajoutez un domaine : nous vous disons ensuite quoi créer côté DNS." />}
      >
        {(d) => (
          <>
            <div className="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
              <KpiCard label="Domaines" value={d.data.length} tone="sky" />
              <KpiCard label="DMARC à durcir" value={d.data.filter((x) => x.dmarc_prochaine_etape).length} sublabel="voir la fiche" tone="amber" />
              <KpiCard label="Listés" value={d.data.filter((x) => x.listes_noires > 0).length} tone={d.data.some((x) => x.listes_noires > 0) ? 'amber' : 'emerald'} />
              <KpiCard label="Garde anti-dispersion" value={`${d.garde_dispersion.utilises} / ${d.garde_dispersion.limite}`} sublabel="trois domaines par serveur" tone="slate" />
            </div>
            <div className="grid gap-3 lg:grid-cols-2">
              {d.data.map((x) => <Ligne key={x.id} d={x} expire={d.expirations[x.id]} reference={reference} />)}
            </div>
          </>
        )}
      </Etats>
      <DialogueAjouterDomaine ouvert={ajout} fermer={() => setAjout(false)} />
      <DialogueQuiEnvoie ouvert={quiEnvoie} fermer={() => setQuiEnvoie(false)} />
    </div>
  );
}
