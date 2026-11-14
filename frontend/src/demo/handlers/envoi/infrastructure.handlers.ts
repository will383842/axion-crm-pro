/**
 * Démo d'Envoi — boîtes, domaines (fiches 30 jours), serveurs et adresses IP,
 * configuration versionnée, chauffe. Contrat : `backend/openapi/envoi/infrastructure.yaml`.
 *
 * Données de la zone (générateur ci-dessous, graine `infrastructure`) : jours
 * d'activité, enregistrements DNS, listes noires, versions de configuration,
 * adresses IP en attente, réglages des boîtes. Les gestes (ajouter une boîte,
 * un domaine, une adresse IP, appliquer une version, faire avancer DMARC…)
 * modifient `demoDb` et survivent au rechargement (journal de session).
 *
 * Décisions du propriétaire : un seul serveur d'envoi au début, 150 envois par boîte et
 * par jour au plus en V0, aucun achat ni compte réel, pas de faux échanges de
 * chauffe, pas de BIMI. Adresses IP : plages de documentation seulement.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/infrastructure';
import type { components as communComponents } from '@/features/envoi/api/commun';
import { demoDb } from '../../base';
import type { Boite, Domaine } from '../../donnees/types';
import type { Alea } from '../../graine';
import { decalage, jourIso, maintenant } from '../../horloge';
import { api, delai, erreur, etatForce, repondreEtatForce } from '../../outils';

type JourActivite = components['schemas']['JourActivite'];
type EnregistrementDns = components['schemas']['EnregistrementDns'];
type ListeNoire = components['schemas']['ListeNoire'];
type VersionConfiguration = components['schemas']['VersionConfiguration'];
type Configuration = components['schemas']['Configuration'];
type ReglagesBoite = components['schemas']['ReglagesBoite'];
type SanteServeur = components['schemas']['SanteServeur'];
type PolitiqueDmarc = communComponents['schemas']['PolitiqueDmarc'];
type FluxEnvoi = communComponents['schemas']['FluxEnvoi'];

/** Plafond d'envois par boîte et par jour en V0 (décision du propriétaire). */
export const PLAFOND_BOITE = 150;
/** Garde anti-dispersion : trois domaines au plus par serveur d'envoi. */
const DOMAINES_PAR_SERVEUR = 3;
const ORDRE_DMARC: readonly PolitiqueDmarc[] = ['none', 'quarantine', 'reject'];
const JOURS_ENTRE_ETAPES_DMARC = 28;

// ── Données de la zone ─────────────────────────────────────────────────────

type RoleDns = EnregistrementDns['role'];
const ROLES_DNS: readonly RoleDns[] = ['spf', 'dkim', 'dmarc', 'ptr', 'mta_sts'];

interface LigneDns { id: string; domaine_id: string; role: RoleDns; etat: EnregistrementDns['etat'] }
interface LigneListe { id: string; domaine_id: string; liste: string; present: boolean; verifie_le: string }
interface LigneVersion extends VersionConfiguration { id: number }
interface LigneIp { id: string; serveur_id: string; adresse: string; flux: FluxEnvoi; domaine_id: string; version: number }
interface LigneReglages extends ReglagesBoite { id: string }
interface LigneExpiration { id: string; le: string }
interface LigneRecul { id: string; jour: string }
interface LigneChauffeIp { id: string; chauffe: NonNullable<Domaine['chauffe']> }
interface LigneTest { id: string; fait_le: string; etapes: Array<{ libelle: string; ok: boolean }> }

const LISTES_SURVEILLEES = ['Liste A (grand public)', 'Liste B (spécialisée)'] as const;

interface DonneesInfra {
  /** Activité sur 30 jours de chaque boîte (clé : identifiant de la boîte). */
  jours: Record<string, JourActivite[]>;
  dns: LigneDns[];
  listes: LigneListe[];
  versions: LigneVersion[];
  ips: LigneIp[];
  reglages: LigneReglages[];
  expirations: LigneExpiration[];
  tests: LigneTest[];
  /** Ressources déjà reculées, avec le jour du recul (un seul recul par jour). */
  reculs: LigneRecul[];
  /** Chauffe propre à chaque adresse IP (clé `ip:<adresse>`), indépendante de celle du domaine. */
  chauffes_ip: LigneChauffeIp[];
}

const ETAPES_TEST = ['Connexion au serveur', 'Envoi vers une boîte témoin interne', 'Réception côté boîte témoin', 'Signature DKIM vérifiée'];

function activite30Jours(alea: Alea, boite: Boite, joursActifs: number): JourActivite[] {
  const jours: JourActivite[] = [];
  for (let i = 29; i >= 0; i -= 1) {
    const date = jourIso(i);
    const semaine = new Date(`${date}T12:00:00Z`).getUTCDay();
    const ouvre = semaine !== 0 && semaine !== 6;
    const actif = i < joursActifs && ouvre;
    let envoyes = 0;
    if (actif) {
      envoyes = i === 0 ? boite.envoyes_aujourdhui : Math.max(1, Math.round(boite.quota_jour * (0.55 + alea.suivant() * 0.4)));
    }
    const refus = Math.round((envoyes * (boite.taux_refus ?? 0)) / 100);
    const durs = Math.round(refus * 0.4);
    const mous = refus - durs;
    const reponses = actif && alea.booleen(Math.min(0.9, 0.12 + boite.reponses_7j / 12)) ? alea.entier(0, 2) : 0;
    jours.push({
      jour: date,
      envoyes,
      remis: Math.max(0, envoyes - durs - mous),
      rebonds_durs: durs,
      rebonds_mous: mous,
      plaintes: 0,
      reponses,
    });
  }
  return jours;
}

