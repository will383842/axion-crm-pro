/**
 * Démo des écrans communs (DM1-COM) : code reçu par e-mail, Mon compte et
 * sécurité, Utilisateurs (accès par produit), Paramètres, Demandes RGPD,
 * Journal des actions.
 *
 * Tout est factice : e-mails en `@example.test`, adresses IP des plages de
 * documentation, aucune personne réelle. Les gestes modifient `demoDb` (zone
 * `ecrans-communs`) et survivent au rechargement de la page.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import { demoDb } from '../../base';
import { IDENTIFIANTS_DEMO } from '../../donnees';
import { ilYa, maintenant } from '../../horloge';
import { api, delai, erreur, repondreEtatForce } from '../../outils';
import { ecrireSession, lireSession } from '../../session';

const MAX_RENVOIS = 3;
const MOT_DE_PASSE_MIN = 12;

interface Acces { id: string; crm: boolean; envoi: boolean; actif: boolean; derniere_connexion: string }
interface Appareil { id: string; nom: string; lieu: string; ip: string; ajoute_le: string; expire_le: string; actuel: boolean; retire: boolean }
interface DemandeRgpd {
  id: number; type: string; status: string; subject_email: string;
  requested_at: string; processed_at: string | null; note: string | null; metadata: null;
}
interface EntreeJournal {
  id: number; event_type: string; produit: 'crm' | 'envoi' | 'commun'; path: string; status_code: number;
  ip: string; user_agent: string; created_at: string; current_hash: string; previous_hash: string | null;
  actor: string; target: string | null; severity: 'info' | 'warning' | 'error' | 'critical';
}
interface Reglage { id: string; name: string; slug: string; cost_cap_eur: number; settings: Record<string, unknown> }
interface Secours { id: string; restants: number }

const STATUTS_OUVERTS = ['pending', 'processing'];

/** Demandes d'effacement ouvertes (pastille « Effacements demandés » du menu). */
export function nombreEffacementsOuverts(): number {
  donnees();
  return demoDb.zone<DemandeRgpd>('ecrans-communs', 'rgpd').filtrer((d) => d.type === 'erasure' && STATUTS_OUVERTS.includes(d.status)).length;
}

const empreinte = (n: number) => `d${String(n).padStart(3, '0')}a9c4e17b2f06835d`;

