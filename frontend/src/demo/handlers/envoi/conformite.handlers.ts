/**
 * Faux serveur de démonstration — conformité et réglages d'envoi (DM2-CNF).
 * Contrat : `backend/openapi/envoi/conformite.yaml`.
 *
 * Rien n'est supprimé (règle n° 2) : une désinscription AJOUTE une ligne au
 * registre. Aucun e-mail ne part : l'« alerte de test » ne crée qu'une
 * notification de la cloche. Données fictives (`.example`, `@example.test`).
 */
import { http, HttpResponse, type HttpHandler } from 'msw';
import type { components } from '@/features/envoi/api/conformite';
import type { components as commun } from '@/features/envoi/api/commun';
import { verifierDurcissement } from '@/features/envoi/conformite/composants/regles';
import { LIBELLE_SOURCE, masquerAdresse } from '@/features/envoi/conformite/composants/format';
import { demoDb } from '../../base';
import { ilYa, maintenant } from '../../horloge';
import { api, delai, erreur, filtrerTexte, lireRecherche, paginer, repondreEtatForce } from '../../outils';

type S = components['schemas'];
type Opposition = commun['schemas']['Opposition'];

/** Lien de démonstration de la page publique (contact « Boulangerie des Tilleuls »). */
export const JETON_DEMO = 'demo-tilleuls';

interface Parametres {
  id: 'unique';
  identite: S['Identite'];
  alertes: { seconde_adresse: string | null; regroupement_minutes: number };
  regles: S['Regles'];
  absence: S['ModeAbsence'];
  crm: S['ConnexionCrm'];
}

const BLOC_ART_14 =
  'Vous recevez ce message car votre adresse professionnelle est publique. Données : nom, adresse, entreprise. Source : annuaires publics. Se désinscrire en un clic.';