function fabrique(alea: Alea): DonneesInfra {
  const jours: Record<string, JourActivite[]> = {};
  for (const b of demoDb.boites.tous()) {
    const joursActifs = b.statut === 'suspendue' ? 0 : (demoDb.domaines.trouver(b.domaine_id ?? '')?.chauffe?.jours_effectifs ?? 30);
    jours[b.id] = activite30Jours(alea, b, joursActifs);
  }

  const dns: LigneDns[] = [];
  for (const d of demoDb.domaines.tous()) {
    ROLES_DNS.forEach((role) => {
      const incomplet = d.id === 'dom-d' && role !== 'spf' && role !== 'dkim';
      dns.push({ id: `${d.id}|${role}`, domaine_id: d.id, role, etat: incomplet ? 'absent' : 'ok' });
    });
  }

  const listes: LigneListe[] = [];
  for (const d of demoDb.domaines.tous()) {
    for (const liste of LISTES_SURVEILLEES) {
      const present = d.id === 'dom-b' && liste === 'Liste B (spécialisée)';
      listes.push({ id: `${d.id}|${liste}`, domaine_id: d.id, liste, present, verifie_le: decalage({ heures: -1 }) });
    }
  }

  const versions: LigneVersion[] = [
    { id: 15, numero: 15, creee_le: decalage({ heures: -1 }), appliquee_le: null, par: null, resultat: 'prete', changements: [{ sorte: 'modification', libelle: 'Plafond de camille@prospection-b.example : 40 → 20 par jour (ralentie)' }] },
    { id: 14, numero: 14, creee_le: decalage({ jours: -15, heures: 0 }), appliquee_le: decalage({ jours: -15, heures: 0 }), par: 'Camille', resultat: 'appliquee', changements: [{ sorte: 'ajout', libelle: 'Domaine prospection-c.example ajouté au flux lettre' }] },
    { id: 13, numero: 13, creee_le: decalage({ jours: -23 }), appliquee_le: decalage({ jours: -23 }), par: 'Camille', resultat: 'remplacee', changements: [{ sorte: 'modification', libelle: 'Montée en douceur de paul@ et hugo@ : démarrage' }] },
    { id: 12, numero: 12, creee_le: decalage({ jours: -31 }), appliquee_le: decalage({ jours: -31 }), par: 'Système', resultat: 'retour_automatique', changements: [{ sorte: 'modification', libelle: 'Version défaillante : retour automatique à la version 11' }] },
  ];

  const reglages: LigneReglages[] = demoDb.boites.tous().map((b) => ({
    id: b.id,
    signature: `${b.nom_affiche.split(' · ')[0] ?? b.nom_affiche}\nAxion (démo)`,
    mode_absence: { actif: false, jusqu_au: null, message: null },
  }));

  const fins: Record<string, string> = { 'dom-a': '2027-09-15', 'dom-b': '2027-02-20', 'dom-c': '2027-12-02', 'dom-d': '2028-01-11' };
  const expirations: LigneExpiration[] = Object.entries(fins).map(([id, le]) => ({ id, le: `${le}T00:00:00.000Z` }));

  const tests: LigneTest[] = demoDb.serveurs.tous().map((s) => ({
    id: s.id,
    fait_le: decalage({ heures: -2, minutes: -25 }),
    etapes: ETAPES_TEST.map((libelle) => ({ libelle, ok: true })),
  }));

  return { jours, dns, listes, versions, ips: [], reglages, expirations, tests, reculs: [], chauffes_ip: [] };
}

function donnees(): DonneesInfra {
  return demoDb.etendre('infrastructure', fabrique);
}
const dnsDb = () => (donnees(), demoDb.zone<LigneDns>('infrastructure', 'dns'));
const listesDb = () => (donnees(), demoDb.zone<LigneListe>('infrastructure', 'listes'));
const versionsDb = () => (donnees(), demoDb.zone<LigneVersion>('infrastructure', 'versions'));
const ipsDb = () => (donnees(), demoDb.zone<LigneIp>('infrastructure', 'ips'));
const reglagesDb = () => (donnees(), demoDb.zone<LigneReglages>('infrastructure', 'reglages'));
const expirationsDb = () => (donnees(), demoDb.zone<LigneExpiration>('infrastructure', 'expirations'));
const chauffesIpDb = () => (donnees(), demoDb.zone<LigneChauffeIp>('infrastructure', 'chauffes_ip'));
const reculsDb = () => (donnees(), demoDb.zone<LigneRecul>('infrastructure', 'reculs'));
const testsDb = () => (donnees(), demoDb.zone<LigneTest>('infrastructure', 'tests'));

// ── Règles ─────────────────────────────────────────────────────────────────

/** Plages de documentation (RFC 5737 et RFC 3849) : les seules adresses acceptées en démo. */
export function estAdresseDeDocumentation(adresse: string): boolean {
  const v4 = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(adresse);
  if (v4) {
    const [a, b, c, d] = v4.slice(1).map(Number) as [number, number, number, number];
    if ([a, b, c, d].some((n) => n > 255)) return false;
    return (a === 192 && b === 0 && c === 2) || (a === 198 && b === 51 && c === 100) || (a === 203 && b === 0 && c === 113);
  }
  return /^2001:0?db8:/i.test(adresse) && /^[0-9a-f:]+$/i.test(adresse);
}

