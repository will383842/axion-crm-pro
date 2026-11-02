/**
 * Faux serveur des contacts (DM1-CON) : liste « Contacts », fiche d'une
 * personne, ajout rapide, import, modifier, retirer (corbeille), export.
 *
 * Rien ne se supprime : « retirer » pose `retire_le`, « restaurer » le lève.
 * Aucune route DELETE n'existe pour une personne.
 *
 * Le même tableau `handlers` sert à la démo et aux tests Vitest.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { cleDePersonne, genererContactsDemo, idDeCle, PREMIER_ID_ORGANISATION_AJOUTEE, type EntreeHistorique, type Organisation } from '../../donnees/crm/contacts';
import { maintenant } from '../../horloge';
import { api, delai, erreur, paginer, repondreEtatForce } from '../../outils';
import type { Destinataire } from '../../donnees/types';
import type {
  AjoutReponse,
  BilanImport,
  CorbeilleLigne,
  DoublonPropose,
  DroitEcrire,
  EnvoiFiche,
  EtatEnvoi,
  FicheContact,
  FicheDetail,
  LigneImport,
  RapportImport,
  TravailImport,
} from '@/features/contacts/ajout-rapide/types';
import { LIFECYCLE_LABELS, RELATION_TYPES, type CountsResponse, type HubCompany, type LifecycleStage, type TimelineEntry, type TimelineResponse } from '@/features/crm-console/types';

// ── Zone de la démo ───────────────────────────────────────────────────────

/** Branche la zone `contacts` sur `demoDb` (une seule fois ; les gestes de session sont rejoués). */
function zone(): void {
  demoDb.etendre('contacts', (alea) => genererContactsDemo(alea, demoDb.contacts.tous(), demoDb.entreprises.tous()));
}
zone();

const fiches = () => demoDb.zone<FicheContact>('contacts', 'fiches');
const organisations = () => demoDb.zone<Organisation>('contacts', 'organisations');
const historique = () => demoDb.zone<EntreeHistorique>('contacts', 'historique');
const imports = () => demoDb.zone<{ id: number; nom_fichier: string; le: string; rapport: RapportImport }>('contacts', 'imports');

const RE_EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const normaliser = (t: string): string => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
const nomComplet = (f: Pick<FicheContact, 'first_name' | 'last_name'>): string => `${f.first_name} ${f.last_name}`.trim();

// ── Organisations (celles du jeu de base et celles ajoutées en démo) ──────

interface Orga {
  id: number;
  denomination: string;
  city_name: string;
  relation_type: HubCompany['relation_type'];
  lifecycle_stage: LifecycleStage;
}

function toutesLesOrganisations(): Orga[] {
  const parId = new Map<number, FicheContact[]>();
  for (const f of fiches().tous()) {
    const l = parId.get(f.company_id) ?? [];
    l.push(f);
    parId.set(f.company_id, l);
  }
  const base = demoDb.entreprises.tous().map((e): Orga => {
    const premiere = parId.get(e.id)?.[0];
    return {
      id: e.id,
      denomination: e.denomination,
      city_name: e.city,
      relation_type: premiere?.relation_type ?? 'prospect',
      lifecycle_stage: premiere?.lifecycle_stage ?? 'nouveau',
    };
  });
  const ajoutees = organisations().tous().map((o): Orga => ({ ...o }));
  return [...base, ...ajoutees];
}

function trouverOuCreerOrganisation(nom: string): { id: number; denomination: string } {
  const cherche = normaliser(nom);
  const existante = toutesLesOrganisations().find((o) => normaliser(o.denomination) === cherche);
  if (existante) return existante;
  const id = Math.max(PREMIER_ID_ORGANISATION_AJOUTEE, ...organisations().tous().map((o) => o.id + 1));
  const nouvelle: Organisation = { id, denomination: nom.trim(), city_name: 'Lyon', relation_type: 'prospect', lifecycle_stage: 'nouveau' };
  organisations().ajouter(nouvelle);
  return nouvelle;
}

