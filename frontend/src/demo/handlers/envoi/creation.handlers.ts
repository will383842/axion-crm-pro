/**
 * Démo d'Envoi — création de campagne en 5 étapes, messages types et éditeur
 * (lot DM2-ASS). Contrat : `backend/openapi/envoi/creation.yaml`.
 *
 * Le même tableau `handlers` sert à la démo, aux tests Vitest et à l'e2e.
 * Rien ne part : « M'envoyer un test » ne contacte personne, la validation ne
 * crée qu'une ligne dans `demoDb.campagnes`. Rien ne se supprime (règle n° 2) :
 * un message type s'archive, une validation s'annule en remettant en brouillon.
 *
 * Données propres à la zone (brouillons, état de validation des messages) :
 * `demoDb.etendre('creation', …)`, donc rejouées après rechargement.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/creation';
import { demoDb } from '../../base';
import { IDENTIFIANTS_DEMO } from '../../donnees';
import { MAINTENANT_ISO, decalage } from '../../horloge';
import { api, delai, erreur, etatForce, lireRecherche, filtrerTexte, normaliser, repondreEtatForce } from '../../outils';

type S = components['schemas'];
type Cible = S['Cible'];
type Brouillon = S['Brouillon'];
type ContenuEtape = S['ContenuEtape'];
type EtapeSequence = S['EtapeSequence'];
type SaisieMessage = S['SaisieMessage'];
type ControleContenu = S['ControleContenu'];
type Controle = S['Controle'];
type MessageTypeEtat = S['MessageTypeEtat'];
type CibleProposee = S['CibleProposee'];
type ChampUtilise = S['ChampUtilise'];
type BlocReutilisable = S['BlocReutilisable'];

// ── Données de la zone ────────────────────────────────────────────────────

/** État de validation d'un message type (le contrat commun n'en porte pas). */
interface EtatMessage {
  id: string;
  /** Statut hors archivage : on archive sans rien perdre, on peut désarchiver. */
  base: 'brouillon' | 'a_relire' | 'valide';
  archive: boolean;
  version_validee: number | null;
  bloc_information: boolean;
}

type BrouillonDemo = Brouillon;

interface ZoneCreation {
  brouillons: BrouillonDemo[];
  etats: EtatMessage[];
  blocs: BlocReutilisable[];
}

function fabriquerZone(): ZoneCreation {
  return {
    brouillons: [],
    // Les autres messages (créés plus tard) sont « valides » tant qu'aucun état
    // n'est noté : voir `etatDe`.
    etats: [
      { id: 'msg-1', base: 'valide', archive: false, version_validee: 3, bloc_information: true },
      { id: 'msg-2', base: 'valide', archive: false, version_validee: 2, bloc_information: true },
      { id: 'msg-3', base: 'a_relire', archive: false, version_validee: null, bloc_information: true },
      { id: 'msg-4', base: 'brouillon', archive: false, version_validee: null, bloc_information: true },
    ],
    blocs: [
      { id: 'bloc-signature', nom: 'Signature', texte: '{signature}', utilise: 12 },
      { id: 'bloc-appel', nom: 'Appel : 15 minutes au téléphone', texte: 'Seriez-vous disponible 15 minutes au téléphone cette semaine ?', utilise: 5 },
      { id: 'bloc-rdv', nom: 'Lien de rendez-vous', texte: 'Choisissez un créneau qui vous convient : {lien_rdv}', utilise: 4 },
      { id: 'bloc-revenir', nom: 'Retour en douceur', texte: 'Je me permets de revenir vers vous.', utilise: 7 },
      { id: 'bloc-merci', nom: 'Remerciement', texte: 'Merci d’avance pour votre retour.', utilise: 6 },
      { id: 'bloc-salon', nom: 'Date du salon', texte: 'Le salon des artisans de Vienne ouvre le 12 mars.', utilise: 3 },
    ],
  };
}

function zone() {
  demoDb.etendre('creation', fabriquerZone);
  return {
    brouillons: demoDb.zone<BrouillonDemo>('creation', 'brouillons'),
    etats: demoDb.zone<EtatMessage>('creation', 'etats'),
    blocs: demoDb.etendre('creation', fabriquerZone).blocs,
  };
}

function etatDe(id: string): EtatMessage {
  const connu = zone().etats.trouver(id);
  if (connu) return connu;
  const message = demoDb.messagesTypes.trouver(id);
  return { id, base: 'valide', archive: false, version_validee: message?.version_courante ?? 1, bloc_information: true };
}

function statutDe(e: EtatMessage): S['StatutMessage'] {
  return e.archive ? 'archive' : e.base;
}

function avecEtat(m: { id: string }): MessageTypeEtat {
  const message = demoDb.messagesTypes.trouver(m.id)!;
  const e = etatDe(m.id);
  return structuredClone({
    id: message.id,
    nom: message.nom,
    version_courante: message.version_courante,
    versions: message.versions,
    utilise_par: message.utilise_par,
    statut: statutDe(e),
    version_validee: e.version_validee,
    bloc_information: e.bloc_information,
  });
}

