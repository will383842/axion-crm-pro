/**
 * Jeu factice de la section « À traiter » du CRM (DM1-ATR) : paires de doublons,
 * personnes à rattacher, propositions de partenaires, tâches en retard.
 *
 * Zone `a-traiter` de `demoDb` (`demoDb.etendre('a-traiter', genererATraiter)`),
 * à graine fixe : mêmes données à chaque chargement. Rien ne se supprime : un
 * geste change le `statut` d'une ligne (fusionnée, écartée, rattachée…).
 *
 * Chiffres des maquettes : 12 doublons (dont 3 certains), 7 personnes à
 * rattacher, 4 propositions, 3 tâches en retard.
 */
import { demoDb } from '../../base';
import type { Alea } from '../../graine';
import { decalage, ilYa } from '../../horloge';
import { sirenInvalide } from '../outils-donnees';

export type MotifDoublon = 'siren' | 'nom_cp' | 'site';

export const LIBELLES_MOTIFS: Record<MotifDoublon, string> = {
  siren: 'Même SIREN',
  nom_cp: 'Même nom + code postal',
  site: 'Même site',
};

export interface FicheDoublonDemo {
  id: number;
  denomination: string | null;
  siren: string | null;
  identifiant: string | null;
  code_postal: string | null;
  ville: string | null;
  site: string | null;
  source: string | null;
  nb_contacts: number;
  protegee: boolean;
}

export interface PaireDemo {
  id: number;
  motif: MotifDoublon;
  score: number;
  fusion_auto: boolean;
  garde: FicheDoublonDemo;
  absorbee: FicheDoublonDemo;
  statut: 'en_attente' | 'fusionnee' | 'ecartee';
}

export interface FusionDemo {
  id: number;
  paire_id: number;
  annulee: boolean;
  faite_le: string;
}

export interface PendingMatchDemo {
  denomination: string;
  postcode: string;
  city: string;
  first_name: string;
  last_name: string;
  email: string;
}

export interface ArbitrageDemo {
  /** Identifiant de la ligne = `activity_id` de l'API. */
  id: number;
  kind: string;
  title: string;
  occurred_at: string;
  external_ref: string | null;
  person_key: string | null;
  pending_match: PendingMatchDemo;
  statut: 'en_attente' | 'rattache' | 'ecarte';
  motif: string | null;
}

export type OrigineDemo = 'apporteur' | 'commercial' | 'societe' | 'site-verifie' | 'annuaire-service-public';

export interface PropositionDemo {
  id: number;
  entite: 'entreprise' | 'personne';
  entite_id: number;
  champ: 'city' | 'phone' | 'website' | 'role';
  libelle_champ: string;
  valeur_proposee: string;
  origine: OrigineDemo;
  recue_le: string;
  champ_declare: boolean;
  statut: 'en_attente' | 'acceptee' | 'refusee';
}

export interface TacheDemo {
  id: number;
  titre: string;
  echeance: string;
  fait: boolean;
}

export interface ATraiterDemo {
  paires: PaireDemo[];
  fusions: FusionDemo[];
  arbitrages: ArbitrageDemo[];
  propositions: PropositionDemo[];
  taches: TacheDemo[];
}

/** [motif, nom gardé, nom absorbé, code postal, ville, site, source gardée, source absorbée, personnes gardée, absorbée, protégée] */
type Modele = [MotifDoublon, string, string, string, string, string | null, string, string, number, number, boolean];

const MODELES: ReadonlyArray<Modele> = [
  ['siren', 'Boulangerie des Tilleuls', 'Boulangerie Tilleuls SARL', '69003', 'Lyon', null, 'Sirene', 'collecte', 2, 0, false],
  ['siren', 'Atelier Perrin Menuiserie', 'Perrin Menuiserie', '69100', 'Villeurbanne', null, 'Sirene', 'site', 1, 1, false],
  ['siren', 'Transports Valette', 'Valette Transports SAS', '42000', 'Saint-Étienne', null, 'Sirene', 'collecte', 3, 0, true],
  ['nom_cp', 'Garage des Saules', 'Garage des Saules', '38200', 'Vienne', null, 'Sirene', 'site', 3, 1, true],
  ['nom_cp', 'Pâtisserie du Marché', 'Patisserie du Marche', '69400', 'Villefranche-sur-Saône', null, 'Sirene', 'collecte', 1, 0, false],
  ['nom_cp', 'Maison Lambert Traiteur', 'Lambert Traiteur', '69700', 'Givors', null, 'Sirene', 'événements', 2, 1, false],
  ['nom_cp', 'Cave du Rhône', 'La Cave du Rhône', '69003', 'Lyon', null, 'Sirene', 'collecte', 1, 1, false],
  ['nom_cp', 'Électricité Roche et fils', 'Roche et fils Électricité', '42300', 'Roanne', null, 'Sirene', 'site', 2, 0, false],
  ['nom_cp', 'Fleurs de Tarare', 'Fleuriste de Tarare', '69170', 'Tarare', null, 'Sirene', 'collecte', 0, 1, false],
  ['site', 'Imprimerie du Lac', 'Imprim’Lac', '74000', 'Annecy', 'imprimerie-lac.example', 'Sirene', 'collecte', 2, 0, false],
  ['site', 'Cycles de la Vallée', 'Vallée Cycles', '73000', 'Chambéry', 'cycles-vallee.example', 'Sirene', 'site', 1, 1, false],
  ['site', 'Bois et Forme', 'Bois Forme Agencement', '26000', 'Valence', 'bois-forme.example', 'Sirene', 'collecte', 4, 0, false],
];

