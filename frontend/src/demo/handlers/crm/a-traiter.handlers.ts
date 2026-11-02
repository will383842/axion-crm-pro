/**
 * Démo de la section « À traiter » du CRM (DM1-ATR) : doublons, personnes à
 * rattacher, propositions, et les compteurs de la pastille du menu.
 *
 * Les gestes modifient `demoDb` (zone `a-traiter`) : ils se voient après un
 * rechargement et font bouger la pastille. Rien ne se supprime : une fusion
 * met la fiche absorbée « à la corbeille » (la ligne reste, annulable) ; un
 * refus ou un rattachement change seulement un statut.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { genererATraiter, LIBELLES_MOTIFS, type ArbitrageDemo, type FusionDemo, type PaireDemo, type PropositionDemo, type TacheDemo } from '../../donnees/crm/a-traiter';
import { maintenant } from '../../horloge';
import { api, delai, erreur, paginer, repondreEtatForce } from '../../outils';

const ZONE = 'a-traiter';
const PREMIERE_FUSION = 214;

/** Les collections de la zone, relues à chaque appel (la remise à zéro les remplace). */
function zone() {
  demoDb.etendre(ZONE, genererATraiter);
  return {
    paires: demoDb.zone<PaireDemo>(ZONE, 'paires'),
    fusions: demoDb.zone<FusionDemo>(ZONE, 'fusions'),
    arbitrages: demoDb.zone<ArbitrageDemo>(ZONE, 'arbitrages'),
    propositions: demoDb.zone<PropositionDemo>(ZONE, 'propositions'),
    taches: demoDb.zone<TacheDemo>(ZONE, 'taches'),
  };
}

export function nombreTachesEnRetard(): number {
  const maintenantIso = maintenant().toISOString();
  return zone().taches.filtrer((t) => !t.fait && t.echeance < maintenantIso).length;
}

/** Les quatre files du CRM (aucune file d'Envoi). */
export function compteursATraiter() {
  const z = zone();
  return {
    doublons: z.paires.filtrer((p) => p.statut === 'en_attente').length,
    a_rattacher: z.arbitrages.filtrer((a) => a.statut === 'en_attente').length,
    propositions: z.propositions.filtrer((p) => p.statut === 'en_attente').length,
    taches_en_retard: nombreTachesEnRetard(),
  };
}

const paramId = (valeur: string | readonly string[] | undefined): number => Number(Array.isArray(valeur) ? valeur[0] : valeur);

// ── Doublons ──────────────────────────────────────────────────────────────

function listeDoublons(request: Request) {
  const z = zone();
  const motif = new URL(request.url).searchParams.get('motif') ?? '';
  const enAttente = z.paires.filtrer((p) => p.statut === 'en_attente');
  const parMotif: Record<string, number> = {};
  for (const p of enAttente) parMotif[p.motif] = (parMotif[p.motif] ?? 0) + 1;
  const visibles = enAttente.filter((p) => motif === '' || p.motif === motif);
  const page = paginer(
    visibles.map((p) => ({
      id: p.id,
      motif: p.motif,
      motif_libelle: LIBELLES_MOTIFS[p.motif],
      score: p.score,
      fusion_auto: p.fusion_auto,
      garde: p.garde,
      absorbee: p.absorbee,
    })),
    request,
    50,
  );
  const fusionsActives = z.fusions.filtrer((f) => !f.annulee);
  return {
    data: page.data,
    meta: {
      total: page.meta.total,
      page: page.meta.page,
      per_page: page.meta.per_page,
      par_motif: parMotif,
      adresses_partagees: { cabinet_comptable: 9, domiciliation: 5, groupe: 2, inconnue: 1 },
      motifs: LIBELLES_MOTIFS,
      bilan: {
        a_verifier: enAttente.length,
        certaines: enAttente.filter((p) => p.fusion_auto).length,
        fusionnees_ce_mois: 48 + fusionsActives.length,
        ecartees: 9 + z.paires.filtrer((p) => p.statut === 'ecartee').length,
        adresses_partagees: 17,
      },
      fusions_recentes: fusionsActives
        .slice()
        .reverse()
        .map((f) => {
          const paire = z.paires.trouver(f.paire_id)!;
          return { id: f.id, garde: paire.garde.denomination, absorbee: paire.absorbee.denomination };
        }),
    },
  };
}

// ── Personnes à rattacher ─────────────────────────────────────────────────

// ── Propositions ──────────────────────────────────────────────────────────

/** La valeur actuelle de la fiche pour le champ concerné (lue dans `demoDb`). */
function valeurActuelle(p: PropositionDemo): string | null {
  if (p.entite === 'entreprise') {
    const e = demoDb.entreprises.trouver(p.entite_id);
    const v = e?.[p.champ as 'city' | 'phone' | 'website'];
    return v === undefined || v === '' ? null : v;
  }
  const c = demoDb.contacts.trouver(p.entite_id);
  const v = c?.role;
  return v === undefined || v === '' ? null : v;
}

