/**
 * Jeu factice d'Envoi : 12 campagnes (tous statuts), 4 domaines `.example`,
 * 8 boîtes, 2 serveurs (adresses de documentation), destinataires et état
 * par destinataire, 30 jours d'événements d'envoi, 60 réponses, messages
 * types versionnés, règles d'alerte, désinscriptions, registre d'opposition.
 *
 * Décision de Will (01/11/2026) : les OUVERTURES sont mesurées quand la
 * campagne coche « Suivre les ouvertures ». Chaque ouverture est datée, par
 * destinataire, et marquée `automatique_probable` quand elle vient
 * vraisemblablement d'un relais (protection d'Apple, relais d'images,
 * antivirus, ouverture dans la seconde). Les campagnes qui ne cochent pas la
 * case n'ont aucune ouverture. Réponses et clics restent les indicateurs
 * principaux.
 */
import { graine, type Alea } from '../graine';
import { ilYa, MAINTENANT_ISO } from '../horloge';
import type {
  Boite,
  Campagne,
  Contact,
  Desinscription,
  Destinataire,
  Domaine,
  Entreprise,
  EtatEnvois,
  EvenementEnvoi,
  MessageType,
  Opposition,
  RegleAlerte,
  Reponse,
  Serveur,
} from './types';
import { arrondi1 } from './outils-donnees';

const MINUTE = 60_000;
const MAINTENANT_MS = Date.parse(MAINTENANT_ISO);

/** Codes serveur traduits en français (rebonds). */
export const CODES_SERVEUR: ReadonlyArray<{ code: string; libelle: string; dur: boolean }> = [
  { code: '550 5.1.1', libelle: 'Adresse inexistante', dur: true },
  { code: '554 5.7.1', libelle: 'Refusé par le filtre du destinataire', dur: true },
  { code: '553 5.1.3', libelle: 'Adresse mal formée', dur: true },
  { code: '452 4.2.2', libelle: 'Boîte du destinataire pleine', dur: false },
  { code: '421 4.7.0', libelle: 'Trop de messages d’un coup : nouvel essai plus tard', dur: false },
  { code: '451 4.7.1', libelle: 'Refus temporaire (liste grise) : nouvel essai prévu', dur: false },
  { code: '450 4.2.1', libelle: 'Boîte momentanément indisponible', dur: false },
];

// ── Domaines, boîtes, serveurs ────────────────────────────────────────────

export const DOMAINES_DEMO: Domaine[] = [
  { id: 'dom-a', nom: 'prospection-a.example', etat: 'vert', boites: 3, dmarc: 'quarantine', dmarc_prochaine_etape: { politique: 'reject', le: '2027-02-15T08:00:00.000Z' }, listes_noires: 0, ajoute_le: ilYa(40, 10, 0), chauffe: null },
  { id: 'dom-b', nom: 'prospection-b.example', etat: 'ambre', boites: 2, dmarc: 'none', dmarc_prochaine_etape: { politique: 'quarantine', le: '2027-01-26T08:00:00.000Z' }, listes_noires: 1, ajoute_le: ilYa(40, 10, 30), chauffe: null },
  { id: 'dom-c', nom: 'prospection-c.example', etat: 'bleu', boites: 2, dmarc: 'none', dmarc_prochaine_etape: { politique: 'quarantine', le: '2027-01-15T08:00:00.000Z' }, listes_noires: 0, ajoute_le: ilYa(6, 9, 0), chauffe: { jour: 6, sur: 30, plafond_aujourdhui: 10 } },
  { id: 'dom-d', nom: 'prospection-d.example', etat: 'gris', boites: 1, dmarc: 'none', dmarc_prochaine_etape: null, listes_noires: 0, ajoute_le: ilYa(1, 16, 0), chauffe: null },
];