function donnees() {
  return demoDb.etendre('ecrans-communs', () => {
    const acces: Acces[] = demoDb.utilisateurs.tous().map((u, i) => ({
      id: u.id, crm: true, envoi: i < 3, actif: true, derniere_connexion: ilYa(i, 8, 40),
    }));
    const appareils: Appareil[] = [
      { id: 'app-1', nom: 'Navigateur sur ordinateur (Firefox)', lieu: 'Lyon', ip: '192.0.2.10', ajoute_le: ilYa(12, 9, 0), expire_le: ilYa(-18, 9, 0), actuel: true, retire: false },
      { id: 'app-2', nom: 'Téléphone (Safari)', lieu: 'Lyon', ip: '198.51.100.24', ajoute_le: ilYa(21, 18, 30), expire_le: ilYa(-9, 18, 30), actuel: false, retire: false },
      { id: 'app-3', nom: 'Tablette (Chrome)', lieu: 'Vienne', ip: '203.0.113.7', ajoute_le: ilYa(27, 11, 15), expire_le: ilYa(-3, 11, 15), actuel: false, retire: false },
    ];
    const rgpd: DemandeRgpd[] = [
      { id: 1, type: 'access', status: 'pending', subject_email: 'paul.morin@example.test', requested_at: ilYa(4, 14, 10), processed_at: null, note: null, metadata: null },
      { id: 2, type: 'opposition', status: 'pending', subject_email: 'yves.roche@example.test', requested_at: ilYa(2, 10, 5), processed_at: null, note: null, metadata: null },
      { id: 3, type: 'rectification', status: 'done', subject_email: 'lea.fournier@example.test', requested_at: ilYa(20, 9, 0), processed_at: ilYa(18, 16, 30), note: 'Adresse corrigée.', metadata: null },
      // Demandes d'EFFACEMENT (exemple) : la personne est déjà mise à l'écart, rien n'est supprimé.
      { id: 4, type: 'erasure', status: 'expired', subject_email: 'exemple-13@exemple.invalid', requested_at: ilYa(60, 8, 40), processed_at: ilYa(40, 9, 0), note: null, metadata: null },
      { id: 5, type: 'erasure', status: 'rejected', subject_email: 'exemple-14@exemple.invalid', requested_at: ilYa(40, 14, 22), processed_at: ilYa(38, 9, 0), note: null, metadata: null },
      { id: 6, type: 'erasure', status: 'done', subject_email: 'exemple-10@exemple.invalid', requested_at: ilYa(23, 16, 52), processed_at: ilYa(22, 8, 0), note: null, metadata: null },
      { id: 7, type: 'erasure', status: 'processing', subject_email: 'exemple-08@exemple.invalid', requested_at: ilYa(5, 11, 3), processed_at: null, note: null, metadata: null },
      { id: 8, type: 'erasure', status: 'pending', subject_email: 'exemple-07@exemple.invalid', requested_at: ilYa(2, 19, 40), processed_at: null, note: null, metadata: null },
      { id: 9, type: 'erasure', status: 'pending', subject_email: 'exemple-01@exemple.invalid', requested_at: ilYa(0, 6, 12), processed_at: null, note: null, metadata: null },
    ];
    const evenements: Array<[string, EntreeJournal['produit'], string, string, string, EntreeJournal['severity']]> = [
      ['Connexion', 'commun', '/login', 'Claire Valette', '192.0.2.10', 'info'],
      ['Accès modifié', 'commun', '/users', 'Claire Valette', '192.0.2.10', 'info'],
      ['Contact ajouté', 'crm', '/contacts', 'Sophie Mercier', '198.51.100.24', 'info'],
      ['Collecte lancée', 'crm', '/collectes', 'Hugo Lambert', '198.51.100.31', 'info'],
      ['Campagne mise en pause', 'envoi', '/envoi/campagnes', 'Claire Valette', '192.0.2.10', 'warning'],
      ['Boîte ralentie', 'envoi', '/envoi/boites', 'Système', '203.0.113.7', 'warning'],
      ['Désinscription enregistrée', 'envoi', '/envoi/desinscriptions', 'Système', '203.0.113.7', 'info'],
      ['Code refusé', 'commun', '/2fa', 'Lucie Perrin', '198.51.100.99', 'error'],
    ];
    const journal: EntreeJournal[] = evenements.map(([event_type, produit, path, actor, ip, severity], i) => ({
      id: i + 1, event_type, produit, path, status_code: severity === 'error' ? 422 : 200, ip,
      user_agent: 'Navigateur de démonstration', created_at: ilYa(Math.floor(i / 3), 17 - i, 10 + i),
      current_hash: empreinte(i + 1), previous_hash: i === 0 ? null : empreinte(i), actor, target: null, severity,
    }));
    const reglages: Reglage[] = [
      { id: 'ws', name: 'Axion (démo)', slug: 'axion-demo', cost_cap_eur: 50, settings: {} },
    ];
    const secours: Secours[] = [{ id: 'secours', restants: 8 }];
    return { acces, appareils, rgpd, journal, reglages, secours, etat: [{ id: 'renvois', n: 0 }] };
  });
}

const role = { 'Propriétaire': 'owner', 'Administrateur': 'admin', 'Opérateur': 'operator', 'Lecteur': 'viewer' } as const;
const libelleRole = { owner: 'Propriétaire', admin: 'Administrateur', operator: 'Opérateur', viewer: 'Lecteur' } as const;
type CleRole = keyof typeof libelleRole;