// ── Liste « Contacts » ────────────────────────────────────────────────────

function companyDeLaListe(o: Orga, contacts: FicheContact[]): HubCompany {
  const e = demoDb.entreprises.trouver(o.id);
  return {
    id: o.id,
    siren: e?.siren ?? null,
    denomination: o.denomination,
    relation_type: o.relation_type,
    lifecycle_stage: o.lifecycle_stage,
    legal_basis: 'legitimate_interest_b2b',
    city_name: o.city_name,
    department_code: e?.department_code ?? '69',
    size_category: e?.size_category ?? null,
    email_generic: e?.email_generic ?? null,
    updated_at: e?.enriched_at ?? e?.created_at ?? null,
    tags: e?.etiquettes ?? [],
    contacts: contacts.map((c) => ({
      id: c.id,
      first_name: c.first_name,
      last_name: c.last_name,
      email: c.email,
      phone: null,
      person_key: c.person_key,
    })),
  };
}

/** Organisations visibles : au moins une personne qui n'est pas dans la corbeille. */
function organisationsVisibles(): Array<{ orga: Orga; contacts: FicheContact[] }> {
  const parOrga = new Map<number, FicheContact[]>();
  for (const f of fiches().tous()) {
    if (f.retire_le !== null) continue;
    const l = parOrga.get(f.company_id) ?? [];
    l.push(f);
    parOrga.set(f.company_id, l);
  }
  return toutesLesOrganisations()
    .filter((o) => parOrga.has(o.id))
    .map((orga) => ({ orga, contacts: parOrga.get(orga.id)! }));
}

const FROIDS: ReadonlySet<LifecycleStage> = new Set(['dormant', 'perdu']);

export function listeHub(url: URL): HubCompany[] {
  const temperature = url.searchParams.get('temperature') ?? 'actifs';
  const type = url.searchParams.get('relation_type');
  const q = normaliser(url.searchParams.get('q') ?? '');
  const pays = url.searchParams.get('filter[country_code]') ?? '';
  const prospection = url.searchParams.get('filter[prospection_status]') ?? '';
  if (pays !== '' && pays !== 'FR') return [];
  return organisationsVisibles()
    .filter(({ orga }) => (temperature === 'tous' ? true : temperature === 'froids' ? FROIDS.has(orga.lifecycle_stage) : !FROIDS.has(orga.lifecycle_stage)))
    .filter(({ orga }) => type === null || orga.relation_type === type)
    .filter(({ orga, contacts }) => {
      if (q === '') return true;
      const e = demoDb.entreprises.trouver(orga.id);
      return (
        normaliser(orga.denomination).includes(q) ||
        (e?.siren ?? '').includes(q) ||
        contacts.some((c) => normaliser(`${nomComplet(c)} ${c.email}`).includes(q))
      );
    })
    .filter(({ orga }) => {
      if (prospection === '') return true;
      const e = demoDb.entreprises.trouver(orga.id);
      const enrichie = e?.enriched_at != null;
      if (prospection === 'ready_for_outreach') return enrichie;
      if (prospection === 'pending') return !enrichie;
      return false;
    })
    .map(({ orga, contacts }) => companyDeLaListe(orga, contacts));
}

function compteursHub(): CountsResponse {
  const visibles = organisationsVisibles();
  const parType: Record<string, number> = {};
  const parEtape: Record<string, number> = {};
  for (const { orga } of visibles) {
    parType[orga.relation_type] = (parType[orga.relation_type] ?? 0) + 1;
    parEtape[orga.lifecycle_stage] = (parEtape[orga.lifecycle_stage] ?? 0) + 1;
  }
  for (const t of RELATION_TYPES) parType[t] ??= 0;
  return { total: visibles.length, by_relation_type: parType, by_lifecycle_stage: parEtape, computed_at: maintenant().toISOString(), fresh_for_seconds: 60 };
}

