/**
 * Faux serveur de « Alimenter la base » et des personnes (DM1-DIV) :
 *  - Newsletter et guide : liste, compteurs, export, fiche, tâches, rattachement ;
 *  - Candidats : liste et compteurs ;
 *  - Collectes : liste, fiche, suivi, pause / reprise / annulation / archivage /
 *    lancement, création ;
 *  - Carte de France : couverture, récupération, enrichissement, et fond de
 *    carte stylisé servi en local (aucune tuile, aucun service extérieur).
 *
 * Rien ne se supprime : « supprimer » une collecte l'archive, et les personnes
 * ne se retirent jamais. Le même tableau `handlers` sert à la démo et à Vitest.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import {
  carteStylisee,
  droitsCollecte,
  genererAlimenterDemo,
  nomDeSource,
  type CelluleDemo,
  type CollecteDemo,
  type PersonneDemo,
  type TacheDemo,
} from '../../donnees/crm/alimenter';
import { maintenant } from '../../horloge';
import { api, delai, erreur, normaliser, paginer, repondreEtatForce } from '../../outils';
import type { Campaign, CampaignStatsResponse } from '@/features/collectes/types';
import type { CandidateRow, CountsResponse, PersonneFiche, PersonneTimelineEntry, PersonnesCounts } from '@/features/crm-console/types';

// ── Zone de la démo ───────────────────────────────────────────────────────

demoDb.etendre('alimenter', (alea) => genererAlimenterDemo(alea));

const personnes = () => demoDb.zone<PersonneDemo>('alimenter', 'personnes');
const taches = () => demoDb.zone<TacheDemo>('alimenter', 'taches');
const candidats = () => demoDb.zone<CandidateRow>('alimenter', 'candidats');
const collectes = () => demoDb.zone<CollecteDemo>('alimenter', 'collectes');
const couverture = () => demoDb.zone<CelluleDemo>('alimenter', 'couverture');

const curseurVide = { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false };
const nomComplet = (p: Pick<PersonneDemo, 'first_name' | 'last_name' | 'email'>): string =>
  [p.first_name, p.last_name].filter(Boolean).join(' ') || p.email || '';

// ── Newsletter et guide ───────────────────────────────────────────────────

function personnesFiltrees(url: URL): PersonneDemo[] {
  const q = normaliser(url.searchParams.get('q') ?? '');
  const statut = url.searchParams.get('statut_lettre') ?? '';
  const source = url.searchParams.get('source') ?? '';
  const nature = url.searchParams.get('nature') ?? '';
  const rattachee = url.searchParams.get('rattachee') ?? '';
  return personnes()
    .filtrer((p) => {
      if (statut !== '' && (p.statut_lettre ?? 'aucun') !== statut) return false;
      if (source !== '' && p.premiere_source !== source) return false;
      if (nature !== '' && p.email_nature !== nature) return false;
      if (rattachee === 'oui' && !p.rattachee) return false;
      if (rattachee === 'non' && p.rattachee) return false;
      return q === '' || normaliser(`${nomComplet(p)} ${p.email ?? ''}`).includes(q);
    })
    .sort((a, b) => (b.derniere_interaction_at ?? '').localeCompare(a.derniere_interaction_at ?? ''));
}

function compteursPersonnes(): PersonnesCounts {
  const toutes = personnes().tous();
  const by_statut_lettre = { abonne: 0, desabonne: 0, aucun: 0 };
  const by_nature = { pro: 0, perso: 0, inconnue: 0 };
  const by_source: Record<string, number> = {};
  for (const p of toutes) {
    by_statut_lettre[p.statut_lettre ?? 'aucun'] += 1;
    by_nature[p.email_nature] += 1;
    by_source[p.premiere_source] = (by_source[p.premiere_source] ?? 0) + 1;
  }
  const rattachees = toutes.filter((p) => p.rattachee).length;
  return { total: toutes.length, by_statut_lettre, by_nature, by_source, rattachees, non_rattachees: toutes.length - rattachees };
}

const sansAbonnement = ({ abonnement: _abonnement, ...ligne }: PersonneDemo): Omit<PersonneDemo, 'abonnement'> => ligne;

function entreeTache(t: TacheDemo): PersonneTimelineEntry {
  return { id: t.id, kind: 'tache', title: t.title, content: null, occurred_at: t.created_at, due_at: t.due_at, done_at: t.done_at, external_ref: null };
}

function ficheDePersonne(p: PersonneDemo): PersonneFiche {
  const siennes = taches().filtrer((t) => t.personne_id === p.id);
  const evenement: PersonneTimelineEntry = {
    id: 900_000 + p.id,
    kind: 'note',
    title: p.premiere_source === 'guide-ia' ? 'Guide téléchargé' : 'Inscription à la newsletter',
    content: null,
    occurred_at: p.premiere_source_at,
    due_at: null,
    done_at: null,
    external_ref: p.premiere_source === 'guide-ia' ? 'Guide IA entreprise' : 'Newsletter',
  };
  const terminees = siennes.filter((t) => t.done_at !== null).map(entreeTache);
  const entreprise = p.company_id !== null ? { id: p.company_id, denomination: p.entreprise, siren: null } : null;
  return {
    personne: sansAbonnement(p),
    abonnement:
      p.abonnement === null
        ? null
        : {
            canal: 'newsletter',
            statut: p.abonnement.statut,
            legal_basis: p.base_lettre,
            consent_version: p.base_lettre === 'consent' ? 'v2' : null,
            consent_at: p.abonnement.abonne_at,
            consent_text_ref: p.base_lettre === 'consent' ? 'La preuve reste sur le site' : null,
            source_slug: p.placement,
            abonne_at: p.abonnement.abonne_at,
            desabonne_at: p.abonnement.desabonne_at,
            motif_desabonnement: p.abonnement.motif,
          },
    entreprise,
    taches: siennes.filter((t) => t.done_at === null).map(entreeTache),
    timeline: [evenement, ...terminees].sort((a, b) => (b.occurred_at ?? '').localeCompare(a.occurred_at ?? '')),
  };
}

const exportCsv = (liste: PersonneDemo[]): string =>
  ['nom;e-mail;statut_lettre;source;rattachee', ...liste.map((p) => `${nomComplet(p)};${p.email ?? ''};${p.statut_lettre ?? 'aucun'};${p.premiere_source};${p.rattachee ? 'oui' : 'non'}`)].join('\n');

// ── Candidats ─────────────────────────────────────────────────────────────

function compteursCandidats(): CountsResponse {
  const tous = candidats().tous();
  const by_relation_type: Record<string, number> = {};
  const by_lifecycle_stage: Record<string, number> = {};
  for (const c of tous) {
    by_relation_type[c.relation_type] = (by_relation_type[c.relation_type] ?? 0) + 1;
    by_lifecycle_stage[c.lifecycle_stage] = (by_lifecycle_stage[c.lifecycle_stage] ?? 0) + 1;
  }
  return { total: tous.length, by_relation_type, by_lifecycle_stage };
}

// ── Collectes ─────────────────────────────────────────────────────────────

/** Ce que la liste et la fiche reçoivent : la collecte sans ses passages. */
function versCampagne(c: CollecteDemo): Campaign {
  const { passages, ...campagne } = c;
  return { ...campagne, runs_preview: passages.slice(0, 5) };
}