function ligneUtilisateur(id: string) {
  const u = demoDb.utilisateurs.trouver(id);
  const a = demoDb.zone<Acces>('ecrans-communs', 'acces').trouver(id);
  if (!u || !a || !a.actif) return null;
  return {
    id: u.id, name: u.name, email: u.email, current_workspace_id: u.current_workspace_id,
    totp_enabled_at: u.totp_enabled_at, first_login_completed_at: u.totp_enabled_at,
    last_login_at: a.derniere_connexion, roles: [role[u.role]],
    acces_produits: { crm: a.crm, envoi: a.envoi },
  };
}

async function corps<T>(request: Request): Promise<Partial<T>> {
  return (await request.json().catch(() => ({}))) as Partial<T>;
}

const codeBon = (code: unknown) => code === IDENTIFIANTS_DEMO.code;
const mauvaisCode = () =>
  HttpResponse.json({ message: 'Code incorrect.', errors: { code: ['Code incorrect. Vérifiez le code affiché dans le bandeau.'] } }, { status: 422 });

/** Le CSV du registre des oppositions (en-têtes en français, une ligne par inscription). */
export function csvOppositions(): string {
  const portees = { adresse: 'Adresse', organisation: 'Organisation', domaine: 'Domaine' } as const;
  const guillemets = (v: string) => `"${v.replace(/"/g, '""')}"`;
  const lignes = demoDb.opposition.tous().map((o) =>
    [o.valeur, portees[o.portee], o.motif, o.inscrite_le].map(guillemets).join(';'),
  );
  return ['Valeur;Portée;Motif;Inscrite le', ...lignes].join('\n') + '\n';
}