export const BOITES_DEMO: Boite[] = [
  { id: 'boi-claire', adresse: 'claire@prospection-a.example', domaine_id: 'dom-a', nom_affiche: 'Claire · Axion (démo)', etat: 'vert', statut: 'prete', quota_jour: 40, envoyes_aujourdhui: 32, taux_refus: 0.6, reponses_7j: 4, chauffe: null },
  { id: 'boi-sophie', adresse: 'sophie@prospection-a.example', domaine_id: 'dom-a', nom_affiche: 'Sophie · Axion (démo)', etat: 'vert', statut: 'prete', quota_jour: 40, envoyes_aujourdhui: 28, taux_refus: 0.4, reponses_7j: 2, chauffe: null },
  { id: 'boi-lucie', adresse: 'lucie@prospection-a.example', domaine_id: 'dom-a', nom_affiche: 'Lucie · Axion (démo)', etat: 'vert', statut: 'prete', quota_jour: 40, envoyes_aujourdhui: 21, taux_refus: 0.8, reponses_7j: 3, chauffe: null },
  { id: 'boi-will', adresse: 'will@prospection-b.example', domaine_id: 'dom-b', nom_affiche: 'Will · Axion (démo)', etat: 'ambre', statut: 'ralentie', quota_jour: 20, envoyes_aujourdhui: 12, taux_refus: 3, reponses_7j: 1, chauffe: null },
  { id: 'boi-nina', adresse: 'nina@prospection-b.example', domaine_id: 'dom-b', nom_affiche: 'Nina · Axion (démo)', etat: 'vert', statut: 'prete', quota_jour: 30, envoyes_aujourdhui: 9, taux_refus: 1.1, reponses_7j: 1, chauffe: null },
  { id: 'boi-paul', adresse: 'paul@prospection-c.example', domaine_id: 'dom-c', nom_affiche: 'Paul · Axion (démo)', etat: 'bleu', statut: 'en_chauffe', quota_jour: 10, envoyes_aujourdhui: 8, taux_refus: 0, reponses_7j: 0, chauffe: { jour: 6, sur: 30, plafond_aujourdhui: 10 } },
  { id: 'boi-hugo', adresse: 'hugo@prospection-c.example', domaine_id: 'dom-c', nom_affiche: 'Hugo · Axion (démo)', etat: 'bleu', statut: 'en_chauffe', quota_jour: 10, envoyes_aujourdhui: 6, taux_refus: 0, reponses_7j: 0, chauffe: { jour: 6, sur: 30, plafond_aujourdhui: 10 } },
  { id: 'boi-marc', adresse: 'marc@prospection-d.example', domaine_id: 'dom-d', nom_affiche: 'Marc · Axion (démo)', etat: 'gris', statut: 'suspendue', quota_jour: 0, envoyes_aujourdhui: 0, taux_refus: 0, reponses_7j: 0, chauffe: null },
];

export const SERVEURS_DEMO: Serveur[] = [
  {
    id: 'srv-1',
    nom: 'Serveur d’envoi 1 (démo)',
    etat: 'vert',
    version_configuration: 14,
    adresses_ip: [
      { adresse: '192.0.2.10', flux: 'prospection', domaine_id: 'dom-a', statut: 'prete', envoyes_aujourdhui: 81 },
      { adresse: '192.0.2.11', flux: 'lettre', domaine_id: 'dom-c', statut: 'en_chauffe', envoyes_aujourdhui: 14 },
    ],
  },
  {
    id: 'srv-2',
    nom: 'Serveur d’envoi 2 (démo)',
    etat: 'ambre',
    version_configuration: 14,
    adresses_ip: [
      { adresse: '198.51.100.20', flux: 'prospection', domaine_id: 'dom-b', statut: 'ralentie', envoyes_aujourdhui: 21 },
      { adresse: '198.51.100.21', flux: 'prospection', domaine_id: 'dom-d', statut: 'suspendue', envoyes_aujourdhui: 0 },
    ],
  },
];

// ── Campagnes ─────────────────────────────────────────────────────────────

interface DefCampagne {
  nom: string;
  statut: Campagne['statut'];
  cible: Campagne['cible'];
  envoyes: number;
  etapes: number;
  ab: boolean;
  suivre: boolean;
  prochain: string | null;
  /** Jours écoulés depuis le premier envoi (0 si rien n'est parti). */
  depuis: number;
  /** Durée des envois, en jours. */
  duree: number;
  boites: string[];
  reponses: number;
  interessees: number;
}

