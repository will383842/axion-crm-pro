/**
 * Faux serveur de la presse et des réseaux (DM1-PRE) : médias, journalistes,
 * registre des communiqués, fédérations et ordres, événements.
 *
 * Ce sont les routes que les écrans réels appellent déjà : le vrai serveur les
 * a, la démo les sert. Rien ne se supprime : « consigner » ajoute un échange,
 * « enregistrer la démarche » change l'étape et l'historique.
 *
 * Le même tableau `handlers` sert à la démo et aux tests Vitest.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import {
  genererPresseReseaux,
  NATURES_ECHANGE,
  NATURES_PRESSE,
  regionDeDepartement,
  type ContactFederation,
  type Echange,
  type EtapeHistorique,
  type FicheEvenement,
  type FicheFederation,
  type FicheJournaliste,
  type FicheMedia,
  type NatureEchange,
} from '../../donnees/crm/presse-reseaux';
import { maintenant } from '../../horloge';
import { api, delai, erreur, normaliser, paginer, repondreEtatForce } from '../../outils';

// ── Zone de la démo ───────────────────────────────────────────────────────

demoDb.etendre('presse', (alea) =>
  genererPresseReseaux(alea, {
    medias: demoDb.medias.tous(),
    journalistes: demoDb.journalistes.tous(),
    federations: demoDb.federations.tous(),
    evenements: demoDb.evenements.tous(),
  }),
);

const medias = () => demoDb.zone<FicheMedia>('presse', 'medias');
const journalistes = () => demoDb.zone<FicheJournaliste>('presse', 'journalistes');
const echanges = () => demoDb.zone<Echange>('presse', 'echanges');
const federations = () => demoDb.zone<FicheFederation>('presse', 'federations');
const contactsFederation = () => demoDb.zone<ContactFederation>('presse', 'contactsFederation');
const evenements = () => demoDb.zone<FicheEvenement>('presse', 'evenements');
const etapes = () => demoDb.zone<EtapeHistorique>('presse', 'etapes');

const aujourdhui = (): string => maintenant().toISOString().slice(0, 10);
const nomComplet = (j: { first_name: string | null; last_name: string }): string => [j.first_name, j.last_name].filter(Boolean).join(' ');
const contient = (texte: string | null | undefined, q: string): boolean => normaliser(texte ?? '').includes(normaliser(q));

function param(request: Request, nom: string): string {
  return new URL(request.url).searchParams.get(nom) ?? '';
}

/** Détail introuvable quand l'état forcé est « vide » ; sinon, l'état forcé habituel. */
async function etatDetail(request: Request): Promise<Response | null> {
  const force = await repondreEtatForce(request, null);
  if (force === null) return null;
  return force.status === 200 ? erreur(404, 'introuvable', 'Fiche introuvable.') : force;
}

