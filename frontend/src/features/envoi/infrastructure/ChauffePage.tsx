/**
 * L8-F08 — « Chauffe » : montée en douceur par adresse IP et par domaine.
 * Une seule action : reculer d'un palier (jamais d'avance). Un terme sans valeur
 * s'affiche « non calculé », jamais 0.
 */
import { useState } from 'react';
import { Button, EmptyState, KpiCard, Modal } from '@/components/ui';
import { useChauffe, useReculerChauffe, type Chauffe, type RessourceChauffe } from './composants/api';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE } from './composants/Formulaire';
import { dateCourte, messageErreur } from './composants/format';
import { Jauge } from './composants/Pastilles';

type Terme = Chauffe['goulot']['terme'];

const LIBELLE_TERME: Record<Terme, string> = {
  courbe_ip: 'Montée des adresses IP',
  courbe_domaine: 'Montée des domaines',
  plafond_boites: 'Plafond des boîtes',
  plafond_serveur: 'Plafond du serveur',
  capacite_reponse: 'Capacité à traiter les réponses',
};
const ORDRE_TERMES: readonly Terme[] = ['courbe_ip', 'courbe_domaine', 'plafond_boites', 'plafond_serveur', 'capacite_reponse'];

const MOTIF: Record<NonNullable<RessourceChauffe['motif_tenue']>, string> = {
  frein: 'frein de sécurité',
  manuel: 'décision manuelle',
  inactivite: 'inactivité',
};

/** « le budget du jour est limité par … » en une phrase. */
function phraseGoulot(c: Chauffe): string {
  const cible = c.goulot.ressource_id ? c.ressources.find((r) => r.id === c.goulot.ressource_id)?.libelle : undefined;
  const cause: Record<Terme, string> = {
    courbe_ip: cible ? `la montée de l’adresse IP ${cible}` : 'la montée d’une adresse IP',
    courbe_domaine: cible ? `la montée du domaine ${cible}` : 'la montée d’un domaine',
    plafond_boites: 'le plafond des boîtes',
    plafond_serveur: 'le plafond du serveur',
    capacite_reponse: 'la capacité à traiter les réponses',
  };
  return `Le budget du jour est limité par ${cause[c.goulot.terme]}.`;
}

type Retour = { genre: 'fait' | 'deja' | 'erreur'; texte: string };

function CarteRessource({ r }: { r: RessourceChauffe }) {
  const reculer = useReculerChauffe();
  const [confirmer, setConfirmer] = useState(false);
  const [retour, setRetour] = useState<Retour | null>(null);

  function lancer() {
    setConfirmer(false);
    reculer.mutate(r.id, {
      onSuccess: (apres) =>
        setRetour(
          apres.palier === r.palier
            ? { genre: 'deja', texte: `Déjà reculé aujourd’hui : ${r.libelle} reste au palier ${apres.palier}.` }
            : { genre: 'fait', texte: `Reculé d’un palier : ${r.palier} → ${apres.palier}. Le budget du jour baisse.` },
        ),
      onError: (e) => setRetour({ genre: 'erreur', texte: messageErreur(e) }),
    });
  }

  return (
    <section aria-label={`Chauffe de ${r.libelle}`} className="space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200">
      <h3 className="break-all text-lg font-bold text-slate-900">{r.libelle}</h3>
      <Jauge libelle="Montée" valeur={r.jours_effectifs} max={Math.max(r.jours_effectifs, 30)} texte={`palier ${r.palier}, jour ${r.jours_effectifs}`} />
      <dl className="grid grid-cols-2 gap-2 text-sm md:grid-cols-3">
        <div><dt className="text-xs text-slate-500">Volume du jour</dt><dd className="font-semibold text-slate-900">{r.envoyes_aujourdhui} <span className="font-normal text-slate-600">prévu : {r.total_palier}</span></dd></div>
        <div><dt className="text-xs text-slate-500">Palier suivant</dt><dd className="font-semibold text-slate-900">{r.jours_avant_palier_suivant === null ? '—' : `dans ${r.jours_avant_palier_suivant} jour${r.jours_avant_palier_suivant > 1 ? 's' : ''}`}</dd></div>
        <div>
          <dt className="text-xs text-slate-500">Tenu jusqu’au</dt>
          <dd className="font-semibold text-slate-900">{r.tenu_jusqu_au ? dateCourte(r.tenu_jusqu_au) : '—'}{r.motif_tenue ? <span className="font-normal text-slate-600"> ({MOTIF[r.motif_tenue]})</span> : null}</dd>
        </div>
      </dl>
      {r.peut_reculer ? (
        <Button variant="secondary" className={BOUTON_TACTILE} aria-label={`Reculer d’un palier : ${r.libelle}`} loading={reculer.isPending} onClick={() => setConfirmer(true)}>Reculer d’un palier</Button>
      ) : null}
      <div role={retour ? (retour.genre === 'erreur' ? 'alert' : 'status') : undefined} aria-live="polite" className={retour?.genre === 'erreur' ? 'text-sm font-medium text-rose-700' : 'text-sm text-slate-700'}>
        {retour?.texte}
      </div>
      <Modal
        open={confirmer}
        onClose={() => setConfirmer(false)}
        title={`Reculer ${r.libelle} d’un palier ?`}
        size="sm"
        footer={<><Button variant="secondary" onClick={() => setConfirmer(false)}>Annuler</Button><Button onClick={lancer}>Reculer</Button></>}
      >
        <p className="text-sm text-slate-700">La ressource ralentit, le budget du jour baisse. Un seul recul est possible par jour et par ressource.</p>
      </Modal>
    </section>
  );
}