export const handlers: HttpHandler[] = [
  // ── Code reçu par e-mail ────────────────────────────────────────────────
  http.post(api('/auth/code-email/verifier'), async ({ request }) => {
    await delai();
    if (lireSession() === 'deconnecte') return erreur(401, 'non_connecte', 'Reconnectez-vous.');
    const c = await corps<{ code: string }>(request);
    if (!codeBon(c.code)) return mauvaisCode();
    ecrireSession('connecte');
    return HttpResponse.json({ ok: true });
  }),

  http.post(api('/auth/code-email/renvoyer'), async () => {
    await delai();
    donnees();
    const etat = demoDb.zone<{ id: string; n: number }>('ecrans-communs', 'etat').trouver('renvois');
    const n = (etat?.n ?? 0) + 1;
    if (n > MAX_RENVOIS) return erreur(429, 'trop_de_renvois', 'Trop de renvois. Patientez quelques minutes.');
    demoDb.zone<{ id: string; n: number }>('ecrans-communs', 'etat').modifier('renvois', { n });
    return HttpResponse.json({ ok: true, renvois_restants: MAX_RENVOIS - n });
  }),

  // ── Mot de passe ────────────────────────────────────────────────────────
  http.get(api('/auth/password/change'), async () => {
    await delai();
    return HttpResponse.json({ email: IDENTIFIANTS_DEMO.email, mot_de_passe_actuel_requis: true, minutes_restantes_sans_ancien: null });
  }),
  http.post(api('/auth/password/change'), async ({ request }) => {
    await delai();
    const c = await corps<{ current_password: string; password: string; password_confirmation: string }>(request);
    if (c.current_password !== IDENTIFIANTS_DEMO.motDePasse) {
      return HttpResponse.json({ error: 'mot_de_passe_actuel_incorrect' }, { status: 422 });
    }
    if ((c.password ?? '').length < MOT_DE_PASSE_MIN || c.password !== c.password_confirmation) {
      return HttpResponse.json({ message: 'Mot de passe refusé.', errors: { password: [`${MOT_DE_PASSE_MIN} caractères minimum, identiques dans les deux champs.`] } }, { status: 422 });
    }
    return HttpResponse.json({ ok: true });
  }),

  // ── Mon compte et sécurité ──────────────────────────────────────────────
  http.get(api('/compte/appareils'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    donnees();
    return HttpResponse.json({ data: demoDb.zone<Appareil>('ecrans-communs', 'appareils').filtrer((a) => !a.retire) });
  }),
  http.post(api('/compte/appareils/:id/retirer'), async ({ request, params }) => {
    await delai();
    const c = await corps<{ code: string }>(request);
    if (!codeBon(c.code)) return mauvaisCode();
    donnees();
    const ok = demoDb.zone<Appareil>('ecrans-communs', 'appareils').modifier(String(params['id']), { retire: true });
    return ok ? HttpResponse.json({ ok: true }) : erreur(404, 'introuvable', 'Appareil introuvable.');
  }),
  http.get(api('/compte/codes-secours'), async () => {
    await delai();
    donnees();
    return HttpResponse.json({ restants: demoDb.zone<Secours>('ecrans-communs', 'secours').trouver('secours')?.restants ?? 0 });
  }),
  http.post(api('/compte/codes-secours/regenerer'), async ({ request }) => {
    await delai();
    const c = await corps<{ code: string }>(request);
    if (!codeBon(c.code)) return mauvaisCode();
    donnees();
    demoDb.zone<Secours>('ecrans-communs', 'secours').modifier('secours', { restants: 8 });
    const codes = Array.from({ length: 8 }, (_, i) => `DEMO-${String(1000 + i * 137).padStart(4, '0')}-${String(2000 + i * 211).padStart(4, '0')}`);
    return HttpResponse.json({ recovery_codes: codes });
  }),

  // ── Utilisateurs ────────────────────────────────────────────────────────
  http.get(api('/users'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    donnees();
    const lignes = demoDb.utilisateurs.tous().map((u) => ligneUtilisateur(u.id)).filter((l) => l !== null);
    return HttpResponse.json({ data: lignes });
  }),
  http.post(api('/users'), async ({ request }) => {
    await delai();
    const c = await corps<{ email: string; name: string; role: CleRole }>(request);
    if (!c.email || !c.name) return HttpResponse.json({ message: 'Nom et adresse requis.' }, { status: 422 });
    donnees();
    const id = `u-demo-${demoDb.utilisateurs.compter() + 1}`;
    demoDb.utilisateurs.ajouter({
      id, name: c.name, email: c.email.toLowerCase(), role: libelleRole[c.role ?? 'operator'],
      current_workspace_id: 'ws-demo', totp_enabled_at: '',
    });
    demoDb.zone<Acces>('ecrans-communs', 'acces').ajouter({ id, crm: true, envoi: false, actif: true, derniere_connexion: '' });
    return HttpResponse.json({ data: ligneUtilisateur(id), invitation_envoyee: false }, { status: 201 });
  }),
  http.put(api('/users/:id'), async ({ request, params }) => {
    await delai();
    donnees();
    const id = String(params['id']);
    const c = await corps<{ role: CleRole; acces_produits: { crm?: boolean; envoi?: boolean } }>(request);
    if (!demoDb.utilisateurs.trouver(id)) return erreur(404, 'introuvable', 'Utilisateur introuvable.');
    if (c.role) demoDb.utilisateurs.modifier(id, { role: libelleRole[c.role] });
    if (c.acces_produits) {
      const patch: Partial<Acces> = {};
      if (typeof c.acces_produits.crm === 'boolean') patch.crm = c.acces_produits.crm;
      if (typeof c.acces_produits.envoi === 'boolean') patch.envoi = c.acces_produits.envoi;
      demoDb.zone<Acces>('ecrans-communs', 'acces').modifier(id, patch);
    }
    return HttpResponse.json({ data: ligneUtilisateur(id) });
  }),
  // « Fermer » désactive l'accès : rien n'est supprimé.
  http.delete(api('/users/:id'), async ({ params }) => {
    await delai();
    donnees();
    const id = String(params['id']);
    demoDb.zone<Acces>('ecrans-communs', 'acces').modifier(id, { actif: false });
    return HttpResponse.json({ closed: id });
  }),

  // ── Paramètres ──────────────────────────────────────────────────────────
  http.get(api('/workspace'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, {});
    if (force) return force;
    donnees();
    return HttpResponse.json(demoDb.zone<Reglage>('ecrans-communs', 'reglages').trouver('ws'));
  }),
  http.put(api('/workspace'), async ({ request }) => {
    await delai();
    donnees();
    const c = await corps<Reglage>(request);
    const patch: Partial<Reglage> = {};
    if (typeof c.name === 'string') patch.name = c.name;
    if (typeof c.cost_cap_eur === 'number') patch.cost_cap_eur = c.cost_cap_eur;
    if (c.settings && typeof c.settings === 'object') patch.settings = c.settings;
    return HttpResponse.json({ data: demoDb.zone<Reglage>('ecrans-communs', 'reglages').modifier('ws', patch) });
  }),

  // ── Demandes RGPD ───────────────────────────────────────────────────────
  http.get(api('/rgpd/requests'), async ({ request }) => {
    await delai();
    // `export_oppositions` : la démo annonce l'export du registre (absent du vrai serveur).
    const force = await repondreEtatForce(request, { data: [], export_oppositions: true });
    if (force) return force;
    donnees();
    const url = new URL(request.url);
    const statut = url.searchParams.get('statut');
    const type = url.searchParams.get('type');
    let liste = [...demoDb.zone<DemandeRgpd>('ecrans-communs', 'rgpd').tous()].reverse();
    if (type) liste = liste.filter((d) => d.type === type);
    if (statut === 'ouverte') {
      // « Ouverte » = à traiter + en cours ; la plus ancienne en haut (délai légal).
      liste = liste.filter((d) => STATUTS_OUVERTS.includes(d.status)).sort((a, b) => a.requested_at.localeCompare(b.requested_at));
    } else if (statut === 'traitee') {
      liste = liste.filter((d) => !STATUTS_OUVERTS.includes(d.status));
    }
    return HttpResponse.json({ data: liste, export_oppositions: true });
  }),
  http.post(api('/rgpd/requests'), async ({ request }) => {
    await delai();
    donnees();
    const c = await corps<{ type: string; subject_email: string }>(request);
    const coll = demoDb.zone<DemandeRgpd>('ecrans-communs', 'rgpd');
    const nouvelle = coll.ajouter({
      id: coll.prochainId(), type: c.type ?? 'access', status: 'pending', subject_email: c.subject_email ?? '',
      requested_at: maintenant().toISOString(), processed_at: null, note: null, metadata: null,
    });
    return HttpResponse.json(nouvelle, { status: 201 });
  }),
  http.post(api('/rgpd/requests/:id/process'), async ({ request, params }) => {
    await delai();
    donnees();
    const c = await corps<{ note: string }>(request);
    const r = demoDb.zone<DemandeRgpd>('ecrans-communs', 'rgpd').modifier(Number(params['id']), {
      status: 'done', processed_at: maintenant().toISOString(), note: c.note || null,
    });
    return r ? HttpResponse.json({ request: r, result: { mise_a_l_ecart: { personnes: 1, abonnements: 0 }, supprime: 0 } }) : erreur(404, 'introuvable', 'Demande introuvable.');
  }),
  http.get(api('/rgpd/oppositions/export'), async () => {
    await delai();
    return new HttpResponse(csvOppositions(), {
      headers: { 'Content-Type': 'text/csv; charset=utf-8', 'Content-Disposition': 'attachment; filename="registre-des-oppositions.csv"' },
    });
  }),

  // ── Journal des actions ─────────────────────────────────────────────────
  http.get(api('/audit-logs'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    donnees();
    return HttpResponse.json({ data: [...demoDb.zone<EntreeJournal>('ecrans-communs', 'journal').tous()].reverse() });
  }),
  http.get(api('/audit-logs/verify-chain'), async () => {
    await delai();
    return HttpResponse.json({ valid: true });
  }),
];
