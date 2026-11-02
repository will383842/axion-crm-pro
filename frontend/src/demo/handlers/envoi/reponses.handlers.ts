/**
 * Démo d'Envoi — la boîte des réponses (DM2-REP).
 * Contrat : `backend/openapi/envoi/reponses.yaml`.
 *
 * Décisions de Will :
 *  · les réponses vivent dans Envoi ; le CRM ne reçoit qu'un RÉSUMÉ
 *    (classement, date, lien, tâche) ;
 *  · une opposition (STOP) met le contact à l'écart des envois, les données
 *    sont GARDÉES : on ajoute une ligne au registre, on ne supprime jamais.
 *
 * Rien ne part pour de vrai : « répondre » ajoute un message au fil de la base
 * en mémoire.
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/reponses';
import type { components as communs } from '@/features/envoi/api/commun';
import { demoDb } from '../../base';
import { ilYa, maintenant } from '../../horloge';
import { api, delai, erreur, filtrerTexte, lireRecherche, paginer, repondreEtatForce } from '../../outils';

type Reponse = communs['schemas']['ReponseResume'];
type Classement = communs['schemas']['ClassementReponse'];
type MessageFil = components['schemas']['MessageFil'];
type Suggestion = components['schemas']['SuggestionRattachement'];
type ResumeCrm = components['schemas']['ResumeCrm'];
type ReponseApresGeste = components['schemas']['ReponseApresGeste'];

interface MessageEnvoye extends MessageFil {
  reponse_id: string;
}
interface TacheCrm {
  id: string;
  reponse_id: string;
  libelle: string;
  creee_le: string;
}
interface Attribution {
  id: string;
  reponse_id: string;
  a: string;
  le: string;
}
interface Rattachement {
  id: string;
  reponse_id: string;
  type: 'contact' | 'entreprise' | 'carnet';
  reference: string;
  le: string;
}
interface Provenance {
  id: string;
  contact_id: number;
  texte: string;
}
interface Raccourci {
  id: string;
  nom: string;
  corps: string;
}

const ADRESSE_INCONNUE = 'p.morin@boulangerie-tilleuls.example';


/** Réponses ajoutées à la démo (le jeu de base n'en a que trois à traiter) : idempotent, jamais de doublon. */
const REPONSES_DE_DEMO: Reponse[] = [
  {
    id: 'rep-morin-autre',
    recue_le: ilYa(1, 18, 2),
    campagne_id: 'cmp-01',
    destinataire_id: null,
    boite_id: 'boi-claire',
    de: ADRESSE_INCONNUE,
    prenom: 'Paul',
    nom: 'Morin',
    organisation: 'Boulangerie des Tilleuls',
    objet: 'RE: Une idée pour votre prochain salon',
    extrait: 'Je réponds depuis mon autre adresse : je suis partant pour jeudi.',
    classement: 'a_rattacher',
    suggestion: { classement: 'interesse', confiance: 88 },
    traitee: false,
  },
  {
    id: 'rep-demo-costa',
    recue_le: ilYa(1, 9, 12),
    campagne_id: 'cmp-01',
    destinataire_id: null,
    boite_id: 'boi-claire',
    de: 'lina.costa@courrier-rhone.example',
    prenom: 'Lina',
    nom: 'Costa',
    organisation: 'Le Courrier du Rhône',
    objet: 'RE: Une idée pour votre prochain salon',
    extrait: 'Je suis absente jusqu’au 12 novembre. Pour toute urgence, contactez l’accueil.',
    classement: 'absence',
    suggestion: { classement: 'absence', confiance: 96 },
    traitee: false,
  },
  {
    id: 'rep-demo-perrin',
    recue_le: ilYa(2, 14, 40),
    campagne_id: 'cmp-01',
    destinataire_id: null,
    boite_id: 'boi-sophie',
    de: 'hugo.perrin@federation-btp.example',
    prenom: 'Hugo',
    nom: 'Perrin',
    organisation: 'Fédération BTP Rhône',
    objet: 'RE: Salon des artisans : on en parle ?',
    extrait: 'Quels sont vos tarifs pour une petite structure ?',
    classement: 'question',
    suggestion: { classement: 'question', confiance: 84 },
    traitee: false,
  },
  {
    id: 'rep-demo-meyer',
    recue_le: ilYa(2, 10, 5),
    campagne_id: 'cmp-01',
    destinataire_id: null,
    boite_id: 'boi-lucie',
    de: 'sonia.meyer@salon-vienne.example',
    prenom: 'Sonia',
    nom: 'Meyer',
    organisation: 'Salon des artisans de Vienne',
    objet: 'RE: Une idée pour votre prochain salon',
    extrait: 'Pas pour le moment, recontactez-moi au printemps.',
    classement: 'pas_maintenant',
    suggestion: { classement: 'pas_maintenant', confiance: 79 },
    traitee: false,
  },
];

