/**
 * Démo du ciblage (DM1-CIB) : audiences et leur éditeur ET / OU / SAUF, listes
 * manuelles, étiquettes. Mêmes routes que le vrai CRM ; le tableau `handlers`
 * sert au navigateur, à Vitest et aux e2e de démo.
 *
 * Les gestes (créer, modifier, retirer) écrivent dans `demoDb` : ils survivent
 * au rechargement de la page. Rien ne se supprime : corbeille, archivage,
 * `retire_le`.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { AudienceCondition, AudienceCriteria, EmailAudience } from '@/features/audiences/AudiencesListPage';
import { demoDb } from '../../base';
import { fabriquerCiblage, type AudienceCiblage, type DonneesCiblage, type EtiquetteMeta, type MembreCiblage } from '../../donnees/crm/ciblage';
import type { Contact, Entreprise, Etiquette } from '../../donnees/types';
import { maintenant } from '../../horloge';
import { api, delai, erreur, paginer, repondreEtatForce } from '../../outils';

const donnees = (): DonneesCiblage => demoDb.etendre('ciblage', fabriquerCiblage);
const zAudiences = () => (donnees(), demoDb.zone<AudienceCiblage>('ciblage', 'audiences'));
const zMembres = () => (donnees(), demoDb.zone<MembreCiblage>('ciblage', 'membres'));
const zListes = () => (donnees(), demoDb.zone<{ id: number; deleted_at: string | null }>('ciblage', 'listesEtat'));
const zMeta = () => (donnees(), demoDb.zone<EtiquetteMeta>('ciblage', 'etiquettesMeta'));

const maintenantIso = () => maintenant().toISOString();

// ── Évaluation des critères sur le jeu factice ───────────────────────────

const REGION_DE_DEPARTEMENT: Readonly<Record<string, string>> = {
  '01': '84', '07': '84', '26': '84', '38': '84', '42': '84', '63': '84', '69': '84', '73': '84', '74': '84',
  '21': '27', '71': '27',
};

const SECTEUR_DE_NAF: Readonly<Record<string, string>> = {
  '10': 'agroalimentaire', '16': 'industrie', '18': 'industrie', '31': 'industrie',
  '41': 'btp', '42': 'btp', '43': 'btp', '45': 'automobile', '47': 'commerce_detail',
  '56': 'restauration', '69': 'comptabilite_audit', '73': 'marketing_publicite', '74': 'services_specialises',
};

function secteurDe(e: Entreprise): string {
  return SECTEUR_DE_NAF[e.naf.slice(0, 2)] ?? 'non_classe';
}

function relationDe(e: Entreprise): string {
  if (e.etiquettes.includes('clients-2026')) return 'client';
  if (e.etiquettes.includes('partenaires')) return 'partenaire';
  return 'prospect';
}

function joignabiliteDe(e: Entreprise): string {
  return e.enriched_at === null ? 'email_non_verifie' : 'email_valide';
}

function valeurDuChamp(e: Entreprise, champ: string): unknown {
  switch (champ) {
    case 'department_code': return e.department_code;
    case 'region_code': return REGION_DE_DEPARTEMENT[e.department_code] ?? null;
    case 'sector_main': return secteurDe(e);
    case 'size_category': return e.size_category;
    case 'tags': return e.etiquettes;
    case 'entity_nature': return 'entreprise';
    case 'relation_type': return relationDe(e);
    case 'joignabilite': return joignabiliteDe(e);
    case 'quality_score': return e.quality_score;
    case 'has_email': return e.email_generic !== '';
    case 'country_code': return 'FR';
    case 'prospection_status': return 'ready_for_outreach';
    default: return undefined;
  }
}

function listeDe(liste: number): Set<number> {
  return new Set(
    zMembres().filtrer((m) => m.liste_id === liste && m.retire_le === null && m.company_id !== null).map((m) => m.company_id as number),
  );
}

/** Une condition vraie pour l'entreprise ? Un champ inconnu ne filtre rien. */
function condition(e: Entreprise, c: AudienceCondition): boolean {
  if (c.field === 'liste_manuelle') {
    const ids = (Array.isArray(c.value) ? c.value : [c.value]).map(Number);
    const dans = ids.some((id) => listeDe(id).has(e.id));
    return c.op === 'not_in' ? !dans : dans;
  }
  const v = valeurDuChamp(e, c.field);
  if (v === undefined) return true;
  const attendues = Array.isArray(c.value) ? c.value : [c.value];
  const presentes = Array.isArray(v) ? v : [v];
  switch (c.op) {
    case 'in':
    case 'contains_any': return presentes.some((x) => attendues.includes(x));
    case 'not_in': return !presentes.some((x) => attendues.includes(x));
    case 'eq': return v === c.value;
    case 'neq': return v !== c.value;
    case 'gte': return typeof v === 'number' && v >= Number(c.value);
    case 'is_null': return v === null;
    default: return true;
  }
}