function csv(lignes: string[][]): Response {
  const echapper = (v: string): string => (/[";\n]/.test(v) ? `"${v.replaceAll('"', '""')}"` : v);
  const corps = lignes.map((l) => l.map(echapper).join(';')).join('\n');
  return new HttpResponse(corps, { headers: { 'Content-Type': 'text/csv; charset=utf-8' } });
}

// ── Médias ────────────────────────────────────────────────────────────────

function mediasFiltres(request: Request): FicheMedia[] {
  const p = new URL(request.url).searchParams;
  const famille = p.get('filter[media_family]') ?? '';
  const nom = p.get('filter[name]') ?? '';
  return medias()
    .tous()
    .filter((m) => {
      if (famille !== '' && m.media_family !== famille) return false;
      if (nom !== '' && !contient(m.name, nom)) return false;
      const type = p.get('filter[media_type]');
      if (type && m.media_type !== type) return false;
      const periodicite = p.get('filter[periodicity]');
      if (periodicite && m.periodicity !== periodicite) return false;
      const dep = p.get('filter[department_code]');
      if (dep && m.department_code !== dep) return false;
      const region = p.get('filter[region_code]');
      if (region && m.region_code !== region) return false;
      const site = p.get('filter[site_fiable]');
      if (site === 'true' && m.site_verifie === null) return false;
      if (site === 'false' && m.site_verifie !== null) return false;
      const mail = p.get('filter[has_email]');
      if (mail === 'true' && m.email === null) return false;
      if (mail === 'false' && m.email !== null) return false;
      const confiance = p.get('filter[email_confidence]');
      if (confiance && m.email_confidence !== confiance) return false;
      return true;
    })
    .sort((a, b) => a.name.localeCompare(b.name, 'fr'));
}

function resumeMedia(m: FicheMedia): Omit<FicheMedia, 'siren' | 'source' | 'parent_id' | 'postcode'> {
  const { siren: _siren, source: _source, parent_id: _parent, postcode: _cp, ...resume } = m;
  void _siren;
  void _source;
  void _parent;
  void _cp;
  return resume;
}

function journalistesDeMedia(mediaId: number) {
  return journalistes()
    .tous()
    .filter((j) => j.media_id === mediaId && j.personne_reelle)
    .map((j) => ({ id: j.id, first_name: j.first_name, last_name: j.last_name, role: j.role, beat: j.beat, email: j.email, opt_out: j.opt_out }));
}

/** L'historique d'une rédaction : ses échanges et ceux de ses journalistes (« via Untel »). */
function fil(mediaId: number) {
  const siens = new Map(journalistes().tous().filter((j) => j.media_id === mediaId).map((j) => [j.id, nomComplet(j)]));
  return echanges()
    .tous()
    .filter((e) => (e.sujet === 'media' && e.sujet_id === mediaId) || (e.sujet === 'journaliste' && siens.has(e.sujet_id)))
    .map((e) => ({ id: e.id, kind: e.kind, title: e.title, content: e.content, occurred_at: e.occurred_at, created_at: e.created_at, via: e.sujet === 'journaliste' ? (siens.get(e.sujet_id) ?? null) : null }))
    .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at));
}

function filJournaliste(id: number) {
  return echanges()
    .tous()
    .filter((e) => e.sujet === 'journaliste' && e.sujet_id === id)
    .map((e) => ({ id: e.id, kind: e.kind, title: e.title, content: e.content, occurred_at: e.occurred_at, created_at: e.created_at }))
    .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at));
}

/** Corps d'un échange consigné ; `null` + réponse d'erreur si la saisie est refusée. */
async function lireEchange(request: Request): Promise<{ kind: NatureEchange; title: string; content: string | null } | Response> {
  const corps = (await request.json().catch(() => ({}))) as { kind?: string; title?: string; content?: string | null };
  const title = (corps.title ?? '').trim();
  if (title === '') return erreur(422, 'saisie_invalide', 'Écrivez en une ligne ce qui s’est passé.');
  if (!NATURES_ECHANGE.includes(corps.kind as NatureEchange)) return erreur(422, 'saisie_invalide', 'Cette nature d’échange n’existe pas.');
  const detail = (corps.content ?? '').trim();
  return { kind: corps.kind as NatureEchange, title, content: detail === '' ? null : detail };
}

function consigner(sujet: Echange['sujet'], sujetId: number, saisie: { kind: NatureEchange; title: string; content: string | null }): Echange {
  const quand = maintenant().toISOString();
  return echanges().ajouter({ id: echanges().prochainId(), sujet, sujet_id: sujetId, kind: saisie.kind, title: saisie.title, content: saisie.content, occurred_at: quand, created_at: quand });
}

// ── Journalistes ──────────────────────────────────────────────────────────

function journalistesFiltres(request: Request): FicheJournaliste[] {
  const personnes = param(request, 'filter[personne_reelle]') || 'true';
  const nom = param(request, 'filter[last_name]');
  return journalistes()
    .tous()
    .filter((j) => (personnes === 'tous' ? true : personnes === 'false' ? !j.personne_reelle : j.personne_reelle))
    .filter((j) => nom === '' || contient(j.last_name, nom) || contient(j.first_name, nom))
    .sort((a, b) => a.last_name.localeCompare(b.last_name, 'fr'));
}

function ligneJournaliste(j: FicheJournaliste) {
  const media = j.media_id === null ? null : medias().trouver(j.media_id);
  return {
    id: j.id,
    first_name: j.first_name,
    last_name: j.last_name,
    role: j.role,
    beat: j.beat,
    email: j.email,
    phone: j.phone,
    opt_out: j.opt_out,
    personne_reelle: j.personne_reelle,
    media: media ? { id: media.id, name: media.name } : null,
  };
}