const REGEX_DOMAINE_DEMO = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)*\.example$/;

function slug(texte: string): string {
  return texte.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
}

function utilisateurCourant(): string {
  return demoDb.utilisateurs.tous()[0]?.name ?? 'Camille';
}

function ipsDuDomaine(domaineId: string): string[] {
  return demoDb.serveurs.tous().flatMap((s) => s.adresses_ip.filter((ip) => ip.domaine_id === domaineId).map((ip) => ip.adresse));
}

function lignesDns(domaine: Domaine): EnregistrementDns[] {
  const ip = ipsDuDomaine(domaine.id)[0] ?? '192.0.2.10';
  const valeurs: Record<RoleDns, Pick<EnregistrementDns, 'type' | 'nom' | 'valeur'>> = {
    spf: { type: 'TXT', nom: domaine.nom, valeur: `v=spf1 ip4:${ip} -all` },
    dkim: { type: 'TXT', nom: `axion._domainkey.${domaine.nom}`, valeur: 'v=DKIM1; k=rsa; p=CLE-PUBLIQUE-FACTICE-DE-DEMONSTRATION' },
    dmarc: { type: 'TXT', nom: `_dmarc.${domaine.nom}`, valeur: `v=DMARC1; p=${domaine.dmarc}; rua=mailto:rapports@${domaine.nom}` },
    ptr: { type: 'PTR', nom: ip, valeur: `mail.${domaine.nom}` },
    mta_sts: { type: 'TXT', nom: `_mta-sts.${domaine.nom}`, valeur: 'v=STSv1; id=20270112T093000' },
  };
  return dnsDb()
    .filtrer((l) => l.domaine_id === domaine.id)
    .map((l) => ({ role: l.role, etat: l.etat, ...valeurs[l.role] }));
}

const QUE_FAIRE =
  '1. Vérifiez que vos messages respectent les règles de prudence (Réglages). ' +
  '2. Réduisez de moitié le volume de ce domaine pendant 7 jours. ' +
  '3. Faites la demande de retrait avec le lien ci-dessous : la réponse prend en général de un à trois jours ouvrés. ' +
  '4. Ne réécrivez pas à la même liste d’adresses.';

function listesNoires(domaine: Domaine): ListeNoire[] {
  return listesDb()
    .filtrer((l) => l.domaine_id === domaine.id)
    .map((l) => ({
      liste: l.liste,
      element: domaine.nom,
      present: l.present,
      verifie_le: l.verifie_le,
      que_faire: l.present ? QUE_FAIRE : null,
      lien_retrait: l.present ? `https://retrait.example/${slug(l.liste)}` : null,
    }));
}

function sommeJours(listes: JourActivite[][]): JourActivite[] {
  const premier = listes[0];
  if (!premier) return [];
  return premier.map((j, i) => ({
    jour: j.jour,
    envoyes: listes.reduce((n, l) => n + (l[i]?.envoyes ?? 0), 0),
    remis: listes.reduce((n, l) => n + (l[i]?.remis ?? 0), 0),
    rebonds_durs: listes.reduce((n, l) => n + (l[i]?.rebonds_durs ?? 0), 0),
    rebonds_mous: listes.reduce((n, l) => n + (l[i]?.rebonds_mous ?? 0), 0),
    plaintes: listes.reduce((n, l) => n + (l[i]?.plaintes ?? 0), 0),
    reponses: listes.reduce((n, l) => n + (l[i]?.reponses ?? 0), 0),
  }));
}

type PalierChauffe = components['schemas']['PalierTable']['palier'];
type RessourceChauffe = components['schemas']['RessourceChauffe'];
type Goulot = components['schemas']['GoulotChauffe'];
type Termes = components['schemas']['TermesChauffe'];
type ChauffeDomaine = NonNullable<Domaine['chauffe']>;

/** Table des paliers de la montée en douceur (jours effectifs d'envoi et total quotidien du palier). */
export const PALIERS: ReadonlyArray<{ palier: PalierChauffe; jours_min: number; jours_max: number; total_jour: number }> = [
  { palier: 'P1', jours_min: 1, jours_max: 3, total_jour: 10 },
  { palier: 'P2', jours_min: 4, jours_max: 7, total_jour: 20 },
  { palier: 'P3', jours_min: 8, jours_max: 10, total_jour: 40 },
  { palier: 'P4', jours_min: 11, jours_max: 14, total_jour: 60 },
  { palier: 'P5', jours_min: 15, jours_max: 18, total_jour: 80 },
  { palier: 'P6', jours_min: 19, jours_max: 23, total_jour: 100 },
  { palier: 'P7', jours_min: 24, jours_max: 29, total_jour: 125 },
  { palier: 'P8', jours_min: 30, jours_max: 999, total_jour: 150 },
];
/** Plafond du serveur d'envoi et capacité à traiter les réponses, par jour (démo). */
const PLAFOND_SERVEUR = 400;
const CAPACITE_REPONSE = 300;

