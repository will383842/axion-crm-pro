/**
 * Démo d'Envoi — pilotage : résultats, santé des envois, chercher un envoi, feu vert (DM2-PIL).
 * Contrat : `backend/openapi/envoi/pilotage.yaml`. Tout est calculé depuis `demoDb`
 * (événements d'envoi, réponses, boîtes) : aucun chiffre écrit en dur, sauf la liste
 * des 15 critères du feu vert. Aucun suivi des clics ; les ouvertures ne sont
 * données qu'en ESTIMATION, ouvertures automatiques probables retirées.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/pilotage';
import { demoDb } from '../../base';
import { maintenant } from '../../horloge';
import { api, delai, erreur, repondreEtatForce } from '../../outils';

type Ligne = components['schemas']['LigneResultat'];
type Regroupement = 'campagne' | 'boite' | 'domaine' | 'liste' | 'message';
interface PointFeuVert { id: string; code: string; libelle: string; fait: boolean; detail: string | null; manuel: boolean }
interface TestMatin { id: string; le: string; temoins_ok: number; temoins_total: number }

const JOUR = 86_400_000;

/** Les 15 critères avant le premier envoi ; 13 faits, 2 inscriptions à faire (comme la maquette). */
const POINTS: ReadonlyArray<Omit<PointFeuVert, 'id'>> = [
  { code: 'dns', libelle: 'Domaines : DNS au vert', fait: true, detail: '5 contrôles sur 5', manuel: false },
  { code: 'dmarc', libelle: 'DMARC publié', fait: true, detail: 'Politique « none », montée progressive prévue', manuel: false },
  { code: 'ptr', libelle: 'Retour (PTR) des adresses IP', fait: true, detail: null, manuel: false },
  { code: 'temoins', libelle: 'Boîtes témoins reçues', fait: true, detail: null, manuel: false },
  { code: 'test-matin', libelle: 'Test du matin réussi 7 jours', fait: true, detail: null, manuel: false },
  { code: 'chauffe', libelle: 'Chauffe terminée (1 boîte au moins)', fait: true, detail: null, manuel: false },
  { code: 'page-desinscription', libelle: 'Page de désinscription en ligne', fait: true, detail: null, manuel: false },
  { code: 'en-tete-desinscription', libelle: 'En-tête de désinscription en un geste', fait: true, detail: null, manuel: false },
  { code: 'bloc-art14', libelle: 'Bloc d’information art. 14', fait: true, detail: null, manuel: false },
  { code: 'identite', libelle: 'Identité de l’expéditeur remplie', fait: true, detail: null, manuel: false },
  { code: 'alertes-email', libelle: 'Alertes par e-mail testées', fait: true, detail: null, manuel: false },
  { code: 'sauvegarde', libelle: 'Sauvegarde vérifiée', fait: true, detail: null, manuel: false },
  { code: 'tout-arreter', libelle: 'Tout arrêter testé', fait: true, detail: null, manuel: false },
  { code: 'boucles-retour', libelle: 'Boucles de retour des messageries', fait: false, detail: 'Inscription à faire auprès des messageries', manuel: true },
  { code: 'tableaux-messageries', libelle: 'Inscription aux tableaux des messageries', fait: false, detail: 'Inscription à faire auprès des messageries', manuel: true },
];

function zone() {
  return demoDb.etendre('pilotage', () => ({
    points: POINTS.map((p): PointFeuVert => ({ ...p, id: p.code })),
    testMatin: [{ id: 'test', le: new Date(maintenant().getTime() - 2 * 3_600_000).toISOString(), temoins_ok: 4, temoins_total: 4 } satisfies TestMatin],
  }));
}

const lignePoint = (p: PointFeuVert) => ({ code: p.code, libelle: p.libelle, fait: p.fait, detail: p.detail });

function vide(cle: string, libelle: string, version?: number): Ligne {
  return { cle, libelle, ...(version === undefined ? {} : { version }), envoyes: 0, remis: 0, rebonds_durs: 0, rebonds_mous: 0, plaintes: 0, desinscriptions: 0, reponses: 0, reponses_interessees: 0, ouvertures: null };
}