export function correspondances(criteres: AudienceCriteria): Entreprise[] {
  const tous = criteres.all ?? [];
  const alt = criteres.any ?? [];
  const sauf = criteres.not ?? [];
  return demoDb.entreprises.filtrer(
    (e) =>
      tous.every((c) => condition(e, c)) &&
      (alt.length === 0 || alt.some((c) => condition(e, c))) &&
      !sauf.some((c) => condition(e, c)),
  );
}

function contactsDe(entreprises: readonly Entreprise[]): Contact[] {
  const ids = new Set(entreprises.map((e) => e.id));
  return demoDb.contacts.filtrer((c) => ids.has(c.company_id) && !c.ne_plus_ecrire);
}

// ── Audiences ────────────────────────────────────────────────────────────

function versAudience(a: (typeof demoDb.audiences extends { tous(): readonly (infer T)[] } ? T : never)): EmailAudience {
  const cible = zAudiences().trouver(a.id);
  const criteria = cible?.criteria ?? {};
  return {
    id: a.id,
    name: a.name,
    description: a.description,
    criteria,
    is_active: a.is_active,
    auto_refresh: a.auto_refresh,
    member_count: correspondances(criteria).length,
    refreshed_at: a.refreshed_at,
    created_at: a.created_at,
    destinataires: {
      mode: cible?.destinataires_mode ?? 'personne_sinon_generique',
      fonctions: [],
      personnes_listees: false,
      avec_adresses_partagees: false,
    },
  };
}

function apercuDestinataires(criteres: AudienceCriteria, mode: string) {
  const orgs = correspondances(criteres);
  const exclues: Record<string, number> = {};
  const lignes: Array<{
    email: string; type: 'generique' | 'nominative'; crm_ref: string; fonction: string | null;
    nb_organisations: number; organisations: Array<{ id: number; nom: string }>;
  }> = [];
  let sans = 0;
  for (const e of orgs) {
    const nommes = demoDb.contacts.filtrer((c) => c.company_id === e.id && !c.ne_plus_ecrire);
    const opposes = demoDb.contacts.filtrer((c) => c.company_id === e.id && c.ne_plus_ecrire).length;
    if (opposes > 0) exclues['opposition'] = (exclues['opposition'] ?? 0) + opposes;
    if (e.enriched_at === null) {
      exclues['non_verifiee'] = (exclues['non_verifiee'] ?? 0) + 1;
      sans += 1;
      continue;
    }
    const organisation = [{ id: e.id, nom: e.denomination }];
    const gen = { email: e.email_generic, type: 'generique' as const, crm_ref: `organisation:${e.id}`, fonction: null, nb_organisations: 1, organisations: organisation };
    const pers = nommes.slice(0, 2).map((c) => ({ email: c.email, type: 'nominative' as const, crm_ref: `contact:${c.id}`, fonction: c.role, nb_organisations: 1, organisations: organisation }));
    if (mode === 'generique') lignes.push(gen);
    else if (mode === 'nominatives') {
      if (pers.length > 0) lignes.push(...pers);
      else sans += 1;
    }
    else if (mode === 'les_deux') lignes.push(gen, ...pers);
    else lignes.push(...(pers.length > 0 ? pers : [gen]));
  }
  const nominatives = lignes.filter((l) => l.type === 'nominative').length;
  const exclTotal = Object.values(exclues).reduce((n, v) => n + v, 0);
  return {
    reglage: { mode, fonctions: [], personnes_listees: false, avec_adresses_partagees: false },
    organisations: orgs.length,
    organisations_avec_destinataire: orgs.length - sans,
    organisations_sans_destinataire: sans,
    destinataires: lignes.length,
    par_type: { generique: lignes.length - nominatives, nominative: nominatives },
    adresses_partagees_entre_organisations: 0,
    doublons_evites: 0,
    exclues,
    exclues_total: exclTotal,
    ecartees_par_le_reglage: {},
    ecartees_total: 0,
    lignes: lignes.slice(0, 50),
  };
}