function noterEtat(id: string, patch: Partial<EtatMessage>): void {
  const { etats } = zone();
  if (etats.trouver(id)) etats.modifier(id, patch);
  else etats.ajouter({ ...etatDe(id), ...patch });
}

// ── Cibles et comptes ─────────────────────────────────────────────────────

/** Retraits d'office : oppositions, clients et affaires en cours, adresses partagées. */
function retraitsDOffice(joignables: number): { opposition: number; clients: number; adresses_partagees: number } {
  return {
    opposition: Math.round(joignables * 0.0567),
    clients: Math.round(joignables * 0.0464),
    adresses_partagees: Math.round(joignables * 0.0155),
  };
}

function toutesLesCibles(entreprise: string | null): CibleProposee[] {
  const cibles: CibleProposee[] = [];
  const ajouter = (type: CibleProposee['type'], reference: string, libelle: string, joignables: number) => {
    const r = retraitsDOffice(joignables);
    cibles.push({ type, reference, libelle, joignables, total: joignables + r.opposition + r.clients + r.adresses_partagees });
  };
  if (entreprise !== null) {
    const e = demoDb.entreprises.trouver(entreprise);
    if (e) {
      const nb = Math.max(1, demoDb.contacts.filtrer((c) => c.company_id === e.id).length);
      ajouter('carnet', `entreprise-${e.id}`, `Entreprise · ${e.denomination}`, nb);
    }
  }
  for (const t of demoDb.etiquettes.tous()) if (t.usage_count > 0) ajouter('etiquette', t.slug, t.slug, t.usage_count);
  for (const a of demoDb.audiences.tous()) ajouter('audience', String(a.id), a.name, a.member_count);
  for (const l of demoDb.listes.tous()) ajouter('liste', String(l.id), l.nom, l.personnes);
  return cibles;
}

function trouverCible(cible: Cible): CibleProposee | undefined {
  const entreprise = cible.type === 'carnet' && cible.reference.startsWith('entreprise-') ? cible.reference.slice('entreprise-'.length) : null;
  return toutesLesCibles(entreprise).find((c) => c.type === cible.type && c.reference === cible.reference);
}

/** Combien d'adresses retirent des exclusions saisies à la main (adresse, domaine, organisation). */
function retraitsSaisis(jetons: readonly string[] | undefined, joignables: number): number {
  const total = (jetons ?? [])
    .flatMap((j) => j.split(/[,;\n]/))
    .map((j) => j.trim())
    .filter((j) => j !== '')
    .reduce((n, j) => n + (j.includes('@') && !j.startsWith('@') ? 1 : /^@?[^\s@]+\.[a-z]{2,}$/i.test(j) ? 6 : 4), 0);
  return Math.min(total, joignables);
}

function estimer(cible: Cible): { total: number; exclus: number; restant: number; exclusions: Record<string, number> } {
  const trouvee = trouverCible(cible);
  const joignables = trouvee?.joignables ?? 0;
  const r = retraitsDOffice(joignables);
  const saisies = retraitsSaisis(cible.exclusions_libres, joignables);
  const exclus = r.opposition + r.clients + r.adresses_partagees + saisies;
  return {
    total: (trouvee?.total ?? 0),
    exclus,
    restant: Math.max(0, joignables - saisies),
    exclusions: { opposition: r.opposition, clients: r.clients, adresses_partagees: r.adresses_partagees, saisies },
  };
}

// ── Champs de fusion, rendu et contrôleur de contenu ──────────────────────

const CHAMPS: Record<string, { source: 'crm' | 'saisie' | 'signature'; obligatoire: boolean; part_manquante: number }> = {
  prénom: { source: 'crm', obligatoire: false, part_manquante: 0.03 },
  entreprise: { source: 'crm', obligatoire: true, part_manquante: 0 },
  ville: { source: 'crm', obligatoire: false, part_manquante: 0.1 },
  lien_rdv: { source: 'saisie', obligatoire: true, part_manquante: 1 },
  signature: { source: 'signature', obligatoire: false, part_manquante: 0 },
  bloc_information: { source: 'signature', obligatoire: false, part_manquante: 0 },
};
const ALIAS: Record<string, string> = { prenom: 'prénom', organisation: 'entreprise', societe: 'entreprise', société: 'entreprise' };

function nomDeChamp(brut: string): string {
  const n = brut.trim().toLowerCase();
  return ALIAS[n] ?? n;
}

/** `{prénom}`, `{{prénom}}` et `{prénom | Bonjour}` (valeur de repli écrite dans le message). */
const MOTIF_CHAMP = /\{\{?\s*([\p{L}_]+)\s*(?:\|\s*([^{}]*?)\s*)?\}\}?/gu;

interface ChampTrouve { nom: string; repliEcrit: string | null }

