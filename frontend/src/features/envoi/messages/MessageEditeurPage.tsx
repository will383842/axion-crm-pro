/**
 * Éditeur d'un message type : objet (et version B), texte simple, blocs
 * réutilisables, aperçu réel, contrôleur de contenu, versions. Lot DM2-ASS.
 *
 * On n'écrase jamais : « Enregistrer la version » ajoute une version, les
 * anciennes restent. Une version doit être validée pour servir en campagne.
 */
import { useRef, useState } from 'react';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { Plus, Send, X } from 'lucide-react';
import { Button, PageHeader, QueryErrorState, Skeleton } from '@/components/ui';
import { NOM_DU_PRODUIT, useProduitCourant } from '@/features/socle/produit/useProduitCourant';
import { Bloc, CHAMP, Interrupteur } from '../creation/composants';
import { useGestesMessages, useMessagesTypes, useMessageType, type MessageTypeEtat } from '../creation/api';
import { ApercuMessage } from './composants/ApercuMessage';
import { BlocsReutilisables } from './composants/BlocsReutilisables';
import { ControlesMessage } from './composants/ControlesMessage';
import { StatutMessage } from './composants/StatutMessage';
import { TestMessage } from './composants/TestMessage';
import { VersionsMessage } from './composants/VersionsMessage';

const CORPS_NOUVEAU = 'Bonjour {prénom},\n\n\n\n{signature}';

interface Brouillon {
  nom: string;
  objet: string;
  /** `null` : pas de version B ; `''` : version B ajoutée, pas encore écrite. */
  objetB: string | null;
  corps: string;
  bloc: boolean;
}

function depuisMessage(m: MessageTypeEtat | null): Brouillon {
  if (m === null) return { nom: 'Nouveau message', objet: '', objetB: null, corps: CORPS_NOUVEAU, bloc: true };
  const v = m.versions.find((x) => x.numero === m.version_courante) ?? m.versions[m.versions.length - 1]!;
  return { nom: m.nom, objet: v.objet, objetB: v.objet_b ?? null, corps: v.corps, bloc: m.bloc_information };
}

