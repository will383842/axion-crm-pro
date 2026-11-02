/** DM2-INF — « Chauffe » : montée en douceur par boîte, en jours effectifs, avec recul d’un palier. */
import { Button, EmptyState, KpiCard } from '@/components/ui';
import { useChauffe, useReculerChauffe, type Boite, type Chauffe } from './composants/api';
import { Entete } from './composants/Entete';
import { Etats } from './composants/Etats';
import { BOUTON_TACTILE } from './composants/Formulaire';
import { dateCourte, LIBELLE_STATUT } from './composants/format';
import { Jauge, PastilleFeu } from './composants/Pastilles';

function Courbe({ courbe, jour }: { courbe: Chauffe['courbe']; jour: number }) {
  const L = 600;
  const H = 140;
  const max = Math.max(1, ...courbe.map((p) => p.plafond));
  const x = (j: number) => 10 + ((L - 20) * (j - 1)) / Math.max(1, courbe.length - 1);
  const y = (v: number) => H - 14 - ((H - 28) * v) / max;
  const points = courbe.map((p) => `${x(p.jour)},${y(p.plafond)}`).join(' ');
  const dernier = courbe[courbe.length - 1];
  return (
    <figure className="m-0">
      <svg viewBox={`0 0 ${L} ${H}`} role="img" aria-label={`Courbe de montée sur ${courbe.length} jours, jusqu’à ${dernier?.plafond ?? 0} envois par jour`} className="h-auto w-full text-sky-600">
        <polyline points={points} fill="none" stroke="currentColor" strokeWidth="2.5" />
        <circle cx={x(jour)} cy={y(courbe[jour - 1]?.plafond ?? 0)} r="5" fill="#0b7285" stroke="#fff" strokeWidth="2" />
      </svg>
      <div aria-hidden className="flex justify-between text-xs text-slate-500"><span>Jour 1</span><span>Jour {Math.round(courbe.length / 3)}</span><span>Jour {courbe.length}</span></div>
      <figcaption className="mt-1 text-xs text-slate-600">Texte équivalent : 2 messages le jour 1, puis 2 de plus à chaque jour d’envoi, jusqu’à {dernier?.plafond ?? 0} le jour {courbe.length}.</figcaption>
    </figure>
  );
}

function CarteChauffe({ b, fin, courbe }: { b: Boite; fin?: string | undefined; courbe: Chauffe['courbe'] }) {
  const reculer = useReculerChauffe();
  if (!b.chauffe) return null;
  return (
    <section aria-label={`Chauffe de ${b.adresse}`} className="space-y-3 rounded-xl bg-white p-4 ring-1 ring-slate-200">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="break-all text-lg font-bold text-slate-900">{b.adresse}</h2>
        <PastilleFeu etat={b.etat} />
      </div>
      <Jauge libelle="Montée" valeur={b.chauffe.jour} max={b.chauffe.sur} texte={`jour ${b.chauffe.jour} sur ${b.chauffe.sur}`} />
      <dl className="grid grid-cols-2 gap-2 text-sm md:grid-cols-3">
        <div><dt className="text-xs text-slate-500">Volume du jour</dt><dd className="font-semibold text-slate-900">{b.envoyes_aujourdhui} <span className="font-normal text-slate-600">prévu : {b.chauffe.plafond_aujourdhui}</span></dd></div>
        <div><dt className="text-xs text-slate-500">Fin prévue</dt><dd className="font-semibold text-slate-900">{fin ? dateCourte(fin) : '—'}</dd></div>
      </dl>
      <Courbe courbe={courbe} jour={b.chauffe.jour} />
      <Button variant="secondary" className={BOUTON_TACTILE} loading={reculer.isPending} onClick={() => reculer.mutate(b.id)}>Reculer d’un palier</Button>
    </section>
  );
}

function Contenu({ chauffe }: { chauffe: Chauffe }) {
  const enChauffe = chauffe.boites.filter((b) => b.chauffe);
  return (
    <div className="space-y-4">
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
        <KpiCard label="En chauffe" value={enChauffe.length} tone="sky" />
        <KpiCard label="Plafond par boîte" value={`${chauffe.plafond_boite} / jour`} sublabel="en V0" tone="slate" />
        <KpiCard label="Terminées" value={chauffe.boites.filter((b) => !b.chauffe && b.statut !== 'suspendue').length} tone="emerald" />
      </div>
      <p className="text-sm text-slate-700">La montée se compte en jours d’envoi effectifs : un jour sans envoi ne fait pas avancer le palier. Elle se fait avec vos vrais envois, sans échanges artificiels entre boîtes.</p>
      <div className="grid gap-4 lg:grid-cols-2">
        {enChauffe.map((b) => <CarteChauffe key={b.id} b={b} fin={chauffe.fins_prevues[b.id]} courbe={chauffe.courbe} />)}
      </div>
      <section aria-labelledby="toutes" className="rounded-xl bg-white p-4 ring-1 ring-slate-200">
        <h2 id="toutes" className="text-lg font-bold text-slate-900">Toutes les boîtes</h2>
        <div className="mt-2 overflow-x-auto">
          <table className="w-full min-w-[30rem] text-left text-sm">
            <caption className="sr-only">Chauffe de toutes les boîtes</caption>
            <thead><tr className="text-xs text-slate-500">
              <th scope="col" className="py-1 pr-3 font-medium">Boîte</th><th scope="col" className="py-1 pr-3 font-medium">Jour</th><th scope="col" className="py-1 pr-3 font-medium">Plafond aujourd’hui</th><th scope="col" className="py-1 font-medium">État</th>
            </tr></thead>
            <tbody>
              {chauffe.boites.map((b) => (
                <tr key={b.id} className="border-t border-slate-100">
                  <th scope="row" className="py-2 pr-3 font-medium text-slate-900 break-all">{b.adresse}</th>
                  <td className="py-2 pr-3">{b.chauffe ? `${b.chauffe.jour} / ${b.chauffe.sur}` : 'Faite'}</td>
                  <td className="py-2 pr-3">{b.quota_jour}</td>
                  <td className="py-2">{LIBELLE_STATUT[b.statut]}</td>
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
  const n = requete.data?.boites.filter((b) => b.chauffe).length ?? 0;
  return (
    <div>
      <Entete title="Chauffe" phrase={requete.data ? <>Montée en douceur : <strong>{n} boîte{n > 1 ? 's' : ''}</strong> en chauffe.</> : undefined} />
      <Etats
        requete={requete}
        contexte="la chauffe des boîtes"
        estVide={(d) => d.boites.length === 0}
        vide={<EmptyState title="Aucune boîte en chauffe" description="Ajoutez une boîte : sa montée en douceur de 30 jours démarre toute seule." />}
      >
        {(c) => <Contenu chauffe={c} />}
      </Etats>
    </div>
  );
}