const CAMPAGNES: ReadonlyArray<DefCampagne> = [
  { nom: 'Organisateurs Rhône-Alpes', statut: 'en_cours', cible: { type: 'audience', reference: '2', libelle: 'Audience · Organisateurs Rhône-Alpes', total: 290 }, envoyes: 168, etapes: 3, ab: true, suivre: true, prochain: ilYa(0, 14, 0), depuis: 9, duree: 9, boites: ['boi-claire', 'boi-will', 'boi-paul'], reponses: 9, interessees: 3 },
  { nom: 'Fédérations — début d’année', statut: 'programmee', cible: { type: 'etiquette', reference: 'salon-artisans-2026', libelle: 'Étiquette · salon-artisans-2026', total: 388 }, envoyes: 0, etapes: 2, ab: false, suivre: false, prochain: '2027-01-13T08:00:00.000Z', depuis: 0, duree: 0, boites: ['boi-claire', 'boi-sophie', 'boi-lucie'], reponses: 0, interessees: 0 },
  { nom: 'Presse régionale AURA', statut: 'a_valider', cible: { type: 'audience', reference: '3', libelle: 'Audience · Audience presse AURA', total: 186 }, envoyes: 0, etapes: 1, ab: false, suivre: false, prochain: null, depuis: 0, duree: 0, boites: ['boi-sophie'], reponses: 0, interessees: 0 },
  { nom: 'Commerces de Vienne', statut: 'en_pause', cible: { type: 'liste', reference: '1', libelle: 'Liste · Invités salon', total: 127 }, envoyes: 64, etapes: 2, ab: false, suivre: true, prochain: null, depuis: 12, duree: 6, boites: ['boi-lucie'], reponses: 4, interessees: 1 },
  { nom: 'Clubs d’entreprises Savoie', statut: 'terminee', cible: { type: 'audience', reference: '4', libelle: 'Audience · Clubs d’entreprises Savoie', total: 212 }, envoyes: 212, etapes: 3, ab: false, suivre: true, prochain: null, depuis: 28, duree: 16, boites: ['boi-claire', 'boi-sophie'], reponses: 17, interessees: 6 },
  { nom: 'Relance printemps', statut: 'brouillon', cible: { type: 'etiquette', reference: 'relance-printemps', libelle: 'Étiquette · relance-printemps', total: 96 }, envoyes: 0, etapes: 2, ab: false, suivre: false, prochain: null, depuis: 0, duree: 0, boites: [], reponses: 0, interessees: 0 },
  { nom: 'Artisans du Pilat', statut: 'terminee', cible: { type: 'audience', reference: '5', libelle: 'Audience · Artisans du Pilat', total: 140 }, envoyes: 140, etapes: 2, ab: false, suivre: false, prochain: null, depuis: 26, duree: 10, boites: ['boi-nina'], reponses: 8, interessees: 2 },
  { nom: 'Salon des artisans de Vienne — exposants', statut: 'en_cours', cible: { type: 'audience', reference: '6', libelle: 'Audience · Exposants potentiels du salon', total: 180 }, envoyes: 75, etapes: 3, ab: true, suivre: false, prochain: ilYa(0, 10, 30), depuis: 5, duree: 5, boites: ['boi-sophie', 'boi-hugo'], reponses: 5, interessees: 2 },
  { nom: 'Traiteurs et événements', statut: 'arretee', cible: { type: 'audience', reference: '7', libelle: 'Audience · Traiteurs et événements', total: 120 }, envoyes: 40, etapes: 2, ab: false, suivre: false, prochain: null, depuis: 15, duree: 3, boites: ['boi-will'], reponses: 1, interessees: 0 },
  { nom: 'Partenaires 2026 — vœux', statut: 'terminee', cible: { type: 'liste', reference: '3', libelle: 'Liste · Partenaires 2026', total: 96 }, envoyes: 96, etapes: 1, ab: false, suivre: true, prochain: null, depuis: 8, duree: 2, boites: ['boi-claire'], reponses: 11, interessees: 3 },
  { nom: 'Presse spécialisée artisanat', statut: 'programmee', cible: { type: 'liste', reference: '5', libelle: 'Liste · Presse spécialisée artisanat', total: 64 }, envoyes: 0, etapes: 1, ab: false, suivre: true, prochain: '2027-01-19T08:30:00.000Z', depuis: 0, duree: 0, boites: ['boi-nina'], reponses: 0, interessees: 0 },
  { nom: 'Nouveaux commerces de Lyon', statut: 'brouillon', cible: { type: 'audience', reference: '8', libelle: 'Audience · Nouveaux commerces de Lyon', total: 150 }, envoyes: 0, etapes: 1, ab: false, suivre: false, prochain: null, depuis: 0, duree: 0, boites: [], reponses: 0, interessees: 0 },
];

