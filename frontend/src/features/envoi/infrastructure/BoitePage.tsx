/** L8-F10 — fiche d’une boîte : envois sur 30 jours, refus en clair, réponses, chauffe du domaine, réglages, suspendre / réactiver. */
import { useState } from 'react';
import { isAxiosError } from 'axios';
import { Link, useParams } from '@tanstack/react-router';
import { Button, KpiCard } from '@/components/ui';
import {
  useBoite,
  useDomaine,
  useEnregistrerReglages,
  useReactiverBoite,
  useSuspendreBoite,
  type FicheBoite,
  type ReglagesBoite,
} from './composants/api';
import { Entete } from './composants/Entete';
import { messageDroits, PERMISSION_REACTIVER, PERMISSION_SUSPENDRE, useDroitsBoites } from './composants/permissions';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE, CLASSE_CHAMP, MessageErreur, MessageSucces } from './composants/Formulaire';
import { dateHeure, LIBELLE_STATUT, messageErreur, nombre, pourcent } from './composants/format';
import { GraphiqueJours, totaux } from './composants/GraphiqueJours';
import { PastilleFeu } from './composants/Pastilles';

const VERDICT: Record<string, string> = {
  vert: 'Tout va bien : la boîte envoie normalement.',
  ambre: 'Boîte ralentie : trop de refus temporaires, le volume est divisé par 2 le temps que ça passe.',
  rouge: 'Boîte arrêtée : plus aucun envoi tant qu’elle n’est pas réactivée.',
  bleu: 'Boîte en montée : le volume augmente chaque jour d’envoi.',
  gris: 'Boîte suspendue : elle n’envoie pas.',
};

function FormulaireReglages({ id, depart }: { id: string; depart: ReglagesBoite }) {
  const enregistrer = useEnregistrerReglages(id);
  const [signature, setSignature] = useState(depart.signature);
  const [absence, setAbsence] = useState(depart.mode_absence.actif);
  const [jusquAu, setJusquAu] = useState(depart.mode_absence.jusqu_au ?? '');
  const [message, setMessage] = useState(depart.mode_absence.message ?? '');
  const [retour, setRetour] = useState<{ ok: boolean; texte: string } | null>(null);

  const valider = async () => {
    setRetour(null);
    try {
      await enregistrer.mutateAsync({ signature, mode_absence: { actif: absence, jusqu_au: jusquAu === '' ? null : jusquAu, message: message === '' ? null : message } });
      setRetour({ ok: true, texte: 'Réglages enregistrés.' });
    } catch (e) {
      setRetour({ ok: false, texte: messageErreur(e) });
    }
  };

  return (
    <section aria-labelledby="reglages-titre" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
      <h2 id="reglages-titre" className="text-lg font-bold text-slate-900">Pied de message et absence</h2>
      <div className="mt-3 space-y-3">
        <div className="flex flex-col gap-1">
          <label htmlFor="boite-signature" className="text-xs font-medium text-slate-700">Pied de message</label>
          <textarea id="boite-signature" rows={3} className={`${CLASSE_CHAMP} h-auto py-2 md:h-auto`} value={signature} onChange={(e) => setSignature(e.target.value)} />
        </div>
        <label className="flex min-h-11 items-center gap-2 text-sm text-slate-800 md:min-h-0">
          <input type="checkbox" className="h-4 w-4" checked={absence} onChange={(e) => setAbsence(e.target.checked)} />
          Mode absence
        </label>
        {absence ? (
          <div className="grid gap-3 md:grid-cols-2">
            <div className="flex flex-col gap-1">
              <label htmlFor="absence-jusqu" className="text-xs font-medium text-slate-700">Jusqu’au</label>
              <input id="absence-jusqu" type="date" className={CLASSE_CHAMP} value={jusquAu} onChange={(e) => setJusquAu(e.target.value)} />
            </div>
            <div className="flex flex-col gap-1">
              <label htmlFor="absence-message" className="text-xs font-medium text-slate-700">Message d’absence</label>
              <input id="absence-message" className={CLASSE_CHAMP} value={message} onChange={(e) => setMessage(e.target.value)} />
            </div>
          </div>
        ) : null}
        <Button variant="primary" className={BOUTON_TACTILE} loading={enregistrer.isPending} onClick={() => void valider()}>Enregistrer</Button>
        {retour ? (retour.ok ? <MessageSucces>{retour.texte}</MessageSucces> : <MessageErreur>{retour.texte}</MessageErreur>) : null}
      </div>
    </section>
  );
}

