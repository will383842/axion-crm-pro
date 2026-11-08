/**
 * Démo d'Envoi — campagnes : liste, gestes (pause, reprise, terminer, dupliquer,
 * changer la date), calendrier, suivi et état de chaque destinataire.
 * Contrat : `backend/openapi/envoi/campagnes.yaml`.
 *
 * Décision produit : aucun suivi des clics et aucune ouverture affichée ; les
 * réponses sont l'indicateur principal.
 *
 * Rien ne se supprime : terminer une campagne la fige, la dupliquer en crée une
 * autre en brouillon.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/campagnes';
import { demoDb } from '../../base';
import { decalage, FUSEAU_DEMO, maintenant } from '../../horloge';
import { api, delai, erreur, etatForce, filtrerTexte, lireRecherche, paginer, repondreEtatForce } from '../../outils';

type Campagne = components['schemas']['CampagneResume'];
type Destinataire = components['schemas']['Destinataire'];
type EvenementEnvoi = components['schemas']['EvenementEnvoi'];

/** Objets du test A/B (les deux versions du premier message). */
const OBJETS_AB = ['Une idée pour votre prochain salon', 'Salon des artisans : on en parle ?'] as const;
/** Délai, en jours, de chaque message de la séquence. */
const DELAIS = [0, 4, 11, 18] as const;
/** Limite de refus affichée à côté du taux (en %). */
const LIMITE_REFUS = 2;
/** Plafond de messages par jour pour une campagne, toutes boîtes comprises. */
const PLAFOND_CAMPAGNE_JOUR = 80;

type Groupe = 'en_file' | 'partis' | 'refuses' | 'ont_repondu' | 'arretes' | 'exclus';

const GROUPES: Record<Destinataire['etat'], Groupe> = {
  a_envoyer: 'en_file',
  envoye: 'partis',
  remis: 'partis',
  rebond_mou: 'refuses',
  rebond_dur: 'refuses',
  a_repondu: 'ont_repondu',
  relances_annulees: 'arretes',
  desinscrit: 'exclus',
  plainte: 'exclus',
  exclu: 'exclus',
};

// ── Dates (Paris) ─────────────────────────────────────────────────────────

const formatJour = new Intl.DateTimeFormat('en-CA', { timeZone: FUSEAU_DEMO, year: 'numeric', month: '2-digit', day: '2-digit' });
const formatJourSemaine = new Intl.DateTimeFormat('en-US', { timeZone: FUSEAU_DEMO, weekday: 'short' });

/** Jour calendaire (AAAA-MM-JJ) d'un instant, à l'heure de Paris. */
function jourParis(iso: string | Date): string {
  return formatJour.format(typeof iso === 'string' ? new Date(iso) : iso);
}

function ajouterJours(jour: string, n: number): string {
  const d = new Date(`${jour}T12:00:00.000Z`);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().slice(0, 10);
}

function estJourOuvre(jour: string): boolean {
  const j = formatJourSemaine.format(new Date(`${jour}T12:00:00.000Z`));
  return j !== 'Sat' && j !== 'Sun';
}

/** Lundi de la semaine du jour donné. */
function lundiDe(jour: string): string {
  const ordre = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const rang = ordre.indexOf(formatJourSemaine.format(new Date(`${jour}T12:00:00.000Z`)));
  return ajouterJours(jour, -Math.max(0, rang));
}

// ── Lecture de l'état d'un destinataire ───────────────────────────────────

function organisationsQuiOntRepondu(campagneId: string): Set<string> {
  const orgs = new Set<string>();
  for (const d of demoDb.destinataires.tous()) {
    if (d.campagne_id === campagneId && d.etat === 'a_repondu' && d.organisation) orgs.add(d.organisation);
  }
  return orgs;
}

function destinatairesAbsents(campagneId: string): Set<string> {
  return new Set(
    demoDb.reponses
      .filtrer((r) => r.campagne_id === campagneId && r.classement === 'absence' && r.destinataire_id !== null)
      .map((r) => String(r.destinataire_id)),
  );
}

