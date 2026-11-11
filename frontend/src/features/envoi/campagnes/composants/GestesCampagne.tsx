/**
 * DM2-CAM — les gestes sur une campagne : Pause / Reprendre, Changer la date,
 * Dupliquer, Terminer. Utilisés dans chaque ligne de la liste ET dans l'en-tête
 * du suivi. Chaque geste qui coupe quelque chose demande une confirmation ;
 * rien n'est jamais perdu (on met en pause, on termine, on duplique).
 */
import { useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { isAxiosError } from 'axios';
import { CalendarClock, Copy, Pause, Play, Square } from 'lucide-react';
import { Button, Modal } from '@/components/ui';
import { cn } from '@/components/ui/cn';
import { useGestesCampagne, type Campagne } from '../api';
import { ajouterJours, dateRelative, jourDe, parisVersIso, prochainLundi } from './libelles';

type Dialogue = 'pause' | 'date' | 'dupliquer' | 'terminer' | null;

export interface GestesCampagneProps {
  campagne: Campagne;
  /** Heure du serveur : « Demain 9:00 » s'en déduit. */
  maintenant: string;
  /** `ligne` : libellés courts dans un tableau ; `entete` : libellés complets en haut du suivi. */
  variante: 'ligne' | 'entete';
  /** Un lien d'ouverture placé avant les gestes (« Relire et valider », « Ouvrir »). */
  avant?: React.ReactNode;
}

/** Quels gestes chaque statut permet. */
export function gestesPermis(statut: Campagne['statut']) {
  return {
    pause: statut === 'en_cours',
    reprise: statut === 'en_pause',
    date: statut === 'programmee' || statut === 'en_pause',
    terminer: statut === 'en_cours' || statut === 'en_pause' || statut === 'programmee',
    dupliquer: true,
  };
}

function messageErreur(e: unknown): string {
  if (isAxiosError(e)) {
    const m = (e.response?.data as { message?: unknown } | undefined)?.message;
    if (typeof m === 'string' && m !== '') return m;
  }
  return 'Le geste n’a pas abouti. Rien n’a changé : réessayez dans un instant.';
}

const TACTILE = 'max-md:h-11 max-md:text-sm';

export function GestesCampagne({ campagne, maintenant, variante, avant }: GestesCampagneProps) {
  const [dialogue, setDialogue] = useState<Dialogue>(null);
  const gestes = useGestesCampagne();
  const permis = gestesPermis(campagne.statut);
  const id = String(campagne.id);
  const entete = variante === 'entete';
  const nom = (court: string) => (entete ? undefined : `${court} : ${campagne.nom}`);
  const fermer = () => setDialogue(null);
  const conteneur = useRef<HTMLDivElement>(null);
  // Après « Reprendre », le bouton disparaît : le focus passe à « Pause » (ou au groupe de gestes), jamais au vide.
  const reprendre = () =>
    gestes.reprise.mutate(id, {
      onSuccess: () => {
        requestAnimationFrame(() => {
          const cible = conteneur.current?.querySelector<HTMLElement>('[data-geste="pause"]') ?? conteneur.current;
          cible?.focus();
        });
      },
    });

  return (
    <>
      <div ref={conteneur} tabIndex={-1} className={cn('flex flex-wrap items-center gap-2 outline-none', entete ? 'justify-end' : 'justify-start')}>
        {avant}
        {permis.pause ? (
          <Button variant="secondary" size="md" className={TACTILE} iconLeft={<Pause className="h-4 w-4" aria-hidden />} aria-label={nom('Pause')} data-geste="pause" onClick={() => setDialogue('pause')}>
            {entete ? 'Mettre en pause' : 'Pause'}
          </Button>
        ) : null}
        {permis.reprise ? (
          <Button
            variant="secondary"
            size="md"
            className={TACTILE}
            iconLeft={<Play className="h-4 w-4" aria-hidden />}
            aria-label={nom('Reprendre')}
            loading={gestes.reprise.isPending}
            onClick={reprendre}
          >
            Reprendre
          </Button>
        ) : null}
        {permis.date ? (
          <Button variant="secondary" size="md" className={TACTILE} iconLeft={<CalendarClock className="h-4 w-4" aria-hidden />} aria-label={nom('Changer la date')} onClick={() => setDialogue('date')}>
            Changer la date
          </Button>
        ) : null}
        <Button variant="secondary" size="md" className={TACTILE} iconLeft={<Copy className="h-4 w-4" aria-hidden />} aria-label={nom('Dupliquer')} onClick={() => setDialogue('dupliquer')}>
          Dupliquer
        </Button>
        {permis.terminer ? (
          <Button variant="ghost" size="md" className={TACTILE} iconLeft={<Square className="h-4 w-4" aria-hidden />} aria-label={nom('Terminer')} onClick={() => setDialogue('terminer')}>
            Terminer
          </Button>
        ) : null}
      </div>
      {gestes.reprise.isError ? (
        <p role="alert" className="mt-2 text-xs text-rose-700">
          {messageErreur(gestes.reprise.error)}
        </p>
      ) : null}

      {dialogue !== null
        ? createPortal(
            <>
              {dialogue === 'pause' ? <DialoguePause campagne={campagne} onClose={fermer} /> : null}
              {dialogue === 'date' ? <DialogueDate campagne={campagne} maintenant={maintenant} onClose={fermer} /> : null}
              {dialogue === 'dupliquer' ? <DialogueDupliquer campagne={campagne} onClose={fermer} /> : null}
              {dialogue === 'terminer' ? <DialogueTerminer campagne={campagne} onClose={fermer} /> : null}
            </>,
            document.body,
          )
        : null}
    </>
  );
}

function PiedDialogue({ annuler, children }: { annuler: () => void; children: React.ReactNode }) {
  return (
    <>
      <Button variant="ghost" className={TACTILE} onClick={annuler}>
        Annuler
      </Button>
      {children}
    </>
  );
}

function Erreur({ erreur }: { erreur: unknown }) {
  if (!erreur) return null;
  return (
    <p role="alert" className="mt-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">
      {messageErreur(erreur)}
    </p>
  );
}

function DialoguePause({ campagne, onClose }: { campagne: Campagne; onClose: () => void }) {
  const { pause } = useGestesCampagne();
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Mettre en pause ?"
      footer={
        <PiedDialogue annuler={onClose}>
          <Button className={TACTILE} loading={pause.isPending} onClick={() => pause.mutate(String(campagne.id), { onSuccess: onClose })}>
            Mettre en pause
          </Button>
        </PiedDialogue>
      }
    >
      <p className="text-sm text-slate-700">
        <strong className="font-semibold text-slate-900">{campagne.nom}</strong> s’arrête tout de suite. Rien n’est perdu : reprise quand vous voulez.
      </p>
      <Erreur erreur={pause.error} />
    </Modal>
  );
}

