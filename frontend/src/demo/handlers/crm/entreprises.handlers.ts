/**
 * Faux serveur de la zone « entreprises » (DM1-ENT) : liste, indicateurs,
 * export CSV, étiquettes en masse, vues sauvegardées, fiche, relation, tâches.
 *
 * Les gestes modifient `demoDb` (étiquettes) et la zone `entreprises`
 * (relation, tâches, vues) : ils se voient après rechargement. Aucune
 * suppression (règle n° 2).
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { zoneEntreprises, type FicheEntreprise } from '../../donnees/crm/entreprises';
import type { Entreprise } from '../../donnees/types';
import { maintenant } from '../../horloge';
import { api, delai, erreur, filtrerTexte, paginer, repondreEtatForce } from '../../outils';

const PAGE_VIDE = { data: [], meta: { total: 0, last_page: 1, current_page: 1, per_page: 100 } };

function lire(request: Request, cle: string): string {
  return new URL(request.url).searchParams.get(cle) ?? '';
}

/** Applique les filtres de la liste (mêmes noms que l'écran réel). */
function filtrer(request: Request): Entreprise[] {
  const taille = lire(request, 'filter[size_category]');
  const priorite = lire(request, 'filter[priority]');
  const dep = lire(request, 'filter[department_code]');
  const etiquette = lire(request, 'filter[tag]');
  const qualite = lire(request, 'filter[quality]');
  const texte = lire(request, 'filter[denomination]');
  const parTexte = filtrerTexte(demoDb.entreprises.tous(), texte, ['denomination', 'city']);
  return parTexte.filter(
    (e) =>
      (taille === '' || e.size_category === taille) &&
      (priorite === '' || e.priority === priorite) &&
      (dep === '' || e.department_code === dep) &&
      (etiquette === '' || e.etiquettes.includes(etiquette)) &&
      (qualite === '' ||
        (qualite === 'complete' && e.quality_score >= 85) ||
        (qualite === 'partielle' && e.quality_score >= 60 && e.quality_score < 85) ||
        (qualite === 'basique' && e.quality_score < 60)),
  );
}

function ligne(e: Entreprise) {
  return {
    id: e.id,
    siren: e.siren,
    denomination: e.denomination,
    naf: e.naf,
    naf_label: e.naf_label,
    size_category: e.size_category,
    effectif_range: e.effectif_range,
    city: e.city,
    postcode: e.postcode,
    quality_score: e.quality_score,
    priority: e.priority,
    enriched_at: e.enriched_at,
  };
}

function fiche(id: string): FicheEntreprise | undefined {
  return zoneEntreprises().fiches.find((f) => String(f.id) === id);
}