function ChauffeDomaine({ domaineId }: { domaineId: string }) {
  const domaine = useDomaine(domaineId).data?.domaine;
  const chauffe = domaine?.chauffe ?? null;
  if (!domaine) return null;
  return chauffe ? (
    <p className="mt-3 text-sm text-slate-700">
      Chauffe du domaine : palier <strong>{chauffe.palier}</strong>, <strong>{chauffe.jours_effectifs} jours</strong> effectifs sur {chauffe.total_palier}
      {chauffe.tenu_jusqu_au ? <>, palier tenu jusqu’au {dateHeure(chauffe.tenu_jusqu_au)}</> : null}.
    </p>
  ) : (
    <p className="mt-3 text-sm text-slate-700">Le domaine n’est plus en chauffe.</p>
  );
}

function Contenu({ fiche }: { fiche: FicheBoite }) {
  const { boite, jours, derniers_refus, reponses, reglages } = fiche;
  const t = totaux(jours);
  return (
    <div className="space-y-4">
      <div role="status" className="flex flex-wrap items-center gap-3 rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <PastilleFeu etat={boite.etat} />
        <span className="text-sm text-slate-800">{VERDICT[boite.etat]}</span>
      </div>
      <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
        <KpiCard label="Envoyés (30 j)" value={nombre(t.envoyes)} tone="sky" />
        <KpiCard label="Refusés" value={pourcent(t.tauxRefus)} sublabel="limite 2 %" tone={t.tauxRefus > 2 ? 'amber' : 'emerald'} />
        <KpiCard label="Réponses" value={t.reponses} tone="emerald" />
        <KpiCard label="Quota du jour" value={`${boite.envoyes_aujourdhui} / ${boite.quota_jour}`} sublabel="plafond 150 par jour" tone="amber" />
      </div>
      <div className="grid gap-4 lg:grid-cols-[2fr_1fr]">
        <section aria-labelledby="b30" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
          <h2 id="b30" className="mb-2 text-lg font-bold text-slate-900">30 derniers jours</h2>
          <GraphiqueJours jours={jours} libelle={boite.adresse} />
        </section>
        <section aria-label="Chauffe" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
          <h2 className="text-lg font-bold text-slate-900">Plafond du jour</h2>
          <p className="mt-3 text-sm text-slate-700"><strong>{boite.envoyes_aujourdhui}</strong> envoyés aujourd’hui sur <strong>{boite.quota_jour}</strong> permis. La montée en douceur se lit par domaine, dans la page Chauffe.</p>
          {boite.domaine_id === null ? null : <ChauffeDomaine domaineId={boite.domaine_id} />}
        </section>
      </div>
      <section aria-label="Derniers refus (raison en clair)" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 className="text-lg font-bold text-slate-900">Derniers refus (raison en clair)</h2>
        {derniers_refus.length === 0 ? (
          <p className="mt-2 text-sm text-slate-600">Aucun refus récent.</p>
        ) : (
          <div className="relative mt-2 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Refus</caption>
              <thead><tr className="text-xs text-slate-500"><th scope="col" className="py-1 pr-3 font-medium">Quand</th><th scope="col" className="py-1 pr-3 font-medium">Destinataire</th><th scope="col" className="py-1 font-medium">Raison</th></tr></thead>
              <tbody>
                {derniers_refus.map((e) => (
                  <tr key={e.id} className="border-t border-slate-100">
                    <td className="py-2 pr-3">{dateHeure(e.survenu_le)}</td>
                    <td className="py-2 pr-3 break-all">{e.adresse}</td>
                    <td className="py-2">{e.libelle ?? 'Raison non précisée'}{e.code_serveur ? ` (${e.code_serveur.split(' ')[0]})` : ''}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
      <section aria-label="Réponses reçues" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 className="text-lg font-bold text-slate-900">Réponses reçues</h2>
        {reponses.length === 0 ? (
          <p className="mt-2 text-sm text-slate-600">Aucune réponse reçue par cette boîte pour le moment.</p>
        ) : (
          <ul className="mt-2 divide-y divide-slate-100">
            {reponses.map((r) => (
              <li key={r.id} className="py-2 text-sm">
                <div className="flex flex-wrap justify-between gap-2"><strong className="text-slate-900">{r.prenom} {r.nom}</strong><span className="text-xs text-slate-500">{dateHeure(r.recue_le)}</span></div>
                <p className="text-slate-700">{r.extrait}</p>
              </li>
            ))}
          </ul>
        )}
      </section>
      <FormulaireReglages key={boite.id} id={boite.id} depart={reglages} />
    </div>
  );
}

export function BoitePage() {
  const { boiteId = '' } = useParams({ strict: false });
  const requete = useBoite(boiteId);
  const suspendre = useSuspendreBoite();
  const reactiver = useReactiverBoite();
  const droits = useDroitsBoites();
  const boite = requete.data?.boite;
  const suspendue = boite?.statut === 'suspendue';
  const refus = reactiver.isError && isAxiosError(reactiver.error) && reactiver.error.response?.status === 409;
  const erreurGeste = suspendre.isError ? messageErreur(suspendre.error) : reactiver.isError ? (refus ? 'Réactivation impossible pour l’instant : la boîte est sous pause de frein ou pause automatique.' : messageErreur(reactiver.error)) : null;
  const permis = suspendue ? droits.reactiver : droits.suspendre;
  const raison = messageDroits(droits, suspendue ? PERMISSION_REACTIVER : PERMISSION_SUSPENDRE);
  return (
    <div>
      <Entete
        title={boite?.adresse ?? 'Boîte d’envoi'}
        phrase={boite ? <>Domaine {boite.domaine_id === null ? <span className="font-medium">{requete.data?.boite.adresse.split('@')[1]}</span> : <Link to="/envoi/domaines/$domaineId" params={{ domaineId: boite.domaine_id }} className="font-medium text-produit underline">{requete.data?.boite.adresse.split('@')[1]}</Link>} · {LIBELLE_STATUT[boite.statut]}</> : undefined}
        actions={
          boite ? (
            <>
              {suspendue ? (
                <Button variant="secondary" className={BOUTON_TACTILE} disabled={!permis} loading={reactiver.isPending} onClick={() => { suspendre.reset(); reactiver.mutate(boite.id); }}>Réactiver</Button>
              ) : (
                <Button variant="secondary" className={BOUTON_TACTILE} disabled={!permis} loading={suspendre.isPending} onClick={() => { reactiver.reset(); suspendre.mutate(boite.id); }}>Suspendre</Button>
              )}
              <Link to="/envoi/campagnes" className="inline-flex h-9 items-center rounded-lg bg-white px-4 text-sm font-medium text-slate-900 ring-1 ring-slate-200 hover:bg-slate-50 max-md:h-11">Voir les campagnes</Link>
            </>
          ) : undefined
        }
      />
      {boite && droits.charges && !permis ? <p className="mb-3 text-xs text-slate-600">{raison}</p> : null}
      {erreurGeste ? <div className="mb-3"><MessageErreur>{erreurGeste}</MessageErreur></div> : null}
      <Etats requete={requete} contexte="cette boîte d’envoi">{(fiche) => <Contenu fiche={fiche} />}</Etats>
    </div>
  );
}