/** Clé et libellé du groupe d'un événement. */
function groupe(par: Regroupement, campagneId: string, boiteId: string): { cle: string; libelle: string; version?: number } {
  const campagne = demoDb.campagnes.trouver(campagneId);
  const rang = Math.max(0, demoDb.campagnes.tous().findIndex((c) => c.id === campagneId));
  if (par === 'campagne') return { cle: campagneId, libelle: campagne?.nom ?? campagneId };
  if (par === 'liste') return { cle: campagne?.cible.reference ?? campagneId, libelle: `${campagne?.cible.libelle ?? campagneId}` };
  if (par === 'boite') return { cle: boiteId, libelle: demoDb.boites.trouver(boiteId)?.adresse ?? boiteId };
  if (par === 'domaine') {
    const d = demoDb.domaines.trouver(demoDb.boites.trouver(boiteId)?.domaine_id ?? '');
    return { cle: d?.id ?? boiteId, libelle: d?.nom ?? boiteId };
  }
  const messages = demoDb.messagesTypes.tous();
  const m = messages[rang % Math.max(1, messages.length)];
  const version = m ? (rang % Math.max(1, m.versions.length)) + 1 : 1;
  return { cle: `${m?.id ?? 'm'}-v${version}`, libelle: m?.nom ?? 'Message', version };
}

function calculer(par: Regroupement, depuisMs: number, jusquaMs: number) {
  const lignes = new Map<string, Ligne>();
  const interessees = new Set(demoDb.reponses.filtrer((r) => r.classement === 'interesse').map((r) => r.destinataire_id));
  const suivies = new Set(demoDb.campagnes.filtrer((c) => c.suivre_ouvertures).map((c) => c.id));
  // Jamais de pixel pour un contact déjà opposé : une « ouverture » postérieure à
  // l'inscription au registre d'opposition n'est pas comptée.
  const opposeLe = new Map<string, number>();
  for (const o of demoDb.opposition.tous()) {
    if (o.portee !== 'adresse') continue;
    const cle = adresseSansAccent(o.valeur);
    const t = Date.parse(o.inscrite_le);
    if (!opposeLe.has(cle) || t < opposeLe.get(cle)!) opposeLe.set(cle, t);
  }
  // Le taux estimé se rapporte aux seuls messages remis des campagnes qui suivent les ouvertures.
  const ouv = new Map<string, { estimees: number; auto: number; remisSuivis: number }>();
  for (const e of demoDb.evenementsEnvoi.tous()) {
    const t = Date.parse(e.survenu_le);
    if (t < depuisMs || t >= jusquaMs) continue;
    const g = groupe(par, e.campagne_id, e.boite_id);
    const l = lignes.get(g.cle) ?? vide(g.cle, g.libelle, g.version);
    lignes.set(g.cle, l);
    if (e.type === 'remis') {
      l.remis += 1; l.envoyes += 1;
      if (suivies.has(e.campagne_id)) {
        const o = ouv.get(g.cle) ?? { estimees: 0, auto: 0, remisSuivis: 0 };
        o.remisSuivis += 1;
        ouv.set(g.cle, o);
      }
    }
    else if (e.type === 'rebond_dur') { l.rebonds_durs += 1; l.envoyes += 1; }
    else if (e.type === 'rebond_mou') l.rebonds_mous += 1;
    else if (e.type === 'plainte') l.plaintes += 1;
    else if (e.type === 'desinscription') l.desinscriptions += 1;
    else if (e.type === 'reponse') {
      l.reponses += 1;
      if (e.destinataire_id !== null && interessees.has(e.destinataire_id)) l.reponses_interessees += 1;
    } else if (e.type === 'ouverture' && suivies.has(e.campagne_id) && !(t >= (opposeLe.get(adresseSansAccent(e.adresse)) ?? Infinity))) {
      const o = ouv.get(g.cle) ?? { estimees: 0, auto: 0, remisSuivis: 0 };
      if (e.automatique_probable === true) o.auto += 1; else o.estimees += 1;
      ouv.set(g.cle, o);
    }
  }
  for (const [cle, l] of lignes) {
    const o = ouv.get(cle);
    if (o) l.ouvertures = { estimees: o.estimees, automatiques_probables: o.auto, taux_estime: o.remisSuivis === 0 ? 0 : Math.round((o.estimees / o.remisSuivis) * 1000) / 10, mention: 'estimation' };
  }
  const data = [...lignes.values()].sort((a, b) => b.envoyes - a.envoyes);
  const totaux = data.reduce<Ligne>((t, l) => ({
    ...t,
    envoyes: t.envoyes + l.envoyes, remis: t.remis + l.remis, rebonds_durs: t.rebonds_durs + l.rebonds_durs, rebonds_mous: t.rebonds_mous + l.rebonds_mous,
    plaintes: t.plaintes + l.plaintes, desinscriptions: t.desinscriptions + l.desinscriptions, reponses: t.reponses + l.reponses, reponses_interessees: t.reponses_interessees + l.reponses_interessees,
  }), vide('total', 'Total'));
  return { data, totaux };
}