function lireCriteres(corps: unknown): AudienceCriteria {
  const c = (corps as { criteria?: AudienceCriteria } | null)?.criteria;
  return c !== undefined && typeof c === 'object' ? c : {};
}

const audienceVisible = (id: number | string) => {
  const a = demoDb.audiences.trouver(Number(id));
  const z = zAudiences().trouver(Number(id));
  return a !== undefined && z !== undefined && !z.archivee ? a : undefined;
};

const handlersAudiences: HttpHandler[] = [
  http.get(api('/audiences'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const visibles = demoDb.audiences.tous().filter((a) => audienceVisible(a.id) !== undefined);
    return HttpResponse.json({ data: visibles.map(versAudience) });
  }),

  http.post(api('/audiences/preview'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { companies: 0, contacts: 0 });
    if (force) return force;
    const orgs = correspondances(lireCriteres(await request.json().catch(() => null)));
    return HttpResponse.json({ companies: orgs.length, contacts: contactsDe(orgs).length });
  }),

  http.post(api('/audiences/apercu-destinataires'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { destinataires_mode?: string };
    return HttpResponse.json({ data: apercuDestinataires(lireCriteres(corps), corps.destinataires_mode ?? 'personne_sinon_generique') });
  }),

  http.post(api('/audiences'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { name?: string; description?: string; criteria?: AudienceCriteria; is_active?: boolean; auto_refresh?: boolean; destinataires_mode?: AudienceCiblage['destinataires_mode'] };
    if (typeof corps.name !== 'string' || corps.name.trim() === '') return erreur(422, 'validation', 'Le nom est requis.');
    const id = demoDb.audiences.prochainId();
    const maintenantTexte = maintenantIso();
    const creee = demoDb.audiences.ajouter({
      id, name: corps.name.trim(), description: corps.description ?? null, is_active: corps.is_active ?? true,
      auto_refresh: corps.auto_refresh ?? false, member_count: 0, refreshed_at: maintenantTexte, created_at: maintenantTexte,
    });
    zAudiences().ajouter({ id, criteria: corps.criteria ?? {}, destinataires_mode: corps.destinataires_mode ?? 'personne_sinon_generique', archivee: false });
    return HttpResponse.json({ data: versAudience(creee) }, { status: 201 });
  }),

  http.get(api('/audiences/:id'), async ({ params }) => {
    await delai();
    const a = audienceVisible(String(params['id']));
    return a === undefined ? erreur(404, 'introuvable', 'Audience introuvable.') : HttpResponse.json({ data: versAudience(a) });
  }),

  http.put(api('/audiences/:id'), async ({ params, request }) => {
    await delai();
    const a = audienceVisible(String(params['id']));
    if (a === undefined) return erreur(404, 'introuvable', 'Audience introuvable.');
    const corps = (await request.json().catch(() => ({}))) as { name?: string; description?: string | null; is_active?: boolean; auto_refresh?: boolean; destinataires_mode?: AudienceCiblage['destinataires_mode'] };
    demoDb.audiences.modifier(a.id, {
      ...(corps.name !== undefined ? { name: corps.name } : {}),
      ...(corps.description !== undefined ? { description: corps.description } : {}),
      ...(corps.is_active !== undefined ? { is_active: corps.is_active } : {}),
      ...(corps.auto_refresh !== undefined ? { auto_refresh: corps.auto_refresh } : {}),
    });
    if (corps.destinataires_mode !== undefined) zAudiences().modifier(a.id, { destinataires_mode: corps.destinataires_mode });
    return HttpResponse.json({ data: versAudience(demoDb.audiences.trouver(a.id)!) });
  }),

  // Rien ne se supprime : l'audience est archivée (elle quitte la liste).
  http.delete(api('/audiences/:id'), async ({ params }) => {
    await delai();
    const a = audienceVisible(String(params['id']));
    if (a === undefined) return erreur(404, 'introuvable', 'Audience introuvable.');
    zAudiences().modifier(a.id, { archivee: true });
    return new HttpResponse(null, { status: 204 });
  }),

  http.post(api('/audiences/:id/refresh'), async ({ params }) => {
    await delai();
    const a = audienceVisible(String(params['id']));
    if (a === undefined) return erreur(404, 'introuvable', 'Audience introuvable.');
    demoDb.audiences.modifier(a.id, { refreshed_at: maintenantIso() });
    return HttpResponse.json({ data: versAudience(demoDb.audiences.trouver(a.id)!) });
  }),

  http.get(api('/audiences/:id/members'), async ({ params, request }) => {
    await delai();
    const a = audienceVisible(String(params['id']));
    if (a === undefined) return erreur(404, 'introuvable', 'Audience introuvable.');
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const limite = Number(new URL(request.url).searchParams.get('limit') ?? 100) || 100;
    const lignes = contactsDe(correspondances(zAudiences().trouver(a.id)!.criteria))
      .slice(0, limite)
      .map((c, i) => {
        const e = demoDb.entreprises.trouver(c.company_id)!;
        return {
          id: i + 1, added_at: a.refreshed_at, company_id: e.id, denomination: e.denomination,
          department_code: e.department_code, size_category: e.size_category, sector_main: secteurDe(e),
          contact_id: c.id, first_name: c.first_name, last_name: c.last_name, email: c.email,
        };
      });
    return HttpResponse.json({ data: lignes });
  }),

  http.get(api('/audiences/:id/destinataires'), async ({ params }) => {
    await delai();
    const a = audienceVisible(String(params['id']));
    if (a === undefined) return erreur(404, 'introuvable', 'Audience introuvable.');
    const z = zAudiences().trouver(a.id)!;
    return HttpResponse.json({ data: apercuDestinataires(z.criteria, z.destinataires_mode) });
  }),
];