/**
 * Ce que l'écran montre d'un destinataire :
 * · un contact dont une collègue de la même entreprise a déjà répondu est
 *   « arrêté pour l'entreprise » (ses relances sont annulées) ;
 * · une réponse « absent du bureau » met la suite en pause, sans l'arrêter ;
 * · aucune ouverture n'est jamais exposée (décision produit).
 */
function vue(d: Destinataire, campagne: Campagne, repondues: Set<string>, absents: Set<string>): Destinataire {
  const copie: Destinataire = { ...d, ouverture: null };
  const reste = d.etat === 'a_envoyer' || ((d.etat === 'remis' || d.etat === 'envoye') && d.etape < campagne.etapes);
  if (reste && d.organisation && repondues.has(d.organisation)) {
    return { ...copie, etat: 'relances_annulees', motif: 'Un autre contact de l’entreprise a répondu' };
  }
  if (d.etat === 'a_repondu' && absents.has(d.id)) {
    return { ...copie, motif: 'Absent du bureau : la suite est en pause jusqu’au retour' };
  }
  return copie;
}

function destinatairesVus(campagne: Campagne): Destinataire[] {
  const repondues = organisationsQuiOntRepondu(campagne.id);
  const absents = destinatairesAbsents(campagne.id);
  return demoDb.destinataires.filtrer((d) => d.campagne_id === campagne.id).map((d) => vue(d, campagne, repondues, absents));
}

// ── Planning (calendrier, fin prévue) ─────────────────────────────────────

/** Messages prévus par jour ouvré, de la prochaine date d'envoi jusqu'à la fin de la liste. */
function planning(campagne: Campagne): Array<{ jour: string; prevus: number }> {
  if (campagne.statut !== 'en_cours' && campagne.statut !== 'programmee') return [];
  if (campagne.prochain_envoi_le === null) return [];
  const quota = campagne.boites.reduce((n, id) => n + (demoDb.boites.trouver(id)?.quota_jour ?? 0), 0);
  const parJour = Math.min(Math.max(quota, 0), PLAFOND_CAMPAGNE_JOUR);
  if (parJour === 0) return [];
  let reste = Math.max(0, campagne.total - campagne.envoyes);
  let jour = jourParis(campagne.prochain_envoi_le);
  const aujourdhui = jourParis(maintenant());
  if (jour < aujourdhui) jour = aujourdhui;
  const plan: Array<{ jour: string; prevus: number }> = [];
  for (let garde = 0; reste > 0 && garde < 120; garde += 1) {
    if (estJourOuvre(jour)) {
      const n = Math.min(parJour, reste);
      plan.push({ jour, prevus: n });
      reste -= n;
    }
    jour = ajouterJours(jour, 1);
  }
  return plan;
}

// ── Calculs de la fiche de suivi ──────────────────────────────────────────

const arrondi1 = (n: number) => Math.round(n * 10) / 10;
const pourcent = (a: number, b: number) => (b === 0 ? 0 : arrondi1((a / b) * 100));

function evenementsDe(campagneId: string): EvenementEnvoi[] {
  return demoDb.evenementsEnvoi.filtrer((e) => e.campagne_id === campagneId);
}