/** Données propres à la zone « réponses » : fil, tâches, rattachements. */
function fabrique() {
  const raccourcis: Raccourci[] = [
    { id: 'rac-1', nom: 'Merci, je vous rappelle', corps: 'Merci pour votre retour. Je vous rappelle très vite pour en parler de vive voix.' },
    { id: 'rac-2', nom: 'Proposer un rendez-vous', corps: 'Avec plaisir. Seriez-vous disponible jeudi ou vendredi pour un court rendez-vous téléphonique ?' },
    { id: 'rac-3', nom: 'Envoyer la plaquette', corps: 'Je vous joins notre présentation (document fictif de démonstration). N’hésitez pas à me poser vos questions.' },
  ];
  return {
    raccourcis,
    messages: [] as MessageEnvoye[],
    taches: [] as TacheCrm[],
    attributions: [] as Attribution[],
    rattachements: [] as Rattachement[],
    provenances: [] as Provenance[],
  };
}

/** Les collections de la zone, prêtes à l'emploi (et la réponse « adresse inconnue » de Paul Morin). */
function zone() {
  demoDb.etendre('reponses', fabrique);
  for (const r of REPONSES_DE_DEMO) {
    if (!demoDb.reponses.trouver(r.id)) demoDb.reponses.ajouter(r);
  }
  return {
    raccourcis: demoDb.zone<Raccourci>('reponses', 'raccourcis'),
    messages: demoDb.zone<MessageEnvoye>('reponses', 'messages'),
    taches: demoDb.zone<TacheCrm>('reponses', 'taches'),
    attributions: demoDb.zone<Attribution>('reponses', 'attributions'),
    rattachements: demoDb.zone<Rattachement>('reponses', 'rattachements'),
    provenances: demoDb.zone<Provenance>('reponses', 'provenances'),
  };
}

// ── Aides ─────────────────────────────────────────────────────────────────

const FILES = {
  a_traiter: (r: Reponse) => !r.traitee && r.classement !== 'a_rattacher',
  a_rattacher: (r: Reponse) => !r.traitee && r.classement === 'a_rattacher',
  traitees: (r: Reponse) => r.traitee,
} as const;
type File = keyof typeof FILES;

function nomComplet(r: Reponse): string {
  return [r.prenom, r.nom].filter(Boolean).join(' ') || r.de;
}

function parFile(): Record<File, number> {
  const toutes = demoDb.reponses.tous();
  return {
    a_traiter: toutes.filter(FILES.a_traiter).length,
    a_rattacher: toutes.filter(FILES.a_rattacher).length,
    traitees: toutes.filter(FILES.traitees).length,
  };
}

function libelleTache(r: Reponse, classement: Classement): string | null {
  const nom = nomComplet(r);
  if (classement === 'interesse') return /jeudi/i.test(r.extrait) ? 'Rappeler jeudi' : `Rappeler ${nom}`;
  if (classement === 'question') return `Répondre à la question de ${nom}`;
  if (classement === 'pas_maintenant') return `Recontacter ${nom} au printemps`;
  return null;
}

function lien(r: Reponse): string {
  return `/envoi/reponses/traitees?reponse=${String(r.id)}`;
}