function Editeur({
  message,
  occupe,
  onEnregistrer,
  onRestaurer,
  onValider,
  onArchiver,
  retourInfo,
}: {
  message: MessageTypeEtat | null;
  occupe: boolean;
  onEnregistrer: (b: Brouillon) => void;
  onRestaurer: (numero: number) => void;
  onValider: (numero: number) => void;
  onArchiver: (archive: boolean) => void;
  retourInfo: string | null;
}) {
  const [b, setB] = useState<Brouillon>(() => depuisMessage(message));
  const [testOuvert, setTestOuvert] = useState(false);
  const [infoTest, setInfoTest] = useState<string | null>(null);
  const zoneTexte = useRef<HTMLTextAreaElement>(null);
  const blocs = useMessagesTypes(false);
  const propre = depuisMessage(message);
  const modifie = message === null || JSON.stringify(b) !== JSON.stringify(propre);
  const maj = (patch: Partial<Brouillon>) => { setB((x) => ({ ...x, ...patch })); };

  const inserer = (texte: string) => {
    const z = zoneTexte.current;
    const debut = z?.selectionStart ?? b.corps.length;
    const fin = z?.selectionEnd ?? debut;
    maj({ corps: `${b.corps.slice(0, debut)}${texte}${b.corps.slice(fin)}` });
    z?.focus();
  };

  const saisie = { nom: b.nom, objet: b.objet, objet_b: b.objetB, corps: b.corps, bloc_information: b.bloc };
  const numeroSuivant = message ? message.version_courante + 1 : 1;

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-2" role="group" aria-label="Actions du message">
        <Button variant="secondary" iconLeft={<Send className="h-4 w-4" aria-hidden />} onClick={() => { setTestOuvert(true); }}>M’envoyer un test</Button>
        <Button variant="primary" loading={occupe} disabled={!modifie || b.objet.trim() === ''} onClick={() => { onEnregistrer(b); }}>
          Enregistrer la version
        </Button>
        {message ? (
          <Button variant="ghost" loading={occupe} onClick={() => { onArchiver(message.statut !== 'archive'); }}>
            {message.statut === 'archive' ? 'Désarchiver' : 'Archiver'}
          </Button>
        ) : null}
        {modifie && message ? <span className="text-xs text-slate-600">Version {numeroSuivant} à enregistrer (la v{message.version_courante} est gardée).</span> : null}
      </div>
      {retourInfo ? <p role="status" className="mb-3 rounded-lg bg-emerald-50 p-3 text-sm text-slate-900 ring-1 ring-emerald-200">{retourInfo}</p> : null}
      {infoTest ? <p role="status" className="mb-3 rounded-lg bg-sky-50 p-3 text-sm text-slate-900 ring-1 ring-sky-200">{infoTest}</p> : null}

      <div className="grid gap-4 lg:grid-cols-2">
        <div className="min-w-0 space-y-4">
          <Bloc titre={message ? `Message · v${message.version_courante}` : 'Nouveau message'} etiquette="Message">
            <div className="space-y-4">
              <div>
                <label htmlFor="msg-nom" className="mb-1 block text-xs font-medium text-slate-600">Nom du message type</label>
                <input id="msg-nom" type="text" className={CHAMP} value={b.nom} onChange={(e) => { maj({ nom: e.target.value }); }} />
              </div>
              <div>
                <label htmlFor="msg-objet" className="mb-1 block text-xs font-medium text-slate-600">{b.objetB === null ? 'Objet' : 'Objet · version A'}</label>
                <input id="msg-objet" type="text" className={CHAMP} value={b.objet} onChange={(e) => { maj({ objet: e.target.value }); }} />
              </div>
              {b.objetB === null ? (
                <Button variant="secondary" iconLeft={<Plus className="h-4 w-4" aria-hidden />} onClick={() => { maj({ objetB: '' }); }}>
                  Ajouter une version
                </Button>
              ) : (
                <div>
                  <label htmlFor="msg-objet-b" className="mb-1 block text-xs font-medium text-slate-600">Objet · version B</label>
                  <div className="flex gap-2">
                    <input id="msg-objet-b" type="text" className={CHAMP} value={b.objetB} onChange={(e) => { maj({ objetB: e.target.value }); }} />
                    <Button variant="ghost" aria-label="Retirer la version B" onClick={() => { maj({ objetB: null }); }}><X className="h-4 w-4" aria-hidden /></Button>
                  </div>
                </div>
              )}
              <div>
                <label htmlFor="msg-texte" className="mb-1 block text-xs font-medium text-slate-600">Texte</label>
                <textarea
                  id="msg-texte"
                  ref={zoneTexte}
                  rows={10}
                  aria-describedby="msg-texte-aide"
                  className="w-full rounded-lg bg-white px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-200 focus:outline-none focus:ring-2 focus:ring-produit"
                  value={b.corps}
                  onChange={(e) => { maj({ corps: e.target.value }); }}
                />
                <span id="msg-texte-aide" className="mt-1 block text-xs text-slate-600">Texte simple. Pas de pièce jointe en prospection : elle est refusée.</span>
              </div>
              <Interrupteur
                id="msg-bloc"
                libelle="Bloc d’information (art. 14) et lien de désinscription"
                aide="Obligatoire en prospection : il dit qui écrit, pourquoi, et comment ne plus recevoir de messages."
                coche={b.bloc}
                onChange={(c) => { maj({ bloc: c }); }}
              />
            </div>
          </Bloc>
          <BlocsReutilisables blocs={blocs.data?.blocs ?? []} onInserer={inserer} />
        </div>

        <div className="min-w-0 space-y-4">
          <ApercuMessage objet={b.objet} corps={b.corps} blocInformation={b.bloc} />
          <ControlesMessage saisie={saisie} />
          {message ? <VersionsMessage message={message} occupe={occupe} onRestaurer={onRestaurer} onValider={onValider} /> : null}
        </div>
      </div>

      <TestMessage ouvert={testOuvert} onFermer={() => { setTestOuvert(false); }} objet={b.objet} objetB={b.objetB} corps={b.corps} onEnvoye={setInfoTest} />
    </div>
  );
}

