/**
 * F-LN — importer une liste de refus (adresses ou domaines à ne plus écrire), en deux temps visibles :
 * simulation (compte rendu chiffré), puis confirmation. Réservé au propriétaire (`workspaces.manage`).
 *
 * Le fichier est lu par le navigateur (`File.text()`) et envoyé en TEXTE dans un corps JSON : jamais de `FormData`.
 * Aucune adresse du fichier n'est réaffichée : seuls des comptes et des numéros de ligne le sont.
 * Contrat : `POST /envoi/opposition/import` (`backend/openapi/envoi/conformite.yaml`). Un fichier est définitif : rien ne s'efface.
 */
import { useEffect, useId, useRef, useState } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Upload } from 'lucide-react';
import { api } from '@/lib/api';
import { Button, Modal } from '@/components/ui';
import { PERMISSION_GERER_ESPACE, permissionsDe } from '@/features/origines/droits';
import type { components } from '@/features/envoi/api/conformite';
import { Annonce, CLASSE_BOUTON_MOBILE, CLASSE_CHAMP, Etiquette, messageDErreur } from './commun';

type Reponse = components['schemas']['ImportOppositionsReponse'];

/** Limites du contrat : 1 048 576 octets (le serveur compte en UTF-8 et fait foi). */
export const OCTETS_MAX = 1_048_576;

function useProprietaire(): boolean {
  const { data } = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => (await api.get<{ roles?: string[]; permissions?: string[] }>('/auth/me')).data,
    retry: false,
    staleTime: 5 * 60 * 1000,
  });
  return permissionsDe(data).has(PERMISSION_GERER_ESPACE);
}

const pluriel = (n: number, un: string, plusieurs: string) => `${n} ${n > 1 ? plusieurs : un}`;

export function BoutonImporterListe() {
  const proprietaire = useProprietaire();
  const [ouvert, setOuvert] = useState(false);
  if (!proprietaire) return null;
  return (
    <>
      <Button variant="secondary" iconLeft={<Upload className="h-4 w-4" aria-hidden />} className={CLASSE_BOUTON_MOBILE} onClick={() => setOuvert(true)}>
        Importer une liste de refus
      </Button>
      {ouvert && <FenetreImport onClose={() => setOuvert(false)} />}
    </>
  );
}