const PRENOMS_ARBITRAGE: ReadonlyArray<[string, string, string]> = [
  ['Camille', 'Garnier', 'Imprimerie du Lac'],
  ['Mehdi', 'Benali', 'Cycles de la Vallée'],
  ['Sandrine', 'Colin', 'Fleurs de Tarare'],
  ['Antoine', 'Reynaud', 'Cave du Rhône'],
  ['Élodie', 'Marchand', 'Pâtisserie du Marché'],
  ['Karim', 'Fontaine', 'Transports Valette'],
  ['Valérie', 'Dubois', 'Garage des Saules'],
];

const DOMAINES = ['imprimerie-lac', 'cycles-vallee', 'fleurs-tarare', 'cave-rhone', 'patisserie-marche', 'transports-valette', 'garage-saules'];

const SOURCES_ARBITRAGE: ReadonlyArray<[string, string]> = [
  ['form.contact', 'Formulaire de contact du site'],
  ['form.newsletter', 'Inscription à la lettre d’information'],
  ['form.guide', 'Demande du guide'],
];

export function genererATraiter(alea: Alea): ATraiterDemo {
  const entreprises = demoDb.entreprises.tous();
  const idEntreprise = (rang: number) => entreprises[(rang * 7) % entreprises.length]!.id;

  const paires: PaireDemo[] = MODELES.map((m, i) => {
    const [motif, gardee, absorbee, cp, ville, site, srcG, srcA, nbG, nbA, protegee] = m;
    const siren = motif === 'siren' ? sirenInvalide(alea) : null;
    const sirenGarde = siren ?? sirenInvalide(alea);
    return {
      id: i + 1,
      motif,
      score: motif === 'siren' ? 1 : motif === 'site' ? 0.92 : 0.85,
      fusion_auto: motif === 'siren',
      garde: {
        id: idEntreprise(i),
        denomination: gardee,
        siren: sirenGarde,
        identifiant: null,
        code_postal: cp,
        ville,
        site,
        source: srcG,
        nb_contacts: nbG,
        protegee,
      },
      absorbee: {
        id: idEntreprise(i + 40),
        denomination: absorbee,
        siren: siren,
        identifiant: siren === null ? String(88000 + (i + 1) * 17) : null,
        code_postal: motif === 'site' && i % 2 === 0 ? null : cp,
        ville: motif === 'site' && i % 2 === 0 ? null : ville,
        site,
        source: srcA,
        nb_contacts: nbA,
        protegee: false,
      },
      statut: 'en_attente',
    };
  });

  const arbitrages: ArbitrageDemo[] = PRENOMS_ARBITRAGE.map(([prenom, nom, entreprise], i) => {
    const [kind, title] = SOURCES_ARBITRAGE[i % SOURCES_ARBITRAGE.length]!;
    const ficheVille = MODELES.find((m) => m[1] === entreprise)!;
    return {
      id: 41 + i,
      kind,
      title,
      occurred_at: ilYa(i % 5, 9 + (i % 4), 10 + i * 3),
      external_ref: `site-${1000 + i * 13}`,
      person_key: null,
      pending_match: {
        denomination: entreprise,
        postcode: ficheVille[3],
        city: ficheVille[4],
        first_name: prenom,
        last_name: nom,
        email: `${prenom.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()}.${nom.toLowerCase()}@${DOMAINES[i]}.example`,
      },
      statut: 'en_attente',
      motif: null,
    };
  });

  // Les propositions visent des fiches qui EXISTENT dans la base de démo.
  const tilleuls = entreprises.find((e) => e.denomination === 'Boulangerie des Tilleuls') ?? entreprises[0]!;
  const juliette = demoDb.contacts.tous().find((c) => c.first_name === 'Julie' && c.last_name === 'Morin');
  const propositions: PropositionDemo[] = [
    { id: 1, entite: 'entreprise', entite_id: tilleuls.id, champ: 'city', libelle_champ: 'Ville', valeur_proposee: 'Villeurbanne', origine: 'apporteur', recue_le: ilYa(1, 16, 20), champ_declare: false, statut: 'en_attente' },
    { id: 2, entite: 'entreprise', entite_id: idEntreprise(5), champ: 'phone', libelle_champ: 'Téléphone', valeur_proposee: '04 65 71 40 12', origine: 'commercial', recue_le: ilYa(2, 11, 5), champ_declare: false, statut: 'en_attente' },
    { id: 3, entite: 'entreprise', entite_id: idEntreprise(9), champ: 'website', libelle_champ: 'Site internet', valeur_proposee: 'www.maison-demo-annuaire.example', origine: 'annuaire-service-public', recue_le: ilYa(3, 10, 45), champ_declare: false, statut: 'en_attente' },
    { id: 4, entite: 'personne', entite_id: juliette?.id ?? 2, champ: 'role', libelle_champ: 'Fonction', valeur_proposee: 'Directrice commerciale', origine: 'societe', recue_le: ilYa(4, 14, 0), champ_declare: true, statut: 'en_attente' },
  ];

  const taches: TacheDemo[] = [
    { id: 1, titre: 'Rappeler Paul Morin', echeance: decalage({ jours: -3 }), fait: false },
    { id: 2, titre: 'Envoyer le devis à Bois et Forme', echeance: decalage({ jours: -2 }), fait: false },
    { id: 3, titre: 'Relire la liste du Salon des artisans de Vienne', echeance: decalage({ jours: -1 }), fait: false },
    { id: 4, titre: 'Préparer la lettre de février', echeance: decalage({ jours: 4 }), fait: false },
    { id: 5, titre: 'Vérifier les fédérations du Rhône', echeance: decalage({ jours: 9 }), fait: false },
    { id: 6, titre: 'Mettre à jour les étiquettes du salon', echeance: decalage({ jours: -6 }), fait: true },
  ];

  return { paires, fusions: [], arbitrages, propositions, taches };
}