export function MessageEditeurPage() {
  const produit = useProduitCourant();
  const { messageId } = useParams({ strict: false });
  const navigate = useNavigate();
  const nouveau = messageId === undefined || messageId === 'nouveau';
  const lecture = useMessageType(nouveau ? null : (messageId ?? null));
  const gestes = useGestesMessages();
  const [info, setInfo] = useState<string | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const occupe = gestes.creer.isPending || gestes.enregistrer.isPending || gestes.restaurer.isPending || gestes.valider.isPending || gestes.archiver.isPending;
  const message = nouveau ? null : (lecture.data ?? null);

  const surErreur = (e: unknown) => {
    const corps = (e as { response?: { data?: { message?: string } } }).response?.data;
    setErreur(corps?.message ?? 'Le geste n’a pas pu être fait. Réessayez dans un instant.');
  };

  const enregistrer = (b: Brouillon) => {
    setErreur(null);
    const saisie = { nom: b.nom, objet: b.objet, objet_b: b.objetB !== null && b.objetB.trim() !== '' ? b.objetB : null, corps: b.corps, bloc_information: b.bloc };
    if (message === null) {
      gestes.creer.mutate(saisie, {
        onSuccess: (m) => {
          setInfo(`Version 1 enregistrée. Validez-la pour pouvoir l’utiliser dans une campagne.`);
          void navigate({ to: '/envoi/campagnes/messages/$messageId', params: { messageId: m.id }, replace: true });
        },
        onError: surErreur,
      });
      return;
    }
    gestes.enregistrer.mutate(
      { id: message.id, saisie },
      {
        onSuccess: (m) => { setInfo(`Version ${m.version_courante} enregistrée (la version ${m.version_courante - 1} est gardée).`); },
        onError: surErreur,
      },
    );
  };

  const entete = (
    <PageHeader
      title={message ? message.nom : nouveau ? 'Nouveau message' : 'Message type'}
      surTitre={NOM_DU_PRODUIT[produit]}
      badge={message ? <StatutMessage statut={message.statut} /> : undefined}
      subtitle={<>Objet, texte, aperçu réel, <strong className="font-semibold text-slate-700">contrôles au vert</strong>. <Link to="/envoi/campagnes/messages" className="text-produit underline">Tous les messages types</Link></>}
    />
  );

  if (!nouveau && lecture.isError) {
    return (
      <div>
        {entete}
        <QueryErrorState error={lecture.error} contexte="ce message type" onRetry={() => { void lecture.refetch(); }} />
      </div>
    );
  }
  if (!nouveau && lecture.isPending) {
    return (
      <div>
        {entete}
        <div role="status" aria-label="Chargement en cours" className="grid gap-4 lg:grid-cols-2">
          <Skeleton className="h-96 w-full" />
          <Skeleton className="h-96 w-full" />
        </div>
      </div>
    );
  }

  return (
    <div>
      {entete}
      {erreur ? <p role="alert" className="mb-3 rounded-lg bg-rose-50 p-3 text-sm text-rose-800 ring-1 ring-rose-200">{erreur}</p> : null}
      <Editeur
        key={`${message?.id ?? 'nouveau'}:${message?.version_courante ?? 0}:${message?.versions.length ?? 0}`}
        message={message}
        occupe={occupe}
        retourInfo={info}
        onEnregistrer={enregistrer}
        onRestaurer={(numero) => {
          setErreur(null);
          if (message) gestes.restaurer.mutate({ id: message.id, numero }, { onSuccess: (m) => { setInfo(`La version ${numero} est restaurée : c’est la version ${m.version_courante}.`); }, onError: surErreur });
        }}
        onValider={(numero) => {
          setErreur(null);
          if (message) gestes.valider.mutate({ id: message.id, numero }, { onSuccess: () => { setInfo(`La version ${numero} est validée : elle peut servir dans une campagne.`); }, onError: surErreur });
        }}
        onArchiver={(archive) => {
          setErreur(null);
          if (message) gestes.archiver.mutate({ id: message.id, archive }, { onSuccess: () => { setInfo(archive ? 'Message archivé : il n’apparaît plus dans les choix, mais rien n’est perdu.' : 'Message désarchivé.'); }, onError: surErreur });
        }}
      />
    </div>
  );
}