function adresseSansAccent(s: string): string { return s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim(); }

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/resultats'), async ({ request }) => {
    await delai();
    const url = new URL(request.url);
    const par = (url.searchParams.get('par') ?? 'campagne') as Regroupement;
    const jours = [7, 30, 90].includes(Number(url.searchParams.get('jours'))) ? Number(url.searchParams.get('jours')) : 30;
    const fin = maintenant().getTime();
    const debut = fin - jours * JOUR;
    const periode = { du: new Date(debut).toISOString().slice(0, 10), au: new Date(fin).toISOString().slice(0, 10) };
    const force = await repondreEtatForce(request, { data: [], totaux: vide('total', 'Total'), precedent: vide('precedent', 'Période d’avant'), entonnoir: { envoyes: 0, reponses: 0, rendez_vous: 0, gagnes: 0 }, periode });
    if (force) return force;
    const { data, totaux } = calculer(par, debut, fin);
    const avant = calculer(par, debut - jours * JOUR, debut).totaux;
    const rendezVous = Math.round(totaux.reponses_interessees * 0.6);
    return HttpResponse.json({
      data, totaux, precedent: { ...avant, libelle: 'Période d’avant' },
      entonnoir: { envoyes: totaux.envoyes, reponses: totaux.reponses, rendez_vous: rendezVous, gagnes: Math.round(rendezVous * 0.4) },
      periode,
    });
  }),

  http.get(api('/envoi/resultats/courbe'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    const fin = maintenant().getTime();
    const jours = Array.from({ length: 30 }, (_, i) => new Date(fin - (29 - i) * JOUR).toISOString().slice(0, 10));
    const campagneId = new URL(request.url).searchParams.get('campagne_id');
    const compte = new Map(jours.map((j) => [j, { remis: 0, rebonds: 0, reponses: 0 }]));
    for (const e of demoDb.evenementsEnvoi.tous()) {
      if (campagneId !== null && e.campagne_id !== campagneId) continue;
      const c = compte.get(e.survenu_le.slice(0, 10));
      if (!c) continue;
      if (e.type === 'remis') c.remis += 1; else if (e.type === 'rebond_dur' || e.type === 'rebond_mou') c.rebonds += 1; else if (e.type === 'reponse') c.reponses += 1;
    }
    return HttpResponse.json({ data: jours.map((jour) => ({ jour, ...compte.get(jour)!, ouvertures_estimees: null })) });
  }),

  http.get(api('/envoi/sante'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { feu: 'gris', boites: [], domaines: [], serveurs: [], test_du_matin: { le: maintenant().toISOString(), temoins_ok: 0, temoins_total: 0, etat: 'gris' }, alertes: 0, verifie_le: maintenant().toISOString(), volume_jour: { possibles: 0, envoyes: 0 }, rythme_messageries: [], envoyes_14_jours: [] });
    if (force) return force;
    const boites = demoDb.boites.tous();
    const actives = boites.filter((b) => b.statut !== 'suspendue');
    const feu = demoDb.etatEnvois.lire().etat === 'arrete' ? 'rouge' : actives.some((b) => b.etat === 'rouge') ? 'rouge' : actives.some((b) => b.etat === 'ambre') ? 'ambre' : 'vert';
    const t = zone().testMatin[0]!;
    const fin = maintenant().getTime();
    const par = new Map<string, number>();
    for (const e of demoDb.evenementsEnvoi.tous()) if (e.type === 'remis') par.set(e.survenu_le.slice(0, 10), (par.get(e.survenu_le.slice(0, 10)) ?? 0) + 1);
    const total = boites.reduce((n, b) => n + b.envoyes_aujourdhui, 0);
    return HttpResponse.json({
      feu, boites, domaines: demoDb.domaines.tous(), serveurs: demoDb.serveurs.tous(),
      test_du_matin: { le: t.le, temoins_ok: t.temoins_ok, temoins_total: t.temoins_total, etat: t.temoins_ok === t.temoins_total ? 'vert' : 'ambre' },
      alertes: demoDb.notifications.filtrer((n) => n.produit === 'envoi' && n.read_at === null).length,
      verifie_le: new Date(fin - 3 * 60_000).toISOString(),
      volume_jour: { possibles: boites.reduce((n, b) => n + b.quota_jour, 0), envoyes: total },
      rythme_messageries: [
        { messagerie: 'Messagerie grand public A', envoyes: Math.round(total * 0.41), par_heure: 10, etat: 'normal' },
        { messagerie: 'Messagerie opérateur B', envoyes: Math.round(total * 0.32), par_heure: 6, etat: 'normal' },
        { messagerie: 'Messagerie pro C', envoyes: Math.round(total * 0.27), par_heure: 3, etat: 'freine' },
      ],
      envoyes_14_jours: Array.from({ length: 14 }, (_, i) => { const jour = new Date(fin - (13 - i) * JOUR).toISOString().slice(0, 10); return { jour, envoyes: jour === new Date(fin).toISOString().slice(0, 10) ? total : (par.get(jour) ?? 0) }; }),
    });
  }),

  http.post(api('/envoi/sante/test'), async () => {
    await delai();
    const le = maintenant().toISOString();
    demoDb.zone<TestMatin>('pilotage', 'testMatin').modifier('test', { le, temoins_ok: 4, temoins_total: 4 });
    return HttpResponse.json({ le, temoins_ok: 4, temoins_total: 4, etat: 'vert' });
  }),

  http.get(api('/envoi/envois/chercher'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { adresse: '', personne: null, evenements: [], peut_ecrire: true, raison: null });
    if (force) return force;
    const q = adresseSansAccent(new URL(request.url).searchParams.get('adresse') ?? '');
    if (q.length < 2) return erreur(422, 'validation', 'Saisissez une adresse e-mail.');
    const contact = demoDb.contacts.tous().find((c) => adresseSansAccent(c.email) === q)
      ?? demoDb.contacts.tous().find((c) => adresseSansAccent(c.email).includes(q) || adresseSansAccent(`${c.first_name} ${c.last_name}`).includes(q));
    const adresse = contact?.email ?? q;
    const evenements = demoDb.evenementsEnvoi.filtrer((e) => adresseSansAccent(e.adresse) === adresseSansAccent(adresse) && e.type !== 'ouverture')
      .sort((a, b) => Date.parse(b.survenu_le) - Date.parse(a.survenu_le));
    const rebond = evenements.find((e) => e.type === 'rebond_dur');
    const opposition = demoDb.opposition.filtrer((o) => adresseSansAccent(o.valeur) === adresseSansAccent(adresse))[0];
    const raison = rebond ? `${rebond.libelle ?? 'Refus du serveur'} (${rebond.code_serveur ?? '—'})` : opposition ? opposition.motif : contact?.ne_plus_ecrire ? 'Ne plus écrire' : null;
    const entreprise = contact ? demoDb.entreprises.trouver(contact.company_id) : undefined;
    return HttpResponse.json({
      adresse,
      personne: contact ? { contact_id: contact.id, nom: `${contact.first_name} ${contact.last_name}`, organisation: entreprise?.denomination ?? null } : null,
      evenements, peut_ecrire: raison === null, raison,
    });
  }),

  http.get(api('/envoi/feu-vert'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { points: [], faits: 0, total: 0 });
    if (force) return force;
    const points = zone().points;
    return HttpResponse.json({ points: points.map(lignePoint), faits: points.filter((p) => p.fait).length, total: points.length });
  }),

  http.post(api('/envoi/feu-vert/:code/fait'), async ({ params }) => {
    await delai();
    const p = demoDb.zone<PointFeuVert>('pilotage', 'points').trouver(String(params['code']));
    if (!p) return erreur(404, 'non_trouve', 'Ce point n’existe pas.');
    if (!p.manuel) return erreur(409, 'point_automatique', 'Ce point se vérifie tout seul.');
    const maj = demoDb.zone<PointFeuVert>('pilotage', 'points').modifier(p.id, { fait: true, detail: 'Fait (démo)' });
    return HttpResponse.json(lignePoint(maj!));
  }),
];