function csv(liste: readonly Entreprise[]): string {
  const echapper = (v: string) => (/[",\n]/.test(v) ? `"${v.replaceAll('"', '""')}"` : v);
  const entete = ['SIREN', 'Dénomination', 'NAF', 'Taille', 'Département', 'Ville', 'Email', 'Téléphone', 'Site web'];
  const lignes = liste.map((e) =>
    [e.siren, e.denomination, e.naf, e.size_category, e.department_code, e.city, e.email_generic, e.phone, e.website].map(echapper).join(','),
  );
  return `\uFEFF${[entete.join(','), ...lignes].join('\n')}\n`;
}

export const handlers: HttpHandler[] = [
  http.get(api('/referentiels/geo'), async () => {
    await delai();
    return HttpResponse.json({
      regions: [{ code: '84', name: 'Auvergne-Rhône-Alpes' }],
      departments: [
        { code: '01', name: 'Ain' },
        { code: '26', name: 'Drôme' },
        { code: '38', name: 'Isère' },
        { code: '69', name: 'Rhône' },
        { code: '73', name: 'Savoie' },
        { code: '74', name: 'Haute-Savoie' },
      ],
    });
  }),

  http.get(api('/companies/stats'), async () => {
    await delai();
    const toutes = demoDb.entreprises.tous();
    const enrichies = toutes.filter((e) => e.enriched_at !== null).length;
    return HttpResponse.json({
      total: toutes.length,
      enrichies_pct: Math.round((enrichies / toutes.length) * 100),
      top_taille: { code: 'tpe', n: toutes.filter((e) => e.size_category === 'tpe').length, pct: 72 },
      top_secteur: { code: 'commerce_detail', n: Math.round(toutes.length * 0.13), pct: 13 },
      computed_at: maintenant().toISOString(),
    });
  }),

  // L'export précède la fiche (`/companies/export` ne doit pas être pris pour un identifiant).
  http.get(api('/companies/export'), async ({ request }) => {
    await delai();
    const ids = lire(request, 'ids').split(',').filter((v) => v !== '');
    const liste = ids.length > 0
      ? demoDb.entreprises.filtrer((e) => ids.includes(String(e.id)))
      : filtrer(request);
    return new HttpResponse(csv(liste), { headers: { 'Content-Type': 'text/csv; charset=UTF-8' } });
  }),

  http.post(api('/companies/tags/bulk'), async ({ request }) => {
    await delai();
    const corps = (await request.json()) as { ids?: number[]; tag?: string; action?: 'add' | 'remove' };
    const slug = (corps.tag ?? '').trim();
    if (!demoDb.etiquettes.tous().some((t) => t.slug === slug)) {
      return erreur(422, 'etiquette_inconnue', `L’étiquette « ${slug} » n’existe pas.`);
    }
    let modifiees = 0;
    let ignorees = 0;
    for (const id of corps.ids ?? []) {
      const e = demoDb.entreprises.trouver(id);
      if (!e) {
        ignorees += 1;
        continue;
      }
      const a = corps.action === 'remove';
      if (a !== e.etiquettes.includes(slug)) {
        ignorees += 1;
        continue;
      }
      demoDb.entreprises.modifier(id, { etiquettes: a ? e.etiquettes.filter((s) => s !== slug) : [...e.etiquettes, slug] });
      modifiees += 1;
    }
    return HttpResponse.json({ modifiees, ignorees });
  }),

  http.get(api('/companies'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, PAGE_VIDE);
    if (force) return force;
    const page = paginer(filtrer(request).map(ligne), request, 100);
    return HttpResponse.json({ data: page.data, meta: { total: page.meta.total, last_page: page.meta.last_page, current_page: page.meta.current_page, per_page: page.meta.per_page } });
  }),

  http.get(api('/companies/:id'), async ({ request, params }) => {
    await delai();
    const force = await repondreEtatForce(request, { error: 'introuvable' });
    if (force) return force;
    const e = demoDb.entreprises.trouver(String(params['id']));
    const f = fiche(String(params['id']));
    if (!e || !f) return erreur(404, 'introuvable', 'Cette entreprise n’existe pas.');
    const contacts = demoDb.contacts.filtrer((c) => c.company_id === e.id).map((c) => ({
      id: c.id,
      first_name: c.first_name,
      last_name: c.last_name,
      role: c.role,
      email: c.email,
      email_status: c.ne_plus_ecrire ? 'invalid' : c.id % 4 === 0 ? 'catchall' : 'valid',
    }));
    return HttpResponse.json({
      id: e.id,
      siren: e.siren,
      denomination: e.denomination,
      naf: e.naf,
      naf_label: e.naf_label,
      legal_form: f.legal_form,
      effectif_range: e.effectif_range,
      size_category: e.size_category,
      address: f.address,
      postcode: e.postcode,
      city: e.city,
      department: e.department_code,
      website: e.website,
      phone: e.phone,
      linkedin_url: null,
      quality_score: e.quality_score,
      quality_breakdown: { email: 100, phone: 100, website: 80, contact: 90 },
      priority: e.priority,
      created_at: e.created_at,
      enriched_at: e.enriched_at,
      contacts,
      tags: e.etiquettes.map((slug) => {
        const t = demoDb.etiquettes.tous().find((x) => x.slug === slug);
        return { id: t?.id ?? 0, slug, name: t?.name ?? slug };
      }),
      relation_type: f.relation_type,
      lifecycle_stage: f.lifecycle_stage,
      relation_saisie_manuelle_at: f.relation_saisie_manuelle_at,
      joignabilite: f.joignabilite,
      responsable: f.responsable,
      affaire: f.affaire,
      historique: f.historique,
      liens: f.liens,
      envois: f.envois,
    });
  }),

  http.post(api('/companies/:id/enrich'), async ({ params }) => {
    await delai();
    const e = demoDb.entreprises.trouver(String(params['id']));
    if (!e) return erreur(404, 'introuvable', 'Cette entreprise n’existe pas.');
    demoDb.entreprises.modifier(e.id, { enriched_at: maintenant().toISOString() });
    return HttpResponse.json({ id: e.id });
  }),

  http.put(api('/companies/:id/relation'), async ({ params, request }) => {
    await delai();
    const f = fiche(String(params['id']));
    if (!f) return erreur(404, 'introuvable', 'Cette entreprise n’existe pas.');
    const corps = (await request.json()) as { relation_type: FicheEntreprise['relation_type']; lifecycle_stage: FicheEntreprise['lifecycle_stage'] };
    demoDb.zone<FicheEntreprise>('entreprises', 'fiches').modifier(f.id, {
      relation_type: corps.relation_type,
      lifecycle_stage: corps.lifecycle_stage,
      relation_saisie_manuelle_at: maintenant().toISOString(),
    });
    return HttpResponse.json({ ok: true });
  }),

  http.get(api('/companies/:id/evenements'), async () => {
    await delai();
    return HttpResponse.json({ data: [] });
  }),

  http.get(api('/companies/:id/taches'), async ({ params }) => {
    await delai();
    zoneEntreprises();
    const liste = demoDb.zone<{ id: number; company_id: number }>('entreprises', 'taches').filtrer((t) => String(t.company_id) === String(params['id']));
    return HttpResponse.json({ data: liste });
  }),

  http.post(api('/companies/:id/taches'), async ({ params, request }) => {
    await delai();
    zoneEntreprises();
    const corps = (await request.json()) as { title: string; due_at: string | null };
    const taches = demoDb.zone<{ id: number }>('entreprises', 'taches');
    const creee = taches.ajouter({ id: taches.prochainId(), company_id: Number(params['id']), title: corps.title, content: null, due_at: corps.due_at, done_at: null, done: false } as never);
    return HttpResponse.json({ data: creee }, { status: 201 });
  }),

  http.post(api('/companies/:id/taches/:tache/terminer'), async ({ params }) => {
    await delai();
    zoneEntreprises();
    demoDb.zone('entreprises', 'taches').modifier(Number(params['tache']), { done: true, done_at: maintenant().toISOString() } as never);
    return HttpResponse.json({ ok: true });
  }),

  // ── Vues sauvegardées (le contrat réel du `SavedViewsController`) ───────
  http.get(api('/saved-views'), async ({ request }) => {
    await delai();
    zoneEntreprises();
    const entite = lire(request, 'entity');
    const vues = demoDb.zone<{ id: number; entity: string; name: string }>('entreprises', 'vues').filtrer((v) => entite === '' || v.entity === entite);
    return HttpResponse.json({ data: vues });
  }),

  http.post(api('/saved-views'), async ({ request }) => {
    await delai();
    zoneEntreprises();
    const corps = (await request.json()) as { entity: string; name: string; filters?: Record<string, string>; is_default?: boolean };
    const nom = (corps.name ?? '').trim();
    if (nom === '') return erreur(422, 'nom_vide', 'Donnez un nom à la vue.');
    const vues = demoDb.zone<{ id: number; entity: string; name: string }>('entreprises', 'vues');
    if (vues.tous().some((v) => v.entity === corps.entity && v.name === nom)) {
      return erreur(422, 'nom_deja_pris', 'Une vue porte déjà ce nom.');
    }
    const maintenantIso = maintenant().toISOString();
    const creee = vues.ajouter({ id: vues.prochainId(), entity: corps.entity, name: nom, filters: corps.filters ?? {}, is_default: corps.is_default ?? false, created_at: maintenantIso, updated_at: maintenantIso } as never);
    return HttpResponse.json({ data: creee }, { status: 201 });
  }),
];