function changerEtat(id: string, statut: Campaign['status'], plus: Partial<CollecteDemo> = {}): Response {
  const c = collectes().trouver(id);
  if (!c) return erreur(404, 'non_trouve', 'Cette collecte n’existe pas.');
  const maj = collectes().modifier(c.id, { status: statut, ...droitsCollecte(statut), updated_at: maintenant().toISOString(), ...plus });
  return HttpResponse.json(versCampagne(maj ?? c));
}

function statsDeCollecte(c: CollecteDemo): CampaignStatsResponse {
  const parSource = c.sources.map((s) => {
    const siens = c.passages.filter((p) => p.source === s);
    const part = Math.round(c.companies_created / Math.max(1, c.sources.length));
    return {
      source: s,
      total: Math.max(1, Math.round(c.runs_total / Math.max(1, c.sources.length))),
      running: siens.filter((p) => p.status === 'running').length,
      success: siens.filter((p) => p.status === 'success').length + Math.round(c.runs_completed / Math.max(1, c.sources.length)),
      failed: siens.filter((p) => p.status === 'failed').length,
      companies: s === c.sources[0] ? c.companies_created - part * (c.sources.length - 1) : part,
    };
  });
  return {
    campaign: versCampagne(c),
    per_source: parSource,
    last_events: c.passages.map((p) => ({ ...p, source: p.source, request_payload: { source: nomDeSource(p.source) } })),
    companies_per_minute: c.status === 'running' ? 4.7 : 0,
  };
}