function champsDe(texte: string): ChampTrouve[] {
  const trouves: ChampTrouve[] = [];
  for (const m of texte.matchAll(MOTIF_CHAMP)) {
    trouves.push({ nom: nomDeChamp(m[1]!), repliEcrit: m[2] !== undefined && m[2] !== '' ? m[2] : null });
  }
  return trouves;
}

const MOTS_INTERDITS = ['gratuit', 'gagnez', 'urgent', 'dernière chance', 'cliquez ici', 'offre exclusive', 'promo', '100 %'];
const RACCOURCISSEURS = ['bit.ly', 'tinyurl.com', 't.co', 'goo.gl', 'ow.ly', 'lnkd.in', 'is.gd'];

export const TEXTE_BLOC_INFORMATION =
  'Vous recevez ce message au titre de votre activité professionnelle. Axion-IA (démo) est responsable du traitement de votre adresse. ' +
  'Vous pouvez vous opposer à tout nouveau message : se désinscrire en un clic.';

export function controlerContenu(saisie: Pick<SaisieMessage, 'objet' | 'corps'> & { objet_b?: string | null; bloc_information?: boolean }): ControleContenu[] {
  const texte = `${saisie.objet}\n${saisie.objet_b ?? ''}\n${saisie.corps}`;
  const normal = normaliser(texte);
  const controles: ControleContenu[] = [];

  const interdits = MOTS_INTERDITS.filter((mot) => {
    const m = normaliser(mot).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp(`(^|[^a-z0-9])${m}`).test(normal);
  });
  controles.push(
    interdits.length === 0
      ? { code: 'mots_interdits', libelle: 'Aucun mot interdit', etat: 'ok', detail: null }
      : { code: 'mots_interdits', libelle: `Mot interdit : « ${interdits.join(' », « ')} »`, etat: 'bloquant', detail: 'Ces mots font classer les messages en indésirables. Reformulez.' },
  );

  const liens = texte.match(/(https?:\/\/[^\s)>\]]+|www\.[^\s)>\]]+)/gi) ?? [];
  const courts = liens.filter((l) => RACCOURCISSEURS.some((r) => normaliser(l).includes(r)));
  controles.push(
    courts.length > 0
      ? { code: 'liens', libelle: `Lien raccourci : ${courts[0]!}`, etat: 'bloquant', detail: 'Les liens raccourcis sont refusés : utilisez l’adresse complète.' }
      : liens.length > 2
        ? { code: 'liens', libelle: `${liens.length} liens dans le message`, etat: 'attention', detail: 'Deux liens au plus : au-delà, le message risque d’être filtré.' }
        : { code: 'liens', libelle: 'Aucun lien raccourci', etat: 'ok', detail: null },
  );

  controles.push(
    saisie.bloc_information === false
      ? { code: 'bloc_information', libelle: 'Bloc d’information absent (art. 14 du RGPD)', etat: 'bloquant', detail: 'Il est obligatoire en prospection : il dit qui écrit, pourquoi, et comment se désinscrire.' }
      : { code: 'bloc_information', libelle: 'Bloc d’information présent (art. 14 du RGPD)', etat: 'ok', detail: null },
  );

  controles.push(
    /<\/?[a-z][^>]*>/i.test(texte)
      ? { code: 'texte_simple', libelle: 'Texte simple exigé', etat: 'bloquant', detail: 'Retirez les balises : la prospection se fait en texte simple, sans pièce jointe.' }
      : { code: 'texte_simple', libelle: 'Texte simple, sans pièce jointe', etat: 'ok', detail: null },
  );

  const champs = champsDe(texte);
  const inconnus = [...new Set(champs.filter((c) => CHAMPS[c.nom] === undefined).map((c) => c.nom))];
  const sansRepli = [...new Set(champs.filter((c) => CHAMPS[c.nom]?.source === 'saisie' && c.repliEcrit === null).map((c) => c.nom))];
  controles.push(
    inconnus.length > 0
      ? { code: 'valeurs_repli', libelle: `Champ inconnu : {${inconnus[0]!}}`, etat: 'bloquant', detail: 'Champs possibles : {prénom}, {entreprise}, {ville}, {lien_rdv}, {signature}.' }
      : sansRepli.length > 0
        ? { code: 'valeurs_repli', libelle: `Valeur de repli à prévoir pour {${sansRepli[0]!}}`, etat: 'attention', detail: 'Vous l’indiquerez à la création de la campagne, ou écrivez-la dans le message : {lien_rdv | https://…}.' }
        : { code: 'valeurs_repli', libelle: 'Valeurs de repli prêtes', etat: 'ok', detail: null },
  );
  return controles;
}

export interface Exemple { id: string; libelle: string; prenom: string | null; organisation: string | null }