function fiche(campagne: Campagne) {
  const dests = destinatairesVus(campagne);
  const compte = (groupe: Groupe) => dests.filter((d) => GROUPES[d.etat] === groupe).length;
  const partis = dests.filter((d) => d.etat !== 'a_envoyer');
  const refuses = compte('refuses');
  const desinscrits = dests.filter((d) => d.etat === 'desinscrit').length;
  const messages = demoDb.messagesTypes.tous();
  const reponses = demoDb.reponses.filtrer((r) => r.campagne_id === campagne.id);
  const events = evenementsDe(campagne.id);
  const envoyes = campagne.envoyes;

  const sequence = Array.from({ length: campagne.etapes }, (_, i) => {
    const etape = i + 1;
    const atteints = partis.filter((d) => d.etape >= etape || (etape === 1 && d.etat !== 'a_envoyer'));
    return {
      etape,
      message_id: messages[i % Math.max(1, messages.length)]?.id ?? 'msg-1',
      delai_jours: DELAIS[i] ?? 0,
      envoyes: atteints.length,
      reponses: dests.filter((d) => d.etat === 'a_repondu' && Math.max(1, d.etape) === etape).length,
      refus: dests.filter((d) => (d.etat === 'rebond_dur' || d.etat === 'rebond_mou') && Math.max(1, d.etape) === etape).length,
    };
  });

  const aujourdhui = jourParis(maintenant());
  const parJour = Array.from({ length: 7 }, (_, k) => {
    const jour = ajouterJours(aujourdhui, k - 6);
    return { jour, envoyes: events.filter((e) => e.type === 'remis' && jourParis(e.survenu_le) === jour).length };
  });

  const parBoite = campagne.boites.map((id) => {
    const boite = demoDb.boites.trouver(id);
    return {
      boite_id: id,
      adresse: boite?.adresse ?? String(id),
      envoyes: events.filter((e) => e.type === 'remis' && e.boite_id === id).length,
      reponses: reponses.filter((r) => r.boite_id === id).length,
      refus: events.filter((e) => (e.type === 'rebond_dur' || e.type === 'rebond_mou') && e.boite_id === id).length,
    };
  });

  let ab: unknown = null;
  if (campagne.ab_objet) {
    const versions = (['A', 'B'] as const).map((version, rang) => {
      const siens = partis.filter((_, i) => i % 2 === rang);
      const rep = siens.filter((d) => d.etat === 'a_repondu').length;
      return { version, objet: OBJETS_AB[rang], envoyes: siens.length, reponses: rep, taux_reponse: pourcent(rep, siens.length), en_tete: false };
    });
    const premiere = versions[0]!;
    const seconde = versions[1]!;
    const enTete = premiere.taux_reponse >= seconde.taux_reponse ? premiere : seconde;
    enTete.en_tete = true;
    const fini = campagne.statut === 'terminee' || campagne.statut === 'arretee';
    ab = {
      versions,
      gagnant: fini ? enTete.version : null,
      choix_automatique_le: fini ? null : decalage({ heures: 22 }),
    };
  }

  const plan = planning(campagne);
  const dernierJour = plan[plan.length - 1]?.jour ?? null;

  return {
    maintenant: maintenant().toISOString(),
    campagne,
    sequence,
    derniers_evenements: events
      .filter((e) => e.type !== 'remis')
      .sort((a, b) => b.survenu_le.localeCompare(a.survenu_le))
      .slice(0, 8),
    compteurs: {
      en_file: compte('en_file'),
      partis: compte('partis'),
      refuses,
      ont_repondu: compte('ont_repondu'),
      exclus: compte('exclus'),
      desinscrits,
      arretes_entreprise: compte('arretes'),
      en_pause_absence: dests.filter((d) => d.etat === 'a_repondu' && d.motif?.startsWith('Absent du bureau')).length,
      taux_refus: pourcent(refuses, envoyes),
      limite_refus: LIMITE_REFUS,
      taux_desinscription: pourcent(desinscrits, envoyes),
    },
    par_boite: parBoite,
    par_jour: parJour,
    ab,
    fin_prevue_le: dernierJour === null ? null : `${dernierJour}T17:00:00.000Z`,
  };
}

// ── Gestes ────────────────────────────────────────────────────────────────

function prochainIdentifiant(): string {
  const n = demoDb.campagnes.tous().reduce((max, c) => Math.max(max, Number(/^cmp-(\d+)$/.exec(String(c.id))?.[1] ?? 0)), 0);
  return `cmp-${String(n + 1).padStart(2, '0')}`;
}

function introuvable() {
  return erreur(404, 'campagne_introuvable', 'Cette campagne n’existe pas.');
}