// ── Fiche d'une personne ──────────────────────────────────────────────────

function fiche(cle: string): FicheContact | undefined {
  const id = idDeCle(cle);
  return id === null ? undefined : fiches().trouver(id);
}

function destinatairesDe(f: FicheContact): Destinataire[] {
  return demoDb.destinataires.filtrer((d) => d.contact_id === f.id || normaliser(d.adresse) === normaliser(f.email));
}

function droitEcrire(f: FicheContact, ds: Destinataire[]): DroitEcrire {
  if (f.retire_le !== null) return { verdict: 'non', raison: 'Cette fiche est dans la corbeille : restaurez-la d’abord.' };
  if (f.ne_plus_ecrire) return { verdict: 'non', raison: 'Marquée « ne plus écrire »' };
  const adresse = normaliser(f.email);
  const opposition = demoDb.opposition.filtrer((o) => normaliser(o.valeur) === adresse)[0];
  if (opposition) return { verdict: 'non', raison: `Registre d’opposition : ${opposition.motif}` };
  if (demoDb.desinscriptions.filtrer((d) => normaliser(d.adresse) === adresse).length > 0) {
    return { verdict: 'non', raison: 'Désinscrite : ne plus lui écrire' };
  }
  const dur = ds.find((d) => d.etat === 'rebond_dur');
  if (dur) return { verdict: 'non', raison: `${dur.motif ?? 'Adresse refusée'} : l’adresse ne fonctionne pas` };
  if (ds.some((d) => d.etat === 'rebond_mou')) return { verdict: 'prudence', raison: 'Un message a rebondi récemment : à surveiller' };
  return { verdict: 'oui', raison: `Aucune opposition · adresse vérifiée il y a ${f.verifie_il_y_a_jours} jours` };
}

function etatEnvoi(d: Destinataire, aRepondu: boolean): { etat: EtatEnvoi; raison: string } {
  if (aRepondu || d.etat === 'a_repondu') return { etat: 'repondu', raison: 'A répondu' };
  switch (d.etat) {
    case 'a_envoyer':
      return { etat: 'en_file', raison: 'Prévu dans la prochaine fenêtre d’envoi' };
    case 'rebond_dur':
      return { etat: 'refuse', raison: d.motif ?? 'Refusé par le serveur du destinataire' };
    case 'rebond_mou':
      return { etat: 'parti', raison: `${d.motif ?? 'Refus temporaire'} : nouvel essai prévu` };
    case 'relances_annulees':
      return { etat: 'annule', raison: 'A répondu : séquence arrêtée' };
    case 'desinscrit':
      return { etat: 'annule', raison: 'Désinscrite : séquence arrêtée' };
    case 'plainte':
    case 'exclu':
      return { etat: 'refuse', raison: 'Exclue des envois' };
    default:
      return { etat: 'parti', raison: 'Accepté par le serveur (250)' };
  }
}

function envoisDe(ds: Destinataire[]): EnvoiFiche[] {
  const reponses = demoDb.reponses.tous();
  return ds.map((d) => {
    const nom = demoDb.campagnes.trouver(d.campagne_id)?.nom ?? 'Campagne';
    const { etat, raison } = etatEnvoi(d, reponses.some((r) => r.destinataire_id === d.id));
    return { id: String(d.id), message: `${nom} · message ${Math.max(1, d.etape)}`, etat, raison, le: d.dernier_evenement_le };
  });
}