const idCampagne = (i: number) => `cmp-${String(i + 1).padStart(2, '0')}`;

// ── Réponses : textes par classement ──────────────────────────────────────

const TEXTES: Record<Reponse['classement'], readonly string[]> = {
  interesse: [
    'Bonjour, c’est intéressant. Pouvez-vous m’appeler cette semaine ?',
    'Envoyez une proposition.',
    'Oui, parlons-en. Quelles sont vos disponibilités ?',
    'Cela nous intéresse pour le prochain salon. Rappelez-moi.',
  ],
  pas_maintenant: ['Pas pour le moment, recontactez-moi au printemps.', 'Nous verrons après l’inventaire de février.'],
  question: ['Quels sont vos tarifs pour une petite entreprise ?', 'Est-ce que cela fonctionne avec notre logiciel de caisse ?'],
  refus: ['Merci, mais nous avons déjà un prestataire.', 'Ce n’est pas pour nous, merci.'],
  stop: ['Ne m’écrivez plus.', 'Merci de me retirer de vos envois.'],
  absence: ['Je suis absent jusqu’au 18 janvier. Pour toute urgence, contactez l’accueil.'],
  a_rattacher: ['Transmis à la direction, qui reviendra vers vous.'],
};

const OBJETS = ['Une idée pour votre prochain salon', 'Salon des artisans : on en parle ?'];

// ── Génération ────────────────────────────────────────────────────────────

export interface EnvoiGenere {
  campagnes: Campagne[];
  domaines: Domaine[];
  boites: Boite[];
  serveurs: Serveur[];
  destinataires: Destinataire[];
  evenementsEnvoi: EvenementEnvoi[];
  reponses: Reponse[];
  messagesTypes: MessageType[];
  reglesAlerte: RegleAlerte[];
  desinscriptions: Desinscription[];
  opposition: Opposition[];
  etatEnvois: EtatEnvois;
}

function instant(ms: number): string {
  return new Date(Math.min(ms, MAINTENANT_MS - MINUTE)).toISOString();
}

/** Un instant d'envoi plausible (jours ouvrés, 8 h – 18 h à Paris) dans la fenêtre de la campagne. */
function instantEnvoi(alea: Alea, def: DefCampagne, rang: number, total: number): number {
  const jourDebut = def.depuis;
  const jourFin = Math.max(0, def.depuis - def.duree);
  const jour = Math.round(jourDebut - ((jourDebut - jourFin) * rang) / Math.max(1, total - 1));
  return Date.parse(ilYa(jour, alea.entier(8, 17), alea.entier(0, 59)));
}

function choisirCode(alea: Alea, dur: boolean) {
  const codes = CODES_SERVEUR.filter((c) => c.dur === dur);
  return alea.choisir(codes);
}

