/**
 * F3-B2 — « Ajouter une boîte » en deux temps : (1) « Recevoir un code » (courriel du propriétaire), (2) code + mot de passe.
 * Le mot de passe vit dans l'état LOCAL de l'étape 2 seulement : ni état global, ni cache de requêtes (appel direct, pas de
 * mutation mémorisée), ni stockage du navigateur, ni URL, ni console ; vidé après chaque envoi, réussi ou non ; jamais réaffiché.
 */
import { useState, type FormEvent } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { isAxiosError } from 'axios';
import { Button, Modal } from '@/components/ui';
import { api } from '@/lib/api';
import { BOUTON_TACTILE, ChampChoix, ChampTexte, MessageErreur, MessageSucces } from './Formulaire';

export const MESSAGE_FINAL = 'Boîte créée en pause : réactivez-la quand le domaine est prêt.';
const MESSAGE_503 = 'Le code ne peut pas partir pour le moment. Patientez quelques minutes avant de réessayer : cette demande compte dans la limite de 3 codes par 15 minutes.';
const MESSAGE_429 = 'Trois codes ont déjà été envoyés ces 15 dernières minutes : patientez avant d’en demander un autre.';
const MESSAGE_429_RENVOI = 'Patientez quelques minutes avant de demander un nouveau code : la limite de 3 codes par 15 minutes est atteinte.';
const MESSAGE_GENERIQUE = 'La boîte n’a pas pu être ajoutée. Rien n’a été créé. Réessayez dans un instant.';

/** Texte en clair d'une erreur de l'ajout ; le message du serveur n'est montré que pour 409 et 422 (jamais en 5xx). */
export function messageAjout(erreur: unknown): string {
  if (!isAxiosError(erreur)) return MESSAGE_GENERIQUE;
  const statut = erreur.response?.status;
  if (statut === 503) return MESSAGE_503;
  if (statut === 429) return MESSAGE_429;
  if (statut === 403) return 'Seul le propriétaire de l’espace peut ajouter une boîte.';
  const message = (erreur.response?.data as { message?: unknown } | undefined)?.message;
  if ((statut === 409 || statut === 422) && typeof message === 'string' && message !== '') return message;
  return MESSAGE_GENERIQUE;
}

interface Demande { adresse: string; hote: string; port: number; chiffrement: 'tls' | 'starttls'; utilisateur: string }

