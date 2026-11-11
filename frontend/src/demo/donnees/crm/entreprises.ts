/**
 * Données de démo de la zone « entreprises » (DM1-ENT) : ce qui s'ajoute à
 * `demoDb.entreprises` pour la liste et la fiche — étape commerciale,
 * responsable, affaire, historique, liens entre entreprises, résumé des envois
 * venus d'Envoi, tâches et vues sauvegardées.
 *
 * Données factices à graine fixe : mêmes valeurs à chaque chargement. Les
 * ouvertures d'e-mails ne sont jamais mesurées (rien ici n'en parle).
 */
import { demoDb } from '../../base';
import { NOM_HOLDING } from '../crm';
import { ilYa } from '../../horloge';
import type { Alea } from '../../graine';

export type TypeRelation = 'prospect' | 'client' | 'presse_media' | 'partenaire' | 'investisseur' | 'conference' | 'newsletter' | 'fournisseur';
export type EtapeCommerciale = 'nouveau' | 'qualifie' | 'opportunite' | 'client' | 'dormant' | 'perdu';

export interface LienEntreprise {
  company_id: number;
  denomination: string;
  lien: 'Société mère' | 'Filiale' | 'Même groupe';
}

export interface EnvoiRecu {
  campagne: string;
  message: string;
  statut: string;
  le: string;
  reponse: string | null;
}

export interface EvenementHistorique {
  le: string;
  quoi: string;
}

export interface FicheEntreprise {
  /** Identifiant de l'entreprise (même que `demoDb.entreprises`). */
  id: number;
  relation_type: TypeRelation;
  lifecycle_stage: EtapeCommerciale;
  relation_saisie_manuelle_at: string | null;
  joignabilite: string;
  responsable: string;
  affaire: { titre: string; montant: string; etape: string } | null;
  liens: LienEntreprise[];
  historique: EvenementHistorique[];
  envois: EnvoiRecu[];
  legal_form: string;
  address: string;
}

export interface TacheEntreprise {
  id: number;
  company_id: number;
  title: string;
  content: string | null;
  due_at: string | null;
  done_at: string | null;
  done: boolean;
}

export interface VueSauvegardee {
  id: number;
  entity: 'companies';
  name: string;
  filters: Record<string, string>;
  is_default: boolean;
  created_at: string;
  updated_at: string;
}

export interface ZoneEntreprises {
  fiches: FicheEntreprise[];
  taches: TacheEntreprise[];
  vues: VueSauvegardee[];
}

const RESPONSABLES = ['Alix Marchand', 'Camille Roux', 'Nadia Perrin'] as const;
const ETAPES: readonly EtapeCommerciale[] = ['nouveau', 'nouveau', 'qualifie', 'qualifie', 'opportunite', 'client', 'dormant', 'perdu'];
const FORMES = ['5499', '5710', '5498', '9220'] as const;

function fabriquer(alea: Alea): ZoneEntreprises {
  const entreprises = demoDb.entreprises.tous();
  const fiches: FicheEntreprise[] = entreprises.map((e, i) => {
    const repere = i === 0;
    const etape = repere ? 'opportunite' : alea.choisir(ETAPES);
    const liens: LienEntreprise[] = [];
    // Une entreprise sur douze appartient à un groupe d'une autre fiche.
    if (!repere && e.denomination !== NOM_HOLDING && i % 12 === 5) {
      const autre = entreprises[(i + 7) % entreprises.length]!;
      liens.push({ company_id: autre.id, denomination: autre.denomination, lien: alea.choisir(['Société mère', 'Filiale', 'Même groupe'] as const) });
    }
    // Le lien du repère mène à la vraie fiche de la holding (même nom), qui porte le lien retour « Filiale ».
    const holding = entreprises.find((x) => x.denomination === NOM_HOLDING);
    if (repere && holding) liens.push({ company_id: holding.id, denomination: holding.denomination, lien: 'Société mère' });
    if (e === holding && entreprises[0]) liens.push({ company_id: entreprises[0].id, denomination: entreprises[0].denomination, lien: 'Filiale' });
    return {
      id: e.id,
      relation_type: repere ? 'prospect' : alea.choisir(['prospect', 'prospect', 'prospect', 'client', 'partenaire'] as const),
      lifecycle_stage: etape,
      relation_saisie_manuelle_at: repere || alea.booleen(0.1) ? ilYa(alea.entier(3, 20), 10, 15) : null,
      joignabilite: e.quality_score >= 70 ? 'email_verifie' : e.quality_score >= 50 ? 'telephone_seul' : 'aucune',
      responsable: repere ? 'Alix Marchand' : alea.choisir(RESPONSABLES),
      affaire: repere || etape === 'opportunite' || etape === 'client'
        ? { titre: repere ? 'Devis site vitrine' : `Offre ${e.denomination}`, montant: repere ? '1 800 €' : `${alea.entier(6, 40) * 100} €`, etape: repere ? 'Devis envoyé' : 'En discussion' }
        : null,
      liens,
      historique: [
        { le: ilYa(alea.entier(1, 6), 9, 40), quoi: repere ? 'Rappel prévu pour le devis' : 'Fiche relue' },
        { le: ilYa(alea.entier(7, 16), 14, 5), quoi: repere ? 'Questionnaire reçu (2 questions sur 4)' : 'Enrichissement terminé' },
        { le: ilYa(alea.entier(17, 30), 11, 20), quoi: 'Fiche créée à partir de la collecte « Rhône »' },
      ],
      envois: repere
        ? [{ campagne: 'Organisateurs Rhône-Alpes', message: 'Message 1', statut: 'Accepté par le serveur', le: ilYa(5, 9, 12), reponse: null }]
        : i % 9 === 4
          ? [{ campagne: 'Organisateurs Rhône-Alpes', message: 'Message 1', statut: 'Accepté par le serveur', le: ilYa(alea.entier(2, 9), 10, 0), reponse: null }]
          : [],
      legal_form: alea.choisir(FORMES),
      address: `${alea.entier(2, 90)} rue ${repere ? 'des Tilleuls' : alea.choisir(['des Acacias', 'du Marché', 'de la Gare', 'des Tisserands'] as const)}`,
    };
  });

  const taches: TacheEntreprise[] = [
    { id: 1, company_id: 1, title: 'Rappeler pour le devis', content: null, due_at: '2027-01-20T09:00:00.000Z', done_at: null, done: false },
    { id: 2, company_id: 1, title: 'Envoyer la plaquette', content: null, due_at: null, done_at: ilYa(4, 15, 10), done: true },
  ];

  const vues: VueSauvegardee[] = [
    { id: 1, entity: 'companies', name: 'TPE du Rhône', filters: { department_code: '69', size: 'tpe' }, is_default: false, created_at: ilYa(12), updated_at: ilYa(12) },
    { id: 2, entity: 'companies', name: 'Prioritaires à enrichir', filters: { priority: 'haute' }, is_default: false, created_at: ilYa(8), updated_at: ilYa(8) },
  ];

  return { fiches, taches, vues };
}

/** La zone « entreprises » de la démo, créée à la première lecture. */
export function zoneEntreprises(): ZoneEntreprises {
  return demoDb.etendre('entreprises', fabriquer);
}