function DialogueTerminer({ campagne, onClose }: { campagne: Campagne; onClose: () => void }) {
  const { terminer } = useGestesCampagne();
  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Terminer définitivement ?"
      footer={
        <PiedDialogue annuler={onClose}>
          <Button variant="destructive" className={TACTILE} loading={terminer.isPending} onClick={() => terminer.mutate(String(campagne.id), { onSuccess: onClose })}>
            Terminer la campagne
          </Button>
        </PiedDialogue>
      }
    >
      <p className="text-sm text-slate-700">
        Plus aucun message ne partira pour <strong className="font-semibold text-slate-900">{campagne.nom}</strong>, relances comprises. Les réponses restent traitées.
      </p>
      <p className="mt-2 text-sm font-medium text-slate-900">
        On ne peut pas la relancer <span className="font-normal text-slate-600">(mais on peut la dupliquer).</span>
      </p>
      <Erreur erreur={terminer.error} />
    </Modal>
  );
}

type ChoixDate = 'demain' | 'lundi' | 'autre';

/** Au niveau du module : défini dans le rendu, il serait recréé à chaque changement d'état et le clavier perdrait le focus. */
function OptionDate({ id, choix, onChoisir, children }: { id: ChoixDate; choix: ChoixDate; onChoisir: (c: ChoixDate) => void; children: React.ReactNode }) {
  return (
    <Button variant={choix === id ? 'primary' : 'secondary'} className={TACTILE} aria-pressed={choix === id} onClick={() => onChoisir(id)}>
      {children}
    </Button>
  );
}

/** Au niveau du module, pour la même raison que `OptionDate`. */
function Case({ valeur, onChange, children }: { valeur: boolean; onChange: (v: boolean) => void; children: React.ReactNode }) {
  return (
    <label className="flex min-h-9 items-center gap-2 text-sm text-slate-700 max-md:min-h-11">
      <input type="checkbox" checked={valeur} onChange={(e) => onChange(e.target.checked)} className="h-4 w-4 rounded border-slate-300" />
      {children}
    </label>
  );
}