function zone() {
  return demoDb.etendre('conformite', () => ({
    alertes: [
      { id: 'al-1', titre: 'Boîte « prospection-a@ » ralentie', detail: 'Refus temporaires à 3 % depuis 09:30.', ce_qui_se_passe: 'Refus temporaires à 3 % depuis 09:30.', ce_que_le_systeme_a_fait: 'Volume divisé par 2.', que_faire: 'Rien si ça baisse ; sinon ouvrir la boîte.', niveau: 'attention', survenue_le: ilYa(0, 9, 30), lien: '/envoi/boites', close: false },
      { id: 'al-2', titre: 'DMARC : passer à « quarantaine »', detail: 'prospection-b.example est prêt.', ce_qui_se_passe: 'prospection-b.example est prêt pour la politique « quarantaine ».', ce_que_le_systeme_a_fait: 'Rappel posé au 15 janvier.', que_faire: 'Ouvrir le domaine.', niveau: 'nouveau', survenue_le: ilYa(1, 16, 10), lien: '/envoi/domaines', close: false },
      { id: 'al-3', titre: 'Test du matin réussi', detail: '4 témoins sur 4 à 07:05.', ce_qui_se_passe: '4 témoins sur 4 à 07:05.', ce_que_le_systeme_a_fait: 'Rien.', que_faire: 'Rien.', niveau: 'fait', survenue_le: ilYa(0, 7, 5), lien: null, close: true },
      { id: 'al-4', titre: 'Réponse intéressée reçue', detail: 'Paul Morin a répondu.', ce_qui_se_passe: 'Paul Morin (Boulangerie des Tilleuls) a répondu avec intérêt.', ce_que_le_systeme_a_fait: 'Relances annulées pour cette personne.', que_faire: 'Lui répondre.', niveau: 'fait', survenue_le: ilYa(2, 11, 40), lien: '/envoi/reponses', close: true },
      { id: 'al-5', titre: 'Plainte signalée', detail: 'Une plainte sur la campagne « Organisateurs Rhône-Alpes ».', ce_qui_se_passe: 'Une personne a signalé un message comme indésirable.', ce_que_le_systeme_a_fait: 'Adresse inscrite au registre ; campagne surveillée.', que_faire: 'Rien : aucun message ne lui sera plus envoyé.', niveau: 'fait', survenue_le: ilYa(4, 10, 15), lien: '/envoi/desinscriptions', close: true },
    ] satisfies S['AlerteEnvoi'][],
    carnet: [
      { id: 'car-1', adresse: 'accueil@salon-vienne.example', prenom: null, nom: null, organisation: 'Salon des artisans de Vienne', etiquettes: ['salon-artisans-2026'], ajoute_le: ilYa(20, 10, 0) },
      { id: 'car-2', adresse: 'lea.fournier@federation-btp.example', prenom: 'Léa', nom: 'Fournier', organisation: 'Association du bâtiment d’exemple', etiquettes: ['fédérations'], ajoute_le: ilYa(14, 14, 30) },
      { id: 'car-3', adresse: 'redaction@courrier-rhone.example', prenom: null, nom: null, organisation: 'Le Courrier du Rhône', etiquettes: ['presse'], ajoute_le: ilYa(9, 9, 15) },
    ] satisfies S['EntreeCarnet'][],
    parametres: [
      {
        id: 'unique',
        identite: {
          raison_sociale: 'Rhône Prospection (société fictive)',
          forme: 'SAS',
          adresse_postale: '1 rue de l’Exemple, 69000 Lyon',
          signature: 'L’équipe Rhône Prospection',
          lien_desinscription: true,
          lien_rendez_vous: 'https://rdv.example/prospection-a',
          bloc_information: BLOC_ART_14,
        },
        alertes: { seconde_adresse: null, regroupement_minutes: 15 },
        regles: {
          pause_si_refus_pct: 2,
          pause_si_plaintes_pour_mille: 1,
          test_du_matin_bloquant: true,
          frein_reponses_par_jour: 15,
          pression_max_par_organisation_30j: 3,
          heure_de: '09:00',
          heure_a: '17:30',
          jours_ouvres_seulement: true,
        },
        absence: { actif: false, du: null, au: null },
        crm: {
          adaptateur: 'crm_pro',
          etat: 'vert',
          derniere_synchro_le: ilYa(0, 9, 12),
          contacts_vus: 0,
          ce_qui_passe: [
            'Les destinataires viennent de vos audiences, étiquettes et listes du CRM Pro.',
            'Les désinscriptions et plaintes reviennent au CRM : un contact opposé n’y reçoit plus rien.',
            'Les réponses sont rattachées à la fiche du contact.',
          ],
          ce_qui_ne_passe_pas: [
            'Les mots de passe et les clés de vos boîtes ne quittent jamais Envoi.',
            'Aucun contact n’est supprimé : on marque « ne plus écrire ».',
          ],
        },
      },
    ] satisfies Parametres[],
  }));
}

const parametres = () => demoDb.zone<Parametres>('conformite', 'parametres');
const lire = (): Parametres => {
  zone();
  return parametres().trouver('unique')!;
};
const ecrire = (patch: Partial<Parametres>): Parametres => {
  zone();
  return parametres().modifier('unique', patch)!;
};

function domaineDe(adresse: string): string {
  return adresse.includes('@') ? adresse.split('@')[1]! : adresse;
}

function motif(o: Opposition): string {
  return o.motif;
}