// ── Registre des communiqués ──────────────────────────────────────────────

function registre(request: Request) {
  const kind = param(request, 'kind');
  const q = param(request, 'q').trim();
  const lignes = echanges()
    .tous()
    .filter((e) => NATURES_PRESSE.includes(e.kind))
    .filter((e) => kind === '' || e.kind === kind)
    .filter((e) => q === '' || contient(e.title, q) || contient(e.content, q))
    .map((e) => {
      if (e.sujet === 'media') {
        const m = medias().trouver(e.sujet_id);
        return { e, cible: m?.name ?? 'Rédaction', cible_type: 'redaction' as const, redaction: m?.name ?? null };
      }
      const j = journalistes().trouver(e.sujet_id);
      const m = j?.media_id == null ? undefined : medias().trouver(j.media_id);
      return { e, cible: j ? nomComplet(j) : 'Journaliste', cible_type: 'journaliste' as const, redaction: m?.name ?? null };
    })
    .sort((a, b) => b.e.occurred_at.localeCompare(a.e.occurred_at));
  return lignes.map(({ e, cible, cible_type, redaction }) => ({
    id: e.id,
    kind: e.kind,
    title: e.title,
    content: e.content,
    occurred_at: e.occurred_at,
    created_at: e.created_at,
    subject_type: e.sujet === 'media' ? 'media' : 'journalist',
    subject_id: e.sujet_id,
    cible,
    cible_type,
    redaction,
  }));
}

/** Les compteurs du trimestre, annoncés par le faux serveur (le vrai serveur ne les envoie pas). */
function compteursRegistre() {
  const toutes = echanges().tous().filter((e) => NATURES_PRESSE.includes(e.kind));
  const semaine = Date.parse(maintenant().toISOString()) - 7 * 86_400_000;
  const compte = (k: NatureEchange) => toutes.filter((e) => e.kind === k).length;
  return {
    communiques: compte('press_release_sent'),
    relances: compte('press_followup'),
    reponses: compte('press_reply'),
    retombees: compte('press_coverage'),
    retombees_semaine: toutes.filter((e) => e.kind === 'press_coverage' && Date.parse(e.occurred_at) >= semaine).length,
  };
}

// ── Fédérations ───────────────────────────────────────────────────────────

function federationBase(id: number) {
  return demoDb.federations.trouver(id);
}

function resumeFederation(f: FicheFederation) {
  const b = federationBase(f.id);
  const tete = f.tete_id === null ? null : federationBase(f.tete_id);
  const aujour = aujourdhui();
  return {
    id: f.id,
    denomination: b?.denomination ?? null,
    sigle: f.sigle,
    entity_nature: f.entity_nature,
    city: b?.city ?? null,
    department_code: b?.department_code ?? null,
    region_code: f.region_code,
    famille: b?.famille ?? 'association_entreprises',
    niveau: b?.niveau ?? 'departemental',
    secteurs: f.secteurs,
    tailles_adherents: f.tailles_adherents,
    pertinence: f.pertinence,
    contactabilite: f.contactabilite,
    certitude: f.certitude,
    partenariat: f.partenariat,
    partenariat_relance_at: f.partenariat_relance_at,
    tete: tete ? { id: tete.id, denomination: tete.denomination } : null,
    nb_antennes: federations().filtrer((x) => x.tete_id === f.id).length,
    evenement_a_venir: demoDb.evenements.tous().some((e) => e.organisateur_id === f.id && e.date_debut >= aujour),
  };
}

function federationsFiltrees(request: Request) {
  const p = new URL(request.url).searchParams;
  return federations()
    .tous()
    .map(resumeFederation)
    .filter((f) => {
      for (const cle of ['famille', 'niveau', 'pertinence', 'contactabilite', 'partenariat'] as const) {
        const v = p.get(cle);
        if (v && f[cle] !== v) return false;
      }
      const secteur = p.get('secteur');
      if (secteur && !f.secteurs.includes(secteur)) return false;
      const region = p.get('region');
      if (region && f.region_code !== region) return false;
      const dep = p.get('departement');
      if (dep && f.department_code !== dep) return false;
      const taille = p.get('taille_adherents');
      if (taille && !f.tailles_adherents.includes(taille)) return false;
      const aVenir = p.get('evenement_a_venir');
      if (aVenir === '1' && !f.evenement_a_venir) return false;
      if (aVenir === '0' && f.evenement_a_venir) return false;
      const q = p.get('q');
      if (q && !contient(f.denomination, q) && !contient(f.sigle, q)) return false;
      return true;
    })
    .sort((a, b) => (a.denomination ?? '').localeCompare(b.denomination ?? '', 'fr'));
}