function chronologie(f: FicheContact, ds: Destinataire[]): TimelineEntry[] {
  const entrees: Array<Omit<TimelineEntry, 'id'>> = [];
  const base = { universe: 'business' as const, subject_type: 'contact', subject_id: f.id };
  for (const h of historique().filtrer((x) => x.contact_id === f.id)) {
    entrees.push({ ...base, kind: h.kind, title: h.title, occurred_at: h.occurred_at, external_ref: null });
  }
  for (const d of ds) {
    const camp = demoDb.campagnes.trouver(d.campagne_id)?.nom ?? null;
    for (const e of demoDb.evenementsEnvoi.filtrer((x) => x.destinataire_id === d.id)) {
      if (e.type === 'remis') entrees.push({ ...base, kind: 'message_envoi', title: 'Message parti (Envoi)', occurred_at: e.survenu_le, external_ref: camp });
      else if (e.type === 'rebond_dur' || e.type === 'rebond_mou') {
        entrees.push({ ...base, kind: 'message_refuse', title: `Message refusé : ${e.libelle ?? 'refus du serveur'}`, occurred_at: e.survenu_le, external_ref: camp });
      }
    }
    for (const r of demoDb.reponses.filtrer((x) => x.destinataire_id === d.id)) {
      entrees.push({ ...base, kind: 'reponse', title: `Réponse : « ${r.extrait} »`, occurred_at: r.recue_le, external_ref: 'Réponses' });
    }
  }
  entrees.sort((a, b) => String(b.occurred_at).localeCompare(String(a.occurred_at)));
  return entrees.map((e, i) => ({ id: i + 1, ...e }));
}

function detail(f: FicheContact, ds: Destinataire[]): FicheDetail {
  return {
    contact_id: f.id,
    first_name: f.first_name,
    last_name: f.last_name,
    email: f.email,
    fonction: f.fonction,
    organisation: f.organisation,
    etape: f.lifecycle_stage,
    provenance: f.provenance,
    retire_le: f.retire_le,
    droit_ecrire: droitEcrire(f, ds),
    envois: envoisDe(ds),
    rappel_le: f.rappel_le,
    rappel_note: f.rappel_note,
  };
}

function reponseFiche(f: FicheContact): TimelineResponse & { fiche: FicheDetail } {
  const ds = destinatairesDe(f);
  return {
    person_key: f.person_key,
    universes: { business: { accessible: true, exists: true }, vivier: { accessible: false, exists: false } },
    subjects: [
      {
        universe: 'business',
        type: 'contact',
        id: f.id,
        first_name: f.first_name,
        last_name: f.last_name,
        email: f.email,
        company: { id: f.company_id, denomination: f.organisation, siren: demoDb.entreprises.trouver(f.company_id)?.siren ?? null },
      },
    ],
    data: chronologie(f, ds),
    fiche: detail(f, ds),
  };
}

// ── Doublons ──────────────────────────────────────────────────────────────

export function chercherDoublons(c: { first_name?: string; last_name?: string; email?: string; organisation?: string }): DoublonPropose[] {
  const email = normaliser(c.email ?? '');
  const prenom = normaliser(c.first_name ?? '');
  const nom = normaliser(c.last_name ?? '');
  const orga = normaliser(c.organisation ?? '');
  const trouves: DoublonPropose[] = [];
  for (const f of fiches().tous()) {
    if (f.retire_le !== null) continue;
    const memeEmail = email !== '' && normaliser(f.email) === email;
    const memeNom = prenom !== '' && nom !== '' && normaliser(f.first_name) === prenom && normaliser(f.last_name) === nom && (orga === '' || normaliser(f.organisation) === orga);
    if (!memeEmail && !memeNom) continue;
    trouves.push({ id: f.id, person_key: f.person_key, nom_complet: nomComplet(f), email: f.email, organisation: f.organisation, raison: memeEmail ? 'meme_email' : 'meme_nom' });
    if (trouves.length >= 3) break;
  }
  return trouves;
}

// ── Création d'une fiche ──────────────────────────────────────────────────

interface Saisie {
  first_name: string;
  last_name: string;
  email: string;
  organisation: string;
  fonction?: string;
  provenance: string;
}