function exemples(): Exemple[] {
  const paul = demoDb.contacts.filtrer((c) => c.first_name === 'Paul' && c.last_name === 'Morin')[0];
  const organisationDe = (id: number | undefined) => (id === undefined ? null : demoDb.entreprises.trouver(id)?.denomination ?? null);
  const lea = demoDb.contacts.filtrer((c) => c.first_name === 'Léa' && c.last_name === 'Fournier')[0];
  const liste: Exemple[] = [
    { id: 'ex-paul', libelle: `Paul Morin (${organisationDe(paul?.company_id) ?? 'Boulangerie des Tilleuls'})`, prenom: 'Paul', organisation: organisationDe(paul?.company_id) ?? 'Boulangerie des Tilleuls' },
  ];
  if (lea) liste.push({ id: 'ex-lea', libelle: `Léa Fournier (${organisationDe(lea.company_id) ?? 'Le Courrier du Rhône'})`, prenom: 'Léa', organisation: organisationDe(lea.company_id) ?? 'Le Courrier du Rhône' });
  liste.push({ id: 'ex-sans-prenom', libelle: 'Sans prénom (valeur de repli)', prenom: null, organisation: 'Imprimerie du Lac' });
  return liste;
}

const SIGNATURE = 'Claire';

/** Rend un texte pour un destinataire exemple ; un champ vide prend sa valeur de repli. */
export function rendre(texte: string, exemple: Exemple, repli: Record<string, string> = {}): string {
  const rendu = texte.replace(MOTIF_CHAMP, (_tout, brut: string, ecrit: string | undefined) => {
    const nom = nomDeChamp(brut);
    const valeurs: Record<string, string | null> = {
      prénom: exemple.prenom,
      entreprise: exemple.organisation,
      ville: 'Vienne',
      signature: SIGNATURE,
      lien_rdv: repli['lien_rdv'] ?? null,
      bloc_information: '',
    };
    const v = valeurs[nom];
    if (v !== null && v !== undefined && v !== '') return v;
    if (nom === 'bloc_information') return '';
    return (ecrit !== undefined && ecrit !== '' ? ecrit : repli[nom]) ?? '';
  });
  // « Bonjour , » devient « Bonjour, » quand le prénom manque.
  return rendu.replace(/[ \t]+([,.;:!?])/g, '$1').replace(/[ \t]{2,}/g, ' ');
}

// ── Brouillons ────────────────────────────────────────────────────────────

function lireBrouillon(id: string): BrouillonDemo | undefined {
  return zone().brouillons.trouver(id);
}

function versionValidee(messageId: string): S['MessageTypeEtat']['versions'][number] | undefined {
  const message = demoDb.messagesTypes.trouver(messageId);
  if (!message) return undefined;
  const e = etatDe(messageId);
  const numero = e.version_validee ?? message.version_courante;
  return message.versions.find((v) => v.numero === numero) ?? message.versions[message.versions.length - 1];
}

function analyserLesChamps(cible: Cible, sequence: readonly EtapeSequence[], repliGlobal: Record<string, string>): ChampUtilise[] {
  const restant = estimer(cible).restant;
  const parNom = new Map<string, string | null>();
  for (const e of sequence) {
    const v = versionValidee(e.message_id);
    if (!v) continue;
    for (const c of champsDe(`${v.objet}\n${e.objet_b ?? v.objet_b ?? ''}\n${v.corps}`)) {
      if (CHAMPS[c.nom] === undefined || CHAMPS[c.nom]!.source === 'signature') continue;
      if (!parNom.has(c.nom) || parNom.get(c.nom) === null) parNom.set(c.nom, c.repliEcrit);
    }
  }
  return [...parNom].map(([nom, repliEcrit]) => {
    const def = CHAMPS[nom]!;
    const manquants = Math.round(restant * def.part_manquante);
    const saisi = (repliGlobal[nom] ?? '').trim();
    const repli = saisi !== '' ? saisi : repliEcrit;
    return { nom, obligatoire: def.obligatoire, manquants, repli, bloquant: def.obligatoire && manquants > 0 && repli === null };
  });
}