// ── Listes manuelles ─────────────────────────────────────────────────────

function etatListe(id: number) {
  return zListes().trouver(id);
}

function versListe(l: { id: number; nom: string; description: string | null; created_at: string; updated_at: string }, contient = false) {
  const actifs = zMembres().filtrer((m) => m.liste_id === l.id && m.retire_le === null);
  return {
    id: l.id,
    nom: l.nom,
    description: l.description,
    organisations: actifs.filter((m) => m.type === 'organisation').length,
    personnes: actifs.filter((m) => m.type === 'personne').length,
    contient,
    created_at: l.created_at,
    updated_at: l.updated_at,
    deleted_at: etatListe(l.id)?.deleted_at ?? null,
  };
}

function versMembre(m: MembreCiblage) {
  const e = m.company_id !== null ? demoDb.entreprises.trouver(m.company_id) : undefined;
  const c = m.contact_id !== null ? demoDb.contacts.trouver(m.contact_id) : undefined;
  return {
    id: m.id, type: m.type, origine: m.origine, ajoute_le: m.ajoute_le,
    company_id: m.company_id, contact_id: m.contact_id, organisation_id: m.company_id ?? c?.company_id ?? null,
    denomination: e?.denomination ?? (c !== undefined ? demoDb.entreprises.trouver(c.company_id)?.denomination ?? null : null),
    siren: e?.siren ?? null, email_generic: e?.email_generic ?? null,
    first_name: c?.first_name ?? null, last_name: c?.last_name ?? null, role: c?.role ?? null, email: c?.email ?? null,
  };
}