function ressource(id: string, type: 'ip' | 'domaine', libelle: string, c: ChauffeDomaine, envoyes: number, reculeAujourdhui: boolean): RessourceChauffe {
  const index = Math.max(0, PALIERS.findIndex((p) => p.palier === c.palier));
  const courant = PALIERS[index] ?? PALIERS[0]!;
  return {
    id,
    type,
    libelle,
    palier: c.palier,
    jours_effectifs: c.jours_effectifs,
    jours_avant_palier_suivant: index >= PALIERS.length - 1 ? null : Math.max(0, courant.jours_max - c.jours_effectifs + 1),
    total_palier: c.total_palier,
    envoyes_aujourdhui: envoyes,
    tenu_jusqu_au: c.tenu_jusqu_au,
    motif_tenue: c.tenu_jusqu_au ? 'manuel' : null,
    peut_reculer: index > 0 && !reculeAujourdhui,
  };
}

/** Chauffe d'une adresse IP : sa propre ligne, créée à la première lecture d'après le domaine (copie, jamais partagée). */
function chauffeIp(ip: { adresse: string; domaine_id: string; statut: string }): ChauffeDomaine | null {
  const id = `ip:${ip.adresse}`;
  const propre = chauffesIpDb().trouver(id);
  if (propre) return propre.chauffe;
  const depuisDomaine = demoDb.domaines.trouver(ip.domaine_id)?.chauffe;
  if (ip.statut !== 'en_chauffe' || !depuisDomaine) return null;
  chauffesIpDb().ajouter({ id, chauffe: { ...depuisDomaine } });
  return { ...depuisDomaine };
}

/** Ressources en chauffe : les domaines en montée et les adresses IP qui leur sont rattachées. */
function ressourcesChauffe(): RessourceChauffe[] {
  const reculs = new Set(reculsDb().filtrer((r) => r.jour === jourIso(0)).map((r) => r.id));
  const sorties: RessourceChauffe[] = [];
  for (const d of demoDb.domaines.tous()) {
    if (!d.chauffe) continue;
    const envoyes = demoDb.boites.filtrer((b) => b.domaine_id === d.id).reduce((n, b) => n + b.envoyes_aujourdhui, 0);
    sorties.push(ressource(`domaine:${d.id}`, 'domaine', d.nom, d.chauffe, envoyes, reculs.has(`domaine:${d.id}`)));
  }
  for (const serveur of demoDb.serveurs.tous()) {
    for (const ip of serveur.adresses_ip) {
      const c = chauffeIp(ip);
      if (!c) continue;
      sorties.push(ressource(`ip:${ip.adresse}`, 'ip', ip.adresse, c, ip.envoyes_aujourdhui, reculs.has(`ip:${ip.adresse}`)));
    }
  }
  return sorties;
}

function minimum(valeurs: number[]): number | null {
  return valeurs.length === 0 ? null : Math.min(...valeurs);
}

function budgetEtGoulot(ressources: RessourceChauffe[]): { budget: number; goulot: Goulot; termes: Termes } {
  const domaines = ressources.filter((r) => r.type === 'domaine');
  const ips = ressources.filter((r) => r.type === 'ip');
  const actives = demoDb.boites.filtrer((b) => b.statut !== 'suspendue');
  const termes: Termes = {
    courbe_ip: minimum(ips.map((r) => r.total_palier)),
    courbe_domaine: minimum(domaines.map((r) => r.total_palier)),
    plafond_boites: actives.reduce((n, b) => n + Math.min(PLAFOND_BOITE, b.quota_jour), 0),
    plafond_serveur: PLAFOND_SERVEUR,
    capacite_reponse: CAPACITE_REPONSE,
  };
  const ordre = ['courbe_ip', 'courbe_domaine', 'plafond_boites', 'plafond_serveur', 'capacite_reponse'] as const;
  let terme: (typeof ordre)[number] = 'capacite_reponse';
  let budget = Number.POSITIVE_INFINITY;
  for (const t of ordre) {
    const v = termes[t];
    if (v !== null && v < budget) {
      budget = v;
      terme = t;
    }
  }
  const origine = terme === 'courbe_ip' ? ips : terme === 'courbe_domaine' ? domaines : [];
  const cible = origine.find((r) => r.total_palier === budget) ?? null;
  const libelles: Record<(typeof ordre)[number], string> = {
    courbe_ip: 'La montée d’une adresse IP limite le volume du jour.',
    courbe_domaine: 'La montée d’un domaine limite le volume du jour.',
    plafond_boites: 'Le plafond des boîtes limite le volume du jour.',
    plafond_serveur: 'Le plafond du serveur limite le volume du jour.',
    capacite_reponse: 'La capacité à traiter les réponses limite le volume du jour.',
  };
  return { budget: Number.isFinite(budget) ? budget : 0, goulot: { terme, ressource_id: cible?.id ?? null, libelle: libelles[terme] }, termes };
}

function configuration(): Configuration {
  const toutes = [...versionsDb().tous()].sort((a, b) => b.numero - a.numero);
  const strip = ({ id, ...v }: LigneVersion): VersionConfiguration => (void id, v);
  const courante = toutes.find((v) => v.resultat === 'appliquee') ?? toutes.find((v) => v.appliquee_le !== null);
  const prete = toutes.find((v) => v.resultat === 'prete') ?? null;
  const historique = toutes.filter((v) => v.resultat !== 'prete').map(strip);
  return { courante: courante ? strip(courante) : (historique[0] as VersionConfiguration), prete: prete ? strip(prete) : null, historique };
}