function controlesDuBrouillon(b: BrouillonDemo): { controles: Controle[]; bloquants: number } {
  const c = b.contenu;
  const cible = c.cible;
  const estimation = cible ? estimer(cible) : { restant: 0, exclusions: { opposition: 0, clients: 0 } as Record<string, number> };
  const sequence = c.sequence ?? [];
  const boites = (c.boites ?? []).map((id) => demoDb.boites.trouver(id)).filter((x): x is NonNullable<typeof x> => x !== undefined);
  const controles: Controle[] = [];

  controles.push(
    estimation.restant > 0
      ? { code: 'cible_non_vide', libelle: 'La cible n’est pas vide', etat: 'ok', detail: `Liste figée (${estimation.restant})` }
      : { code: 'cible_non_vide', libelle: 'La cible est vide', etat: 'bloquant', detail: 'Choisissez une étiquette, une audience ou une liste qui contient des adresses joignables.' },
  );
  controles.push({ code: 'opposition_retiree', libelle: 'Oppositions et clients retirés', etat: 'ok', detail: `Oppositions (${estimation.exclusions['opposition'] ?? 0}), clients et affaires en cours (${estimation.exclusions['clients'] ?? 0}).` });

  const sansBloc = sequence.filter((e) => !etatDe(e.message_id).bloc_information);
  controles.push(
    sansBloc.length > 0
      ? { code: 'lien_desinscription', libelle: 'Bloc d’information absent', etat: 'bloquant', detail: `Ajoutez-le au message « ${demoDb.messagesTypes.trouver(sansBloc[0]!.message_id)?.nom ?? ''} » : il porte la désinscription en un clic.` }
      : { code: 'lien_desinscription', libelle: 'Bloc d’information et désinscription en un clic', etat: 'ok', detail: null },
  );
  controles.push({ code: 'identite_expediteur', libelle: 'Identité de l’expéditeur', etat: 'ok', detail: 'Nom, société et adresse figurent dans le bloc d’information.' });

  const vraiesBoites = boites.length;
  const suspendues = boites.filter((x) => x.statut === 'suspendue');
  const prudentes = boites.filter((x) => x.statut === 'en_chauffe' || x.statut === 'ralentie');
  controles.push(
    vraiesBoites === 0
      ? { code: 'boites_pretes', libelle: 'Boîtes prêtes : aucune boîte choisie', etat: 'bloquant', detail: 'Cochez au moins une boîte d’envoi à l’étape « Quand ».' }
      : suspendues.length > 0
        ? { code: 'boites_pretes', libelle: `Boîtes prêtes : ${suspendues.length} suspendue${suspendues.length > 1 ? 's' : ''}`, etat: 'bloquant', detail: `${suspendues[0]!.adresse} ne peut pas envoyer.` }
        : prudentes.length > 0
          ? { code: 'boites_pretes', libelle: `Boîtes prêtes (${vraiesBoites - prudentes.length} sur ${vraiesBoites})`, etat: 'attention', detail: `${prudentes[0]!.adresse} est ${prudentes[0]!.statut === 'en_chauffe' ? 'en chauffe' : 'ralentie'} : son plafond sera réduit.` }
          : { code: 'boites_pretes', libelle: `Boîtes prêtes (${vraiesBoites} sur ${vraiesBoites})`, etat: 'ok', detail: null },
  );

  const domaines = [...new Set(boites.map((x) => x.domaine_id))].map((id) => demoDb.domaines.trouver(id)).filter((d): d is NonNullable<typeof d> => d !== undefined);
  const rouge = domaines.find((d) => d.etat === 'rouge');
  const ambre = domaines.find((d) => d.etat === 'ambre');
  controles.push(
    rouge
      ? { code: 'domaines_sains', libelle: 'Domaines sains : un domaine est arrêté', etat: 'bloquant', detail: rouge.nom }
      : ambre
        ? { code: 'domaines_sains', libelle: 'Domaines sains : un domaine demande attention', etat: 'attention', detail: `${ambre.nom} figure sur une liste de surveillance.` }
        : { code: 'domaines_sains', libelle: `Domaines sains (${domaines.length} sur ${domaines.length})`, etat: 'ok', detail: null },
  );

  const capacite = boites.reduce((n, x) => n + x.quota_jour, 0);
  const plafond = c.plafond_jour ?? 0;
  controles.push(
    plafond > capacite
      ? { code: 'plafond_respecte', libelle: 'Plafond non respecté', etat: 'bloquant', detail: `${plafond} par jour demandés, ${capacite} possibles avec ces boîtes.` }
      : { code: 'plafond_respecte', libelle: 'Plafond respecté', etat: 'ok', detail: `${plafond} par jour, ${capacite} possibles.` },
  );
  return { controles, bloquants: controles.filter((x) => x.etat === 'bloquant').length };
}

function nouvelIdBrouillon(): string {
  return `bro-${String(zone().brouillons.compter() + 1).padStart(4, '0')}`;
}

const lire = async <T>(request: Request): Promise<T> => (await request.json()) as T;

// ── Handlers ──────────────────────────────────────────────────────────────