const GESTES_OUVERTS: Record<string, readonly Campagne['statut'][]> = {
  pause: ['en_cours'],
  reprise: ['en_pause'],
  terminer: ['en_cours', 'en_pause', 'programmee', 'a_valider'],
  date: ['programmee', 'en_pause', 'en_cours'],
};

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/campagnes'), async ({ request }) => {
    await delai();
    const forcee = await repondreEtatForce(request, { data: [], meta: paginer([], request).meta, par_statut: {}, maintenant: maintenant().toISOString() });
    if (forcee) return forcee;
    const url = new URL(request.url);
    const statut = url.searchParams.get('statut');
    const toutes = demoDb.campagnes.tous();
    const par_statut: Record<string, number> = {};
    for (const c of toutes) par_statut[c.statut] = (par_statut[c.statut] ?? 0) + 1;
    let liste = filtrerTexte(toutes, lireRecherche(request), ['nom']);
    if (statut) liste = liste.filter((c) => c.statut === statut);
    return HttpResponse.json({ ...paginer(liste, request, 50), par_statut, maintenant: maintenant().toISOString() });
  }),

  // Avant `/:id` : « calendrier » ne doit pas être pris pour un identifiant.
  http.get(api('/envoi/campagnes/calendrier'), async ({ request }) => {
    await delai();
    const url = new URL(request.url);
    const du = url.searchParams.get('du') ?? lundiDe(jourParis(maintenant()));
    const au = url.searchParams.get('au') ?? ajouterJours(du, 6);
    const forcee = await repondreEtatForce(request, { maintenant: maintenant().toISOString(), du, au, data: [] });
    if (forcee) return forcee;

    const aujourdhui = jourParis(maintenant());
    const lignes = new Map<string, { jour: string; campagne_id: string; nom: string; statut: Campagne['statut']; prevus: number; envoyes: number }>();
    const ligne = (c: Campagne, jour: string) => {
      const cle = `${jour}|${c.id}`;
      let l = lignes.get(cle);
      if (!l) {
        l = { jour, campagne_id: String(c.id), nom: c.nom, statut: c.statut, prevus: 0, envoyes: 0 };
        lignes.set(cle, l);
      }
      return l;
    };
    for (const c of demoDb.campagnes.tous()) {
      for (const e of evenementsDe(String(c.id))) {
        if (e.type !== 'remis') continue;
        const jour = jourParis(e.survenu_le);
        if (jour < du || jour > au) continue;
        const l = ligne(c, jour);
        l.envoyes += 1;
        l.prevus += 1;
      }
      for (const p of planning(c)) {
        if (p.jour < du || p.jour > au) continue;
        if (p.jour < aujourdhui) continue;
        ligne(c, p.jour).prevus += p.prevus;
      }
    }
    const data = [...lignes.values()].sort((a, b) => a.jour.localeCompare(b.jour) || a.nom.localeCompare(b.nom, 'fr'));
    return HttpResponse.json({ maintenant: maintenant().toISOString(), du, au, data });
  }),

  http.get(api('/envoi/campagnes/:id'), async ({ request, params }) => {
    await delai();
    const etat = etatForce(request);
    if (etat === 'erreur' || etat === 'chargement') {
      const forcee = await repondreEtatForce(request, null);
      if (forcee) return forcee;
    }
    const campagne = demoDb.campagnes.trouver(String(params['id']));
    if (!campagne) return introuvable();
    return HttpResponse.json(fiche(campagne));
  }),

  http.get(api('/envoi/campagnes/:id/destinataires'), async ({ request, params }) => {
    await delai();
    const campagne = demoDb.campagnes.trouver(String(params['id']));
    const forcee = await repondreEtatForce(request, { data: [], meta: paginer([], request).meta });
    if (forcee) return forcee;
    if (!campagne) return introuvable();
    const url = new URL(request.url);
    const groupe = url.searchParams.get('groupe');
    const etat = url.searchParams.get('etat');
    const ouvert = url.searchParams.get('ouvert');
    let liste = destinatairesVus(campagne);
    if (groupe) liste = liste.filter((d) => GROUPES[d.etat] === groupe);
    if (etat) liste = liste.filter((d) => d.etat === etat);
    if (ouvert === 'oui') liste = liste.filter((d) => d.ouverture !== null && !d.ouverture.automatique_probable);
    if (ouvert === 'non') liste = liste.filter((d) => d.ouverture === null);
    if (ouvert === 'automatique') liste = liste.filter((d) => d.ouverture?.automatique_probable === true);
    const q = lireRecherche(request);
    if (q.trim() !== '') {
      const enrichis = liste.map((d) => ({ d, texte: `${d.prenom ?? ''} ${d.nom ?? ''} ${d.organisation ?? ''} ${d.adresse}` }));
      liste = filtrerTexte(enrichis, q, ['texte']).map((x) => x.d);
    }
    liste.sort((a, b) => (b.dernier_evenement_le ?? '').localeCompare(a.dernier_evenement_le ?? ''));
    return HttpResponse.json(paginer(liste, request, 25));
  }),

  http.post(api('/envoi/campagnes/:id/pause'), async ({ params }) => {
    await delai();
    const c = demoDb.campagnes.trouver(String(params['id']));
    if (!c) return introuvable();
    if (!GESTES_OUVERTS['pause']!.includes(c.statut)) return erreur(409, 'statut_incompatible', 'Seule une campagne en cours peut être mise en pause.');
    return HttpResponse.json(demoDb.campagnes.modifier(c.id, { statut: 'en_pause', prochain_envoi_le: null }));
  }),

  http.post(api('/envoi/campagnes/:id/reprise'), async ({ params }) => {
    await delai();
    const c = demoDb.campagnes.trouver(String(params['id']));
    if (!c) return introuvable();
    if (!GESTES_OUVERTS['reprise']!.includes(c.statut)) return erreur(409, 'statut_incompatible', 'Seule une campagne en pause peut reprendre.');
    return HttpResponse.json(demoDb.campagnes.modifier(c.id, { statut: 'en_cours', prochain_envoi_le: decalage({ heures: 1 }) }));
  }),

  http.post(api('/envoi/campagnes/:id/terminer'), async ({ params }) => {
    await delai();
    const c = demoDb.campagnes.trouver(String(params['id']));
    if (!c) return introuvable();
    if (!GESTES_OUVERTS['terminer']!.includes(c.statut)) return erreur(409, 'statut_incompatible', 'Cette campagne ne peut pas être terminée.');
    return HttpResponse.json(demoDb.campagnes.modifier(c.id, { statut: 'terminee', prochain_envoi_le: null }));
  }),

  http.post(api('/envoi/campagnes/:id/dupliquer'), async ({ request, params }) => {
    await delai();
    const c = demoDb.campagnes.trouver(String(params['id']));
    if (!c) return introuvable();
    const corps = (await request.json().catch(() => ({}))) as { nom?: unknown; garder_cible?: unknown; garder_messages?: unknown; exclure_deja_recus?: unknown };
    const nom = typeof corps.nom === 'string' && corps.nom.trim() !== '' ? corps.nom.trim() : `${c.nom} (copie)`;
    const exclure = corps.exclure_deja_recus === true;
    const copie: Campagne = {
      ...structuredClone(c),
      id: prochainIdentifiant(),
      nom,
      statut: 'brouillon',
      envoyes: 0,
      reponses: 0,
      reponses_interessees: 0,
      ouvertures: null,
      prochain_envoi_le: null,
      cree_le: maintenant().toISOString(),
      etapes: corps.garder_messages === false ? 1 : c.etapes,
      cible: exclure ? { ...c.cible, total: Math.max(0, c.cible.total - c.envoyes) } : { ...c.cible },
      total: exclure ? Math.max(0, c.total - c.envoyes) : c.total,
    };
    return HttpResponse.json(demoDb.campagnes.ajouter(copie), { status: 201 });
  }),

  http.post(api('/envoi/campagnes/:id/date'), async ({ request, params }) => {
    await delai();
    const c = demoDb.campagnes.trouver(String(params['id']));
    if (!c) return introuvable();
    const corps = (await request.json().catch(() => ({}))) as { le?: unknown };
    const le = typeof corps.le === 'string' ? new Date(corps.le) : null;
    if (le === null || Number.isNaN(le.getTime())) return erreur(422, 'date_invalide', 'Cette date n’est pas valide.');
    if (le.getTime() <= maintenant().getTime()) return erreur(422, 'date_passee', 'Choisissez une date à venir.');
    if (!GESTES_OUVERTS['date']!.includes(c.statut)) return erreur(409, 'statut_incompatible', 'La date de cette campagne ne peut plus changer.');
    return HttpResponse.json(demoDb.campagnes.modifier(c.id, { prochain_envoi_le: le.toISOString() }));
  }),
];
