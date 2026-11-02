/**
 * Scénarios rejoués : la démo « vit » pendant une présentation.
 *
 *  · 30 s après l'ouverture, une réponse intéressée arrive (Envoi) ;
 *  · 75 s après, le domaine `prospection-c.example` passe à l'ambre.
 *
 * Chaque scénario ne joue qu'UNE fois par session (même après rechargement),
 * et ses effets passent par `demoDb` : « Remettre la démo à zéro » les efface.
 * `?demo-scenarios=non` les coupe (utilisé par les e2e, pour que rien ne bouge
 * pendant un test).
 */
import { demoDb } from './base';
import { maintenant } from './horloge';

export interface Scenario {
  id: string;
  apresMs: number;
  jouer: () => void;
}

const CLE_ACTIFS = 'axion-demo:scenarios';
const CLE_JOUES = 'axion-demo:scenarios-joues';

export const SCENARIOS: readonly Scenario[] = [
  {
    id: 'reponse-interessee',
    apresMs: 30_000,
    jouer: () => {
      const instant = maintenant().toISOString();
      const campagne = demoDb.campagnes.trouver('cmp-01');
      demoDb.reponses.ajouter({
        id: 'rep-scenario-1',
        recue_le: instant,
        campagne_id: 'cmp-01',
        destinataire_id: null,
        boite_id: 'boi-claire',
        de: 'nadia.haddad@imprimerie-lac.example',
        prenom: 'Nadia',
        nom: 'Haddad',
        organisation: 'Imprimerie du Lac',
        objet: 'RE: Une idée pour votre prochain salon',
        extrait: 'Bonjour, votre proposition tombe bien. Pouvons-nous en parler demain ?',
        classement: 'interesse',
        suggestion: { classement: 'interesse', confiance: 94 },
        traitee: false,
      });
      if (campagne) {
        demoDb.campagnes.modifier('cmp-01', { reponses: campagne.reponses + 1, reponses_interessees: campagne.reponses_interessees + 1 });
      }
      demoDb.notifications.ajouter({
        id: 'ntf-scenario-1', type: 'demo.a_faire', title: 'Nouvelle réponse intéressée', body: 'Nadia Haddad (Imprimerie du Lac).',
        action_url: '/envoi/reponses', lien: '/envoi/reponses', produit: 'envoi', niveau: 'a_faire', read_at: null, created_at: instant,
      });
    },
  },
  {
    id: 'domaine-ambre',
    apresMs: 75_000,
    jouer: () => {
      const instant = maintenant().toISOString();
      demoDb.domaines.modifier('dom-c', { etat: 'ambre' });
      demoDb.notifications.ajouter({
        id: 'ntf-scenario-2', type: 'demo.attention', title: 'Domaine « prospection-c.example » à surveiller', body: 'Refus temporaires en hausse.',
        action_url: '/envoi/domaines/dom-c', lien: '/envoi/domaines/dom-c', produit: 'envoi', niveau: 'attention', read_at: null, created_at: instant,
      });
    },
  },
];

function lire(cle: string): string | null {
  try {
    return globalThis.sessionStorage?.getItem(cle) ?? null;
  } catch {
    return null;
  }
}

function ecrire(cle: string, valeur: string | null): void {
  try {
    if (valeur === null) globalThis.sessionStorage?.removeItem(cle);
    else globalThis.sessionStorage?.setItem(cle, valeur);
  } catch {
    // stockage indisponible : sans conséquence.
  }
}

export function definirScenariosActifs(actifs: boolean): void {
  ecrire(CLE_ACTIFS, actifs ? null : 'non');
}

export function scenariosActifs(): boolean {
  return lire(CLE_ACTIFS) !== 'non';
}

function joues(): string[] {
  return (lire(CLE_JOUES) ?? '').split(',').filter((s) => s !== '');
}

/** Oublie les scénarios déjà joués (« Remettre la démo à zéro »). */
export function oublierScenarios(): void {
  ecrire(CLE_JOUES, null);
}

/**
 * Lance les scénarios pas encore joués ; `surChangement` est appelé après
 * chacun (l'application rafraîchit alors ses données). Rend une fonction
 * d'arrêt.
 */
export function demarrerScenarios(surChangement: () => void, liste: readonly Scenario[] = SCENARIOS): () => void {
  if (!scenariosActifs()) return () => {};
  const minuteurs = liste
    .filter((s) => !joues().includes(s.id))
    .map((s) =>
      setTimeout(() => {
        if (joues().includes(s.id)) return;
        s.jouer();
        ecrire(CLE_JOUES, [...joues(), s.id].join(','));
        surChangement();
      }, s.apresMs),
    );
  return () => minuteurs.forEach((m) => clearTimeout(m));
}