function creerCollecte(corps: Record<string, unknown>, statut: 'draft' | 'running'): CollecteDemo {
  const zones = (corps['zones'] as CollecteDemo['zones'] | undefined) ?? [];
  const sources = (corps['sources'] as CollecteDemo['sources'] | undefined) ?? ['insee'];
  const max = Number(corps['max_companies'] ?? 1000);
  const maintenantIso = maintenant().toISOString();
  const nouvelle: CollecteDemo = {
    archived_at: null,
    id: collectes().prochainId(),
    workspace_id: 'axion-ia',
    created_by: 'will',
    name: typeof corps['name'] === 'string' ? corps['name'] : 'Nouvelle collecte',
    description: typeof corps['description'] === 'string' && corps['description'] !== '' ? corps['description'] : null,
    status: statut,
    sources,
    zones,
    max_companies: max,
    max_duration_minutes: Number(corps['max_duration_minutes'] ?? 180),
    max_requests_per_minute: Number(corps['max_requests_per_minute'] ?? 30),
    per_source_limits: null,
    scheduled_at: null,
    expires_at: null,
    companies_created: 0,
    requests_made: 0,
    runs_completed: 0,
    runs_total: statut === 'running' ? 24 : 0,
    duration_seconds_used: 0,
    progress_percent: 0,
    elapsed_minutes: 0,
    remaining_minutes: Number(corps['max_duration_minutes'] ?? 180),
    companies_remaining: max,
    started_at: statut === 'running' ? maintenantIso : null,
    paused_at: null,
    finished_at: null,
    paused_reason: null,
    ...droitsCollecte(statut),
    created_at: maintenantIso,
    updated_at: maintenantIso,
    passages: [],
  };
  return collectes().ajouter(nouvelle);
}

// ── Couverture ────────────────────────────────────────────────────────────

const NIVEAUX = ['region', 'department', 'city'] as const;

// ── Les routes ────────────────────────────────────────────────────────────

