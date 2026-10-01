/**
 * À QUI ÉCRIRE DANS CHAQUE ORGANISATION (2026-09-30) — le réglage d'une
 * audience et la forme de l'aperçu rendu par l'API
 * (`App\Crm\Campagnes\ResolveurDestinataires`). Rien n'est envoyé.
 */

export type ModeDestinataires = 'personne_sinon_generique' | 'generique' | 'nominatives' | 'les_deux';

export interface ReglageDestinataires {
  mode: ModeDestinataires;
  fonctions: string[];
  personnes_listees: boolean;
  avec_adresses_partagees: boolean;
}

export const REGLAGE_PAR_DEFAUT: ReglageDestinataires = {
  mode: 'personne_sinon_generique',
  fonctions: [],
  personnes_listees: false,
  avec_adresses_partagees: false,
};

export const MODES: Array<{ code: ModeDestinataires; libelle: string; aide: string }> = [
  {
    code: 'personne_sinon_generique',
    libelle: 'La personne nommée, sinon l’adresse générique',
    aide: 'Règle par défaut : on écrit aux personnes ; une organisation sans personne joignable reçoit sur son adresse générique.',
  },
  { code: 'generique', libelle: 'L’adresse générique seulement', aide: 'contact@, accueil@… et les canaux typés « générique ».' },
  { code: 'nominatives', libelle: 'Les personnes nommées seulement', aide: 'Les contacts et les canaux typés « nominatif ».' },
  { code: 'les_deux', libelle: 'Les deux', aide: 'Générique ET personnes — chaque adresse une seule fois.' },
];

/** Fonctions proposées (le filtre porte sur `contacts.role`, jamais sur le titre). */
export const FONCTIONS_PROPOSEES = [
  'Président',
  'Dirigeant',
  'Directeur général',
  'Délégué général',
  'Secrétaire général',
  'Gérant',
  'Chargé des événements',
];

export const MOTIFS_EXCLUSION: Record<string, string> = {
  invalide: 'Adresse invalide ou jetable',
  non_verifiee: 'Adresse non vérifiée',
  personnelle: 'Adresse personnelle (gmail…)',
  opposition: 'Ne plus écrire (opposition ou suppression)',
  adresse_partagee: 'Cabinet ou domiciliation partagée',
  // Audience presse : la provenance de l'adresse (`AdressePresseFiable`).
  site_devine: 'Adresse tirée d’un site deviné, non vérifié',
  journaliste_sans_acces: 'Journaliste sans accès « e-mail de rédaction »',
  journaliste_retire: 'Journaliste opposé ou retiré',
};

export const RAISONS_REGLAGE: Record<string, string> = {
  type_inconnu: 'Canal sans type connu',
  fonction_non_retenue: 'Fonction non retenue',
  personne_non_cochee: 'Personne non cochée dans la liste',
  generique_non_demandee: 'Générique non demandée',
  personnes_non_demandees: 'Personnes non demandées',
  generique_remplacee_par_une_personne: 'Générique remplacée par une personne',
};

export interface LigneDestinataire {
  email: string | null;
  type: 'generique' | 'nominative';
  crm_ref: string;
  fonction: string | null;
  nb_organisations: number;
  organisations: Array<{ id: number; nom: string }>;
}

export interface ApercuDestinataires {
  reglage: ReglageDestinataires;
  organisations: number;
  organisations_avec_destinataire: number;
  organisations_sans_destinataire: number;
  destinataires: number;
  par_type: { generique: number; nominative: number };
  adresses_partagees_entre_organisations: number;
  doublons_evites: number;
  exclues: Record<string, number>;
  exclues_total: number;
  ecartees_par_le_reglage: Record<string, number>;
  ecartees_total: number;
  lignes: LigneDestinataire[];
}

/** Le réglage, dans la forme qu'attend l'API (`destinataires_*`). */
export function reglageVersApi(r: ReglageDestinataires): Record<string, unknown> {
  return {
    destinataires_mode: r.mode,
    destinataires_fonctions: r.fonctions,
    destinataires_personnes_listees: r.personnes_listees,
    destinataires_avec_adresses_partagees: r.avec_adresses_partagees,
  };
}