function creerFiche(s: Saisie): FicheContact {
  const orga = trouverOuCreerOrganisation(s.organisation || 'Sans organisation');
  const meme = fiches().filtrer((x) => x.company_id === orga.id)[0];
  const id = fiches().prochainId();
  const o = toutesLesOrganisations().find((x) => x.id === orga.id);
  return fiches().ajouter({
    id,
    company_id: orga.id,
    first_name: s.first_name.trim(),
    last_name: s.last_name.trim(),
    email: s.email.trim(),
    fonction: (s.fonction ?? '').trim(),
    organisation: orga.denomination,
    relation_type: meme?.relation_type ?? o?.relation_type ?? 'prospect',
    lifecycle_stage: meme?.lifecycle_stage ?? o?.lifecycle_stage ?? 'nouveau',
    provenance: s.provenance,
    person_key: cleDePersonne(id),
    retire_le: null,
    ne_plus_ecrire: false,
    verifie_il_y_a_jours: 0,
    rappel_le: null,
    rappel_note: null,
    created_at: maintenant().toISOString(),
  });
}

function erreurValidation(details: Record<string, string[]>): Response {
  return HttpResponse.json({ error: 'validation', message: 'Certaines informations sont à corriger.', details }, { status: 422 });
}

function validerSaisie(c: Partial<Saisie>): Record<string, string[]> {
  const e: Record<string, string[]> = {};
  if (!c.last_name?.trim()) e['last_name'] = ['Le nom est obligatoire.'];
  if (!c.email?.trim() || !RE_EMAIL.test(c.email.trim())) e['email'] = ['Indiquez une adresse e-mail valide.'];
  if (!c.provenance?.trim()) e['provenance'] = ['La provenance est obligatoire : d’où vient cette personne ?'];
  return e;
}

// ── Import ────────────────────────────────────────────────────────────────

/** Au-delà, l'import tourne en arrière-plan avec une barre d'avancement. */
export const SEUIL_ARRIERE_PLAN = 200;
const PAS_ARRIERE_PLAN = 150;

export function analyserLignes(lignes: readonly LigneImport[]): BilanImport {
  const refusees: BilanImport['refusees'] = [];
  const doublons: BilanImport['doublons'] = [];
  const sansProvenance: number[] = [];
  const emailsVus = new Set<string>();
  let aCreer = 0;
  for (const l of lignes) {
    if (!l.nom.trim()) {
      refusees.push({ ligne: l.ligne, raison: 'Nom manquant' });
      continue;
    }
    if (!RE_EMAIL.test(l.email.trim())) {
      refusees.push({ ligne: l.ligne, raison: 'Adresse e-mail invalide' });
      continue;
    }
    if (!l.provenance.trim()) {
      sansProvenance.push(l.ligne);
      continue;
    }
    const email = normaliser(l.email);
    const existant = chercherDoublons({ email: l.email })[0];
    if (existant || emailsVus.has(email)) {
      doublons.push({ ligne: l.ligne, existant: existant?.nom_complet ?? 'Autre ligne du même fichier' });
      continue;
    }
    emailsVus.add(email);
    aCreer += 1;
  }
  return { total: lignes.length, a_creer: aCreer, refusees, doublons, sans_provenance: sansProvenance };
}

function importerLignes(lignes: readonly LigneImport[], dejaVus: Set<string>): number {
  let crees = 0;
  for (const l of lignes) {
    const email = normaliser(l.email);
    if (!l.nom.trim() || !RE_EMAIL.test(l.email.trim()) || !l.provenance.trim()) continue;
    if (dejaVus.has(email) || chercherDoublons({ email: l.email }).length > 0) continue;
    dejaVus.add(email);
    creerFiche({ first_name: l.prenom, last_name: l.nom, email: l.email, organisation: l.organisation, fonction: l.fonction, provenance: l.provenance });
    crees += 1;
  }
  return crees;
}

interface Travail {
  id: string;
  lignes: LigneImport[];
  traitees: number;
  crees: number;
  nomFichier: string;
  bilan: BilanImport;
  dejaVus: Set<string>;
}
const travaux = new Map<string, Travail>();

function rapportDe(bilan: BilanImport, crees: number): RapportImport {
  return { ...bilan, crees };
}