function DialogueDate({ campagne, maintenant, onClose }: { campagne: Campagne; maintenant: string; onClose: () => void }) {
  const { date } = useGestesCampagne();
  const aujourdhui = jourDe(maintenant);
  const demain = parisVersIso(ajouterJours(aujourdhui, 1), '09:00');
  const lundi = parisVersIso(prochainLundi(aujourdhui), '09:00');
  const [choix, setChoix] = useState<ChoixDate>('demain');
  const [jour, setJour] = useState(ajouterJours(aujourdhui, 2));
  const [heure, setHeure] = useState('09:00');
  // `le` est nul tant que le jour ou l'heure est vidé : on calcule `valide` d'abord, rien ne plante.
  const le = choix === 'demain' ? demain : choix === 'lundi' ? lundi : parisVersIso(jour, heure);
  const valide = le !== null && (choix !== 'autre' || new Date(le).getTime() > new Date(maintenant).getTime());

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Changer la date"
      description={`${campagne.nom} · liste et messages inchangés.`}
      footer={
        <PiedDialogue annuler={onClose}>
          <Button className={TACTILE} disabled={!valide} loading={date.isPending} onClick={() => le !== null && date.mutate({ id: String(campagne.id), le }, { onSuccess: onClose })}>
            Reprogrammer
          </Button>
        </PiedDialogue>
      }
    >
      <div className="flex flex-wrap gap-2">
        <OptionDate id="demain" choix={choix} onChoisir={setChoix}>
          Demain 9:00
        </OptionDate>
        <OptionDate id="lundi" choix={choix} onChoisir={setChoix}>
          Lundi 9:00
        </OptionDate>
        <OptionDate id="autre" choix={choix} onChoisir={setChoix}>
          Autre…
        </OptionDate>
      </div>
      {choix === 'autre' ? (
        <div className="mt-3 grid grid-cols-2 gap-3">
          <label className="text-sm font-medium text-slate-700">
            Jour
            <input type="date" value={jour} min={aujourdhui} onChange={(e) => setJour(e.target.value)} className="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm max-md:h-11" />
          </label>
          <label className="text-sm font-medium text-slate-700">
            Heure
            <input type="time" value={heure} onChange={(e) => setHeure(e.target.value)} className="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-2 text-sm max-md:h-11" />
          </label>
        </div>
      ) : le !== null ? (
        <p className="mt-3 text-sm text-slate-600">Prochain envoi : {dateRelative(le, maintenant)}.</p>
      ) : null}
      {choix === 'autre' && !valide ? (
        <p className="mt-2 text-xs text-slate-500">Choisissez un jour et une heure à venir.</p>
      ) : null}
      <Erreur erreur={date.error} />
    </Modal>
  );
}

function DialogueDupliquer({ campagne, onClose }: { campagne: Campagne; onClose: () => void }) {
  const { dupliquer } = useGestesCampagne();
  const [nom, setNom] = useState(`${campagne.nom} (copie)`);
  const [garderCible, setGarderCible] = useState(true);
  const [garderMessages, setGarderMessages] = useState(true);
  const [exclure, setExclure] = useState(false);

  return (
    <Modal
      open
      onClose={onClose}
      size="sm"
      title="Dupliquer la campagne"
      footer={
        <PiedDialogue annuler={onClose}>
          <Button
            className={TACTILE}
            disabled={nom.trim() === ''}
            loading={dupliquer.isPending}
            onClick={() =>
              dupliquer.mutate(
                { id: String(campagne.id), corps: { nom: nom.trim(), garder_cible: garderCible, garder_messages: garderMessages, exclure_deja_recus: exclure } },
                { onSuccess: onClose },
              )
            }
          >
            Créer la copie (brouillon)
          </Button>
        </PiedDialogue>
      }
    >
      <label className="block text-sm font-medium text-slate-700">
        Nom de la copie
        <input value={nom} onChange={(e) => setNom(e.target.value)} className="mt-1 h-9 w-full rounded-lg border border-slate-300 bg-white px-3 text-sm max-md:h-11" />
      </label>
      <div className="mt-3 space-y-1">
        <Case valeur={garderCible} onChange={setGarderCible}>
          Garder la même cible (recalculée)
        </Case>
        <Case valeur={garderMessages} onChange={setGarderMessages}>
          Garder les messages
        </Case>
        <Case valeur={exclure} onChange={setExclure}>
          Exclure ceux qui ont déjà reçu
        </Case>
      </div>
      <Erreur erreur={dupliquer.error} />
    </Modal>
  );
}
