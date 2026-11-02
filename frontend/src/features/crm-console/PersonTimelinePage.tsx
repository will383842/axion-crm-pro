/**
 * FICHE 360° — la timeline unifiée d'une personne.
 *
 * La timeline est un INDEX des touchpoints, jamais une copie de leur contenu
 * (plan §2.6) : chaque ligne référence sa source, le détail reste dans le
 * système qui l'a produit.
 *
 * L'encart « existe aussi dans l'autre univers » affiche un BOOLÉEN et rien
 * d'autre quand l'univers n'est pas accessible (plan §2.4.3). Ce n'est pas une
 * demi-mesure : sans lui, un opérateur business créerait une seconde fiche pour
 * quelqu'un qui en a déjà une — l'étanchéité produirait des doublons au lieu de
 * protéger.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from '@tanstack/react-router';
import { toast } from 'sonner';
import { Button, Card, CardTitle, EmptyState, Modal, PageHeader, QueryErrorState, StatusPill, type StatusTone } from '@/components/ui';
import { api, qualifierErreur } from '@/lib/api';
import {
  ETAT_ENVOI_LIBELLES,
  dateFr,
  erreursDeChamps,
  libelleProvenance,
  messageServeur,
  type EtatEnvoi,
  type FicheDetail,
} from '@/features/contacts/ajout-rapide/types';
import { ConsoleGate, ConsoleListSkeleton } from './ConsoleGate';
import { LIFECYCLE_LABELS, type LifecycleStage, type TimelineEntry, type TimelineResponse } from './types';

/** Ce que la fiche ajoute à la réponse d'historique (absent : l'écran reste celui d'avant). */
type ReponseFiche = TimelineResponse & { fiche?: FicheDetail };

const TON_ENVOI: Record<EtatEnvoi, StatusTone> = { en_file: 'info', parti: 'success', refuse: 'danger', repondu: 'success', annule: 'neutral' };
const POINT_PAR_TYPE: Record<string, string> = {
  message_envoi: 'bg-sky-500',
  message_refuse: 'bg-rose-500',
  reponse: 'bg-emerald-500',
  appel: 'bg-amber-500',
  rendez_vous: 'bg-violet-500',
  questionnaire: 'bg-violet-500',
  note: 'bg-slate-400',
};

export function PersonTimelinePage() {
  return (
    <ConsoleGate>
      <PersonTimelineContent />
    </ConsoleGate>
  );
}