function suggestionsPour(r: Reponse): Suggestion[] {
  const organisation = (r.organisation ?? '').trim().toLowerCase();
  if (organisation === '') return [];
  const entreprise = demoDb.entreprises.tous().find((e) => e.denomination.trim().toLowerCase() === organisation);
  if (!entreprise) return [];
  const domaine = r.de.split('@')[1] ?? '';
  const note = (c: { first_name: string; last_name: string }) =>
    c.last_name === r.nom && c.first_name === r.prenom ? 0 : c.last_name === r.nom ? 1 : 2;
  const contacts = demoDb.contacts
    .filtrer((c) => c.company_id === entreprise.id)
    .sort((a, b) => note(a) - note(b))
    .slice(0, 2)
    .map<Suggestion>((c) => ({
      type: 'contact',
      reference: String(c.id),
      libelle: `${c.first_name} ${c.last_name}`,
      raison:
        note(c) === 0
          ? `${entreprise.denomination} · ${c.email.endsWith(`@${domaine}`) ? 'même domaine' : 'même nom et même organisation'}`
          : note(c) === 1
            ? `${entreprise.denomination} · même nom de famille`
            : `${entreprise.denomination} · même organisation`,
    }));
  return [
    ...contacts,
    { type: 'entreprise', reference: String(entreprise.id), libelle: entreprise.denomination, raison: 'Fiche entreprise · adresse générique' },
  ];
}

function filDe(r: Reponse): MessageFil[] {
  const boite = demoDb.boites.trouver(r.boite_id);
  const recue = Date.parse(r.recue_le);
  const initial: MessageFil = {
    id: `msg-${String(r.id)}-1`,
    sens: 'envoye',
    de: boite?.adresse ?? 'envoi@prospection-a.example',
    le: new Date(recue - 3 * 86_400_000).toISOString(),
    objet: r.objet.replace(/^RE:\s*/i, ''),
    corps: `Bonjour ${r.prenom ?? ''}, j’ai vu que ${r.organisation ?? 'votre structure'} organise des événements. Nous aidons les organisateurs à simplifier leurs envois. Seriez-vous disponible pour en parler ?`.replace('Bonjour ,', 'Bonjour,'),
  };
  const recu: MessageFil = { id: `msg-${String(r.id)}-2`, sens: 'recu', de: r.de, le: r.recue_le, objet: r.objet, corps: r.extrait };
  const siens = zone()
    .messages.filtrer((m) => m.reponse_id === String(r.id))
    .map<MessageFil>(({ reponse_id: _ignore, ...m }) => m);
  return [initial, recu, ...siens];
}

function resume(r: Reponse, classement: Classement, tache: string | null, opposition = false): ResumeCrm {
  return { classement, le: maintenant().toISOString(), lien: lien(r), tache, opposition };
}

function apresGeste(r: Reponse, resumeCrm: ResumeCrm | null): ReponseApresGeste {
  return { ...r, resume_crm: resumeCrm };
}

function texteDe(valeur: unknown): string {
  return typeof valeur === 'string' ? valeur.trim() : '';
}

function lireCorps(request: Request): Promise<Record<string, unknown>> {
  return request.json().then((c) => (c ?? {}) as Record<string, unknown>).catch(() => ({}));
}

/** Après rattachement : la réponse a une fiche, elle revient dans « À traiter ». */
function remettreATraiter(r: Reponse): Reponse | undefined {
  const classement: Classement = r.suggestion.classement === 'a_rattacher' ? 'question' : r.suggestion.classement;
  return demoDb.reponses.modifier(r.id, { classement, traitee: false });
}