function Formulaire({ fermer }: { fermer: () => void }) {
  const client = useQueryClient();
  const [adresse, setAdresse] = useState('');
  const [hote, setHote] = useState('');
  const [port, setPort] = useState('587');
  const [chiffrement, setChiffrement] = useState<Demande['chiffrement']>('starttls');
  const [utilisateur, setUtilisateur] = useState('');
  const [code, setCode] = useState('');
  const [secret, setSecret] = useState('');
  const [etape, setEtape] = useState<1 | 2>(1);
  const [occupe, setOccupe] = useState(false);
  const [erreur, setErreur] = useState<string | null>(null);
  const [fini, setFini] = useState(false);
  const [renvoye, setRenvoye] = useState(false);

  const demande = (): Demande => ({ adresse: adresse.trim(), hote: hote.trim(), port: Number(port), chiffrement, utilisateur: utilisateur.trim() });
  const etape1Complete = adresse.trim() !== '' && hote.trim() !== '' && utilisateur.trim() !== '' && Number.isInteger(Number(port)) && Number(port) > 0;
  const codeValide = /^[0-9]{6}$/.test(code);

  const recevoirCode = async (e: FormEvent) => {
    e.preventDefault();
    if (occupe || !etape1Complete) return;
    setOccupe(true);
    setErreur(null);
    try {
      await api.post('/envoi/boites/ajout/code', demande());
      setEtape(2);
    } catch (err) {
      setErreur(messageAjout(err));
    } finally {
      setOccupe(false);
    }
  };

  /** UI-BOITE : même demande qu'à l'étape 1 (sans mot de passe ni code) ; le secret et l'ancien code sont vidés dans tous les cas. */
  const renvoyerCode = async () => {
    if (occupe || !etape1Complete) return;
    setOccupe(true);
    setErreur(null);
    setRenvoye(false);
    try {
      await api.post('/envoi/boites/ajout/code', demande());
      setRenvoye(true);
    } catch (err) {
      setErreur(isAxiosError(err) && err.response?.status === 429 ? MESSAGE_429_RENVOI : messageAjout(err));
    } finally {
      setSecret('');
      setCode('');
      setOccupe(false);
    }
  };

  const creer = async (e: FormEvent) => {
    e.preventDefault();
    if (occupe || !codeValide || secret === '') return;
    setOccupe(true);
    setErreur(null);
    try {
      await api.post('/envoi/boites', { ...demande(), secret, code });
      setFini(true);
      void client.invalidateQueries({ queryKey: ['envoi'] });
    } catch (err) {
      setErreur(messageAjout(err));
    } finally {
      setSecret('');
      setCode('');
      setOccupe(false);
    }
  };

  if (fini) {
    return (
      <div className="space-y-3">
        <MessageSucces>{MESSAGE_FINAL}</MessageSucces>
        <div className="flex justify-end"><Button variant="primary" className={BOUTON_TACTILE} onClick={fermer}>Fermer</Button></div>
      </div>
    );
  }

  return (
    <form className="space-y-3" onSubmit={(e) => { void (etape === 1 ? recevoirCode(e) : creer(e)); }} autoComplete="off">
      <ChampTexte label="Adresse de la boîte" type="email" value={adresse} onChange={(e) => setAdresse(e.target.value)} disabled={etape === 2} autoComplete="off" />
      <ChampTexte label="Serveur de courrier (hôte)" value={hote} onChange={(e) => setHote(e.target.value)} disabled={etape === 2} autoComplete="off" />
      <div className="grid grid-cols-2 gap-3">
        <ChampTexte label="Port" value={port} onChange={(e) => setPort(e.target.value)} disabled={etape === 2} inputMode="numeric" autoComplete="off" />
        <ChampChoix label="Chiffrement" value={chiffrement} onChange={(e) => setChiffrement(e.target.value as Demande['chiffrement'])} disabled={etape === 2}>
          <option value="starttls">STARTTLS</option>
          <option value="tls">TLS</option>
        </ChampChoix>
      </div>
      <ChampTexte label="Identifiant de connexion" value={utilisateur} onChange={(e) => setUtilisateur(e.target.value)} disabled={etape === 2} autoComplete="off" />
      {etape === 2 ? (
        <>
          <ChampTexte label="Code reçu par courriel" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} inputMode="numeric" autoComplete="one-time-code" aide="Valable 10 minutes, il ne sert qu’une fois." />
          <ChampTexte label="Mot de passe de la boîte" type="password" value={secret} onChange={(e) => setSecret(e.target.value)} autoComplete="new-password" aide="Saisi une seule fois : il n’est ni affiché ni gardé sur cet écran." />
        </>
      ) : null}
      {renvoye && !erreur ? <MessageSucces>Un nouveau code est parti. L’ancien ne sert plus.</MessageSucces> : null}
      {erreur ? <MessageErreur>{erreur}</MessageErreur> : null}
      <div className="flex justify-end gap-2">
        <Button type="button" variant="secondary" className={BOUTON_TACTILE} onClick={fermer}>Annuler</Button>
        {etape === 2 ? (
          <Button type="button" variant="secondary" className={BOUTON_TACTILE} disabled={occupe} onClick={() => { void renvoyerCode(); }}>Recevoir un nouveau code</Button>
        ) : null}
        {etape === 1 ? (
          <Button type="submit" variant="primary" className={BOUTON_TACTILE} loading={occupe} disabled={!etape1Complete}>Recevoir un code</Button>
        ) : (
          <Button type="submit" variant="primary" className={BOUTON_TACTILE} loading={occupe} disabled={!codeValide || secret === ''}>Créer la boîte</Button>
        )}
      </div>
    </form>
  );
}

export function AjoutBoite({ ouvert, fermer }: { ouvert: boolean; fermer: () => void }) {
  return (
    <Modal open={ouvert} onClose={fermer} title="Ajouter une boîte" description="Un code de confirmation part à votre adresse de connexion. La boîte naît en pause.">
      {ouvert ? <Formulaire fermer={fermer} /> : null}
    </Modal>
  );
}