export function genererEnvoi(contacts: Contact[], entreprises: Entreprise[]): EnvoiGenere {
  const alea = graine('envoi');
  const entrepriseDe = new Map(entreprises.map((e) => [e.id, e]));
  const paul = contacts.find((c) => c.first_name === 'Paul' && c.last_name === 'Morin')!;
  const yves = contacts.find((c) => c.first_name === 'Yves' && c.last_name === 'Roche')!;
  const autres = contacts.filter((c) => c !== paul && c !== yves);

  const destinataires: Destinataire[] = [];
  const evenements: EvenementEnvoi[] = [];
  const reponses: Reponse[] = [];
  const desinscriptions: Desinscription[] = [];
  const opposition: Opposition[] = [];
  const campagnes: Campagne[] = [];
  let numEvt = 0;
  const evt = (e: Omit<EvenementEnvoi, 'id'>) => {
    numEvt += 1;
    evenements.push({ id: `evt-${String(numEvt).padStart(5, '0')}`, ...e });
  };

  CAMPAGNES.forEach((def, ic) => {
    const campagneId = idCampagne(ic);
    const choisis = ic === 0 ? [paul, yves, ...alea.echantillon(autres, def.cible.total - 2)] : alea.echantillon(autres, def.cible.total);
    const classements: Reponse['classement'][] = [];
    for (let k = 0; k < def.reponses; k += 1) {
      if (k < def.interessees) classements.push('interesse');
      else classements.push(alea.choisir(['pas_maintenant', 'question', 'refus', 'stop', 'absence'] as const));
    }

    choisis.forEach((contact, rang) => {
      const org = entrepriseDe.get(contact.company_id)?.denomination ?? null;
      const id = `${campagneId}-d${String(rang + 1).padStart(3, '0')}`;
      const base: Destinataire = {
        id,
        campagne_id: campagneId,
        contact_id: contact.id,
        adresse: contact.email,
        prenom: contact.first_name,
        nom: contact.last_name,
        organisation: org,
        etape: 0,
        etat: 'a_envoyer',
        dernier_evenement_le: null,
        code_serveur: null,
        motif: null,
        ouverture: null,
        clics: null,
      };
      if (rang >= def.envoyes) {
        destinataires.push(base);
        return;
      }

      const boiteId = def.boites[rang % Math.max(1, def.boites.length)] ?? 'boi-claire';
      const envoiMs = instantEnvoi(alea, def, rang, def.envoyes);
      const joursDepuis = (MAINTENANT_MS - envoiMs) / 86_400_000;
      const etape = Math.max(1, Math.min(def.etapes, 1 + Math.floor(joursDepuis / 4)));
      const d: Destinataire = { ...base, etape, etat: 'remis', dernier_evenement_le: instant(envoiMs) };

      // Qui répond ? Décidé AVANT les rebonds : un destinataire qui répond a
      // forcément reçu (et le compte de 60 réponses reste exact).
      const reponseIndex = contact === paul ? 0 : ic === 0 ? rang - 1 : rang;
      const repond = reponseIndex >= 0 && reponseIndex < classements.length && contact !== yves;

      // Rebonds : Yves Roche, le repère des maquettes, puis quelques autres.
      const tirage = repond ? 1 : alea.suivant();
      const estYves = contact === yves;
      if (estYves || tirage < 0.015) {
        const code = estYves ? CODES_SERVEUR[0]! : choisirCode(alea, true);
        Object.assign(d, { etat: 'rebond_dur', etape: 1, code_serveur: code.code, motif: code.libelle });
        evt({ survenu_le: instant(envoiMs + MINUTE), type: 'rebond_dur', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: code.code, libelle: code.libelle, automatique_probable: null });
        destinataires.push(d);
        return;
      }
      for (let e = 1; e <= etape; e += 1) {
        evt({ survenu_le: instant(envoiMs + (e - 1) * 4 * 86_400_000), type: 'remis', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: '250 2.0.0', libelle: 'Remis', automatique_probable: null });
      }
      if (tirage < 0.04) {
        const code = choisirCode(alea, false);
        Object.assign(d, { etat: 'rebond_mou', code_serveur: code.code, motif: code.libelle });
        evt({ survenu_le: instant(envoiMs + 2 * MINUTE), type: 'rebond_mou', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: code.code, libelle: code.libelle, automatique_probable: null });
      }

      // Ouvertures (seulement si la campagne les suit).
      if (def.suivre && d.etat !== 'rebond_mou' && (repond || alea.booleen(0.42))) {
        const automatique = !repond && alea.booleen(0.18);
        const delai = automatique ? alea.entier(0, 1) * MINUTE + 20_000 : alea.entier(12, 60 * 30) * MINUTE;
        const premiere = envoiMs + delai;
        const nombre = automatique ? 1 : alea.entier(1, 4);
        d.ouverture = {
          premiere_le: instant(premiere),
          derniere_le: instant(premiere + (nombre - 1) * alea.entier(30, 600) * MINUTE),
          nombre,
          automatique_probable: automatique,
          raison_automatique: automatique ? alea.choisir(['protection_apple', 'protection_apple', 'relais_images', 'antivirus', 'ouverture_immediate'] as const) : null,
        };
        evt({ survenu_le: d.ouverture.premiere_le, type: 'ouverture', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: null, libelle: automatique ? 'Ouverture automatique probable' : 'Ouverture estimée', automatique_probable: automatique });
      }

      // Clics (indicateur principal, mesurés pour toutes les campagnes).
      if (d.etat === 'remis' && (repond ? alea.booleen(0.4) : alea.booleen(0.05))) {
        const premier = envoiMs + alea.entier(20, 60 * 20) * MINUTE;
        d.clics = { premier_le: instant(premier), nombre: alea.entier(1, 3) };
        evt({ survenu_le: d.clics.premier_le, type: 'clic', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: null, libelle: 'Lien cliqué', automatique_probable: null });
      }

      // Réponses.
      if (repond && d.etat === 'remis') {
        const classement = classements[reponseIndex]!;
        const estPaul = contact === paul;
        const recueMs = estPaul ? Date.parse(ilYa(1, 16, 20)) : Math.min(envoiMs + alea.entier(40, 60 * 48) * MINUTE, MAINTENANT_MS - 30 * MINUTE);
        const recente = MAINTENANT_MS - recueMs < 3 * 86_400_000;
        const repId = `rep-${String(reponses.length + 1).padStart(2, '0')}`;
        reponses.push({
          id: repId,
          recue_le: instant(recueMs),
          campagne_id: campagneId,
          destinataire_id: id,
          boite_id: boiteId,
          de: d.adresse,
          prenom: d.prenom,
          nom: d.nom,
          organisation: org,
          objet: `RE: ${OBJETS[ic % OBJETS.length]!}`,
          extrait: estPaul ? 'Bonjour, c’est intéressant. Pouvez-vous me rappeler jeudi matin ? Paul' : alea.choisir(TEXTES[classement]),
          classement,
          suggestion: { classement, confiance: estPaul ? 92 : alea.entier(61, 97) },
          traitee: !(estPaul || recente),
        });
        d.etat = classement === 'stop' ? 'desinscrit' : 'a_repondu';
        evt({ survenu_le: instant(recueMs), type: 'reponse', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: null, libelle: 'Réponse reçue', automatique_probable: null });
        if (classement === 'stop') {
          desinscriptions.push({ id: `des-${String(desinscriptions.length + 1).padStart(2, '0')}`, adresse: d.adresse, survenue_le: instant(recueMs), source: 'reponse_stop', campagne_id: campagneId });
          opposition.push({ id: `opp-${String(opposition.length + 1).padStart(2, '0')}`, portee: 'adresse', valeur: d.adresse, motif: 'Réponse « stop »', inscrite_le: instant(recueMs), source: 'reponse_stop' });
          evt({ survenu_le: instant(recueMs + MINUTE), type: 'desinscription', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: null, libelle: 'Inscrit au registre d’opposition', automatique_probable: null });
        }
      } else if (d.etat === 'remis' && alea.booleen(0.006)) {
        const le = instant(envoiMs + alea.entier(60, 60 * 24) * MINUTE);
        d.etat = 'desinscrit';
        desinscriptions.push({ id: `des-${String(desinscriptions.length + 1).padStart(2, '0')}`, adresse: d.adresse, survenue_le: le, source: 'lien', campagne_id: campagneId });
        opposition.push({ id: `opp-${String(opposition.length + 1).padStart(2, '0')}`, portee: 'adresse', valeur: d.adresse, motif: 'Lien de désinscription', inscrite_le: le, source: 'lien' });
        evt({ survenu_le: le, type: 'desinscription', campagne_id: campagneId, boite_id: boiteId, destinataire_id: id, adresse: d.adresse, code_serveur: null, libelle: 'Désinscrit par le lien', automatique_probable: null });
      }
      destinataires.push(d);
    });

    // Une plainte sur la campagne terminée de Savoie (taux réaliste).
    if (ic === 4) {
      const cible = destinataires.find((x) => x.campagne_id === campagneId && x.etat === 'remis');
      if (cible) {
        cible.etat = 'plainte';
        const le = instant(Date.parse(cible.dernier_evenement_le ?? MAINTENANT_ISO) + 3 * 60 * MINUTE);
        evt({ survenu_le: le, type: 'plainte', campagne_id: campagneId, boite_id: 'boi-sophie', destinataire_id: cible.id, adresse: cible.adresse, code_serveur: null, libelle: 'Signalé comme indésirable', automatique_probable: null });
        opposition.push({ id: `opp-${String(opposition.length + 1).padStart(2, '0')}`, portee: 'adresse', valeur: cible.adresse, motif: 'Plainte (signalé comme indésirable)', inscrite_le: le, source: 'plainte' });
      }
    }

    const siens = destinataires.filter((x) => x.campagne_id === campagneId);
    const remis = siens.filter((x) => x.etape > 0 && x.etat !== 'rebond_dur').length;
    const estimees = siens.filter((x) => x.ouverture && !x.ouverture.automatique_probable).length;
    const automatiques = siens.filter((x) => x.ouverture?.automatique_probable === true).length;
    const siennes = reponses.filter((r) => r.campagne_id === campagneId);
    campagnes.push({
      id: campagneId,
      nom: def.nom,
      statut: def.statut,
      cible: def.cible,
      envoyes: siens.filter((x) => x.etape > 0).length,
      total: def.cible.total,
      reponses: siennes.length,
      reponses_interessees: siennes.filter((r) => r.classement === 'interesse').length,
      clics: siens.filter((x) => x.clics !== null).length,
      suivre_ouvertures: def.suivre,
      ouvertures: def.suivre && def.envoyes > 0
        ? { estimees, automatiques_probables: automatiques, taux_estime: remis === 0 ? 0 : arrondi1((estimees * 100) / remis), mention: 'estimation' }
        : null,
      ab_objet: def.ab,
      prochain_envoi_le: def.prochain,
      cree_le: ilYa(Math.min(40, def.depuis + 3 + ic), 10, 15),
      boites: def.boites,
      etapes: def.etapes,
    });
  });

  // Réponses hors campagne, jusqu'à 60 (dont une adresse à rattacher).
  const horsCampagne: ReadonlyArray<[string, string | null, string | null, string | null, Reponse['classement'], string, number]> = [
    ['accueil@imprimerie-lac.example', null, null, 'Imprimerie du Lac', 'a_rattacher', 'Transmis à la direction, qui reviendra vers vous.', 0],
    ['direction@commercants-quais.example', 'Gilles', 'Barbier', 'Union des commerçants des Quais', 'stop', 'Ne m’écrivez plus.', 6],
    ['contact@artisans-pilat.example', null, null, 'Groupement des artisans du Pilat', 'question', 'Avez-vous une offre pour nos adhérents ?', 9],
    ['secretariat@dirigeants-drome.example', null, null, 'Cercle des dirigeants de la Drôme', 'interesse', 'Venez présenter votre offre à notre prochaine réunion.', 13],
    ['bureau@cavistes-rhone.example', null, null, 'Union des cavistes du Rhône', 'absence', 'Bureau fermé jusqu’au 6 janvier.', 18],
  ];
  for (const [de, prenom, nom, organisation, classement, extrait, jours] of horsCampagne) {
    if (reponses.length >= 60) break;
    const recue = ilYa(jours, 8, 5);
    reponses.push({ id: `rep-${String(reponses.length + 1).padStart(2, '0')}`, recue_le: recue, campagne_id: null, destinataire_id: null, boite_id: 'boi-claire', de, prenom, nom, organisation, objet: 'RE: Une idée pour votre prochain salon', extrait, classement, suggestion: { classement, confiance: classement === 'a_rattacher' ? 54 : 88 }, traitee: jours > 2 });
    if (classement === 'stop') {
      opposition.push({ id: `opp-${String(opposition.length + 1).padStart(2, '0')}`, portee: 'organisation', valeur: organisation ?? de, motif: 'Réponse « stop » (toute l’organisation)', inscrite_le: recue, source: 'reponse_stop' });
    }
  }
  // Une réponse intéressée toute fraîche pour la cloche (« il y a 25 min »).
  const fraiche = reponses.find((r) => r.campagne_id === 'cmp-01' && r.classement === 'interesse' && r.prenom !== 'Paul');
  if (fraiche) {
    fraiche.recue_le = ilYa(0, 9, 5);
    fraiche.traitee = false;
  }

  evenements.sort((a, b) => (a.survenu_le < b.survenu_le ? -1 : a.survenu_le > b.survenu_le ? 1 : a.id < b.id ? -1 : 1));
  reponses.sort((a, b) => (a.recue_le < b.recue_le ? 1 : -1));

  return {
    campagnes,
    domaines: DOMAINES_DEMO.map((d) => ({ ...d })),
    boites: BOITES_DEMO.map((b) => ({ ...b })),
    serveurs: SERVEURS_DEMO.map((s) => ({ ...s, adresses_ip: s.adresses_ip.map((ip) => ({ ...ip })) })),
    destinataires,
    evenementsEnvoi: evenements,
    reponses,
    messagesTypes: genererMessagesTypes(),
    reglesAlerte: REGLES_ALERTE.map((r) => ({ ...r, canaux: [...r.canaux] })),
    desinscriptions,
    opposition,
    etatEnvois: { etat: 'actif', feu: 'ambre', depuis: ilYa(0, 7, 5), par: null, motif: null },
  };
}