// ── Les routes ────────────────────────────────────────────────────────────

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/reponses/raccourcis'), async () => {
    await delai();
    return HttpResponse.json({
      data: zone().raccourcis.tous(),
      collegues: demoDb.utilisateurs.tous().map((u) => ({ id: u.id, nom: u.name })),
    });
  }),

  http.get(api('/envoi/reponses'), async ({ request }) => {
    await delai();
    zone();
    const forcee = await repondreEtatForce(request, {
      data: [],
      meta: paginer([], request).meta,
      par_file: { a_traiter: 0, a_rattacher: 0, traitees: 0 },
    });
    if (forcee) return forcee;
    const url = new URL(request.url);
    const file = (url.searchParams.get('file') ?? 'a_traiter') as File;
    const classement = url.searchParams.get('classement');
    let liste = demoDb.reponses.tous().filter(FILES[file] ?? FILES.a_traiter);
    if (classement) liste = liste.filter((r) => r.classement === classement);
    const texte = filtrerTexte(
      liste.map((r) => ({ ...r, nom_complet: nomComplet(r) })),
      lireRecherche(request),
      ['nom_complet', 'de', 'organisation', 'extrait', 'objet'],
    );
    const ordonnee = texte
      .map(({ nom_complet: _n, ...r }) => r)
      .sort((a, b) => {
        if (file === 'a_traiter') {
          const ia = a.suggestion.classement === 'interesse' ? 0 : 1;
          const ib = b.suggestion.classement === 'interesse' ? 0 : 1;
          if (ia !== ib) return ia - ib;
        }
        return Date.parse(b.recue_le) - Date.parse(a.recue_le);
      });
    return HttpResponse.json({ ...paginer(ordonnee, request), par_file: parFile() });
  }),

  http.get(api('/envoi/reponses/:id'), async ({ params }) => {
    await delai();
    zone();
    const r = demoDb.reponses.trouver(String(params['id']));
    if (!r) return erreur(404, 'introuvable', 'Cette réponse n’existe pas.');
    return HttpResponse.json({ reponse: r, fil: filDe(r), suggestions_rattachement: r.classement === 'a_rattacher' ? suggestionsPour(r) : [] });
  }),

  http.post(api('/envoi/reponses/:id/classer'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const r = demoDb.reponses.trouver(String(params['id']));
    if (!r) return erreur(404, 'introuvable', 'Cette réponse n’existe pas.');
    const corps = await lireCorps(request);
    const classement = corps['classement'] as Classement | undefined;
    if (!classement) return erreur(422, 'classement_manquant', 'Choisissez un classement.');
    const maj = demoDb.reponses.modifier(r.id, { classement, traitee: true }) ?? r;

    // Arrêter la séquence : la personne ne reçoit plus de relance (elle a répondu).
    const destinataire = maj.destinataire_id !== null ? demoDb.destinataires.trouver(maj.destinataire_id) : undefined;
    if (destinataire && destinataire.etat !== 'desinscrit') demoDb.destinataires.modifier(destinataire.id, { etat: classement === 'stop' ? 'desinscrit' : 'a_repondu' });

    if (classement === 'stop') {
      // Mise à l'écart des envois — AUCUNE suppression : on ajoute au registre.
      const instant = maintenant().toISOString();
      if (!demoDb.opposition.filtrer((o) => o.portee === 'adresse' && o.valeur === maj.de).length) {
        demoDb.opposition.ajouter({ id: `opp-rep-${String(maj.id)}`, portee: 'adresse', valeur: maj.de, motif: 'Réponse « stop »', inscrite_le: instant, source: 'reponse_stop' });
      }
      if (corps['toute_organisation'] === true && maj.organisation) {
        demoDb.opposition.ajouter({ id: `opp-org-${String(maj.id)}`, portee: 'organisation', valeur: maj.organisation, motif: 'Réponse « stop » étendue à l’organisation', inscrite_le: instant, source: 'reponse_stop' });
      }
      demoDb.desinscriptions.ajouter({ id: `des-rep-${String(maj.id)}`, adresse: maj.de, survenue_le: instant, source: 'reponse_stop', campagne_id: maj.campagne_id });
      const contact = demoDb.contacts.filtrer((c) => c.email === maj.de)[0];
      if (contact) demoDb.contacts.modifier(contact.id, { ne_plus_ecrire: true });
      return HttpResponse.json(apresGeste(maj, resume(maj, classement, null, true)));
    }

    let tache: string | null = null;
    if (corps['creer_tache'] === true) {
      tache = libelleTache(maj, classement);
      if (tache) z.taches.ajouter({ id: `tch-${String(maj.id)}`, reponse_id: String(maj.id), libelle: tache, creee_le: maintenant().toISOString() });
    }
    return HttpResponse.json(apresGeste(maj, resume(maj, classement, tache)));
  }),

  http.post(api('/envoi/reponses/:id/rattacher'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const r = demoDb.reponses.trouver(String(params['id']));
    if (!r) return erreur(404, 'introuvable', 'Cette réponse n’existe pas.');
    const corps = await lireCorps(request);
    const type = corps['type'] as Rattachement['type'] | undefined;
    const reference = corps['reference'];
    if (!type || typeof reference !== 'string' || reference === '') return erreur(422, 'fiche_manquante', 'Choisissez une fiche.');
    z.rattachements.ajouter({ id: `rat-${String(r.id)}`, reponse_id: String(r.id), type, reference, le: maintenant().toISOString() });
    const maj = remettreATraiter(r) ?? r;
    return HttpResponse.json(apresGeste(maj, resume(maj, maj.classement, null)));
  }),

  http.post(api('/envoi/reponses/:id/creer-fiche'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const r = demoDb.reponses.trouver(String(params['id']));
    if (!r) return erreur(404, 'introuvable', 'Cette réponse n’existe pas.');
    const corps = await lireCorps(request);
    const prenom = texteDe(corps['prenom']);
    const nom = texteDe(corps['nom']);
    const provenance = texteDe(corps['provenance']);
    if (prenom === '' || nom === '') return erreur(422, 'identite_manquante', 'Indiquez le prénom et le nom.');
    if (provenance === '') return erreur(422, 'provenance_manquante', 'Indiquez d’où vient cette personne.');
    const organisation = typeof corps['organisation'] === 'string' ? corps['organisation'].trim() : (r.organisation ?? '');
    const entreprise = demoDb.entreprises.tous().find((e) => e.denomination.trim().toLowerCase() === organisation.toLowerCase());
    const contact = demoDb.contacts.ajouter({
      id: demoDb.contacts.prochainId(),
      company_id: entreprise?.id ?? 0,
      first_name: prenom,
      last_name: nom,
      role: '',
      email: r.de,
      etiquettes: ['reponse-envoi'],
      ne_plus_ecrire: false,
      created_at: maintenant().toISOString(),
    });
    z.provenances.ajouter({ id: `prv-${String(r.id)}`, contact_id: contact.id, texte: provenance });
    z.rattachements.ajouter({ id: `rat-${String(r.id)}`, reponse_id: String(r.id), type: 'contact', reference: String(contact.id), le: maintenant().toISOString() });
    const maj = remettreATraiter(r) ?? r;
    return HttpResponse.json(apresGeste(maj, resume(maj, maj.classement, null)));
  }),

  http.post(api('/envoi/reponses/:id/repondre'), async ({ params, request }) => {
    await delai();
    const z = zone();
    const r = demoDb.reponses.trouver(String(params['id']));
    if (!r) return erreur(404, 'introuvable', 'Cette réponse n’existe pas.');
    const corps = await lireCorps(request);
    const modele = typeof corps['raccourci_id'] === 'string' ? z.raccourcis.trouver(corps['raccourci_id']) : undefined;
    const texte = (typeof corps['corps'] === 'string' ? corps['corps'] : '').trim() || modele?.corps || '';
    if (texte === '') return erreur(422, 'message_vide', 'Écrivez votre réponse avant de l’envoyer.');
    const boite = demoDb.boites.trouver(r.boite_id);
    const instant = maintenant().toISOString();
    // Même fil, même boîte. Rien ne part pour de vrai : le message reste dans la base de démo.
    z.messages.ajouter({
      id: `msg-${String(r.id)}-s${z.messages.compter() + 1}`,
      reponse_id: String(r.id),
      sens: 'envoye',
      de: boite?.adresse ?? 'envoi@prospection-a.example',
      le: instant,
      objet: r.objet,
      corps: texte,
    });
    const collegue = typeof corps['attribuer_a'] === 'string' && corps['attribuer_a'] !== '' ? corps['attribuer_a'] : null;
    if (collegue) z.attributions.ajouter({ id: `att-${String(r.id)}`, reponse_id: String(r.id), a: collegue, le: instant });
    return HttpResponse.json(apresGeste(r, resume(r, r.classement, null)));
  }),
];