/** « Puis-je écrire à… ? » : la réponse et TOUTES ses raisons. */
function puisJeEcrire(saisie: string): { adresse: string; reponse: 'oui' | 'non' | 'prudence'; raisons: string[]; mesure_ouvertures_permise: boolean } {
  const adresse = saisie.trim().toLowerCase();
  const domaine = domaineDe(adresse);
  const raisons: string[] = [];
  const etat = { reponse: 'oui' as 'oui' | 'non' | 'prudence' };
  const non = (r: string) => {
    etat.reponse = 'non';
    raisons.push(r);
  };
  const prudence = (r: string) => {
    if (etat.reponse === 'oui') etat.reponse = 'prudence';
    raisons.push(r);
  };

  const registre = demoDb.opposition.tous();
  const contact = demoDb.contacts.tous().find((c) => c.email.toLowerCase() === adresse);
  const entreprise = contact ? demoDb.entreprises.trouver(contact.company_id) : undefined;
  for (const o of registre) {
    const v = o.valeur.toLowerCase();
    if (o.portee === 'adresse' && v === adresse) non(`Registre d’opposition : ${motif(o)}`);
    else if (o.portee === 'domaine' && v === domaine) non(`Domaine entier au registre : ${motif(o)}`);
    else if (o.portee === 'organisation' && entreprise && v === entreprise.denomination.toLowerCase()) {
      non(`Toute l’organisation « ${entreprise.denomination} » est au registre : ${motif(o)}`);
    }
  }
  if (contact?.ne_plus_ecrire === true && etat.reponse !== 'non') non('Marqué « ne plus écrire » dans le CRM.');
  if (!adresse.includes('@')) {
    const nb = registre.filter((o) => o.portee === 'adresse' && domaineDe(o.valeur) === domaine).length;
    if (nb > 0 && etat.reponse !== 'non') prudence(`${nb} adresse(s) de ce domaine sont déjà au registre.`);
  }
  const recus = demoDb.destinataires.tous().filter((d) => d.adresse.toLowerCase() === adresse);
  if (recus.some((d) => d.etat === 'rebond_dur')) non('Adresse refusée de façon définitive par la messagerie (adresse inexistante) : aucun message ne doit plus lui être envoyé.');
  if (recus.some((d) => d.etat === 'a_repondu') && etat.reponse !== 'non') prudence('Cette personne a répondu : écrivez-lui à la main, pas par campagne.');
  const sequences = recus.filter((d) => ['a_envoyer', 'envoye', 'remis'].includes(d.etat)).length;
  if (sequences > 0 && etat.reponse !== 'non') prudence('Déjà dans une campagne en cours : attendez la fin de la séquence.');

  if (etat.reponse === 'oui') raisons.push('Aucune opposition connue pour cette adresse.');
  if (etat.reponse === 'non') raisons.push('Aucun message ne part vers cette adresse.');
  return { adresse: saisie.trim(), reponse: etat.reponse, raisons, mesure_ouvertures_permise: etat.reponse !== 'non' };
}

function resumeRegistre() {
  const tous = demoDb.opposition.tous();
  const debutMois = new Date(maintenant());
  debutMois.setUTCDate(1);
  debutMois.setUTCHours(0, 0, 0, 0);
  const ceMois = tous.filter((o) => new Date(o.inscrite_le) >= debutMois);
  return {
    total: tous.length,
    ce_mois: ceMois.length,
    ce_mois_par_lien: ceMois.filter((o) => o.source === 'lien').length,
    domaines_entiers: tous.filter((o) => o.portee === 'domaine').length,
    organisations: tous.filter((o) => o.portee === 'organisation').length,
  };
}

function inscrire(portee: Opposition['portee'], valeur: string, motifTexte: string, source: Opposition['source']): Opposition {
  const numero = demoDb.opposition.compter() + 1;
  return demoDb.opposition.ajouter({
    id: `opp-${String(numero).padStart(3, '0')}`,
    portee,
    valeur,
    motif: motifTexte,
    inscrite_le: maintenant().toISOString(),
    source,
  });
}

function destinataireDuJeton(jeton: string) {
  if (jeton === JETON_DEMO) {
    return demoDb.destinataires.tous().find((d) => d.adresse.includes('boulangerie-tilleuls'));
  }
  return demoDb.destinataires.trouver(jeton);
}