function ajouterMembres(listeId: number, companyIds: number[], contactIds: number[], origine: MembreCiblage['origine']) {
  const bilan = { ajoutes: 0, reactives: 0, deja_presents: 0, introuvables: 0 };
  const poser = (type: MembreCiblage['type'], company: number | null, contact: number | null) => {
    const existant = zMembres().filtrer((m) => m.liste_id === listeId && m.company_id === company && m.contact_id === contact && m.type === type)[0];
    if (existant === undefined) {
      zMembres().ajouter({
        id: zMembres().prochainId(), liste_id: listeId, type, origine, ajoute_le: maintenantIso(),
        company_id: company, contact_id: contact, retire_le: null,
      });
      bilan.ajoutes += 1;
    } else if (existant.retire_le !== null) {
      zMembres().modifier(existant.id, { retire_le: null });
      bilan.reactives += 1;
    } else bilan.deja_presents += 1;
  };
  for (const id of companyIds) {
    if (demoDb.entreprises.trouver(id)) poser('organisation', id, null);
    else bilan.introuvables += 1;
  }
  for (const id of contactIds) {
    if (demoDb.contacts.trouver(id)) poser('personne', null, id);
    else bilan.introuvables += 1;
  }
  const l = demoDb.listes.trouver(listeId);
  if (l) demoDb.listes.modifier(listeId, { updated_at: maintenantIso() });
  return bilan;
}