function ficheFederation(f: FicheFederation) {
  const b = federationBase(f.id);
  const resume = resumeFederation(f);
  const ascendants = f.tete_id === null ? [] : [{ id: f.tete_id, denomination: federationBase(f.tete_id)?.denomination ?? null, niveau: federationBase(f.tete_id)?.niveau ?? null }];
  const antennes = federations()
    .filtrer((x) => x.tete_id === f.id)
    .map((x) => ({ id: x.id, denomination: federationBase(x.id)?.denomination ?? null, niveau: federationBase(x.id)?.niveau ?? null, city: federationBase(x.id)?.city ?? null, department_code: federationBase(x.id)?.department_code ?? null }));
  const mails = b ? [{ email: b.email_generic, type: 'generique', domaine_verifie: true, verifie_le: '2026-10-05' }] : [];
  return {
    ...resume,
    siren: b?.siren ?? null,
    identifiant: f.identifiant,
    naf: f.naf,
    legal_form: f.legal_form,
    effectif_range: f.effectif_range,
    address: f.address,
    postcode: f.postcode,
    sector_main: f.sector_main,
    website: b?.website ?? null,
    linkedin_url: f.linkedin_url,
    phone: f.phone,
    email_generic: b?.email_generic ?? null,
    contact_form_url: f.contact_form_url,
    nom_developpe: f.nom_developpe,
    date_creation: f.date_creation,
    nb_etablissements: f.nb_etablissements,
    origine_classement: f.origine_classement,
    partenariat_note: f.partenariat_note,
    email_generic_verification: b ? { type: 'domaine_du_site', verifie_le: '2026-10-05' } : null,
    canaux: { emails: mails, telephones: [], sites: b ? [b.website] : [], linkedin: [] },
    ascendants,
    antennes,
    contacts: contactsFederation().filtrer((c) => c.federation_id === f.id).map(({ federation_id: _f, ...c }) => (void _f, c)),
    evenements: demoDb.evenements
      .tous()
      .filter((e) => e.organisateur_id === f.id)
      .map((e) => {
        const fe = evenements().trouver(e.id);
        return { id: e.id, nom: e.nom, type: fe?.type ?? 'autre', date_debut: e.date_debut, date_fin: e.date_fin, recurrence: null, ville: e.ville, participation: fe?.participation ?? 'repere', intervention: fe?.intervention ?? 'aucune' };
      }),
    historique: etapes()
      .filtrer((x) => x.sujet === 'federation' && x.sujet_id === f.id)
      .map(({ id, kind, occurred_at }) => ({ id, kind, occurred_at }))
      .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at)),
  };
}

function noterEtape(sujet: EtapeHistorique['sujet'], sujetId: number, kind: string): void {
  etapes().ajouter({ id: etapes().prochainId(), sujet, sujet_id: sujetId, kind, occurred_at: maintenant().toISOString() });
}

// ── Événements ────────────────────────────────────────────────────────────

function resumeEvenement(e: (typeof demoDb.evenements extends { tous(): readonly (infer T)[] } ? T : never)) {
  const f = evenements().trouver(e.id);
  const orga = e.organisateur_id === null ? null : federationBase(e.organisateur_id);
  const fo = e.organisateur_id === null ? null : federations().trouver(e.organisateur_id);
  return {
    id: e.id,
    nom: e.nom,
    type: f?.type ?? 'autre',
    date_debut: e.date_debut,
    date_fin: e.date_fin,
    recurrence: f?.recurrence ?? null,
    heure: f?.heure ?? null,
    ville: e.ville,
    departement_code: e.departement_code,
    region: regionDeDepartement(e.departement_code),
    appel_intervenants: e.appel_intervenants,
    verifie: e.verifie,
    participation: f?.participation ?? 'repere',
    intervention: f?.intervention ?? 'aucune',
    prochaine_relance_at: f?.prochaine_relance_at ?? null,
    organisateurs: orga ? [{ id: orga.id, denomination: orga.denomination, entity_nature: fo?.entity_nature ?? 'federation' }] : [],
  };
}