function etatTravail(t: Travail): TravailImport {
  const fini = t.traitees >= t.lignes.length;
  return { id: t.id, statut: fini ? 'termine' : 'en_cours', total: t.lignes.length, traitees: t.traitees, rapport: fini ? rapportDe(t.bilan, t.crees) : null };
}

function noterImport(t: { nomFichier: string; bilan: BilanImport; crees: number }): void {
  imports().ajouter({ id: imports().prochainId(), nom_fichier: t.nomFichier, le: maintenant().toISOString(), rapport: rapportDe(t.bilan, t.crees) });
}

// ── Export ────────────────────────────────────────────────────────────────

function ligneCsv(champs: string[]): string {
  return champs.map((c) => `"${c.replace(/"/g, '""')}"`).join(';');
}

// ── Les routes ────────────────────────────────────────────────────────────

export const handlers: HttpHandler[] = [
  http.get(api('/crm/contacts-hub'), async ({ request }) => {
    await delai();
    const vide = { data: [], meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false } };
    const force = await repondreEtatForce(request, vide);
    if (force) return force;
    const liste = listeHub(new URL(request.url));
    return HttpResponse.json({ data: liste.slice(0, 50), meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: liste.length > 50 } });
  }),

  http.get(api('/crm/contacts-hub/counts'), async ({ request }) => {
    await delai();
    // `gestes_fiches` : la démo annonce l'ajout, l'import, la corbeille et l'export (absent du vrai serveur).
    const force = await repondreEtatForce(request, { total: 0, by_relation_type: {}, by_lifecycle_stage: {}, gestes_fiches: true });
    if (force) return force;
    return HttpResponse.json({ ...compteursHub(), gestes_fiches: true });
  }),

  // Ancienne liste (`/contacts`), gardée en démo pour le retour arrière du drapeau « console v2 ».
  http.get(api('/contacts'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, paginer([], request));
    if (force) return force;
    const q = normaliser(new URL(request.url).searchParams.get('filter[last_name]') ?? '');
    const liste = fiches()
      .filtrer((f) => f.retire_le === null && (q === '' || normaliser(f.last_name).includes(q)))
      .map((f) => ({
        id: f.id,
        first_name: f.first_name,
        last_name: f.last_name,
        role: f.fonction,
        email: f.email,
        email_status: 'valid',
        joignabilite: 'email_valide',
        email_score: 90,
        phone: null,
        linkedin_url: null,
        discovery_source: f.provenance,
        company_id: f.company_id,
        company: { id: f.company_id, denomination: f.organisation },
      }));
    return HttpResponse.json(paginer(liste, request, 50));
  }),

  http.get(api('/crm/persons/:cle/timeline'), async ({ request, params }) => {
    await delai();
    const force = await repondreEtatForce(request, { person_key: String(params['cle']), universes: { business: { accessible: true, exists: false }, vivier: { accessible: false, exists: false } }, subjects: [], data: [] });
    if (force) return force;
    const f = fiche(String(params['cle']));
    if (!f) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    return HttpResponse.json(reponseFiche(f));
  }),

  http.post(api('/crm/persons/:cle/appels'), async ({ request, params }) => {
    await delai();
    const f = fiche(String(params['cle']));
    if (!f) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { compte_rendu?: string };
    if (!corps.compte_rendu?.trim()) return erreurValidation({ compte_rendu: ['Dites en une phrase ce qui s’est dit.'] });
    const e = historique().ajouter({ id: historique().prochainId(), contact_id: f.id, kind: 'appel', title: `Appel : ${corps.compte_rendu.trim()}`, occurred_at: maintenant().toISOString() });
    return HttpResponse.json(e, { status: 201 });
  }),

  http.post(api('/crm/persons/:cle/rappel'), async ({ request, params }) => {
    await delai();
    const f = fiche(String(params['cle']));
    if (!f) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { le?: string; note?: string };
    if (!corps.le || !/^\d{4}-\d{2}-\d{2}$/.test(corps.le)) return erreurValidation({ le: ['Choisissez la date du rappel.'] });
    fiches().modifier(f.id, { rappel_le: corps.le, rappel_note: corps.note?.trim() || null });
    return HttpResponse.json({ rappel_le: corps.le });
  }),

  http.get(api('/crm/contacts/doublons'), async ({ request }) => {
    await delai();
    const p = new URL(request.url).searchParams;
    const data = chercherDoublons({ first_name: p.get('prenom') ?? '', last_name: p.get('nom') ?? '', email: p.get('email') ?? '', organisation: p.get('organisation') ?? '' });
    return HttpResponse.json({ data });
  }),

  http.get(api('/crm/contacts/corbeille'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const data: CorbeilleLigne[] = fiches()
      .filtrer((f) => f.retire_le !== null)
      .sort((a, b) => String(b.retire_le).localeCompare(String(a.retire_le)))
      .map((f) => ({ id: f.id, person_key: f.person_key, first_name: f.first_name, last_name: f.last_name, email: f.email, organisation: f.organisation, retire_le: f.retire_le! }));
    return HttpResponse.json({ data });
  }),

  http.post(api('/crm/contacts'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as Partial<Saisie> & { doublon?: 'fusionner' | 'garder'; fusionner_avec?: number };
    const erreurs = validerSaisie(corps);
    if (Object.keys(erreurs).length > 0) return erreurValidation(erreurs);
    const saisie = corps as Saisie;
    if (corps.doublon === undefined) {
      const doublons = chercherDoublons({ first_name: saisie.first_name, last_name: saisie.last_name, email: saisie.email, organisation: saisie.organisation });
      if (doublons.length > 0) {
        return HttpResponse.json({ error: 'doublon', message: 'Cette personne existe peut-être déjà.', doublons }, { status: 409 });
      }
    }
    if (corps.doublon === 'fusionner') {
      const cible = corps.fusionner_avec === undefined ? undefined : fiches().trouver(corps.fusionner_avec);
      if (!cible) return erreur(404, 'non_trouve', 'La fiche à fusionner n’existe pas.');
      fiches().modifier(cible.id, {
        fonction: cible.fonction || (saisie.fonction ?? '').trim(),
        provenance: cible.provenance || saisie.provenance,
        retire_le: null,
      });
      return HttpResponse.json({ resultat: 'fusionne', fiche: fiches().trouver(cible.id)! } satisfies AjoutReponse);
    }
    const creee = creerFiche(saisie);
    return HttpResponse.json({ resultat: 'cree', fiche: creee } satisfies AjoutReponse, { status: 201 });
  }),

  http.patch(api('/crm/contacts/:id'), async ({ request, params }) => {
    await delai();
    const f = fiches().trouver(Number(params['id']));
    if (!f) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as Partial<Pick<FicheContact, 'first_name' | 'last_name' | 'email' | 'fonction' | 'lifecycle_stage'>>;
    const erreurs: Record<string, string[]> = {};
    if (corps.last_name !== undefined && !corps.last_name.trim()) erreurs['last_name'] = ['Le nom est obligatoire.'];
    if (corps.email !== undefined && !RE_EMAIL.test(corps.email.trim())) erreurs['email'] = ['Indiquez une adresse e-mail valide.'];
    if (corps.lifecycle_stage !== undefined && !(corps.lifecycle_stage in LIFECYCLE_LABELS)) erreurs['lifecycle_stage'] = ['Étape inconnue.'];
    if (Object.keys(erreurs).length > 0) return erreurValidation(erreurs);
    const patch: Partial<FicheContact> = {};
    if (corps.first_name !== undefined) patch.first_name = corps.first_name.trim();
    if (corps.last_name !== undefined) patch.last_name = corps.last_name.trim();
    if (corps.email !== undefined) patch.email = corps.email.trim();
    if (corps.fonction !== undefined) patch.fonction = corps.fonction.trim();
    if (corps.lifecycle_stage !== undefined) patch.lifecycle_stage = corps.lifecycle_stage;
    fiches().modifier(f.id, patch);
    return HttpResponse.json(reponseFiche(fiches().trouver(f.id)!));
  }),

  http.post(api('/crm/contacts/:id/retirer'), async ({ params }) => {
    await delai();
    const f = fiches().trouver(Number(params['id']));
    if (!f) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    fiches().modifier(f.id, { retire_le: maintenant().toISOString() });
    return HttpResponse.json(reponseFiche(fiches().trouver(f.id)!));
  }),

  http.post(api('/crm/contacts/:id/restaurer'), async ({ params }) => {
    await delai();
    const f = fiches().trouver(Number(params['id']));
    if (!f) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    fiches().modifier(f.id, { retire_le: null });
    return HttpResponse.json(reponseFiche(fiches().trouver(f.id)!));
  }),

  http.post(api('/crm/contacts/import/controle'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { lignes?: LigneImport[] };
    if (!Array.isArray(corps.lignes) || corps.lignes.length === 0) return erreurValidation({ lignes: ['Le fichier ne contient aucune ligne.'] });
    return HttpResponse.json(analyserLignes(corps.lignes));
  }),

  http.post(api('/crm/contacts/import'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { lignes?: LigneImport[]; nom_fichier?: string };
    if (!Array.isArray(corps.lignes) || corps.lignes.length === 0) return erreurValidation({ lignes: ['Le fichier ne contient aucune ligne.'] });
    const bilan = analyserLignes(corps.lignes);
    const nomFichier = corps.nom_fichier ?? 'fichier.csv';
    if (corps.lignes.length <= SEUIL_ARRIERE_PLAN) {
      const crees = importerLignes(corps.lignes, new Set());
      noterImport({ nomFichier, bilan, crees });
      const sortie: TravailImport = { id: 'imp-direct', statut: 'termine', total: corps.lignes.length, traitees: corps.lignes.length, rapport: rapportDe(bilan, crees) };
      return HttpResponse.json(sortie, { status: 201 });
    }
    const id = `imp-${travaux.size + 1}`;
    const t: Travail = { id, lignes: corps.lignes, traitees: 0, crees: 0, nomFichier, bilan, dejaVus: new Set() };
    travaux.set(id, t);
    return HttpResponse.json(etatTravail(t), { status: 202 });
  }),

  // Chaque lecture fait avancer le travail d'un pas : une barre d'avancement
  // honnête, sans minuteur caché.
  http.get(api('/crm/contacts/import/:id'), async ({ params }) => {
    await delai();
    const t = travaux.get(String(params['id']));
    if (!t) return erreur(404, 'non_trouve', 'Cet import n’existe pas.');
    if (t.traitees < t.lignes.length) {
      const lot = t.lignes.slice(t.traitees, t.traitees + PAS_ARRIERE_PLAN);
      t.crees += importerLignes(lot, t.dejaVus);
      t.traitees += lot.length;
      if (t.traitees >= t.lignes.length) noterImport(t);
    }
    return HttpResponse.json(etatTravail(t));
  }),

  http.post(api('/crm/contacts/export'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { company_ids?: number[]; contact_ids?: number[] };
    const orgs = new Set(corps.company_ids ?? []);
    const ids = new Set(corps.contact_ids ?? []);
    const choisies = fiches().filtrer((f) => f.retire_le === null && (ids.has(f.id) || orgs.has(f.company_id)));
    const csv = [ligneCsv(['Prénom', 'Nom', 'E-mail', 'Organisation', 'Fonction', 'Provenance']), ...choisies.map((f) => ligneCsv([f.first_name, f.last_name, f.email, f.organisation, f.fonction, f.provenance]))].join('\n');
    return new HttpResponse(csv, { status: 200, headers: { 'Content-Type': 'text/csv; charset=utf-8', 'X-Nombre-De-Fiches': String(choisies.length) } });
  }),
];