function nomDeLaFiche(p: PropositionDemo): string | null {
  if (p.entite === 'entreprise') return demoDb.entreprises.trouver(p.entite_id)?.denomination ?? null;
  const c = demoDb.contacts.trouver(p.entite_id);
  return c ? `${c.first_name} ${c.last_name}` : null;
}

/** Empreinte de ce que l'écran montre : si la fiche change, elle change. */
function empreinte(p: PropositionDemo): string {
  return `emp-${p.id}-${valeurActuelle(p) ?? 'vide'}`;
}

function projeter(p: PropositionDemo) {
  const entrepriseId = p.entite === 'entreprise' ? p.entite_id : (demoDb.contacts.trouver(p.entite_id)?.company_id ?? null);
  return {
    id: p.id,
    entite: p.entite,
    entite_id: p.entite_id,
    entreprise_id: entrepriseId,
    fiche: nomDeLaFiche(p),
    champ: p.champ,
    libelle_champ: p.libelle_champ,
    valeur_actuelle: valeurActuelle(p),
    valeur_proposee: p.valeur_proposee,
    origine: p.origine,
    recue_le: p.recue_le,
    empreinte: empreinte(p),
    fiche_supprimee: false,
    fiche_modifiee_depuis: false,
    champ_declare: p.champ_declare,
  };
}

// ── Les routes ────────────────────────────────────────────────────────────