export const handlers: HttpHandler[] = [
  // Cibles proposables.
  http.get(api('/envoi/creation/cibles'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const url = new URL(request.url);
    const liste = filtrerTexte(toutesLesCibles(url.searchParams.get('entreprise')), lireRecherche(request), ['libelle']);
    return HttpResponse.json({ data: liste });
  }),

  // Boîtes choisissables.
  http.get(api('/envoi/creation/boites'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({
      data: demoDb.boites.tous().map((b) => ({ id: b.id, adresse: b.adresse, statut: b.statut, quota_jour: b.quota_jour })),
    });
  }),

  http.get(api('/envoi/creation/contexte'), async () => {
    await delai();
    return HttpResponse.json({ maintenant: MAINTENANT_ISO, fuseau: 'Europe/Paris' });
  }),

  http.get(api('/envoi/creation/destinataires-exemples'), async () => {
    await delai();
    return HttpResponse.json({ data: exemples() });
  }),

  http.post(api('/envoi/creation/champs'), async ({ request }) => {
    await delai();
    const corps = await lire<{ cible: Cible; sequence: EtapeSequence[]; repli?: Record<string, string> }>(request);
    const champs = analyserLesChamps(corps.cible, corps.sequence, corps.repli ?? {});
    return HttpResponse.json({ champs, bloquants: champs.filter((c) => c.bloquant).length });
  }),

  // Brouillons.
  http.post(api('/envoi/brouillons'), async ({ request }) => {
    await delai();
    const { nom } = await lire<{ nom: string }>(request);
    const b: BrouillonDemo = {
      id: nouvelIdBrouillon(),
      nom: nom.trim() === '' ? 'Nouvelle campagne' : nom.trim(),
      etape_courante: 1,
      etapes_faites: [],
      contenu: {},
      mis_a_jour_le: MAINTENANT_ISO,
      campagne_id: null,
      annulable_jusqua: null,
    };
    return HttpResponse.json(zone().brouillons.ajouter(b), { status: 201 });
  }),

  http.get(api('/envoi/brouillons/:id'), async ({ params }) => {
    await delai();
    const b = lireBrouillon(String(params['id']));
    return b ? HttpResponse.json(b) : erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
  }),

  http.put(api('/envoi/brouillons/:id/etapes/:etape'), async ({ params, request }) => {
    await delai();
    const b = lireBrouillon(String(params['id']));
    if (!b) return erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
    const etape = Number(params['etape']);
    const corps = await lire<ContenuEtape>(request);
    if (etape === 2 && corps.sequence !== undefined && corps.sequence.length === 0) {
      return erreur(422, 'validation', 'Choisissez au moins un message.');
    }
    const contenu: ContenuEtape = { ...b.contenu, ...corps };
    const nom = corps.nom !== undefined && corps.nom.trim() !== '' ? corps.nom.trim() : b.nom;
    const faites = [...new Set([...b.etapes_faites, ...Array.from({ length: Math.max(0, etape - 1) }, (_x, i) => i + 1)])].sort();
    const maj = zone().brouillons.modifier(b.id, { nom, contenu, etape_courante: etape, etapes_faites: faites, mis_a_jour_le: MAINTENANT_ISO });
    return HttpResponse.json(maj);
  }),

  http.post(api('/envoi/brouillons/:id/cible/estimation'), async ({ params, request }) => {
    await delai();
    // Un calcul pur : l'identifiant peut être « nouveau » tant que rien n'est enregistré.
    void params;
    return HttpResponse.json(estimer(await lire<Cible>(request)));
  }),

  http.get(api('/envoi/brouillons/:id/controles'), async ({ params }) => {
    await delai();
    const b = lireBrouillon(String(params['id']));
    if (!b) return erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
    return HttpResponse.json(controlesDuBrouillon(b));
  }),

  http.post(api('/envoi/brouillons/:id/apercu'), async ({ params, request }) => {
    await delai();
    const b = lireBrouillon(String(params['id']));
    if (!b) return erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
    const corps = await lire<{ etape: number; variante?: 'a' | 'b'; destinataire_id?: string }>(request);
    const e = b.contenu.sequence?.find((s) => s.etape === corps.etape);
    const v = e ? versionValidee(e.message_id) : undefined;
    if (!e || !v) return erreur(404, 'non_trouve', 'Ce message n’existe pas.');
    const exemple = exemples().find((x) => x.id === corps.destinataire_id) ?? exemples()[0]!;
    const objet = corps.variante === 'b' ? e.objet_b ?? v.objet_b ?? v.objet : v.objet;
    return HttpResponse.json({ objet: rendre(objet, exemple, b.contenu.repli), corps: rendre(v.corps, exemple, b.contenu.repli), de: 'Claire · Axion (démo)' });
  }),

  // « M'envoyer un test » depuis l'assistant : aucun envoi réel.
  http.post(api('/envoi/brouillons/:id/test'), async ({ params }) => {
    await delai();
    if (!lireBrouillon(String(params['id']))) return erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
    return HttpResponse.json({ ok: true, message: 'Test envoyé à vos adresses de test (démonstration : rien n’est parti).' });
  }),

  http.post(api('/envoi/brouillons/:id/validation/code'), async ({ params }) => {
    await delai();
    const b = lireBrouillon(String(params['id']));
    if (!b) return erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
    if (controlesDuBrouillon(b).bloquants > 0) return erreur(422, 'controles_bloquants', 'Corrigez d’abord les contrôles en rouge.');
    return HttpResponse.json({ envoye_a: IDENTIFIANTS_DEMO.email, expire_le: decalage({ minutes: 10 }) });
  }),

  http.post(api('/envoi/brouillons/:id/valider'), async ({ params, request }) => {
    await delai();
    const b = lireBrouillon(String(params['id']));
    if (!b) return erreur(404, 'non_trouve', 'Ce brouillon n’existe pas.');
    const { code } = await lire<{ code: string }>(request);
    if (code !== IDENTIFIANTS_DEMO.code) return erreur(422, 'code_incorrect', 'Ce code n’est pas le bon. Vérifiez le dernier e-mail reçu.');
    if (controlesDuBrouillon(b).bloquants > 0) return erreur(422, 'controles_bloquants', 'Corrigez d’abord les contrôles en rouge.');

    const c = b.contenu;
    const cible = trouverCible(c.cible!);
    const estimation = estimer(c.cible!);
    const sequence = c.sequence ?? [];
    const idCampagne = `cmp-${String(demoDb.campagnes.compter() + 1).padStart(2, '0')}`;
    const annulable = decalage({ minutes: 5 });
    const campagne = demoDb.campagnes.ajouter({
      id: idCampagne,
      nom: b.nom,
      statut: 'programmee',
      cible: { type: c.cible!.type, reference: c.cible!.reference, libelle: cible?.libelle ?? c.cible!.reference, total: estimation.restant },
      envoyes: 0,
      total: estimation.restant,
      reponses: 0,
      reponses_interessees: 0,
      suivre_ouvertures: c.suivre_ouvertures === true,
      ouvertures: null,
      ab_objet: sequence.some((s) => s.objet_b !== undefined && s.objet_b !== null && s.objet_b !== ''),
      prochain_envoi_le: c.debut_le ?? decalage({ minutes: 5 }),
      cree_le: MAINTENANT_ISO,
      boites: c.boites ?? [],
      etapes: Math.min(4, Math.max(1, sequence.length)),
    });
    zone().brouillons.modifier(b.id, { campagne_id: idCampagne, annulable_jusqua: annulable, etape_courante: 5, mis_a_jour_le: MAINTENANT_ISO });
    return HttpResponse.json({ campagne, annulable_jusqua: annulable });
  }),

  http.post(api('/envoi/campagnes/:id/annuler-validation'), async ({ params }) => {
    await delai();
    const id = String(params['id']);
    const campagne = demoDb.campagnes.trouver(id);
    const b = zone().brouillons.filtrer((x) => x.campagne_id === id)[0];
    if (!campagne || !b) return erreur(404, 'non_trouve', 'Cette campagne n’existe pas.');
    if (campagne.statut !== 'programmee' || campagne.envoyes > 0) {
      return erreur(409, 'trop_tard', 'La campagne est déjà partie : elle ne peut plus être annulée d’un clic. Mettez-la en pause depuis son suivi.');
    }
    demoDb.campagnes.modifier(id, { statut: 'brouillon', prochain_envoi_le: null });
    const maj = zone().brouillons.modifier(b.id, { campagne_id: null, annulable_jusqua: null, etape_courante: 5, mis_a_jour_le: MAINTENANT_ISO });
    return HttpResponse.json(maj);
  }),

  // Messages types.
  http.get(api('/envoi/messages'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], blocs: [] });
    if (force) return force;
    const avecArchives = new URL(request.url).searchParams.get('archives') === 'true';
    const liste = filtrerTexte(demoDb.messagesTypes.tous(), lireRecherche(request), ['nom'])
      .map((m) => avecEtat(m))
      .filter((m) => avecArchives || m.statut !== 'archive');
    return HttpResponse.json({ data: liste, blocs: zone().blocs });
  }),

  http.post(api('/envoi/messages'), async ({ request }) => {
    await delai();
    const s = await lire<SaisieMessage>(request);
    const id = `msg-${demoDb.messagesTypes.compter() + 1}`;
    demoDb.messagesTypes.ajouter({
      id,
      nom: s.nom.trim() === '' ? 'Nouveau message' : s.nom.trim(),
      version_courante: 1,
      utilise_par: 0,
      versions: [{ numero: 1, objet: s.objet, objet_b: s.objet_b ?? null, corps: s.corps, modifie_le: MAINTENANT_ISO }],
    });
    noterEtat(id, { base: 'brouillon', archive: false, version_validee: null, bloc_information: s.bloc_information !== false });
    return HttpResponse.json(avecEtat({ id }), { status: 201 });
  }),

  http.post(api('/envoi/messages/controler'), async ({ request }) => {
    await delai();
    const controles = controlerContenu(await lire<SaisieMessage>(request));
    return HttpResponse.json({ controles, bloquants: controles.filter((c) => c.etat === 'bloquant').length });
  }),

  http.post(api('/envoi/messages/apercu'), async ({ request }) => {
    await delai();
    const s = await lire<{ objet: string; corps: string; bloc_information?: boolean; destinataire_exemple_id?: string }>(request);
    const exemple = exemples().find((x) => x.id === s.destinataire_exemple_id) ?? exemples()[0]!;
    const corps = rendre(s.corps, exemple);
    return HttpResponse.json({
      objet: rendre(s.objet, exemple),
      corps: s.bloc_information === false ? corps : `${corps}\n\n${TEXTE_BLOC_INFORMATION}`,
      de: 'Claire · Axion (démo)',
    });
  }),

  http.post(api('/envoi/messages/test'), async ({ request }) => {
    await delai();
    const s = await lire<{ a_moi?: boolean; boites_temoins?: boolean }>(request);
    // Aucun envoi réel : on compte seulement les adresses de test qui auraient reçu.
    const adresses = (s.a_moi === false ? 0 : 1) + (s.boites_temoins === false ? 0 : 4);
    return HttpResponse.json({ ok: true, adresses });
  }),

  http.post(api('/envoi/messages/suggestion'), async () => {
    await delai();
    return HttpResponse.json({
      suggestion: true,
      messages: [
        { delai_jours: 0, objet: 'Une idée pour votre prochain salon', corps: 'Bonjour {prénom},\n\nNous aidons les organisateurs comme {entreprise} à remplir leurs allées. Auriez-vous dix minutes cette semaine ?\n\n{signature}' },
        { delai_jours: 4, objet: 'Suite à mon message', corps: 'Bonjour {prénom},\n\nJe me permets de revenir vers vous. Est-ce le bon moment pour en parler ?\n\n{signature}' },
        { delai_jours: 7, objet: 'Dernier mot', corps: 'Bonjour {prénom},\n\nSans retour de votre part, je ne vous écrirai plus à ce sujet. Merci de votre attention.\n\n{signature}' },
      ],
    });
  }),

  http.get(api('/envoi/messages/:id'), async ({ params, request }) => {
    await delai();
    // État « vide » : ce message n'existe pas (404) ; « erreur » et « chargement » comme ailleurs.
    if (etatForce(request) === 'vide') return erreur(404, 'non_trouve', 'Ce message n’existe pas.');
    const force = await repondreEtatForce(request, {});
    if (force) return force;
    const id = String(params['id']);
    return demoDb.messagesTypes.trouver(id) ? HttpResponse.json(avecEtat({ id })) : erreur(404, 'non_trouve', 'Ce message n’existe pas.');
  }),

  http.put(api('/envoi/messages/:id'), async ({ params, request }) => {
    await delai();
    const id = String(params['id']);
    const m = demoDb.messagesTypes.trouver(id);
    if (!m) return erreur(404, 'non_trouve', 'Ce message n’existe pas.');
    const s = await lire<SaisieMessage>(request);
    const numero = m.version_courante + 1;
    // On n'écrase jamais : la nouvelle version s'ajoute, les anciennes restent.
    demoDb.messagesTypes.modifier(id, {
      nom: s.nom.trim() === '' ? m.nom : s.nom.trim(),
      version_courante: numero,
      versions: [...m.versions, { numero, objet: s.objet, objet_b: s.objet_b ?? null, corps: s.corps, modifie_le: MAINTENANT_ISO }],
    });
    const e = etatDe(id);
    noterEtat(id, { base: e.base === 'valide' ? 'a_relire' : e.base, bloc_information: s.bloc_information !== false });
    return HttpResponse.json(avecEtat({ id }));
  }),

  http.post(api('/envoi/messages/:id/versions/:numero/restaurer'), async ({ params }) => {
    await delai();
    const id = String(params['id']);
    const m = demoDb.messagesTypes.trouver(id);
    const ancienne = m?.versions.find((v) => v.numero === Number(params['numero']));
    if (!m || !ancienne) return erreur(404, 'non_trouve', 'Cette version n’existe pas.');
    const numero = m.version_courante + 1;
    demoDb.messagesTypes.modifier(id, {
      version_courante: numero,
      versions: [...m.versions, { ...ancienne, numero, modifie_le: MAINTENANT_ISO }],
    });
    const e = etatDe(id);
    noterEtat(id, { base: e.base === 'valide' ? 'a_relire' : e.base });
    return HttpResponse.json(avecEtat({ id }));
  }),

  http.post(api('/envoi/messages/:id/versions/:numero/valider'), async ({ params }) => {
    await delai();
    const id = String(params['id']);
    const m = demoDb.messagesTypes.trouver(id);
    const v = m?.versions.find((x) => x.numero === Number(params['numero']));
    if (!m || !v) return erreur(404, 'non_trouve', 'Cette version n’existe pas.');
    const bloquants = controlerContenu({ objet: v.objet, objet_b: v.objet_b ?? null, corps: v.corps, bloc_information: etatDe(id).bloc_information }).filter((c) => c.etat === 'bloquant');
    if (bloquants.length > 0) return erreur(422, 'controles_bloquants', `Corrigez d’abord : ${bloquants[0]!.libelle}.`);
    noterEtat(id, { base: 'valide', version_validee: v.numero });
    return HttpResponse.json(avecEtat({ id }));
  }),

  http.post(api('/envoi/messages/:id/archivage'), async ({ params, request }) => {
    await delai();
    const id = String(params['id']);
    if (!demoDb.messagesTypes.trouver(id)) return erreur(404, 'non_trouve', 'Ce message n’existe pas.');
    const { archive } = await lire<{ archive: boolean }>(request);
    noterEtat(id, { archive });
    return HttpResponse.json(avecEtat({ id }));
  }),
];