function csv(): string {
  const lignes = [['portee', 'valeur', 'motif', 'source', 'inscrite_le']];
  for (const o of demoDb.opposition.tous()) lignes.push([o.portee, o.valeur, o.motif, LIBELLE_SOURCE[o.source], o.inscrite_le]);
  return lignes.map((l) => l.map((c) => `"${c.replaceAll('"', '""')}"`).join(';')).join('\n');
}

const REPONSE_SUCCES = () => HttpResponse.json({ ok: true, message: 'Enregistré.' });

export const handlers: HttpHandler[] = [
  http.get(api('/envoi/desinscriptions'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: paginer([], request).meta });
    if (force) return force;
    return HttpResponse.json(paginer(filtrerTexte(demoDb.desinscriptions.tous(), lireRecherche(request), ['adresse']), request));
  }),

  http.get(api('/envoi/opposition'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, {
      data: [],
      meta: paginer([], request).meta,
      resume: { total: 0, ce_mois: 0, ce_mois_par_lien: 0, domaines_entiers: 0, organisations: 0 },
    });
    if (force) return force;
    const liste = filtrerTexte(demoDb.opposition.tous(), lireRecherche(request), ['valeur', 'motif']).sort((a, b) => b.inscrite_le.localeCompare(a.inscrite_le));
    const { data, meta } = paginer(liste, request);
    return HttpResponse.json({ data, meta, resume: resumeRegistre() });
  }),

  http.post(api('/envoi/opposition'), async ({ request }) => {
    await delai();
    const corps = (await request.json()) as { portee?: Opposition['portee']; valeur?: string; motif?: string };
    const valeur = (corps.valeur ?? '').trim().toLowerCase();
    if (valeur === '' || corps.portee === undefined) return erreur(422, 'validation', 'Indiquez une adresse, une organisation ou un domaine.');
    return HttpResponse.json(inscrire(corps.portee, valeur, (corps.motif ?? '').trim() || 'Demande RGPD', 'manuel'), { status: 201 });
  }),

  http.get(api('/envoi/opposition/export'), async () => {
    await delai();
    return new HttpResponse(csv(), { headers: { 'Content-Type': 'text/csv; charset=utf-8' } });
  }),

  http.get(api('/envoi/puis-je-ecrire'), async ({ request }) => {
    await delai();
    const adresse = new URL(request.url).searchParams.get('adresse') ?? '';
    if (adresse.trim() === '') return erreur(422, 'validation', 'Saisissez une adresse ou un domaine.');
    return HttpResponse.json(puisJeEcrire(adresse));
  }),

  http.get(api('/envoi/alertes'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [] });
    if (force) return force;
    zone();
    return HttpResponse.json({ data: demoDb.zone<S['AlerteEnvoi']>('conformite', 'alertes').tous() });
  }),

  http.post(api('/envoi/alertes/:id/regler'), async ({ params }) => {
    await delai();
    zone();
    const alerte = demoDb.zone<S['AlerteEnvoi']>('conformite', 'alertes').modifier(String(params['id']), { close: true, niveau: 'fait' });
    return alerte ? HttpResponse.json(alerte) : erreur(404, 'non_trouve', 'Cette alerte n’existe pas.');
  }),

  http.get(api('/envoi/reglages/alertes'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], seconde_adresse: null, regroupement_minutes: 15 });
    if (force) return force;
    const p = lire();
    return HttpResponse.json({ data: demoDb.reglesAlerte.tous(), ...p.alertes });
  }),

  http.put(api('/envoi/reglages/alertes'), async ({ request }) => {
    await delai();
    const corps = (await request.json()) as S['ReglagesAlertes'];
    for (const r of corps.data) {
      const canaux = r.canaux.filter((c) => c === 'console' || c === 'email');
      demoDb.reglesAlerte.modifier(r.id, { canaux, actif: r.actif });
    }
    ecrire({ alertes: { seconde_adresse: corps.seconde_adresse, regroupement_minutes: corps.regroupement_minutes } });
    return HttpResponse.json({ data: demoDb.reglesAlerte.tous(), ...lire().alertes });
  }),

  http.post(api('/envoi/reglages/alertes/test'), async () => {
    await delai();
    const numero = demoDb.notifications.compter() + 1;
    const lien = '/envoi/alertes';
    demoDb.notifications.ajouter({
      id: `notif-test-${numero}`,
      type: 'alerte_test',
      title: 'Alerte de test',
      body: 'Ceci est un essai : aucune action n’est nécessaire.',
      action_url: lien,
      lien,
      produit: 'envoi',
      niveau: 'nouveau',
      read_at: null,
      created_at: maintenant().toISOString(),
    });
    return HttpResponse.json({ ok: true, message: 'Alerte de test envoyée dans la cloche (et par e-mail en production).' });
  }),

  http.get(api('/envoi/reglages/apercu'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { regles_actives: 0, canaux: ['console', 'email'], oppositions: 0, absence: { actif: false, du: null, au: null }, boucles_de_retour: [] });
    if (force) return force;
    const p = lire();
    return HttpResponse.json({
      regles_actives: demoDb.reglesAlerte.filtrer((r) => r.actif).length,
      canaux: ['console', 'email'],
      oppositions: demoDb.opposition.compter(),
      absence: p.absence,
      boucles_de_retour: [
        { nom: 'Signal Spam', etat: 'fait', detail: 'Inscrit' },
        { nom: 'Messagerie pro C', etat: 'a_faire', detail: 'À faire au premier envoi' },
        { nom: 'Messagerie grand public A', etat: 'a_faire', detail: 'À faire au premier envoi' },
      ],
    } satisfies S['ApercuReglages']);
  }),

  http.put(api('/envoi/reglages/absence'), async ({ request }) => {
    await delai();
    const corps = (await request.json()) as S['ModeAbsence'];
    if (corps.actif && (corps.du === null || corps.au === null || corps.du > corps.au)) {
      return erreur(422, 'validation', 'Indiquez un début et une fin, la fin après le début.');
    }
    return HttpResponse.json(ecrire({ absence: corps }).absence);
  }),

  http.get(api('/envoi/reglages/regles'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, lire().regles);
    if (force && new URL(request.url).searchParams.get('demo-etat') !== 'vide') return force;
    return HttpResponse.json(lire().regles);
  }),

  http.put(api('/envoi/reglages/regles'), async ({ request }) => {
    await delai();
    const corps = (await request.json()) as S['Regles'];
    const refus = verifierDurcissement(lire().regles, corps);
    if (refus.length > 0) {
      return HttpResponse.json({ error: 'assouplissement_refuse', message: `Vous ne pouvez que durcir les règles. ${refus.join(' ')}`, details: { raisons: refus } }, { status: 422 });
    }
    return HttpResponse.json(ecrire({ regles: corps }).regles);
  }),

  http.get(api('/envoi/reglages'), async () => {
    await delai();
    const r = lire().regles;
    return HttpResponse.json({
      plafond_jour: demoDb.boites.tous().reduce((n, b) => n + b.quota_jour, 0) || 1,
      jours: ['lun', 'mar', 'mer', 'jeu', 'ven'],
      heures: { de: r.heure_de, a: r.heure_a },
      fuseau: 'Europe/Paris',
      pause_si_refus_pct: r.pause_si_refus_pct,
    } satisfies S['Reglages']);
  }),

  http.get(api('/envoi/reglages/identite'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, lire().identite);
    if (force && new URL(request.url).searchParams.get('demo-etat') !== 'vide') return force;
    return HttpResponse.json(lire().identite);
  }),

  http.put(api('/envoi/reglages/identite'), async ({ request }) => {
    await delai();
    const corps = (await request.json()) as S['Identite'];
    if (corps.adresse_postale.trim() === '' || corps.raison_sociale.trim() === '') {
      return erreur(422, 'validation', 'La raison sociale et l’adresse postale sont obligatoires (elles figurent sous chaque message).');
    }
    const actuelle = lire().identite;
    const identite: S['Identite'] = { ...corps, forme: 'SAS', lien_desinscription: true, bloc_information: actuelle.bloc_information };
    return HttpResponse.json(ecrire({ identite }).identite);
  }),

  http.get(api('/envoi/reglages/connexion-crm'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, lire().crm);
    if (force && new URL(request.url).searchParams.get('demo-etat') !== 'vide') return force;
    return HttpResponse.json({ ...lire().crm, contacts_vus: demoDb.contacts.compter() });
  }),

  http.post(api('/envoi/reglages/connexion-crm/tester'), async () => {
    await delai();
    return HttpResponse.json({ ...ecrire({ crm: { ...lire().crm, etat: 'vert', derniere_synchro_le: maintenant().toISOString() } }).crm, contacts_vus: demoDb.contacts.compter() });
  }),

  http.get(api('/envoi/carnet'), async ({ request }) => {
    await delai();
    const force = await repondreEtatForce(request, { data: [], meta: paginer([], request).meta });
    if (force) return force;
    zone();
    const liste = filtrerTexte(demoDb.zone<S['EntreeCarnet']>('conformite', 'carnet').tous(), lireRecherche(request), ['adresse', 'nom', 'organisation']);
    return HttpResponse.json(paginer(liste, request));
  }),

  http.post(api('/envoi/carnet'), async ({ request }) => {
    await delai();
    zone();
    const corps = (await request.json()) as { adresse?: string; prenom?: string; nom?: string; organisation?: string };
    const adresse = (corps.adresse ?? '').trim().toLowerCase();
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(adresse)) return erreur(422, 'validation', 'Cette adresse e-mail n’est pas valable.');
    const carnet = demoDb.zone<S['EntreeCarnet']>('conformite', 'carnet');
    if (carnet.tous().some((e) => e.adresse === adresse)) return erreur(409, 'doublon', 'Cette adresse est déjà dans le carnet.');
    const entree = carnet.ajouter({
      id: `car-${carnet.compter() + 1}`,
      adresse,
      prenom: corps.prenom?.trim() || null,
      nom: corps.nom?.trim() || null,
      organisation: corps.organisation?.trim() || null,
      etiquettes: [],
      ajoute_le: maintenant().toISOString(),
    });
    return HttpResponse.json(entree, { status: 201 });
  }),

  http.get(api('/public/desinscription/:jeton'), async ({ params, request }) => {
    await delai();
    const force = await repondreEtatForce(request, null);
    if (force && new URL(request.url).searchParams.get('demo-etat') !== 'vide') return force;
    const dest = destinataireDuJeton(String(params['jeton']));
    if (!dest) return erreur(404, 'non_trouve', 'Ce lien n’est plus valable.');
    const deja = demoDb.opposition.tous().some((o) => o.portee === 'adresse' && o.valeur.toLowerCase() === dest.adresse.toLowerCase());
    return HttpResponse.json({
      adresse_masquee: masquerAdresse(dest.adresse),
      expediteur: 'Rhône Prospection',
      deja_desinscrit: deja,
      organisation: dest.organisation,
      pourquoi_ce_message: BLOC_ART_14,
    });
  }),

  http.post(api('/public/desinscription/:jeton'), async ({ params, request }) => {
    await delai();
    const dest = destinataireDuJeton(String(params['jeton']));
    if (!dest) return erreur(404, 'non_trouve', 'Ce lien n’est plus valable.');
    const corps = (await request.json().catch(() => ({}))) as { toute_l_organisation?: boolean };
    const toute = corps.toute_l_organisation === true && dest.organisation !== null;
    if (toute) inscrire('organisation', dest.organisation!, 'Lien de désinscription (toute l’organisation)', 'lien');
    else inscrire('adresse', dest.adresse, 'Lien de désinscription', 'lien');
    demoDb.destinataires.modifier(dest.id, { etat: 'desinscrit' });
    return REPONSE_SUCCES();
  }),
];