function numeroSuivant(): number {
  return versionsDb().tous().reduce((m, v) => Math.max(m, v.numero), 0) + 1;
}

function appliquerLaVersionPrete(): Response | null {
  const prete = versionsDb().filtrer((v) => v.resultat === 'prete')[0];
  if (!prete) return erreur(409, 'rien_a_appliquer', 'Aucune version n’attend d’être appliquée.');
  for (const v of versionsDb().filtrer((x) => x.resultat === 'appliquee')) versionsDb().modifier(v.id, { resultat: 'remplacee' });
  versionsDb().modifier(prete.id, { resultat: 'appliquee', appliquee_le: maintenant().toISOString(), par: utilisateurCourant() });
  for (const ip of ipsDb().filtrer((i) => i.version === prete.numero)) {
    const serveur = demoDb.serveurs.trouver(ip.serveur_id);
    if (serveur && !serveur.adresses_ip.some((a) => a.adresse === ip.adresse)) {
      demoDb.serveurs.modifier(serveur.id, {
        adresses_ip: [...serveur.adresses_ip, { adresse: ip.adresse, flux: ip.flux, domaine_id: ip.domaine_id, statut: 'en_chauffe', envoyes_aujourdhui: 0 }],
      });
    }
  }
  for (const s of demoDb.serveurs.tous()) demoDb.serveurs.modifier(s.id, { version_configuration: prete.numero });
  return null;
}

// ── Handlers ───────────────────────────────────────────────────────────────

/** 404 pour l'état « vide » d'une fiche, état forcé sinon. */
async function etatDeFiche(request: Request): Promise<Response | null> {
  if (etatForce(request) === 'vide') return erreur(404, 'non_trouve', 'Cet élément n’existe pas ou n’a pas encore de données.');
  return repondreEtatForce(request, null);
}