export const handlers: HttpHandler[] = [
  // Newsletter et guide
  http.get(api('/crm/personnes/counts'), async ({ request }) => {
    await delai();
    const vide: PersonnesCounts = { total: 0, by_statut_lettre: { abonne: 0, desabonne: 0, aucun: 0 }, by_nature: { pro: 0, perso: 0, inconnue: 0 }, by_source: {}, rattachees: 0, non_rattachees: 0 };
    const force = await repondreEtatForce(request, vide);
    if (force) return force;
    return HttpResponse.json(compteursPersonnes());
  }),

  http.get(api('/crm/personnes/export'), async ({ request }) => {
    await delai();
    const liste = personnesFiltrees(new URL(request.url));
    return new HttpResponse(exportCsv(liste), { headers: { 'Content-Type': 'text/csv; charset=utf-8' } });
  }),

  http.get(api('/crm/personnes'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: curseurVide });
    if (force) return force;
    const liste = personnesFiltrees(new URL(request.url));
    return HttpResponse.json({ data: liste.slice(0, 50).map(sansAbonnement), meta: { ...curseurVide, has_more: liste.length > 50 } });
  }),

  http.get(api('/crm/personnes/:id'), async ({ request, params }) => {
    await delai();
    const force = await repondreEtatForce(request, { error: 'non_trouve', message: 'Cette personne n’existe pas dans votre univers.' });
    if (force) return force;
    const p = personnes().trouver(String(params['id']));
    if (!p) return erreur(404, 'non_trouve', 'Cette personne n’existe pas dans votre univers.');
    return HttpResponse.json(ficheDePersonne(p));
  }),

  http.post(api('/crm/personnes/:id/taches'), async ({ request, params }) => {
    await delai();
    const p = personnes().trouver(String(params['id']));
    if (!p) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { title?: string; due_at?: string };
    const titre = (corps.title ?? '').trim();
    if (titre === '') return erreur(422, 'donnees_invalides', 'Indiquez la tâche à poser.');
    const deja = taches().filtrer((t) => t.personne_id === p.id && t.done_at === null && normaliser(t.title) === normaliser(titre));
    if (deja.length > 0) return HttpResponse.json({ deja_consignee: true });
    taches().ajouter({ id: taches().prochainId(), personne_id: p.id, title: titre, due_at: corps.due_at ?? null, done_at: null, created_at: maintenant().toISOString() });
    return HttpResponse.json({ deja_consignee: false });
  }),

  http.post(api('/crm/personnes/:id/taches/:tache/terminer'), async ({ params }) => {
    await delai();
    const t = taches().trouver(String(params['tache']));
    if (!t || t.personne_id !== Number(params['id']) || t.done_at !== null) return erreur(404, 'non_trouve', 'Tâche introuvable ou déjà terminée.');
    taches().modifier(t.id, { done_at: maintenant().toISOString() });
    return HttpResponse.json({ done: true });
  }),

  http.post(api('/crm/personnes/:id/rattacher'), async ({ request, params }) => {
    await delai();
    const p = personnes().trouver(String(params['id']));
    if (!p) return erreur(404, 'non_trouve', 'Cette personne n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { company_id?: number; first_name?: string; last_name?: string };
    const entreprise = demoDb.entreprises.trouver(Number(corps.company_id));
    if (!entreprise) return erreur(422, 'donnees_invalides', 'Choisissez l’entreprise dans la liste.');
    const nom = corps.last_name?.trim() || p.last_name;
    if (!nom) return erreur(422, 'donnees_invalides', 'Indiquez le nom de famille de la personne.');
    personnes().modifier(p.id, {
      rattachee: true,
      company_id: entreprise.id,
      entreprise: entreprise.denomination,
      contact_id: 5000 + p.id,
      last_name: nom,
      first_name: corps.first_name?.trim() || p.first_name,
      purge_prevue_le: null,
    });
    return HttpResponse.json({ contact_created: true });
  }),

  // Candidats
  http.get(api('/crm/candidates/counts'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { total: 0, by_relation_type: {}, by_lifecycle_stage: {} });
    if (force) return force;
    return HttpResponse.json(compteursCandidats());
  }),

  http.get(api('/crm/candidates'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: curseurVide });
    if (force) return force;
    const url = new URL(request.url);
    const famille = url.searchParams.get('relation_type');
    const q = normaliser(url.searchParams.get('q') ?? '');
    const liste = candidats()
      .filtrer((c) => (famille === null || c.relation_type === famille) && (q === '' || normaliser(`${c.first_name ?? ''} ${c.last_name} ${c.email ?? ''}`).includes(q)))
      .sort((a, b) => (b.derniere_interaction_at ?? '').localeCompare(a.derniere_interaction_at ?? '') || a.id - b.id);
    return HttpResponse.json({ data: liste.slice(0, 50), meta: { ...curseurVide, has_more: liste.length > 50 } });
  }),

  // Collectes
  http.get(api('/collectes'), async ({ request }) => {
    await delai();
    const vide = { data: [], meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 } };
    const force = await repondreEtatForce(request, vide);
    if (force) return force;
    const url = new URL(request.url);
    const q = normaliser(url.searchParams.get('search') ?? '');
    const avecArchivees = url.searchParams.get('archivees') === 'tous';
    const liste = collectes()
      .filtrer((c) => (avecArchivees || !c.archived_at) && (q === '' || normaliser(`${c.name} ${c.description ?? ''}`).includes(q)))
      .sort((a, b) => b.id - a.id)
      .map(versCampagne);
    const page = paginer(liste, request, 50);
    return HttpResponse.json({ data: page.data, meta: { total: liste.length, per_page: page.meta.per_page, current_page: page.meta.current_page, last_page: page.meta.last_page } });
  }),

  http.post(api('/collectes'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as Record<string, unknown>;
    if (typeof corps['name'] !== 'string' || corps['name'].trim() === '') return erreur(422, 'donnees_invalides', 'Donnez un nom à la collecte.');
    return HttpResponse.json(versCampagne(creerCollecte(corps, 'draft')), { status: 201 });
  }),

  http.get(api('/collectes/:id'), async ({ request, params }) => {
    await delai();
    const force = await repondreEtatForce(request, { error: 'non_trouve', message: 'Cette collecte n’a pas pu être lue.' });
    if (force) return force;
    const c = collectes().trouver(String(params['id']));
    if (!c) return erreur(404, 'non_trouve', 'Cette collecte n’existe pas.');
    return HttpResponse.json(versCampagne(c));
  }),

  http.get(api('/collectes/:id/stats'), async ({ params }) => {
    await delai();
    const c = collectes().trouver(String(params['id']));
    if (!c) return erreur(404, 'non_trouve', 'Cette collecte n’existe pas.');
    return HttpResponse.json(statsDeCollecte(c));
  }),

  http.post(api('/collectes/:id/pause'), async ({ params }) => {
    await delai();
    return changerEtat(String(params['id']), 'paused', { paused_at: maintenant().toISOString(), paused_reason: 'manual' });
  }),
  http.post(api('/collectes/:id/resume'), async ({ params }) => {
    await delai();
    return changerEtat(String(params['id']), 'running', { paused_at: null, paused_reason: null });
  }),
  http.post(api('/collectes/:id/cancel'), async ({ params }) => {
    await delai();
    return changerEtat(String(params['id']), 'cancelled', { finished_at: maintenant().toISOString() });
  }),
  http.post(api('/collectes/:id/start'), async ({ params }) => {
    await delai();
    return changerEtat(String(params['id']), 'running', { started_at: maintenant().toISOString(), runs_total: 24 });
  }),
  http.post(api('/collectes/:id/archive'), async ({ params }) => {
    await delai();
    const c = collectes().trouver(String(params['id']));
    if (!c) return erreur(404, 'non_trouve', 'Cette collecte n’existe pas.');
    return HttpResponse.json(versCampagne(collectes().modifier(c.id, { archived_at: maintenant().toISOString() }) ?? c));
  }),
  http.post(api('/collectes/:id/unarchive'), async ({ params }) => {
    await delai();
    const c = collectes().trouver(String(params['id']));
    if (!c) return erreur(404, 'non_trouve', 'Cette collecte n’existe pas.');
    return HttpResponse.json(versCampagne(collectes().modifier(c.id, { archived_at: null }) ?? c));
  }),
  // Rien ne se supprime : « supprimer » une collecte la range avec les archivées.
  http.delete(api('/collectes/:id'), async ({ params }) => {
    await delai();
    const c = collectes().trouver(String(params['id']));
    if (!c) return erreur(404, 'non_trouve', 'Cette collecte n’existe pas.');
    collectes().modifier(c.id, { archived_at: maintenant().toISOString() });
    return new HttpResponse(null, { status: 204 });
  }),

  // Carte de France
  http.get(api('/coverage'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { cells: [], quality_a_recalculer_pct: null });
    if (force) return force;
    const niveau = new URL(request.url).searchParams.get('level') ?? 'department';
    const level = NIVEAUX.find((n) => n === niveau) ?? 'department';
    const cells = couverture()
      .filtrer((c) => c.level === level)
      .map(({ id: _id, level: _niveau, ...cellule }) => cellule);
    return HttpResponse.json({ cells, quality_a_recalculer_pct: 8 });
  }),

  http.post(api('/coverage/launch'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { department?: string; limit?: number };
    const code = corps.department ?? '';
    const zone = couverture().trouver(`department:${code}`);
    if (!zone) return erreur(422, 'donnees_invalides', 'Ce département n’existe pas.');
    const c = creerCollecte(
      { name: `Récupération — ${zone.name}`, description: 'Lancée depuis la carte de France', sources: ['insee'], zones: [{ type: 'department', code, label: zone.name }], max_companies: corps.limit ?? 100, max_duration_minutes: 60 },
      'running',
    );
    return HttpResponse.json({ campaign_id: c.id, queued: corps.limit ?? 100 }, { status: 202 });
  }),

  http.post(api('/coverage/enrich'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { department?: string; limit?: number };
    const zone = couverture().trouver(`department:${corps.department ?? ''}`);
    const file = Math.min(zone?.total ?? 0, corps.limit ?? 0);
    return HttpResponse.json({ queued: file }, { status: 202 });
  }),

  // Fond de carte stylisé : local à la démo, jamais de tuile extérieure.
  http.get('*/tiles/admin/departements.geojson', async () => {
    await delai();
    return HttpResponse.json(carteStylisee(), { headers: { 'Cache-Control': 'no-store' } });
  }),
];