function PersonTimelineContent() {
  const { personKey } = useParams({ from: '/layout/console/personnes/$personKey' });

  const timeline = useQuery<ReponseFiche>({
    queryKey: ['crm', 'person-timeline', personKey],
    queryFn: async () => (await api.get<ReponseFiche>(`/crm/persons/${personKey}/timeline`)).data,
  });

  if (timeline.isLoading) {
    return (
      <div>
        <ConsoleListSkeleton rows={6} />
      </div>
    );
  }

  const data = timeline.data;
  // P0-3 — une panne n'est pas une fiche introuvable : seul un 404 l'est.
  if (data === undefined && timeline.error !== null && qualifierErreur(timeline.error).nature !== 'introuvable') {
    return (
      <div>
        <QueryErrorState error={timeline.error} contexte="l’historique de cette personne" onRetry={() => void timeline.refetch()} />
      </div>
    );
  }
  if (data === undefined) {
    return (
      <div>
        <EmptyState title="Fiche introuvable" description="Cette personne n’existe dans aucune base à laquelle vous avez accès." />
      </div>
    );
  }

  const identity = data.subjects[0];
  const displayName =
    identity === undefined
      ? 'Personne'
      : [identity.first_name, identity.last_name].filter(Boolean).join(' ') || 'Personne';

  return (
    <div>
      <PageHeader
        surTitre="CRM Pro"
        title={displayName}
        subtitle="Tout ce qui concerne cette personne."
        actions={data.fiche === undefined ? undefined : <ActionsFiche fiche={data.fiche} personKey={personKey} />}
      />
      {data.fiche?.retire_le != null ? <BandeauCorbeille fiche={data.fiche} personKey={personKey} /> : null}

      <div className="grid gap-4 lg:grid-cols-[320px_1fr]">
        <div className="flex flex-col gap-4">
          <Card>
            <CardTitle>Identité</CardTitle>
            {data.subjects.length === 0 ? (
              <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">
                Aucune fiche dans les bases auxquelles vous avez accès.
              </p>
            ) : (
              <ul className="mt-2 flex flex-col gap-3 text-xs text-slate-600 dark:text-slate-300">
                {data.subjects.map((subject) => (
                  <li key={`${subject.universe}-${subject.type}-${subject.id}`}>
                    <div className="font-medium text-slate-900 dark:text-white">
                      {[subject.first_name, subject.last_name].filter(Boolean).join(' ')}
                    </div>
                    <div>{subject.email ?? '—'}</div>
                    {data.fiche !== undefined && subject.type === 'contact' && (
                      <>
                        {data.fiche.fonction !== '' && <div>{data.fiche.fonction}</div>}
                        <div>
                          Étape : {LIFECYCLE_LABELS[data.fiche.etape] ?? data.fiche.etape} · Provenance : {libelleProvenance(data.fiche.provenance)}
                        </div>
                        {data.fiche.rappel_le !== null && (
                          <div className="font-medium text-slate-900">
                            Rappel prévu le {dateFr(data.fiche.rappel_le)}
                            {data.fiche.rappel_note ? ` : ${data.fiche.rappel_note}` : ''}
                          </div>
                        )}
                      </>
                    )}
                    {subject.company !== undefined && subject.company !== null && (
                      <div className="text-slate-400">{subject.company.denomination ?? subject.company.siren}</div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>

          <Card>
            <CardTitle>Base</CardTitle>
            <ul className="mt-2 flex flex-col gap-2 text-xs">
              <UniverseLine
                label="Clients et prospects"
                accessible={data.universes.business.accessible}
                exists={data.universes.business.exists}
              />
              <UniverseLine
                label="Candidats"
                accessible={data.universes.vivier.accessible}
                exists={data.universes.vivier.exists}
              />
            </ul>
          </Card>

          {data.fiche !== undefined && <PeutOnEcrire fiche={data.fiche} />}
        </div>

        <div className="flex flex-col gap-4">
        <section aria-label="Historique">
        <Card>
          <CardTitle>Historique</CardTitle>
          {/* D25-011 — `data.data` vient d'une reponse d'API que rien ne valide
              a l'execution : sans `?.`, une clef absente jette et emporte tout
              l'ecran (timeline ET l'encart « univers » au-dessus). On accepte
              ici de confondre « absent » et « vide » : l'ecran ne sait de toute
              facon pas distinguer les deux, et un ecran blanc est pire.
              Mesure du 2026-08-22. */}
          {(data.data?.length ?? 0) === 0 ? (
            <p className="mt-2 text-xs text-slate-500 dark:text-slate-400">Aucun échange enregistré.</p>
          ) : (
            <ol className="mt-3 flex flex-col gap-3">
              {data.data.map((entry) => (
                <li key={`${entry.universe}-${entry.id}`} className="border-l-2 border-slate-200 pl-3 dark:border-slate-700">
                  <div className="flex items-center gap-2 text-xs text-slate-400">
                    {data.fiche !== undefined && <PointHistorique entree={entry} />}
                    {data.fiche !== undefined ? dateFr(entry.occurred_at) : (entry.occurred_at ?? '—')}
                  </div>
                  <div className="text-sm text-slate-900 dark:text-white">{entry.title ?? entry.kind}</div>
                  <div className="text-xs text-slate-400">
                    {entry.universe === 'vivier' ? 'Candidats' : 'Clients et prospects'}
                    {entry.external_ref !== null && <> · {entry.external_ref}</>}
                  </div>
                </li>
              ))}
            </ol>
          )}
        </Card>
        </section>
        {data.fiche !== undefined && <EtatDesMessages fiche={data.fiche} />}
        </div>
      </div>
    </div>
  );
}

function UniverseLine({
  label,
  accessible,
  exists,
}: {
  label: string;
  accessible: boolean;
  exists: boolean;
}) {
  return (
    <li className="flex items-center justify-between gap-2">
      <span className="text-slate-600 dark:text-slate-300">{label}</span>
      {!exists ? (
        <StatusPill tone="neutral">Aucune fiche</StatusPill>
      ) : accessible ? (
        <StatusPill tone="success">Fiche présente</StatusPill>
      ) : (
        // Un booléen, et rien d'autre : ni nom, ni étape, ni activité.
        <StatusPill tone="info">Existe dans l’autre base</StatusPill>
      )}
    </li>
  );
}

// ── DM1-CON : ce que la fiche ajoute ────────────────────────────────────────

function PointHistorique({ entree }: { entree: TimelineEntry }) {
  return <span aria-hidden className={`inline-block h-2 w-2 rounded-full ${POINT_PAR_TYPE[entree.kind ?? ''] ?? 'bg-slate-300'}`} />;
}

function PeutOnEcrire({ fiche }: { fiche: FicheDetail }) {
  const { verdict, raison } = fiche.droit_ecrire;
  const mot = verdict === 'oui' ? 'Oui' : verdict === 'non' ? 'Non' : 'Avec prudence';
  const ton: StatusTone = verdict === 'oui' ? 'success' : verdict === 'non' ? 'danger' : 'warning';
  return (
    <section aria-label="Peut-on lui écrire ?">
      <Card>
        <div className="flex items-center justify-between gap-2">
          <CardTitle>Peut-on lui écrire ?</CardTitle>
          <StatusPill tone={ton}>{verdict === 'oui' ? 'Joignable' : verdict === 'non' ? 'Ne pas écrire' : 'À surveiller'}</StatusPill>
        </div>
        <p className="mt-2 text-sm font-semibold text-slate-900">{mot}</p>
        <p className="text-xs text-slate-600">{raison}</p>
      </Card>
    </section>
  );
}

function EtatDesMessages({ fiche }: { fiche: FicheDetail }) {
  return (
    <section aria-label="État de ses messages (Envoi)">
      <Card>
        <CardTitle>État de ses messages (Envoi)</CardTitle>
        {fiche.envois.length === 0 ? (
          <p className="mt-2 text-xs text-slate-500">Aucun message envoyé à cette personne.</p>
        ) : (
          <div className="mt-2 overflow-x-auto">
            <table className="w-full text-left text-sm">
              <caption className="sr-only">Messages envoyés à cette personne</caption>
              <thead className="text-xs text-slate-500">
                <tr>
                  <th scope="col" className="py-1 pr-3 font-medium">Message</th>
                  <th scope="col" className="py-1 pr-3 font-medium">État</th>
                  <th scope="col" className="py-1 pr-3 font-medium">Raison</th>
                  <th scope="col" className="py-1 font-medium">Date</th>
                </tr>
              </thead>
              <tbody>
                {fiche.envois.map((e) => (
                  <tr key={e.id} className="border-t border-slate-100 align-top">
                    <td className="py-2 pr-3 text-slate-900">{e.message}</td>
                    <td className="py-2 pr-3">
                      <StatusPill tone={TON_ENVOI[e.etat]}>{ETAT_ENVOI_LIBELLES[e.etat]}</StatusPill>
                    </td>
                    <td className="py-2 pr-3 text-xs text-slate-600">{e.raison}</td>
                    <td className="py-2 text-xs text-slate-600">{dateFr(e.le)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Card>
    </section>
  );
}

function BandeauCorbeille({ fiche, personKey }: { fiche: FicheDetail; personKey: string }) {
  const client = useQueryClient();
  const restaurer = useMutation({
    mutationFn: async () => { await api.post(`/crm/contacts/${fiche.contact_id}/restaurer`); },
    onSuccess: () => {
      toast.success('Fiche restaurée');
      void client.invalidateQueries({ queryKey: ['crm'] });
      void client.invalidateQueries({ queryKey: ['crm', 'person-timeline', personKey] });
    },
    onError: (e) => toast.error(messageServeur(e) ?? 'La restauration n’a pas pu se faire.'),
  });
  return (
    <div role="status" aria-label="Fiche dans la corbeille" className="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900 ring-1 ring-amber-200">
      <span>Cette fiche est dans la corbeille depuis le {dateFr(fiche.retire_le)}. Elle n’apparaît plus dans les listes, mais rien n’est supprimé.</span>
      <Button variant="secondary" size="sm" className="h-11 md:h-8" loading={restaurer.isPending} onClick={() => restaurer.mutate()}>
        Restaurer
      </Button>
    </div>
  );
}

type Fenetre = 'modifier' | 'retirer' | 'appel' | 'rappel' | null;

const CLS_CONTROLE =
  'h-11 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-produit md:h-9';

function ActionsFiche({ fiche, personKey }: { fiche: FicheDetail; personKey: string }) {
  const client = useQueryClient();
  const [fenetre, setFenetre] = useState<Fenetre>(null);
  const [erreurs, setErreurs] = useState<string[]>([]);
  const fermer = () => {
    setFenetre(null);
    setErreurs([]);
  };
  const rafraichir = () => {
    void client.invalidateQueries({ queryKey: ['crm'] });
    void client.invalidateQueries({ queryKey: ['crm', 'person-timeline', personKey] });
  };
  const enEchec = (e: unknown, repli: string) => {
    const liste = erreursDeChamps(e);
    setErreurs(liste.length > 0 ? liste : [messageServeur(e) ?? repli]);
  };

  // Modifier
  const [prenom, setPrenom] = useState(fiche.first_name);
  const [nom, setNom] = useState(fiche.last_name);
  const [email, setEmail] = useState(fiche.email);
  const [fonction, setFonction] = useState(fiche.fonction);
  const [etape, setEtape] = useState<LifecycleStage>(fiche.etape);
  // Appel et rappel
  const [compteRendu, setCompteRendu] = useState('');
  const [dateRappel, setDateRappel] = useState('');
  const [noteRappel, setNoteRappel] = useState('');

  const ouvrir = (f: Exclude<Fenetre, null>) => {
    setPrenom(fiche.first_name);
    setNom(fiche.last_name);
    setEmail(fiche.email);
    setFonction(fiche.fonction);
    setEtape(fiche.etape);
    setCompteRendu('');
    setDateRappel('');
    setNoteRappel('');
    setErreurs([]);
    setFenetre(f);
  };

  const modifier = useMutation({
    mutationFn: async () => { await api.patch(`/crm/contacts/${fiche.contact_id}`, { first_name: prenom, last_name: nom, email, fonction, lifecycle_stage: etape }); },
    onSuccess: () => {
      toast.success('Fiche enregistrée');
      rafraichir();
      fermer();
    },
    onError: (e) => enEchec(e, 'La modification n’a pas pu se faire.'),
  });
  const retirer = useMutation({
    mutationFn: async () => { await api.post(`/crm/contacts/${fiche.contact_id}/retirer`); },
    onSuccess: () => {
      toast.success('Fiche placée dans la corbeille');
      rafraichir();
      fermer();
    },
    onError: (e) => enEchec(e, 'Le retrait n’a pas pu se faire.'),
  });
  const appel = useMutation({
    mutationFn: async () => { await api.post(`/crm/persons/${personKey}/appels`, { compte_rendu: compteRendu }); },
    onSuccess: () => {
      toast.success('Appel noté');
      rafraichir();
      fermer();
    },
    onError: (e) => enEchec(e, 'L’appel n’a pas pu être noté.'),
  });
  const rappel = useMutation({
    mutationFn: async () => { await api.post(`/crm/persons/${personKey}/rappel`, { le: dateRappel, note: noteRappel }); },
    onSuccess: () => {
      toast.success('Rappel planifié');
      rafraichir();
      fermer();
    },
    onError: (e) => enEchec(e, 'Le rappel n’a pas pu être planifié.'),
  });

  const alerte =
    erreurs.length > 0 ? (
      <div role="alert" className="rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">
        {erreurs.join(' ')}
      </div>
    ) : null;
  const pied = (action: () => void, enCours: boolean, libelle: string, tonDestructif = false) => (
    <>
      <Button variant="secondary" onClick={fermer}>
        Annuler
      </Button>
      <Button variant={tonDestructif ? 'destructive' : 'primary'} loading={enCours} onClick={action}>
        {libelle}
      </Button>
    </>
  );

  return (
    <>
      <Button variant="secondary" onClick={() => ouvrir('modifier')}>
        Modifier
      </Button>
      <Button variant="secondary" onClick={() => ouvrir('rappel')}>
        Planifier un rappel
      </Button>
      <Button variant="secondary" disabled={fiche.retire_le !== null} onClick={() => ouvrir('retirer')}>
        Retirer
      </Button>
      <Button variant="primary" onClick={() => ouvrir('appel')}>
        Noter un appel
      </Button>

      <Modal open={fenetre === 'modifier'} onClose={fermer} title="Modifier la fiche" footer={pied(() => modifier.mutate(), modifier.isPending, 'Enregistrer')}>
        <div className="grid gap-3 sm:grid-cols-2">
          <ChampFiche label="Prénom" valeur={prenom} onChange={setPrenom} />
          <ChampFiche label="Nom" valeur={nom} onChange={setNom} />
          <ChampFiche label="E-mail" valeur={email} onChange={setEmail} type="email" />
          <ChampFiche label="Fonction" valeur={fonction} onChange={setFonction} />
          <label className="flex flex-col gap-1 text-sm font-medium text-slate-700 sm:col-span-2">
            Étape
            <select className={CLS_CONTROLE} value={etape} onChange={(e) => setEtape(e.target.value as LifecycleStage)}>
              {(Object.keys(LIFECYCLE_LABELS) as LifecycleStage[]).map((k) => (
                <option key={k} value={k}>
                  {LIFECYCLE_LABELS[k]}
                </option>
              ))}
            </select>
          </label>
          <div className="sm:col-span-2">{alerte}</div>
        </div>
      </Modal>

      <Modal
        open={fenetre === 'retirer'}
        onClose={fermer}
        title="Retirer cette fiche ?"
        description="La fiche va dans la corbeille. Elle n’est pas supprimée : vous pourrez la restaurer à tout moment, avec son historique."
        footer={pied(() => retirer.mutate(), retirer.isPending, 'Retirer de la base', true)}
      >
        {alerte}
      </Modal>

      <Modal open={fenetre === 'appel'} onClose={fermer} title="Noter un appel" footer={pied(() => appel.mutate(), appel.isPending, 'Enregistrer')}>
        <label className="flex flex-col gap-1 text-sm font-medium text-slate-700">
          Compte rendu
          <textarea className="min-h-24 w-full rounded-lg bg-white p-3 text-sm text-slate-900 ring-1 ring-slate-300 focus:outline-none focus:ring-2 focus:ring-produit" value={compteRendu} onChange={(e) => setCompteRendu(e.target.value)} />
        </label>
        <div className="mt-3">{alerte}</div>
      </Modal>

      <Modal open={fenetre === 'rappel'} onClose={fermer} title="Planifier un rappel" footer={pied(() => rappel.mutate(), rappel.isPending, 'Enregistrer')}>
        <div className="flex flex-col gap-3">
          <ChampFiche label="Date du rappel" valeur={dateRappel} onChange={setDateRappel} type="date" />
          <ChampFiche label="Note (facultatif)" valeur={noteRappel} onChange={setNoteRappel} />
          {alerte}
        </div>
      </Modal>
    </>
  );
}

function ChampFiche({ label, valeur, onChange, type = 'text' }: { label: string; valeur: string; onChange: (v: string) => void; type?: string }) {
  return (
    <label className="flex flex-col gap-1 text-sm font-medium text-slate-700">
      {label}
      <input type={type} className={CLS_CONTROLE} value={valeur} onChange={(e) => onChange(e.target.value)} />
    </label>
  );
}