function genererMessagesTypes(): MessageType[] {
  const v = (numero: number, objet: string, corps: string, jours: number, objet_b: string | null = null) => ({ numero, objet, objet_b, corps, modifie_le: ilYa(jours, 10, 0) });
  return [
    {
      id: 'msg-1',
      nom: 'Une idée pour votre prochain salon',
      version_courante: 3,
      utilise_par: 2,
      versions: [
        v(1, 'Une idée pour votre prochain salon', 'Bonjour {{prénom}},\n\nNous accompagnons les organisateurs de salons de la région. Pouvons-nous en parler dix minutes ?\n\nClaire', 30),
        v(2, 'Une idée pour votre prochain salon', 'Bonjour {{prénom}},\n\nNous aidons les organisateurs comme {{organisation}} à remplir leurs allées. Dix minutes cette semaine ?\n\nClaire', 18),
        v(3, 'Une idée pour votre prochain salon', 'Bonjour {{prénom}},\n\nNous aidons les organisateurs comme {{organisation}} à remplir leurs allées. Seriez-vous disponible dix minutes cette semaine ?\n\nClaire', 11, 'Votre prochain salon : une piste'),
      ],
    },
    {
      id: 'msg-2',
      nom: 'Salon des artisans : on en parle ?',
      version_courante: 2,
      utilise_par: 1,
      versions: [
        v(1, 'Salon des artisans : on en parle ?', 'Bonjour {{prénom}},\n\nLe salon des artisans de Vienne approche. Souhaitez-vous y exposer ?\n\nSophie', 12),
        v(2, 'Salon des artisans : on en parle ?', 'Bonjour {{prénom}},\n\nLe salon des artisans de Vienne ouvre le 12 mars. Souhaitez-vous y exposer ? Je vous envoie le dossier.\n\nSophie', 6, 'Exposer au salon de Vienne ?'),
      ],
    },
    {
      id: 'msg-3',
      nom: 'Relance douce',
      version_courante: 1,
      utilise_par: 3,
      versions: [v(1, 'Suite à mon message', 'Bonjour {{prénom}},\n\nJe me permets de revenir vers vous. Est-ce le bon moment pour en parler ?\n\nClaire', 25)],
    },
    {
      id: 'msg-4',
      nom: 'Invitation presse',
      version_courante: 2,
      utilise_par: 1,
      versions: [
        v(1, 'Invitation : salon des artisans de Vienne', 'Bonjour {{prénom}},\n\nNous serions heureux de vous accueillir au salon des artisans de Vienne.\n\nL’équipe Axion (démo)', 20),
        v(2, 'Invitation presse : salon des artisans de Vienne', 'Bonjour {{prénom}},\n\nNous serions heureux d’accueillir {{organisation}} au salon des artisans de Vienne, le 12 mars.\n\nL’équipe Axion (démo)', 4),
      ],
    },
  ];
}

const REGLES_ALERTE: RegleAlerte[] = [
  { id: 'reg-1', nom: 'Taux de refus trop haut', condition: 'taux_refus', seuil: 3, canaux: ['console', 'email'], actif: true },
  { id: 'reg-2', nom: 'Une plainte reçue', condition: 'plainte', seuil: 1, canaux: ['console', 'email'], actif: true },
  { id: 'reg-3', nom: 'Domaine ou adresse IP sur une liste noire', condition: 'liste_noire', seuil: null, canaux: ['console', 'email'], actif: true },
  { id: 'reg-4', nom: 'Boîte ralentie', condition: 'boite_ralentie', seuil: null, canaux: ['console'], actif: true },
  { id: 'reg-5', nom: 'Test du matin en échec', condition: 'test_matin', seuil: null, canaux: ['console', 'email'], actif: true },
  { id: 'reg-6', nom: 'Réponse intéressée', condition: 'reponse_interessee', seuil: null, canaux: ['console'], actif: true },
  { id: 'reg-7', nom: 'Plafond du jour atteint', condition: 'quota_atteint', seuil: null, canaux: ['console'], actif: false },
];