function evenementsFiltres(request: Request) {
  const p = new URL(request.url).searchParams;
  const aujour = aujourdhui();
  const periode = p.get('periode') ?? '';
  return demoDb.evenements
    .tous()
    .map(resumeEvenement)
    .filter((e) => {
      if (periode === 'a_venir' && !(e.date_debut !== null && (e.date_fin ?? e.date_debut) >= aujour)) return false;
      if (periode === 'passes' && !(e.date_debut !== null && (e.date_fin ?? e.date_debut) < aujour)) return false;
      if (periode === 'sans_date' && e.date_debut !== null) return false;
      for (const cle of ['region', 'type', 'participation', 'intervention'] as const) {
        const v = p.get(cle);
        if (v && e[cle] !== v) return false;
      }
      const appel = p.get('appel_intervenants');
      if (appel && e.appel_intervenants !== appel) return false;
      const q = p.get('q');
      if (q && !contient(e.nom, q) && !contient(e.ville, q)) return false;
      if (p.get('relance') === 'a_faire' && !(e.prochaine_relance_at !== null && e.prochaine_relance_at.slice(0, 10) <= aujour)) return false;
      return true;
    })
    .sort((a, b) => (a.date_debut ?? '9999').localeCompare(b.date_debut ?? '9999'));
}

function ficheEvenement(id: number) {
  const base = demoDb.evenements.trouver(id);
  const f = evenements().trouver(id);
  if (!base || !f) return null;
  const orga = base.organisateur_id === null ? null : federationBase(base.organisateur_id);
  const resume = resumeEvenement(base);
  return {
    ...resume,
    lieu: f.lieu,
    public_vise: f.public_vise,
    taille: f.taille,
    prix: f.prix,
    lien_evenement: f.lien_evenement,
    lien_inscription: f.lien_inscription,
    appel_intervenants_limite: f.appel_intervenants_limite,
    source_url: f.source_url,
    notes: f.notes,
    demarche_note: f.demarche_note,
    organisateurs: orga ? [{ id: orga.id, denomination: orga.denomination, entity_nature: 'federation', city: orga.city, website: orga.website, contact_form_url: federations().trouver(orga.id)?.contact_form_url ?? null }] : [],
    historique: etapes()
      .filtrer((x) => x.sujet === 'evenement' && x.sujet_id === id)
      .map(({ id: i, kind, occurred_at }) => ({ id: i, kind, occurred_at }))
      .sort((a, b) => b.occurred_at.localeCompare(a.occurred_at)),
  };
}

const PARTICIPATIONS = ['repere', 'inscrit', 'rencontre'];
const INTERVENTIONS = ['aucune', 'proposee', 'acceptee', 'refusee', 'realisee'];
const PARTENARIATS_SUIVIS = ['propose', 'en_discussion', 'accepte', 'refuse'];

// ── Routes ────────────────────────────────────────────────────────────────