const handlersListes: HttpHandler[] = [
  http.get(api('/listes-manuelles'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const q = new URL(request.url).searchParams;
    const corbeille = q.get('corbeille') === '1';
    const company = q.get('company_id');
    const contact = q.get('contact_id');
    const lignes = demoDb.listes
      .tous()
      .filter((l) => (etatListe(l.id)?.deleted_at != null) === corbeille)
      .map((l) => {
        const contient =
          company !== null ? zMembres().filtrer((m) => m.liste_id === l.id && m.retire_le === null && m.company_id === Number(company)).length > 0
          : contact !== null ? zMembres().filtrer((m) => m.liste_id === l.id && m.retire_le === null && m.contact_id === Number(contact)).length > 0
          : false;
        return versListe(l, contient);
      });
    return HttpResponse.json({ data: lignes });
  }),

  http.post(api('/listes-manuelles'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { nom?: string; description?: string };
    if (typeof corps.nom !== 'string' || corps.nom.trim() === '') return erreur(422, 'validation', 'Le nom de la liste est requis.');
    const id = demoDb.listes.prochainId();
    const t = maintenantIso();
    const l = demoDb.listes.ajouter({ id, nom: corps.nom.trim(), description: corps.description ?? null, organisations: 0, personnes: 0, created_at: t, updated_at: t });
    zListes().ajouter({ id, deleted_at: null });
    return HttpResponse.json({ data: versListe(l) }, { status: 201 });
  }),

  http.get(api('/listes-manuelles/:id'), async ({ params, request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: null });
    if (force) return force;
    const l = demoDb.listes.trouver(Number(params['id']));
    return l === undefined ? erreur(404, 'introuvable', 'Liste introuvable.') : HttpResponse.json({ data: versListe(l) });
  }),

  http.put(api('/listes-manuelles/:id'), async ({ params, request }) => {
    await delai();
    const l = demoDb.listes.trouver(Number(params['id']));
    if (l === undefined) return erreur(404, 'introuvable', 'Liste introuvable.');
    const corps = (await request.json().catch(() => ({}))) as { nom?: string; description?: string | null };
    demoDb.listes.modifier(l.id, {
      ...(typeof corps.nom === 'string' && corps.nom.trim() !== '' ? { nom: corps.nom.trim() } : {}),
      ...(corps.description !== undefined ? { description: corps.description } : {}),
      updated_at: maintenantIso(),
    });
    return HttpResponse.json({ data: versListe(demoDb.listes.trouver(l.id)!) });
  }),

  // Rien ne se supprime : la liste va à la corbeille et peut en sortir.
  http.delete(api('/listes-manuelles/:id'), async ({ params }) => {
    await delai();
    const l = demoDb.listes.trouver(Number(params['id']));
    if (l === undefined) return erreur(404, 'introuvable', 'Liste introuvable.');
    zListes().modifier(l.id, { deleted_at: maintenantIso() });
    return new HttpResponse(null, { status: 204 });
  }),

  http.post(api('/listes-manuelles/:id/restaurer'), async ({ params }) => {
    await delai();
    const l = demoDb.listes.trouver(Number(params['id']));
    if (l === undefined) return erreur(404, 'introuvable', 'Liste introuvable.');
    zListes().modifier(l.id, { deleted_at: null });
    return HttpResponse.json({ data: versListe(l) });
  }),

  http.get(api('/listes-manuelles/:id/membres'), async ({ params, request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0 } });
    if (force) return force;
    const lignes = zMembres().filtrer((m) => m.liste_id === Number(params['id']) && m.retire_le === null).map(versMembre);
    const page = paginer(lignes, request, 25);
    return HttpResponse.json({ data: page.data, meta: { ...page.meta } });
  }),

  http.post(api('/listes-manuelles/:id/membres'), async ({ params, request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { company_ids?: number[]; contact_ids?: number[] };
    return HttpResponse.json({ data: ajouterMembres(Number(params['id']), corps.company_ids ?? [], corps.contact_ids ?? [], 'coche') });
  }),

  // Retirer garde la ligne (`retire_le`) : la fiche elle-même est intacte.
  http.post(api('/listes-manuelles/:id/membres/retirer'), async ({ params, request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { company_ids?: number[]; contact_ids?: number[] };
    const liste = Number(params['id']);
    let retires = 0;
    let absents = 0;
    const retirer = (m: MembreCiblage | undefined) => {
      if (m === undefined || m.retire_le !== null) absents += 1;
      else {
        zMembres().modifier(m.id, { retire_le: maintenantIso() });
        retires += 1;
      }
    };
    for (const id of corps.company_ids ?? []) retirer(zMembres().filtrer((m) => m.liste_id === liste && m.company_id === id)[0]);
    for (const id of corps.contact_ids ?? []) retirer(zMembres().filtrer((m) => m.liste_id === liste && m.contact_id === id)[0]);
    return HttpResponse.json({ data: { retires, absents } });
  }),

  http.post(api('/listes-manuelles/:id/import'), async ({ params, request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { contenu?: string; a_blanc?: boolean };
    const lignes = (corps.contenu ?? '').split(/\r?\n/).map((l) => l.trim()).filter((l) => l !== '' && !/^siren|^email/i.test(l));
    const companyIds: number[] = [];
    const contactIds: number[] = [];
    const vus = new Set<string>();
    const bilan = {
      lignes_lues: lignes.length, rapprochees: 0, rejetees: { format_inconnu: 0, introuvable: 0 },
      doublons_dans_le_fichier: 0, rapprochees_a_plusieurs_fiches: 0,
      par_type: { crm: 0, siren: 0, email_personne: 0, email_organisation: 0 },
      organisations_retrouvees: 0, personnes_retrouvees: 0,
      exemples_rejets: [] as Array<{ ligne: number; motif: string }>, a_blanc: corps.a_blanc === true,
    };
    lignes.forEach((brut, i) => {
      const cle = (brut.split(/[;,\t]/)[0] ?? '').trim().toLowerCase();
      if (vus.has(cle)) { bilan.doublons_dans_le_fichier += 1; return; }
      vus.add(cle);
      const e = demoDb.entreprises.filtrer((x) => x.siren === cle || x.email_generic.toLowerCase() === cle)[0];
      const c = demoDb.contacts.filtrer((x) => x.email.toLowerCase() === cle)[0];
      if (e) { companyIds.push(e.id); bilan.rapprochees += 1; bilan.organisations_retrouvees += 1; bilan.par_type[/^\d+$/.test(cle) ? 'siren' : 'email_organisation'] += 1; }
      else if (c) { contactIds.push(c.id); bilan.rapprochees += 1; bilan.personnes_retrouvees += 1; bilan.par_type.email_personne += 1; }
      else {
        const inconnu = !/^\d{9}$/.test(cle) && !cle.includes('@') && !/^(organisation|contact):\d+$/.test(cle);
        bilan.rejetees[inconnu ? 'format_inconnu' : 'introuvable'] += 1;
        if (bilan.exemples_rejets.length < 5) bilan.exemples_rejets.push({ ligne: i + 1, motif: inconnu ? 'format_inconnu' : 'introuvable' });
      }
    });
    const ajout = corps.a_blanc === true ? undefined : ajouterMembres(Number(params['id']), companyIds, contactIds, 'import');
    return HttpResponse.json({ data: { ...bilan, ...(ajout !== undefined ? { ajout } : {}) } });
  }),
];

// ── Étiquettes ───────────────────────────────────────────────────────────

const COULEUR_VERS_PASTILLE: Record<Etiquette['color'], string> = {
  bleu: 'sky', vert: 'emerald', ambre: 'amber', violet: 'violet', gris: 'slate', terracotta: 'rose',
};
const PASTILLE_VERS_COULEUR: Record<string, Etiquette['color']> = {
  sky: 'bleu', emerald: 'vert', amber: 'ambre', violet: 'violet', slate: 'gris', rose: 'terracotta', indigo: 'bleu',
};

const NOMS_DEPARTEMENTS: Readonly<Record<string, string>> = {
  '01': 'Ain', '07': 'Ardèche', '21': 'Côte-d’Or', '26': 'Drôme', '38': 'Isère', '42': 'Loire',
  '63': 'Puy-de-Dôme', '69': 'Rhône', '71': 'Saône-et-Loire', '73': 'Savoie', '74': 'Haute-Savoie',
};
const LIBELLES_TAILLES: Readonly<Record<string, string>> = { tpe: 'TPE', pme: 'PME', eti: 'ETI', grand_groupe: 'Grand groupe' };
const LIBELLES_SECTEURS: Readonly<Record<string, string>> = {
  agroalimentaire: 'Agroalimentaire', industrie: 'Industrie', btp: 'Bâtiment', automobile: 'Automobile',
  commerce_detail: 'Commerce de détail', restauration: 'Restauration', comptabilite_audit: 'Comptabilité',
  marketing_publicite: 'Marketing et publicité', services_specialises: 'Services spécialisés',
};
const AXE_IA = ['intérêt-formation', 'intérêt-site-web', 'intérêt-salon'];

interface TagServi {
  id: number; slug: string; name: string; color: string; category: EtiquetteMeta['categorie'];
  kind: 'auto' | 'manual' | 'llm'; description: string | null; companies_count: number; joignables_count: number;
}

const estJoignable = (e: Entreprise) => e.enriched_at !== null;

function etiquettesServies(): TagServi[] {
  const tous = demoDb.entreprises.tous();
  const sortie: TagServi[] = [];
  let id = 1000;
  const compter = (qui: (e: Entreprise) => boolean) => {
    const ciblees = tous.filter(qui);
    return { companies_count: ciblees.length, joignables_count: ciblees.filter(estJoignable).length };
  };
  for (const [code, nom] of Object.entries(NOMS_DEPARTEMENTS)) {
    const n = compter((e) => e.department_code === code);
    if (n.companies_count > 0) sortie.push({ id: id++, slug: `dep-${code}`, name: nom, color: 'slate', category: 'geo', kind: 'auto', description: null, ...n });
  }
  for (const [code, nom] of Object.entries(LIBELLES_SECTEURS)) {
    const n = compter((e) => secteurDe(e) === code);
    if (n.companies_count > 0) sortie.push({ id: id++, slug: `secteur-${code}`, name: nom, color: 'sky', category: 'sector', kind: 'auto', description: null, ...n });
  }
  for (const [code, nom] of Object.entries(LIBELLES_TAILLES)) {
    const n = compter((e) => e.size_category === code);
    if (n.companies_count > 0) sortie.push({ id: id++, slug: `taille-${code}`, name: nom, color: 'emerald', category: 'size', kind: 'auto', description: null, ...n });
  }
  AXE_IA.forEach((nom, i) => {
    const n = compter((e) => e.id % (7 + i) === 0);
    sortie.push({ id: id++, slug: `ia-${i + 1}`, name: nom, color: 'amber', category: 'ia', kind: 'llm', description: null, ...n });
  });
  for (const t of demoDb.etiquettes.tous()) {
    const meta = zMeta().trouver(t.slug);
    if (meta === undefined || meta.archivee) continue;
    const n = compter((e) => e.etiquettes.includes(t.slug));
    sortie.push({
      id: t.id, slug: t.slug, name: t.name, color: COULEUR_VERS_PASTILLE[t.color], category: meta.categorie,
      kind: 'manual', description: meta.description, ...n,
    });
  }
  return sortie;
}

const handlersEtiquettes: HttpHandler[] = [
  http.get(api('/tags'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { comptes_calcules_le: null } });
    if (force) return force;
    return HttpResponse.json({ data: etiquettesServies(), meta: { comptes_calcules_le: maintenantIso() } });
  }),

  http.post(api('/tags'), async ({ request }) => {
    await delai();
    const corps = (await request.json().catch(() => ({}))) as { name?: string; slug?: string; category?: EtiquetteMeta['categorie']; color?: string; description?: string };
    const nom = (corps.name ?? '').trim();
    if (nom === '') return erreur(422, 'validation', 'Le nom est requis.');
    const slug = (corps.slug ?? nom).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
    if (demoDb.etiquettes.filtrer((t) => t.slug === slug || t.name.toLowerCase() === nom.toLowerCase()).length > 0) {
      return erreur(409, 'doublon', 'Une étiquette porte déjà ce nom.');
    }
    const creee = demoDb.etiquettes.ajouter({
      id: demoDb.etiquettes.prochainId(), name: nom, slug, color: PASTILLE_VERS_COULEUR[corps.color ?? 'slate'] ?? 'gris', usage_count: 0,
    });
    zMeta().ajouter({ id: slug, categorie: corps.category ?? 'custom', description: corps.description ?? null, archivee: false });
    const servie = etiquettesServies().find((t) => t.id === creee.id);
    return HttpResponse.json({ data: servie }, { status: 201 });
  }),

  // Rien ne se supprime : l'étiquette est archivée, les fiches la gardent.
  http.delete(api('/tags/:id'), async ({ params }) => {
    await delai();
    const t = demoDb.etiquettes.trouver(Number(params['id']));
    if (t === undefined) return erreur(403, 'protegee', 'Les étiquettes automatiques ou proposées par l’IA sont protégées.');
    zMeta().modifier(t.slug, { archivee: true });
    return new HttpResponse(null, { status: 204 });
  }),

  // L'export des entreprises (route réelle), filtré par étiquette : CSV.
  http.get(api('/companies/export'), async ({ request }) => {
    await delai();
    const slug = new URL(request.url).searchParams.get('tag') ?? '';
    const cellule = (v: string) => `"${v.replace(/"/g, '""')}"`;
    const lignes = demoDb.entreprises
      .filtrer((e) => slug === '' || e.etiquettes.includes(slug))
      .map((e) => [e.denomination, e.siren, e.city, e.email_generic, estJoignable(e) ? 'oui' : 'non'].map(cellule).join(';'));
    const corps = ['Dénomination;SIREN;Ville;E-mail;Joignable', ...lignes].join('\r\n');
    return new HttpResponse(`\uFEFF${corps}`, { headers: { 'Content-Type': 'text/csv; charset=utf-8' } });
  }),
];

export const handlers: HttpHandler[] = [...handlersAudiences, ...handlersListes, ...handlersEtiquettes];