export const handlers: HttpHandler[] = [
  // ── Boîtes ────────────────────────────────────────────────────────────────
  http.get(api('/envoi/boites'), async ({ request }) => {
    await delai();
    donnees();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: demoDb.boites.tous() });
  }),

  // Au palier 0, une boîte s'ajoute par commande locale : ni l'ajout ni le test de connexion ne passent par l'écran.
  http.post(api('/envoi/boites/test-connexion'), async () => {
    await delai();
    return erreur(501, 'non_disponible', 'Au palier 0, une boîte s’ajoute par commande locale sur le serveur.');
  }),

  http.post(api('/envoi/boites'), async () => {
    await delai();
    return erreur(501, 'non_disponible', 'Au palier 0, une boîte s’ajoute par commande locale sur le serveur.');
  }),

  http.get(api('/envoi/boites/:id'), async ({ request, params }) => {
    await delai();
    donnees();
    const force = await etatDeFiche(request);
    if (force) return force;
    const boite = demoDb.boites.trouver(String(params['id']));
    if (!boite) return erreur(404, 'non_trouve', 'Cette boîte n’existe pas.');
    const refus = demoDb.evenementsEnvoi
      .filtrer((e) => e.boite_id === boite.id && (e.type === 'rebond_dur' || e.type === 'rebond_mou'))
      .sort((a, b) => (a.survenu_le < b.survenu_le ? 1 : -1))
      .slice(0, 5);
    const reponses = demoDb.reponses
      .filtrer((r) => r.boite_id === boite.id)
      .sort((a, b) => (a.recue_le < b.recue_le ? 1 : -1))
      .slice(0, 5);
    const reglages = reglagesDb().trouver(boite.id);
    return HttpResponse.json({
      boite,
      jours: donnees().jours[boite.id] ?? [],
      derniers_refus: refus,
      reponses,
      reglages: reglages ? { signature: reglages.signature, mode_absence: reglages.mode_absence } : { signature: '', mode_absence: { actif: false, jusqu_au: null, message: null } },
    });
  }),

  http.put(api('/envoi/boites/:id/reglages'), async ({ request, params }) => {
    await delai();
    donnees();
    const id = String(params['id']);
    if (!demoDb.boites.trouver(id)) return erreur(404, 'non_trouve', 'Cette boîte n’existe pas.');
    const corps = (await request.json()) as ReglagesBoite;
    const nouveau: ReglagesBoite = { signature: corps.signature ?? '', mode_absence: corps.mode_absence };
    if (reglagesDb().trouver(id)) reglagesDb().modifier(id, nouveau);
    else reglagesDb().ajouter({ id, ...nouveau });
    return HttpResponse.json(nouveau);
  }),

  http.post(api('/envoi/boites/:id/suspendre'), async ({ params }) => {
    await delai();
    const boite = demoDb.boites.modifier(String(params['id']), { statut: 'suspendue', etat: 'gris' });
    return boite ? HttpResponse.json(boite) : erreur(404, 'non_trouve', 'Cette boîte n’existe pas.');
  }),

  http.post(api('/envoi/boites/:id/reactiver'), async ({ params }) => {
    await delai();
    const actuelle = demoDb.boites.trouver(String(params['id']));
    if (!actuelle) return erreur(404, 'non_trouve', 'Cette boîte n’existe pas.');
    if (demoDb.etatEnvois.lire().etat === 'arrete' || actuelle.etat === 'rouge') {
      return erreur(409, 'pause_active', 'Une pause de frein ou une pause automatique est active : la boîte ne peut pas être réactivée maintenant.');
    }
    const enChauffe = Boolean(demoDb.domaines.trouver(actuelle.domaine_id ?? '')?.chauffe);
    const boite = demoDb.boites.modifier(actuelle.id, {
      statut: enChauffe ? 'en_chauffe' : 'prete',
      etat: enChauffe ? 'bleu' : 'vert',
      quota_jour: actuelle.quota_jour > 0 ? actuelle.quota_jour : 40,
    });
    return HttpResponse.json(boite);
  }),

  // ── Domaines ──────────────────────────────────────────────────────────────
  http.get(api('/envoi/domaines'), async ({ request }) => {
    await delai();
    donnees();
    const garde = { utilises: demoDb.domaines.compter(), limite: DOMAINES_PAR_SERVEUR * Math.max(1, demoDb.serveurs.compter()) };
    const force = await repondreEtatForce(request, { data: [], garde_dispersion: { utilises: 0, limite: garde.limite }, expirations: {} });
    if (force) return force;
    return HttpResponse.json({
      data: demoDb.domaines.tous(),
      garde_dispersion: garde,
      expirations: Object.fromEntries(expirationsDb().tous().map((e) => [e.id, e.le])),
    });
  }),

  http.post(api('/envoi/domaines'), async ({ request }) => {
    await delai();
    donnees();
    const corps = (await request.json().catch(() => ({}))) as { nom?: string };
    const nom = (corps.nom ?? '').trim().toLowerCase();
    if (!REGEX_DOMAINE_DEMO.test(nom)) return erreur(422, 'validation', 'En démonstration, seuls les domaines finissant par « .example » sont acceptés.');
    if (demoDb.domaines.filtrer((d) => d.nom === nom).length > 0) return erreur(422, 'validation', 'Ce domaine est déjà dans la liste.');
    const limite = DOMAINES_PAR_SERVEUR * Math.max(1, demoDb.serveurs.compter());
    if (demoDb.domaines.compter() >= limite) {
      return erreur(422, 'garde_dispersion', `Vous avez déjà ${demoDb.domaines.compter()} domaines pour ${demoDb.serveurs.compter()} serveur(s) d’envoi : ${limite} au plus. Ajoutez d’abord un serveur, ou gardez vos domaines actuels : trop de domaines dilue la réputation.`);
    }
    const domaine = demoDb.domaines.ajouter({
      id: `dom-${slug(nom.replace(/\.example$/, ''))}`,
      nom,
      etat: 'gris',
      boites: 0,
      dmarc: 'none',
      dmarc_prochaine_etape: null,
      listes_noires: 0,
      ajoute_le: maintenant().toISOString(),
      chauffe: null,
    } satisfies Domaine);
    for (const role of ROLES_DNS) dnsDb().ajouter({ id: `${domaine.id}|${role}`, domaine_id: domaine.id, role, etat: 'absent' });
    for (const liste of LISTES_SURVEILLEES) {
      listesDb().ajouter({ id: `${domaine.id}|${liste}`, domaine_id: domaine.id, liste, present: false, verifie_le: maintenant().toISOString() });
    }
    expirationsDb().ajouter({ id: domaine.id, le: decalage({ jours: 365 }) });
    return HttpResponse.json({ domaine, dns: lignesDns(domaine) }, { status: 201 });
  }),

  http.get(api('/envoi/domaines/:id'), async ({ request, params }) => {
    await delai();
    donnees();
    const force = await etatDeFiche(request);
    if (force) return force;
    const domaine = demoDb.domaines.trouver(String(params['id']));
    if (!domaine) return erreur(404, 'non_trouve', 'Ce domaine n’existe pas.');
    const boites = demoDb.boites.filtrer((b) => b.domaine_id === domaine.id);
    const jours = sommeJours(boites.map((b) => donnees().jours[b.id] ?? []));
    return HttpResponse.json({
      domaine,
      dns: lignesDns(domaine),
      jours: jours.length > 0 ? jours : activiteVide(),
      listes_noires: listesNoires(domaine),
      expire_le: expirationsDb().trouver(domaine.id)?.le ?? decalage({ jours: 365 }),
    });
  }),

  http.post(api('/envoi/domaines/:id/dns/verifier'), async ({ params }) => {
    await delai();
    donnees();
    const domaine = demoDb.domaines.trouver(String(params['id']));
    if (!domaine) return erreur(404, 'non_trouve', 'Ce domaine n’existe pas.');
    for (const l of dnsDb().filtrer((x) => x.domaine_id === domaine.id && x.etat !== 'ok')) dnsDb().modifier(l.id, { etat: 'ok' });
    if (domaine.etat === 'gris') demoDb.domaines.modifier(domaine.id, { etat: 'bleu' });
    return HttpResponse.json({ dns: lignesDns(domaine) });
  }),

  http.post(api('/envoi/domaines/:id/dmarc'), async ({ request, params }) => {
    await delai();
    const domaine = demoDb.domaines.trouver(String(params['id']));
    if (!domaine) return erreur(404, 'non_trouve', 'Ce domaine n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { politique?: PolitiqueDmarc; rappel_le?: string };
    const cible = corps.politique;
    if (!cible || !ORDRE_DMARC.includes(cible)) return erreur(422, 'validation', 'Politique DMARC inconnue.');
    if (corps.rappel_le) {
      const suivante = domaine.dmarc_prochaine_etape?.politique ?? cible;
      return HttpResponse.json(demoDb.domaines.modifier(domaine.id, { dmarc_prochaine_etape: { politique: suivante, le: corps.rappel_le } }));
    }
    if (ORDRE_DMARC.indexOf(cible) <= ORDRE_DMARC.indexOf(domaine.dmarc ?? 'none')) {
      return erreur(422, 'validation', 'Le DMARC ne peut qu’avancer : none, puis quarantine, puis reject.');
    }
    const suivante = ORDRE_DMARC[ORDRE_DMARC.indexOf(cible) + 1];
    return HttpResponse.json(
      demoDb.domaines.modifier(domaine.id, {
        dmarc: cible,
        dmarc_prochaine_etape: suivante ? { politique: suivante, le: decalage({ jours: JOURS_ENTRE_ETAPES_DMARC }) } : null,
      }),
    );
  }),

  // ── Serveurs et adresses IP ───────────────────────────────────────────────
  http.get(api('/envoi/serveurs'), async ({ request }) => {
    await delai();
    donnees();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    return HttpResponse.json({ data: demoDb.serveurs.tous() });
  }),

  http.get(api('/envoi/serveurs/:id/sante'), async ({ params }) => {
    await delai();
    const sante = santeDuServeur(String(params['id']));
    return sante ? HttpResponse.json(sante) : erreur(404, 'non_trouve', 'Ce serveur n’existe pas.');
  }),

  http.post(api('/envoi/serveurs/:id/test'), async ({ params }) => {
    await delai();
    donnees();
    const id = String(params['id']);
    if (!demoDb.serveurs.trouver(id)) return erreur(404, 'non_trouve', 'Ce serveur n’existe pas.');
    testsDb().modifier(id, { fait_le: maintenant().toISOString(), etapes: ETAPES_TEST.map((libelle) => ({ libelle, ok: true })) });
    return HttpResponse.json(santeDuServeur(id));
  }),

  http.post(api('/envoi/serveurs/:id/adresses-ip'), async ({ request, params }) => {
    await delai();
    donnees();
    const serveur = demoDb.serveurs.trouver(String(params['id']));
    if (!serveur) return erreur(404, 'non_trouve', 'Ce serveur n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { adresse?: string; flux?: FluxEnvoi; domaine_id?: string };
    const adresse = (corps.adresse ?? '').trim();
    if (!estAdresseDeDocumentation(adresse)) {
      return erreur(422, 'validation', 'En démonstration, seules les adresses des plages de documentation sont acceptées : 192.0.2.0/24, 198.51.100.0/24, 203.0.113.0/24 ou 2001:db8::/32.');
    }
    const dejaLa =
      demoDb.serveurs.tous().some((s) => s.adresses_ip.some((a) => a.adresse === adresse)) || ipsDb().tous().some((i) => i.adresse === adresse);
    if (dejaLa) return erreur(422, 'validation', 'Cette adresse IP est déjà dans la configuration.');
    const domaine = corps.domaine_id ? demoDb.domaines.trouver(corps.domaine_id) : undefined;
    if (!domaine) return erreur(422, 'validation', 'Choisissez le domaine qui enverra par cette adresse.');
    const flux: FluxEnvoi = corps.flux === 'lettre' ? 'lettre' : 'prospection';

    let prete = versionsDb().filtrer((v) => v.resultat === 'prete')[0];
    if (!prete) {
      const numero = numeroSuivant();
      prete = versionsDb().ajouter({ id: numero, numero, creee_le: maintenant().toISOString(), appliquee_le: null, par: null, resultat: 'prete', changements: [] });
    }
    versionsDb().modifier(prete.id, {
      changements: [...prete.changements, { sorte: 'ajout', libelle: `Adresse IP ${adresse} · flux ${flux} · domaine ${domaine.nom}` }],
    });
    ipsDb().ajouter({ id: `ip-${adresse}`, serveur_id: serveur.id, adresse, flux, domaine_id: domaine.id, version: prete.numero });
    return HttpResponse.json(configuration());
  }),

  // ── Configuration versionnée ──────────────────────────────────────────────
  http.get(api('/envoi/configuration'), async ({ request }) => {
    await delai();
    donnees();
    const force = await repondreEtatForce(request, { courante: null, prete: null, historique: [] });
    if (force) return force;
    return HttpResponse.json(configuration());
  }),

  http.post(api('/envoi/configuration/appliquer'), async () => {
    await delai();
    donnees();
    const refus = appliquerLaVersionPrete();
    return refus ?? HttpResponse.json(configuration());
  }),

  http.post(api('/envoi/configuration/versions/:numero/revenir'), async ({ params }) => {
    await delai();
    donnees();
    const numero = Number(params['numero']);
    const cible = versionsDb().trouver(numero);
    if (!cible || cible.appliquee_le === null) return erreur(404, 'non_trouve', 'Cette version n’existe pas ou n’a jamais été appliquée.');
    for (const v of versionsDb().filtrer((x) => x.resultat === 'appliquee')) versionsDb().modifier(v.id, { resultat: 'remplacee' });
    const nouveau = numeroSuivant();
    versionsDb().ajouter({
      id: nouveau,
      numero: nouveau,
      creee_le: maintenant().toISOString(),
      appliquee_le: maintenant().toISOString(),
      par: utilisateurCourant(),
      resultat: 'appliquee',
      changements: [{ sorte: 'modification', libelle: `Retour à la version ${numero}` }],
    });
    for (const s of demoDb.serveurs.tous()) demoDb.serveurs.modifier(s.id, { version_configuration: nouveau });
    return HttpResponse.json(configuration());
  }),

  // ── Chauffe ───────────────────────────────────────────────────────────────
  http.get(api('/envoi/chauffe'), async ({ request }) => {
    await delai();
    donnees();
    const force = await repondreEtatForce(request, {
      jour: jourIso(0),
      budget_du_jour: 0,
      plafond_boite: PLAFOND_BOITE,
      goulot: { terme: 'capacite_reponse', ressource_id: null, libelle: 'Aucune ressource en chauffe.' },
      termes: { courbe_ip: null, courbe_domaine: null, plafond_boites: null, plafond_serveur: null, capacite_reponse: null },
      ressources: [],
      paliers: [],
    });
    if (force) return force;
    const ressources = ressourcesChauffe();
    const { budget, goulot, termes } = budgetEtGoulot(ressources);
    return HttpResponse.json({
      jour: jourIso(0),
      budget_du_jour: budget,
      plafond_boite: PLAFOND_BOITE,
      goulot,
      termes,
      ressources,
      paliers: PALIERS,
    });
  }),

  http.post(api('/envoi/chauffe/:id/reculer'), async ({ params }) => {
    await delai();
    const id = String(params['id']);
    const courante = ressourcesChauffe().find((r) => r.id === id);
    if (!courante) return erreur(404, 'non_trouve', 'Cette ressource n’est pas en chauffe.');
    const index = PALIERS.findIndex((p) => p.palier === courante.palier);
    if (index <= 0) return erreur(409, 'deja_au_premier_palier', 'Cette ressource est déjà au premier palier : il n’y a rien à reculer.');
    // Un second recul le même jour ne recule pas deux fois : la ressource est rendue telle quelle.
    if (!courante.peut_reculer) return HttpResponse.json(courante);
    const precedent = PALIERS[index - 1]!;
    const chauffe = { palier: precedent.palier, jours_effectifs: precedent.jours_min, total_palier: precedent.total_jour, tenu_jusqu_au: null };
    // Chaque ressource a sa propre donnée : reculer une IP ne touche jamais le domaine de même nom, et inversement.
    if (id.startsWith('domaine:')) demoDb.domaines.modifier(id.slice('domaine:'.length), { chauffe });
    else chauffesIpDb().modifier(id, { chauffe });
    // Le verrou « un recul par jour » est par ressource (type + identifiant, déjà dans `id`).
    reculsDb().ajouter({ id, jour: jourIso(0) });
    const apres = ressourcesChauffe().find((r) => r.id === id);
    return HttpResponse.json(apres ?? courante);
  }),

  // ── Listes noires ─────────────────────────────────────────────────────────
  http.get(api('/envoi/listes-noires'), async ({ request }) => {
    await delai();
    donnees();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const domaines = demoDb.domaines.tous().flatMap((d) => listesNoires(d));
    const ips = demoDb.serveurs.tous().flatMap((s) =>
      s.adresses_ip.flatMap((ip) =>
        LISTES_SURVEILLEES.map((liste) => ({ liste, element: ip.adresse, present: false, verifie_le: decalage({ heures: -1 }), que_faire: null, lien_retrait: null })),
      ),
    );
    return HttpResponse.json({ data: [...domaines, ...ips] });
  }),
];

function activiteVide(): JourActivite[] {
  return Array.from({ length: 30 }, (_, i) => ({ jour: jourIso(29 - i), envoyes: 0, remis: 0, rebonds_durs: 0, rebonds_mous: 0, plaintes: 0, reponses: 0 }));
}

function santeDuServeur(id: string): SanteServeur | null {
  donnees();
  const serveur = demoDb.serveurs.trouver(id);
  if (!serveur) return null;
  const test = testsDb().trouver(id);
  const domainesDuServeur = new Set(serveur.adresses_ip.map((ip) => ip.domaine_id));
  const enFile = demoDb.campagnes
    .filtrer((c) => c.statut === 'en_cours')
    .filter((c) => c.boites.some((b) => domainesDuServeur.has(demoDb.boites.trouver(b)?.domaine_id ?? '')))
    .reduce((n, c) => n + (c.total - c.envoyes), 0);
  const paliers = serveur.adresses_ip
    .filter((ip) => ip.statut === 'en_chauffe')
    .map((ip) => {
      const chauffe = demoDb.domaines.trouver(ip.domaine_id)?.chauffe ?? { palier: 'P1' as const, jours_effectifs: 1, total_palier: PALIERS[0]!.total_jour, tenu_jusqu_au: null };
      return { adresse: ip.adresse, jour: chauffe.jours_effectifs, sur: 30, plafond_aujourdhui: chauffe.total_palier };
    });
  return {
    serveur_id: id,
    en_ligne_depuis_jours: id === 'srv-1' ? 41 : 12,
    en_file: enFile,
    test: { fait_le: test?.fait_le ?? decalage({ heures: -2 }), etapes: test?.etapes ?? ETAPES_TEST.map((libelle) => ({ libelle, ok: true })) },
    paliers,
  };
}
