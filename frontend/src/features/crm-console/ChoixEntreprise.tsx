/**
 * CHOISIR UNE ENTREPRISE — lot 13 (audit UX du 02/10/2026, P1-8).
 *
 * Avant ce lot, rattacher une personne exigeait de TAPER l'identifiant interne
 * de l'entreprise (« ex. 1842 ») : il fallait ouvrir un autre onglet, trouver
 * la fiche, lire son numéro dans l'adresse. Ce sélecteur le remplace : on tape
 * un nom (éventuellement suivi de la ville ou du code postal), un SIREN ou un
 * SIRET, on choisit dans la liste, et le parent reçoit l'identifiant.
 *
 * ── Ce qui part au serveur, et quand ───────────────────────────────────────
 *  - après 300 ms de calme (`useAntiRebond`), jamais à chaque touche ;
 *  - au moins un mot significatif de 3 lettres (articles et formes juridiques
 *    comme « SARL » ne comptent pas — même règle que le serveur) ; un numéro
 *    (que des chiffres) ne part que COMPLET
 *    (9 chiffres pour un SIREN, 14 pour un SIRET) : une recherche partielle
 *    sur un numéro n'est servie par aucun index côté serveur ;
 *  - une requête périmée est annulée CÔTÉ NAVIGATEUR (React Query transmet un
 *    `signal` à axios) — le serveur, lui, va au bout, borné à 8 s — et son
 *    résultat n'est jamais affiché : seule compte la clé de la saisie en cours.
 *
 * ── Suggestions ────────────────────────────────────────────────────────────
 * Quand le parent connaît déjà un nom d'entreprise (et un code postal), on les
 * propose À L'OUVERTURE du champ vide — une seule requête, la même que celle
 * d'une recherche, et seulement si l'opérateur ouvre le champ : une file de
 * 50 cartes ne lance pas 50 recherches au chargement.
 *
 * ── Clavier (motif « combobox » ARIA 1.2) ──────────────────────────────────
 *  ↓ / ↑ : parcourir · Entrée : choisir · Échap : fermer la liste (puis, une
 *  seconde fois, effacer la saisie).
 */