export const handlers: HttpHandler[] = [
  // Médias
  http.get(api('/media/stats'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { total: 0, avec_site_fiable: 0, avec_email: 0, top_type: null });
    if (force) return force;
    const liste = mediasFiltres(request);
    const parType = new Map<string, number>();
    for (const m of liste) parType.set(m.media_type, (parType.get(m.media_type) ?? 0) + 1);
    const top = [...parType.entries()].sort((a, b) => b[1] - a[1])[0];
    return HttpResponse.json({
      total: liste.length,
      avec_site_fiable: liste.filter((m) => m.site_verifie !== null).length,
      avec_email: liste.filter((m) => m.email !== null).length,
      top_type: top ? { media_type: top[0], n: top[1] } : null,
      computed_at: maintenant().toISOString(),
    });
  }),
  http.get(api('/media/export'), async ({ request }) => {
    await delai();
    const liste = mediasFiltres(request);
    return csv([['Nom', 'Type', 'Ville', 'Département', 'Site', 'E-mail'], ...liste.map((m) => [m.name, m.media_type, m.city, m.department_code, m.site_verifie ?? '', m.email ?? ''])]);
  }),
  http.get(api('/media'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, last_page: 1 } });
    if (force) return force;
    const page = paginer(mediasFiltres(request).map(resumeMedia), request, 100);
    return HttpResponse.json({ data: page.data, meta: { total: page.meta.total, last_page: page.meta.last_page, current_page: page.meta.current_page, per_page: page.meta.per_page } });
  }),
  http.get(api('/media/:id'), async ({ request, params }) => {
    await delai();
    const force = await etatDetail(request);
    if (force) return force;
    const m = medias().trouver(Number(params.id));
    if (!m) return erreur(404, 'introuvable', 'Média introuvable.');
    const parent = m.parent_id === null ? null : medias().trouver(m.parent_id);
    return HttpResponse.json({
      data: {
        ...m,
        company_id: null,
        company: null,
        journalists: journalistesDeMedia(m.id),
        children: medias().filtrer((x) => x.parent_id === m.id).map(resumeMedia),
        parent: parent ? resumeMedia(parent) : null,
        socials: null,
      },
      timeline: fil(m.id),
    });
  }),
  http.post(api('/media/:id/activities'), async ({ request, params }) => {
    await delai();
    const m = medias().trouver(Number(params.id));
    if (!m) return erreur(404, 'introuvable', 'Média introuvable.');
    const saisie = await lireEchange(request);
    if (saisie instanceof Response) return saisie;
    return HttpResponse.json({ data: consigner('media', m.id, saisie) }, { status: 201 });
  }),

  // Journalistes
  http.get(api('/journalists/export'), async ({ request }) => {
    await delai();
    return csv([['Prénom', 'Nom', 'Rédaction', 'E-mail'], ...journalistesFiltres(request).map((j) => [j.first_name ?? '', j.last_name, ligneJournaliste(j).media?.name ?? '', j.opt_out ? '' : (j.email ?? '')])]);
  }),
  http.get(api('/journalists'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, last_page: 1, stats: { total: 0, avec_email: 0, opt_out: 0, ecartees: 0 } } });
    if (force) return force;
    const liste = journalistesFiltres(request);
    const page = paginer(liste.map(ligneJournaliste), request, 100);
    const tous = journalistes().tous();
    return HttpResponse.json({
      data: page.data,
      meta: {
        total: page.meta.total,
        last_page: page.meta.last_page,
        stats: {
          total: liste.length,
          avec_email: liste.filter((j) => j.email !== null && !j.opt_out).length,
          opt_out: liste.filter((j) => j.opt_out).length,
          ecartees: tous.filter((j) => !j.personne_reelle).length,
        },
      },
    });
  }),
  http.get(api('/journalists/:id'), async ({ request, params }) => {
    await delai();
    const force = await etatDetail(request);
    if (force) return force;
    const j = journalistes().trouver(Number(params.id));
    if (!j) return erreur(404, 'introuvable', 'Contact introuvable.');
    const media = j.media_id === null ? null : medias().trouver(j.media_id);
    return HttpResponse.json({ data: { ...j, media: media ? { id: media.id, name: media.name } : null }, timeline: filJournaliste(j.id) });
  }),
  http.post(api('/journalists/:id/activities'), async ({ request, params }) => {
    await delai();
    const j = journalistes().trouver(Number(params.id));
    if (!j) return erreur(404, 'introuvable', 'Contact introuvable.');
    const saisie = await lireEchange(request);
    if (saisie instanceof Response) return saisie;
    return HttpResponse.json({ data: consigner('journaliste', j.id, saisie) }, { status: 201 });
  }),

  // Registre des communiqués
  http.get(api('/presse/envois'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 } });
    if (force) return force;
    const page = paginer(registre(request), request, 50);
    return HttpResponse.json({
      data: page.data,
      meta: { total: page.meta.total, per_page: page.meta.per_page, current_page: page.meta.current_page, last_page: page.meta.last_page, ...compteursRegistre() },
    });
  }),

  // Fédérations et ordres
  http.get(api('/federations'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, page: 1, per_page: 50 } });
    if (force) return force;
    const page = paginer(federationsFiltrees(request), request, 50);
    return HttpResponse.json({ data: page.data, meta: { total: page.meta.total, page: page.meta.page, per_page: page.meta.per_page } });
  }),
  http.get(api('/federations/:id'), async ({ request, params }) => {
    await delai();
    const force = await etatDetail(request);
    if (force) return force;
    const f = federations().trouver(Number(params.id));
    if (!f) return erreur(404, 'introuvable', 'Organisme introuvable.');
    return HttpResponse.json(ficheFederation(f));
  }),
  http.patch(api('/federations/:id/demarche'), async ({ request, params }) => {
    await delai();
    const f = federations().trouver(Number(params.id));
    if (!f) return erreur(404, 'introuvable', 'Organisme introuvable.');
    const corps = (await request.json().catch(() => ({}))) as { partenariat?: string; partenariat_relance_at?: string | null; partenariat_note?: string | null };
    const patch: Partial<FicheFederation> = {};
    if (corps.partenariat !== undefined) {
      if (!['aucun', ...PARTENARIATS_SUIVIS].includes(corps.partenariat)) return erreur(422, 'saisie_invalide', 'Cette étape de partenariat n’existe pas.');
      patch.partenariat = corps.partenariat;
    }
    if (corps.partenariat_relance_at !== undefined) patch.partenariat_relance_at = corps.partenariat_relance_at;
    if (corps.partenariat_note !== undefined) patch.partenariat_note = corps.partenariat_note;
    const change = patch.partenariat !== undefined && patch.partenariat !== f.partenariat;
    federations().modifier(f.id, patch);
    if (change && patch.partenariat !== undefined && PARTENARIATS_SUIVIS.includes(patch.partenariat)) noterEtape('federation', f.id, `partenariat_${patch.partenariat}`);
    return HttpResponse.json(ficheFederation(federations().trouver(f.id) as FicheFederation));
  }),

  // Événements
  http.get(api('/evenements'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, page: 1, per_page: 50 } });
    if (force) return force;
    const page = paginer(evenementsFiltres(request), request, 50);
    return HttpResponse.json({ data: page.data, meta: { total: page.meta.total, page: page.meta.page, per_page: page.meta.per_page } });
  }),
  http.get(api('/evenements/:id'), async ({ request, params }) => {
    await delai();
    const force = await etatDetail(request);
    if (force) return force;
    const fiche = ficheEvenement(Number(params.id));
    return fiche ? HttpResponse.json(fiche) : erreur(404, 'introuvable', 'Événement introuvable.');
  }),
  http.patch(api('/evenements/:id/demarche'), async ({ request, params }) => {
    await delai();
    const id = Number(params.id);
    const e = evenements().trouver(id);
    if (!e) return erreur(404, 'introuvable', 'Événement introuvable.');
    const corps = (await request.json().catch(() => ({}))) as Partial<Pick<FicheEvenement, 'participation' | 'intervention' | 'prochaine_relance_at' | 'demarche_note'>>;
    if (corps.participation !== undefined && !PARTICIPATIONS.includes(corps.participation)) return erreur(422, 'saisie_invalide', 'Cette participation n’existe pas.');
    if (corps.intervention !== undefined && !INTERVENTIONS.includes(corps.intervention)) return erreur(422, 'saisie_invalide', 'Cette intervention n’existe pas.');
    const patch: Partial<FicheEvenement> = {};
    if (corps.participation !== undefined) patch.participation = corps.participation;
    if (corps.intervention !== undefined) patch.intervention = corps.intervention;
    if (corps.prochaine_relance_at !== undefined) patch.prochaine_relance_at = corps.prochaine_relance_at;
    if (corps.demarche_note !== undefined) patch.demarche_note = corps.demarche_note;
    // `modifier` change la fiche en place : on retient l'état d'avant pour savoir ce qui a bougé.
    const avant = { participation: e.participation, intervention: e.intervention };
    evenements().modifier(id, patch);
    if (patch.participation !== undefined && patch.participation !== avant.participation) noterEtape('evenement', id, `evenement_${patch.participation}`);
    if (patch.intervention !== undefined && patch.intervention !== avant.intervention && patch.intervention !== 'aucune') noterEtape('evenement', id, `intervention_${patch.intervention}`);
    return HttpResponse.json(ficheEvenement(id));
  }),
];
