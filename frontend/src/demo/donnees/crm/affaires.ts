/**
 * Données factices de l'accueil CRM : tâches (`activities` de type `task`),
 * affaires (étape de la fiche) et réglage du rappel du matin.
 *
 * Aujourd'hui = mardi 12 janvier 2027 (horloge de la démo). Tout est écrit en
 * dates relatives à ce jour : les groupes « en retard / aujourd'hui / cette
 * semaine » sont toujours pleins. Noms inventés, aucune personne réelle.
 */
import type { Affaire } from '@/features/crm/affaires/etapes';
import type { Tache } from '@/features/crm/taches/types';
import { demoDb } from '../../base';
import { ilYa, jourIso } from '../../horloge';

export interface DonneesAccueil {
  taches: Tache[];
  affaires: Affaire[];
  reglages: Array<{ id: string; actif: boolean }>;
}

type TacheSansId = Omit<Tache, 'id'>;

function tache(t: TacheSansId, n: number): Tache {
  return { id: `tache-${n}`, ...t };
}

export function fabriquerAccueil(trouverEntreprise: (nom: string) => number | null): DonneesAccueil {
  const base: TacheSansId[] = [
    // En retard : hier.
    { titre: 'Relancer la fédération du bâtiment', contexte: 'Fédération BTP Rhône', origine: 'reponse', genre: 'relance', echeance: jourIso(1), heure: null, faite: false, faite_le: null },
    // Aujourd'hui.
    { titre: 'Rappeler Paul Morin', contexte: 'Boulangerie des Tilleuls', origine: 'manuelle', genre: 'tache', echeance: jourIso(0), heure: '11:00', faite: false, faite_le: null },
    { titre: 'Envoyer le devis', contexte: 'Imprimerie du Lac', origine: 'manuelle', genre: 'tache', echeance: jourIso(0), heure: '14:00', faite: false, faite_le: null },
    { titre: 'Préparer le salon', contexte: 'Salon des artisans de Vienne', origine: 'manuelle', genre: 'tache', echeance: jourIso(0), heure: null, faite: false, faite_le: null },
    { titre: 'Vérifier la liste presse', contexte: 'Presse régionale', origine: 'manuelle', genre: 'tache', echeance: jourIso(0), heure: null, faite: false, faite_le: null },
    // Cette semaine (jusqu'au dimanche 17 janvier).
    { titre: 'Rendez-vous Club de voile', contexte: 'Club de voile du Lac', origine: 'manuelle', genre: 'rendez_vous', echeance: jourIso(-1), heure: '10:00', faite: false, faite_le: null },
    { titre: 'Relance événement CCI', contexte: 'Chambre de commerce', origine: 'manuelle', genre: 'relance', echeance: jourIso(-3), heure: null, faite: false, faite_le: null },
    { titre: 'Répondre à Le Courrier du Rhône', contexte: 'Le Courrier du Rhône', origine: 'reponse', genre: 'tache', echeance: jourIso(-4), heure: '09:00', faite: false, faite_le: null },
  ];
  const faites: TacheSansId[] = [
    ['Envoyer le dossier de présentation', 'Transports Rivière', 2],
    ['Appeler Léa Fournier', 'Office de tourisme du Val', 3],
    ['Mettre à jour la liste du salon', 'Salon des artisans de Vienne', 5],
    ['Confirmer le rendez-vous', 'Garage des Saules', 7],
  ].map(([titre, contexte, j]) => ({
    titre: String(titre),
    contexte: String(contexte),
    origine: 'manuelle' as const,
    genre: 'tache' as const,
    echeance: jourIso(Number(j)),
    heure: null,
    faite: true,
    faite_le: ilYa(Number(j), 16, 30),
  }));

  const affaire = (
    n: number,
    entreprise: string,
    etape: Affaire['etape'],
    montant: number | null,
    chance: number,
    signature_prevue: string | null,
    raison_perte: string | null = null,
  ): Affaire => ({ id: `affaire-${n}`, entreprise, entreprise_id: trouverEntreprise(entreprise), montant, etape, chance, signature_prevue, raison_perte });

  return {
    taches: [...base, ...faites].map((t, i) => tache(t, i + 1)),
    affaires: [
      affaire(1, 'Club de voile du Lac', 'a_contacter', null, 10, null),
      affaire(2, 'Office de tourisme du Val', 'contacte', 3400, 20, '2027-02-26'),
      affaire(3, 'Fédération BTP Rhône', 'repondu', 5200, 40, '2027-02-15'),
      affaire(4, 'Boulangerie des Tilleuls', 'repondu', 1200, 50, '2027-01-29'),
      affaire(5, 'Salon des artisans de Vienne', 'rendez_vous', 6500, 60, '2027-01-28'),
      affaire(6, 'Imprimerie du Lac', 'devis', 8900, 70, '2027-01-22'),
      affaire(7, 'Transports Rivière', 'devis', 12000, 55, '2027-01-31'),
      affaire(8, 'Garage des Saules', 'gagne', 2600, 100, '2027-01-05'),
      affaire(9, 'Le Courrier du Rhône', 'gagne', 1800, 100, '2027-01-08'),
      affaire(10, 'Atelier Bois & Forme', 'perdu', 4000, 0, null, 'Prix trop élevé'),
    ],
    reglages: [{ id: 'rappel-matin', actif: true }],
  };
}

/** Les collections de la zone « accueil » (créées au premier appel, partagées par les trois handlers). */
export function zoneAccueil() {
  demoDb.etendre('accueil', () =>
    fabriquerAccueil((nom) => demoDb.entreprises.tous().find((e) => e.denomination === nom)?.id ?? null),
  );
  return {
    taches: demoDb.zone<Tache>('accueil', 'taches'),
    affaires: demoDb.zone<Affaire>('accueil', 'affaires'),
    reglages: demoDb.zone<{ id: string; actif: boolean }>('accueil', 'reglages'),
  };
}