export const handlers: HttpHandler[] = [
  http.get(api('/crm/a-traiter/compteurs'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { doublons: 0, a_rattacher: 0, propositions: 0, taches_en_retard: 0 });
    if (force) return force;
    return HttpResponse.json(compteursATraiter());
  }),

  // Doublons
  http.get(api('/doublons'), async ({ request }) => {
    await delai();
    const vide = { data: [], meta: { ...listeDoublons(request).meta, total: 0, par_motif: {}, bilan: undefined, fusions_recentes: [] } };
    const force = await repondreEtatForce(request, vide);
    if (force) return force;
    return HttpResponse.json(listeDoublons(request));
  }),

  http.post(api('/doublons/:id/fusionner'), async ({ params }) => {
    await delai();
    const z = zone();
    const paire = z.paires.trouver(paramId(params['id']));
    if (!paire || paire.statut !== 'en_attente') return erreur(404, 'non_trouve', 'Cette paire n’existe plus.');
    const fusionId = PREMIERE_FUSION + z.fusions.compter();
    // La fiche absorbée passe à la corbeille (marqueur), jamais supprimée.
    z.paires.modifier(paire.id, { statut: 'fusionnee', absorbee: { ...paire.absorbee, a_la_corbeille: true } });
    z.fusions.ajouter({ id: fusionId, paire_id: paire.id, annulee: false, faite_le: maintenant().toISOString() });
    return HttpResponse.json({ fusion_id: fusionId });
  }),

  http.post(api('/doublons/fusions/:id/annuler'), async ({ params }) => {
    await delai();
    const z = zone();
    const fusion = z.fusions.trouver(paramId(params['id']));
    if (!fusion || fusion.annulee) return erreur(404, 'non_trouve', 'Cette fusion n’existe plus ou est déjà annulée.');
    z.fusions.modifier(fusion.id, { annulee: true });
    const paire = z.paires.trouver(fusion.paire_id);
    if (paire) z.paires.modifier(paire.id, { statut: 'en_attente', absorbee: { ...paire.absorbee, a_la_corbeille: false } });
    return HttpResponse.json({ ok: true });
  }),

  http.post(api('/doublons/:id/ignorer'), async ({ params }) => {
    await delai();
    const z = zone();
    const paire = z.paires.trouver(paramId(params['id']));
    if (!paire || paire.statut !== 'en_attente') return erreur(404, 'non_trouve', 'Cette paire n’existe plus.');
    z.paires.modifier(paire.id, { statut: 'ecartee' });
    return HttpResponse.json({ ok: true });
  }),

  // Personnes à rattacher (arbitrage)
  http.get(api('/crm/arbitrage'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, per_page: 50 } });
    if (force) return force;
    const lignes = zone().arbitrages.filtrer((a) => a.statut === 'en_attente');
    const page = paginer(lignes, request, 50);
    return HttpResponse.json({
      data: page.data.map((a) => ({
        activity_id: a.id,
        kind: a.kind,
        title: a.title,
        occurred_at: a.occurred_at,
        external_ref: a.external_ref,
        person_key: a.person_key,
        pending_match: a.pending_match,
      })),
      meta: { total: lignes.length, per_page: page.meta.per_page },
    });
  }),

  http.post(api('/crm/arbitrage/:id/attach'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const ligne = z.arbitrages.trouver(paramId(params['id']));
    if (!ligne) return erreur(404, 'non_trouve', 'Cet événement n’existe pas.');
    if (ligne.statut !== 'en_attente') return erreur(409, 'deja_traite', 'Cet événement est déjà rattaché.');
    const corps = (await request.json().catch(() => ({}))) as { company_id?: unknown };
    const entreprise = demoDb.entreprises.trouver(Number(corps.company_id));
    if (!entreprise) return erreur(422, 'entreprise_introuvable', 'Entreprise introuvable : choisissez-la dans la liste.');
    const m = ligne.pending_match;
    // La fiche personne est créée seulement si l'événement porte un nom ; sinon on ne fabrique rien.
    const aUnNom = m.first_name.trim() !== '' || m.last_name.trim() !== '';
    if (aUnNom && demoDb.contacts.filtrer((c) => c.email === m.email).length === 0) {
      demoDb.contacts.ajouter({
        id: demoDb.contacts.prochainId(),
        company_id: entreprise.id,
        first_name: m.first_name,
        last_name: m.last_name,
        role: '',
        email: m.email,
        etiquettes: [],
        ne_plus_ecrire: false,
        created_at: maintenant().toISOString(),
      });
    }
    z.arbitrages.modifier(ligne.id, { statut: 'rattache' });
    return HttpResponse.json({ contact_created: aUnNom });
  }),

  http.post(api('/crm/arbitrage/:id/dismiss'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const ligne = z.arbitrages.trouver(paramId(params['id']));
    if (!ligne) return erreur(404, 'non_trouve', 'Cet événement n’existe pas.');
    const corps = (await request.json().catch(() => ({}))) as { reason?: unknown };
    const motif = typeof corps.reason === 'string' ? corps.reason.trim() : '';
    if (motif.length < 3) return erreur(422, 'motif_obligatoire', 'Le motif est obligatoire (3 caractères minimum).');
    z.arbitrages.modifier(ligne.id, { statut: 'ecarte', motif });
    return HttpResponse.json({ dismissed: true });
  }),

  // Recherche d'entreprise du rattachement
  http.get(api('/crm/entreprises/choix'), async ({ request }) => {
    await delai();
    const url = new URL(request.url);
    const q = (url.searchParams.get('q') ?? '').trim().toLowerCase();
    if (q.length < 2) return HttpResponse.json({ data: [], indice: 'trop_court' });
    const cp = url.searchParams.get('code_postal');
    const trouvees = demoDb.entreprises
      .filtrer((e) => e.denomination.toLowerCase().includes(q) || e.siren.replace(/\s/g, '').startsWith(q.replace(/\s/g, '')))
      .filter((e) => cp === null || e.postcode === cp)
      .slice(0, 8)
      .map((e) => ({ id: e.id, denomination: e.denomination, siren: e.siren, siret: null, code_postal: e.postcode, ville: e.city }));
    return HttpResponse.json({ data: trouvees, indice: null });
  }),

  // Propositions
  http.get(api('/crm/propositions'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: { total: 0, per_page: 25, page: 1 } });
    if (force) return force;
    const lignes = zone().propositions.filtrer((p) => p.statut === 'en_attente');
    const page = paginer(lignes, request, 25);
    return HttpResponse.json({
      data: page.data.map(projeter),
      meta: { total: lignes.length, per_page: page.meta.per_page, page: page.meta.page },
    });
  }),

  http.post(api('/crm/propositions/:id/accepter'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const p = z.propositions.trouver(paramId(params['id']));
    if (!p || p.statut !== 'en_attente') return erreur(404, 'non_trouve', 'Cette proposition n’existe plus.');
    const corps = (await request.json().catch(() => ({}))) as { empreinte?: unknown };
    if (corps.empreinte !== empreinte(p)) {
      return erreur(409, 'fiche_modifiee', 'La fiche a changé depuis cette proposition : rechargez la page avant de décider.');
    }
    if (p.entite === 'entreprise') {
      demoDb.entreprises.modifier(p.entite_id, { [p.champ]: p.valeur_proposee });
    } else {
      demoDb.contacts.modifier(p.entite_id, { role: p.valeur_proposee });
    }
    z.propositions.modifier(p.id, { statut: 'acceptee' });
    return HttpResponse.json({ id: p.id, statut: 'acceptee' });
  }),

  http.post(api('/crm/propositions/:id/refuser'), async ({ params }) => {
    await delai();
    const z = zone();
    const p = z.propositions.trouver(paramId(params['id']));
    if (!p || p.statut !== 'en_attente') return erreur(404, 'non_trouve', 'Cette proposition n’existe plus.');
    z.propositions.modifier(p.id, { statut: 'refusee' });
    return HttpResponse.json({ id: p.id, statut: 'refusee' });
  }),
];