function Groupe({ id, titre, ressources }: { id: string; titre: string; ressources: RessourceChauffe[] }) {
  if (ressources.length === 0) return null;
  return (
    <section aria-labelledby={id} className="space-y-3">
      <h2 id={id} className="text-lg font-bold text-slate-900">{titre}</h2>
      <div className="grid gap-4 lg:grid-cols-2">{ressources.map((r) => <CarteRessource key={r.id} r={r} />)}</div>
    </section>
  );
}

function Contenu({ chauffe }: { chauffe: Chauffe }) {
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
        <KpiCard label="Budget du jour" value={chauffe.budget_du_jour} sublabel="envois permis" tone="sky" />
        <KpiCard label="Plafond par boîte" value={`${chauffe.plafond_boite} / jour`} sublabel="en V0" tone="slate" />
        <KpiCard label="En chauffe" value={chauffe.ressources.length} tone="emerald" />
      </div>
      <p className="text-sm font-medium text-slate-900">{phraseGoulot(chauffe)}</p>
      <section aria-labelledby="termes" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 id="termes" className="text-lg font-bold text-slate-900">Les termes du budget</h2>
        <dl className="mt-2 grid gap-2 text-sm sm:grid-cols-2">
          {ORDRE_TERMES.map((t) => {
            const v = chauffe.termes[t];
            return (
              <div key={t} className="flex justify-between gap-2">
                <dt className="text-slate-600">{LIBELLE_TERME[t]}{chauffe.goulot.terme === t ? ' (limite)' : ''}</dt>
                <dd className={v === null ? 'italic text-slate-500' : 'font-semibold text-slate-900'}>{v === null ? 'non calculé' : v}</dd>
              </div>
            );
          })}
        </dl>
      </section>
      <p className="text-sm text-slate-700">La montée se compte en jours d’envoi effectifs : un jour sans envoi ne fait pas avancer le palier.</p>
      <Groupe id="groupe-ip" titre="Adresses IP" ressources={chauffe.ressources.filter((r) => r.type === 'ip')} />
      <Groupe id="groupe-domaines" titre="Domaines" ressources={chauffe.ressources.filter((r) => r.type === 'domaine')} />
      <section aria-labelledby="paliers" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 id="paliers" className="text-lg font-bold text-slate-900">Paliers</h2>
        <div className="relative mt-2 overflow-x-auto">
          <table className="w-full min-w-[24rem] text-left text-sm">
            <caption className="sr-only">Table des paliers de la montée en douceur</caption>
            <thead><tr className="text-xs text-slate-500">
              <th scope="col" className="py-1 pr-3 font-medium">Palier</th><th scope="col" className="py-1 pr-3 font-medium">Jours d’envoi</th><th scope="col" className="py-1 font-medium">Envois par jour</th>
            </tr></thead>
            <tbody>
              {chauffe.paliers.map((p) => (
                <tr key={p.palier} className="border-t border-slate-100">
                  <th scope="row" className="py-2 pr-3 font-medium text-slate-900">{p.palier}</th>
                  <td className="py-2 pr-3">{p.jours_min} à {p.jours_max}</td>
                  <td className="py-2">{p.total_jour}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

export function ChauffePage() {
  const requete = useChauffe();
  const n = requete.data?.ressources.length ?? 0;
  return (
    <div>
      <Entete title="Chauffe" phrase={requete.data ? <>Montée en douceur : <strong>{n} ressource{n > 1 ? 's' : ''}</strong> en chauffe.</> : undefined} />
      <Etats
        requete={requete}
        contexte="la chauffe"
        estVide={(d) => d.ressources.length === 0}
        vide={<EmptyState title="Rien en chauffe" description="Aucun domaine ni aucune adresse IP n’est en montée en douceur." />}
      >
        {(c) => <Contenu chauffe={c} />}
      </Etats>
    </div>
  );
}