function FenetreImport({ onClose }: { onClose: () => void }) {
  const client = useQueryClient();
  const idFichier = useId();
  const champ = useRef<HTMLInputElement>(null);
  const compteRendu = useRef<HTMLDivElement>(null);
  /** Numéro de la requête courante : toute réponse d'un numéro périmé est ignorée. */
  const courante = useRef(0);
  const [contenu, setContenu] = useState<string | null>(null);
  const [simulation, setSimulation] = useState<Reponse | null>(null);
  const [confirmation, setConfirmation] = useState<Reponse | null>(null);
  const [erreur, setErreur] = useState<string | null>(null);
  const [enCours, setEnCours] = useState(false);
  /** Vrai quand le focus doit revenir sur le champ fichier, dès qu'il est de nouveau actif. */
  const [focusChamp, setFocusChamp] = useState(false);

  // Les boutons « Simuler » puis « Confirmer » disparaissent en gardant le focus : on le replace sur le compte rendu.
  useEffect(() => {
    if (simulation !== null || confirmation !== null) compteRendu.current?.focus();
  }, [simulation, confirmation]);

  // Après un 409 le champ est encore désactivé au moment de l'erreur : on attend le rendu où il est actif.
  useEffect(() => {
    if (focusChamp && !enCours) {
      champ.current?.focus();
      setFocusChamp(false);
    }
  }, [focusChamp, enCours]);

  async function choisir(fichier: File | undefined) {
    const numero = ++courante.current;
    setErreur(null);
    setSimulation(null);
    setConfirmation(null);
    setContenu(null);
    setEnCours(false);
    if (fichier === undefined) return;
    // Refus AVANT toute lecture et tout envoi.
    if (fichier.size === 0) {
      setErreur('Le fichier est vide. Choisissez un fichier contenant une adresse ou un domaine par ligne.');
      return;
    }
    if (fichier.size > OCTETS_MAX) {
      setErreur('Fichier trop gros : 5 000 lignes et 1 Mo au plus. Découpez-le en plusieurs fichiers.');
      return;
    }
    try {
      const texte = await fichier.text();
      if (numero !== courante.current) return;
      if (texte.trim() === '') setErreur('Le fichier est vide. Choisissez un fichier contenant une adresse ou un domaine par ligne.');
      else setContenu(texte);
    } catch {
      if (numero === courante.current) setErreur('Le fichier n’a pas pu être lu. Choisissez-en un autre.');
    }
  }

  async function envoyer(etape: 'simulation' | 'confirmation') {
    if (contenu === null) return;
    const numero = ++courante.current;
    setErreur(null);
    setEnCours(true);
    try {
      const corps = etape === 'simulation' ? { etape, contenu } : { etape, contenu, jeton: simulation?.jeton };
      const { data } = await api.post<Reponse>('/envoi/opposition/import', corps);
      if (numero !== courante.current) return;
      if (etape === 'simulation') setSimulation(data);
      else {
        setConfirmation(data);
        setSimulation(null);
        await client.invalidateQueries({ queryKey: ['envoi', 'opposition'] });
      }
    } catch (e) {
      if (numero !== courante.current) return;
      setErreur(messageDErreur(e, 'L’import a échoué. Réessayez.'));
      if (etape === 'confirmation' && (e as { response?: { status?: number } } | null)?.response?.status === 409) {
        // Jeton refusé (409) : retour à l'étape de simulation, contenu vidé, le même fichier doit pouvoir être re-choisi.
        // Les autres erreurs gardent l'état (simulation et fichier) : on peut réessayer.
        setSimulation(null);
        setContenu(null);
        if (champ.current !== null) champ.current.value = '';
        setFocusChamp(true);
      }
    } finally {
      if (numero === courante.current) setEnCours(false);
    }
  }

  const compte = confirmation ?? simulation;
  return (
    <Modal
      open
      onClose={onClose}
      title="Importer une liste de refus"
      description="Un fichier d’une colonne : une adresse ou un domaine par ligne. Les lignes inscrites ne pourront jamais être retirées."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>{confirmation === null ? 'Annuler' : 'Fermer'}</Button>
          {confirmation === null && simulation === null && (
            <Button disabled={contenu === null || enCours} loading={enCours} onClick={() => void envoyer('simulation')}>Simuler l’import</Button>
          )}
          {simulation !== null && (
            <Button disabled={enCours || simulation.valides === 0} loading={enCours} onClick={() => void envoyer('confirmation')}>Confirmer l’import</Button>
          )}
        </>
      }
    >
      <div className="space-y-4">
        <div>
          <Etiquette htmlFor={idFichier}>Fichier de la liste (CSV, 1 Mo au plus)</Etiquette>
          <input ref={champ} id={idFichier} type="file" disabled={enCours} accept=".csv,.txt,text/csv,text/plain" className={CLASSE_CHAMP} onChange={(e) => void choisir(e.target.files?.[0])} />
        </div>
        <Annonce message={erreur} ton="erreur" />
        {compte !== null && (
          <div ref={compteRendu} tabIndex={-1} role="status" className="space-y-2 text-sm text-slate-700 focus:outline-none">
            <p className="font-medium text-slate-900">
              {confirmation === null ? 'Simulation : rien n’est encore inscrit.' : 'Import confirmé.'}
            </p>
            <ul className="list-disc pl-5">
              <li>{pluriel(compte.valides, 'adresse valide', 'adresses valides')}{confirmation === null ? ' à inscrire' : ' inscrites'}</li>
              <li>{pluriel(compte.deja_presentes, 'déjà présente', 'déjà présentes')}</li>
              <li>{pluriel(compte.refusees.length, 'refusée', 'refusées')}</li>
            </ul>
            {compte.refusees.length > 0 && (
              <ul className="space-y-1 rounded-lg bg-slate-50 p-3">
                {compte.refusees.map((r) => (
                  <li key={r.ligne}>Ligne {r.ligne} : {r.motif}</li>
                ))}
              </ul>
            )}
            {confirmation === null && compte.valides === 0 && <p>Rien à inscrire : corrigez le fichier puis relancez la simulation.</p>}
          </div>
        )}
      </div>
    </Modal>
  );
}