import { useId, useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import { useQuery } from '@tanstack/react-query';
import { api, qualifierErreur } from '@/lib/api';
import { useAntiRebond } from '@/hooks/useAntiRebond';
import { cn } from '@/components/ui/cn';

export interface EntrepriseChoisie {
  id: number;
  denomination: string | null;
  siren: string | null;
  siret: string | null;
  code_postal: string | null;
  ville: string | null;
}

interface ReponseChoix {
  data: EntrepriseChoisie[];
  indice: 'trop_court' | 'mots_vides' | 'numero_incomplet' | null;
}

export interface SuggestionEntreprise {
  /** Le nom tel que la personne l'a donné (formulaire du site). */
  nom?: string | null | undefined;
  codePostal?: string | null | undefined;
}

interface ChoixEntrepriseProps {
  valeur: EntrepriseChoisie | null;
  onChange: (entreprise: EntrepriseChoisie | null) => void;
  suggestion?: SuggestionEntreprise | undefined;
  /** Message d'erreur à afficher sous le champ (ex. « Choisissez une entreprise dans la liste »). */
  erreur?: string | null | undefined;
  disabled?: boolean | undefined;
  className?: string | undefined;
}

/** Un numéro tapé avec ses espaces (« 552 100 554 ») reste un numéro. */
function seulementDesChiffres(saisie: string): string | null {
  const brut = saisie.replace(/[\s.-]/g, '');
  return brut !== '' && /^\d+$/.test(brut) ? brut : null;
}

/**
 * Mots ignorés par la recherche — JUMEAU de `ChoixEntrepriseController::MOTS_VIDES`
 * (backend), sous la même forme normalisée (minuscules, sans accent). L'écran
 * s'en sert pour ne pas envoyer une requête que le serveur refuserait ; le
 * serveur reste l'autorité.
 */
const MOTS_VIDES = new Set([
  'le', 'la', 'les', 'l', 'de', 'du', 'des', 'd', 'et', 'au', 'aux', 'en',
  'sur', 'sous', 'par', 'pour', 'chez', 'un', 'une', 'a', 'the', 'and',
  'sarl', 'sas', 'sasu', 'sa', 'eurl', 'sci', 'snc', 'scop', 'scp', 'scm',
  'sel', 'selarl', 'selas', 'ei', 'eirl', 'gie', 'gaec', 'earl', 'scea',
  'association', 'asso',
  'societe', 'ste', 'ets', 'etablissement', 'etablissements', 'cie',
  'compagnie', 'groupe', 'france', 'entreprise', 'entreprises',
]);

/** Le seuil, le même que le serveur : 3 lettres ou chiffres dans un mot significatif. */
const MOT_MINIMAL = 3;

export const MESSAGE_TROP_COURT = 'Tapez au moins 3 lettres du nom de l’entreprise.';
export const MESSAGE_MOTS_VIDES =
  'Ajoutez un mot du nom de l’entreprise : « SARL », « les » ou « société » seuls ne suffisent pas.';
export const MESSAGE_NUMERO = 'Un SIREN compte 9 chiffres, un SIRET 14 chiffres.';

function normaliserMot(mot: string): string {
  return mot.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
}

/**
 * La saisie mérite-t-elle une requête ? `null` = oui ; sinon, la phrase à
 * afficher à la place. Mêmes règles que le serveur : un code postal glissé
 * dans la saisie ne compte pas comme un mot du nom.
 */
function raisonDeNePasChercher(saisie: string): string | null {
  const texte = saisie.trim();
  if (texte === '') return null;
  const chiffres = seulementDesChiffres(texte);
  if (chiffres !== null) {
    return chiffres.length === 9 || chiffres.length === 14 ? null : MESSAGE_NUMERO;
  }
  const mots = texte
    .split(/[\s,;'’]+/)
    .filter((m) => m !== '' && !/^\d{5}$/.test(m))
    .map(normaliserMot);
  let motsVides = 0;
  for (const mot of mots) {
    if (MOTS_VIDES.has(mot)) {
      motsVides += 1;
      continue;
    }
    if ((mot.match(/[\p{L}\p{N}]/gu) ?? []).length >= MOT_MINIMAL) return null;
  }
  return motsVides > 0 ? MESSAGE_MOTS_VIDES : MESSAGE_TROP_COURT;
}

function formatSiren(siren: string | null): string | null {
  if (siren === null) return null;
  return siren.length === 9 ? `${siren.slice(0, 3)} ${siren.slice(3, 6)} ${siren.slice(6)}` : siren;
}

function lieu(e: EntrepriseChoisie): string | null {
  const morceaux = [e.code_postal, e.ville].filter((m): m is string => m !== null && m !== '');
  return morceaux.length > 0 ? morceaux.join(' ') : null;
}

function messageErreur(error: unknown): string {
  const { nature } = qualifierErreur(error);
  if (nature === 'trop_longue') {
    return 'La recherche est trop large : ajoutez un mot du nom, la ville ou le code postal.';
  }
  if (nature === 'refus') return 'Vous n’avez pas accès à la recherche d’entreprises.';
  if (nature === 'reseau') return 'Le serveur ne répond pas. Vérifiez votre connexion puis réessayez.';
  return 'La recherche n’a pas abouti. Réessayez dans un instant.';
}

async function chercher(q: string, codePostal: string | null, signal: AbortSignal): Promise<ReponseChoix> {
  const params = new URLSearchParams({ q });
  if (codePostal !== null && /^\d{5}$/.test(codePostal)) params.set('code_postal', codePostal);
  return (await api.get<ReponseChoix>(`/crm/entreprises/choix?${params.toString()}`, { signal })).data;
}

export function ChoixEntreprise({ valeur, onChange, suggestion, erreur, disabled, className }: ChoixEntrepriseProps) {
  const idBase = useId();
  const idChamp = `${idBase}-champ`;
  const idListe = `${idBase}-liste`;
  const idAide = `${idBase}-aide`;
  const idErreur = `${idBase}-erreur`;
  const refChamp = useRef<HTMLInputElement | null>(null);

  const [saisie, setSaisie] = useState(valeur?.denomination ?? '');
  const [ouvert, setOuvert] = useState(false);
  const [actif, setActif] = useState(-1);

  const saisieDifferee = useAntiRebond(saisie.trim());
  const empechement = raisonDeNePasChercher(saisieDifferee);
  const chercheTexte = ouvert && saisieDifferee !== '' && empechement === null;

  const recherche = useQuery<ReponseChoix>({
    queryKey: ['crm', 'choix-entreprise', saisieDifferee],
    queryFn: ({ signal }) => chercher(saisieDifferee, null, signal),
    enabled: chercheTexte,
    staleTime: 30_000,
    retry: false,
  });

  const nomSuggere = suggestion?.nom?.trim() ?? '';
  const cpSuggere = suggestion?.codePostal?.trim() ?? null;
  const montreSuggestions = ouvert && saisie.trim() === '' && nomSuggere !== '' && raisonDeNePasChercher(nomSuggere) === null;

  const suggestions = useQuery<ReponseChoix>({
    queryKey: ['crm', 'choix-entreprise', 'suggestions', nomSuggere, cpSuggere],
    queryFn: ({ signal }) => chercher(nomSuggere, cpSuggere, signal),
    enabled: montreSuggestions,
    staleTime: 60_000,
    retry: false,
  });

  // La saisie VISIBLE peut avoir bougé depuis la valeur différée : on n'affiche
  // jamais les résultats d'une saisie que l'opérateur a déjà modifiée.
  const enAttente = saisie.trim() !== saisieDifferee;
  const source = montreSuggestions ? suggestions : chercheTexte ? recherche : null;
  const options: EntrepriseChoisie[] =
    montreSuggestions || (!enAttente && chercheTexte) ? (source?.data?.data ?? []) : [];

  let etat: string | null = null;
  if (montreSuggestions) {
    // Une suggestion est un bonus : vide ou en échec, on se tait, et le champ
    // reste disponible pour une recherche tapée.
    if (suggestions.isFetching && suggestions.data === undefined) etat = 'Recherche des suggestions…';
  } else if (saisie.trim() !== '') {
    const empechementVisible = raisonDeNePasChercher(saisie);
    if (empechementVisible !== null) etat = empechementVisible;
    else if (enAttente || (recherche.isFetching && recherche.data === undefined)) etat = 'Recherche en cours…';
    else if (recherche.isError) etat = messageErreur(recherche.error);
    else if (recherche.data?.indice === 'trop_court') etat = MESSAGE_TROP_COURT;
    else if (recherche.data?.indice === 'mots_vides') etat = MESSAGE_MOTS_VIDES;
    else if (recherche.data?.indice === 'numero_incomplet') etat = MESSAGE_NUMERO;
    else if (recherche.data !== undefined && options.length === 0) {
      etat = 'Aucune entreprise trouvée. Essayez le SIREN, ou ajoutez la ville ou le code postal.';
    }
  }

  // La liste n'est « déployée » (au sens ARIA) que si elle porte des options ;
  // un état (chargement, aucun résultat, erreur) passe par la zone `status`.
  const listeVisible = ouvert && options.length > 0;
  const etatVisible = ouvert && options.length === 0 && etat !== null;
  const indexActif = actif >= 0 && actif < options.length ? actif : -1;

  const choisir = (entreprise: EntrepriseChoisie) => {
    onChange(entreprise);
    setSaisie(entreprise.denomination ?? formatSiren(entreprise.siren) ?? '');
    setOuvert(false);
    setActif(-1);
  };

  const surTouche = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'ArrowDown') {
      event.preventDefault();
      if (!ouvert) {
        setOuvert(true);
        return;
      }
      if (options.length > 0) setActif((i) => (i + 1 >= options.length ? 0 : i + 1));
    } else if (event.key === 'ArrowUp') {
      event.preventDefault();
      if (options.length > 0) setActif((i) => (i <= 0 ? options.length - 1 : i - 1));
    } else if (event.key === 'Enter') {
      const choix = indexActif >= 0 ? options[indexActif] : undefined;
      if (ouvert && choix !== undefined) {
        event.preventDefault();
        choisir(choix);
      }
    } else if (event.key === 'Escape') {
      if (ouvert) {
        event.preventDefault();
        setOuvert(false);
        setActif(-1);
      } else if (saisie !== '') {
        event.preventDefault();
        setSaisie('');
        if (valeur !== null) onChange(null);
      }
    }
  };

  const decrit = [erreur ? idErreur : null, valeur !== null ? idAide : null].filter(Boolean).join(' ') || undefined;

  return (
    <div className={cn('relative flex flex-col gap-1', className)}>
      <label htmlFor={idChamp} className="text-xs font-medium text-slate-600">
        Entreprise
      </label>
      <input
        ref={refChamp}
        id={idChamp}
        type="text"
        role="combobox"
        autoComplete="off"
        spellCheck={false}
        aria-autocomplete="list"
        aria-expanded={listeVisible}
        aria-controls={idListe}
        aria-activedescendant={indexActif >= 0 ? `${idListe}-${indexActif}` : undefined}
        aria-invalid={erreur ? true : undefined}
        aria-describedby={decrit}
        disabled={disabled}
        value={saisie}
        placeholder="Nom, ville, SIREN ou SIRET"
        onChange={(event) => {
          setSaisie(event.target.value);
          setOuvert(true);
          setActif(-1);
          // Modifier le texte après un choix l'annule : on ne garde jamais un
          // identifiant qui ne correspond plus à ce qui est affiché.
          if (valeur !== null) onChange(null);
        }}
        onFocus={() => setOuvert(true)}
        onClick={() => setOuvert(true)}
        onBlur={() => {
          // Laisse le clic sur une option se terminer (`onMouseDown` l'empêche
          // déjà de voler le focus) avant de fermer.
          setOuvert(false);
          setActif(-1);
        }}
        onKeyDown={surTouche}
        className={cn(
          'h-9 w-full rounded-lg bg-white px-3 text-sm text-slate-900 ring-1 transition placeholder:text-slate-500',
          'focus:outline-none focus:ring-2',
          erreur ? 'ring-rose-300 focus:ring-rose-400' : 'ring-slate-200 focus:ring-slate-300',
        )}
      />

      <div
        id={idListe}
        role="listbox"
        aria-label={montreSuggestions ? 'Suggestions d’entreprises' : 'Entreprises trouvées'}
        hidden={!listeVisible}
        className="absolute left-0 right-0 top-full z-20 mt-1 max-h-72 overflow-y-auto rounded-lg bg-white py-1 shadow-lg ring-1 ring-slate-200"
      >
        {montreSuggestions && options.length > 0 && (
          <div role="presentation" className="px-3 pb-1 pt-1 text-xs font-semibold text-slate-500">
            Suggestions
          </div>
        )}
        {options.map((entreprise, index) => {
          const siren = formatSiren(entreprise.siren);
          const ou = lieu(entreprise);
          return (
            <div
              key={entreprise.id}
              id={`${idListe}-${index}`}
              role="option"
              aria-selected={index === indexActif}
              onMouseDown={(event) => {
                // Sans cela, le champ perd le focus, la liste se ferme, et le
                // clic n'atteint jamais l'option.
                event.preventDefault();
                choisir(entreprise);
              }}
              onMouseEnter={() => setActif(index)}
              className={cn(
                'cursor-pointer px-3 py-2 text-sm',
                index === indexActif ? 'bg-brand-50 text-slate-900' : 'text-slate-800 hover:bg-slate-50',
              )}
            >
              <div className="font-medium">{entreprise.denomination ?? 'Entreprise sans nom'}</div>
              <div className="text-xs text-slate-600">
                {[ou, siren !== null ? `SIREN ${siren}` : null].filter(Boolean).join(' · ') || '—'}
              </div>
            </div>
          );
        })}
      </div>

      {/*
        UNE seule zone annoncée (toujours montée, sinon les lecteurs d'écran
        ne lisent pas ses changements) : l'état en clair sous le champ quand il
        n'y a rien à choisir, sinon le nombre d'entreprises proposées.
      */}
      <div
        role="status"
        aria-live="polite"
        className={
          etatVisible
            ? 'absolute left-0 right-0 top-full z-20 mt-1 rounded-lg bg-white px-3 py-2 text-xs text-slate-600 shadow-lg ring-1 ring-slate-200'
            : 'sr-only'
        }
      >
        {etatVisible ? etat : listeVisible ? `${options.length} entreprise(s) proposée(s).` : ''}
      </div>

      {valeur !== null && (
        <p id={idAide} className="text-xs text-slate-600">
          Choisie : {valeur.denomination ?? 'Entreprise sans nom'}
          {lieu(valeur) !== null && <> · {lieu(valeur)}</>}
          {valeur.siren !== null && <> · SIREN {formatSiren(valeur.siren)}</>}
        </p>
      )}
      {erreur && (
        <p id={idErreur} className="text-xs text-rose-700">
          {erreur}
        </p>
      )}
    </div>
  );
}
