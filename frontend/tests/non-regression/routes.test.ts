/**
 * NR-20 (côté frontend) — chaque appel d'API de l'écran vise une route qui existe.
 *
 * La photo des routes du serveur est rangée par le filet Pest :
 * `backend/tests/Feature/NonRegression/instantanes/nr20-routes.json`. Ce test
 * relève, dans `src/`, tous les appels `api.get|post|put|patch|delete(...)` à
 * chemin lisible et exige qu'ils tombent sur une route de la photo (méthode et
 * chemin, paramètres `{x}` et `${...}` compris).
 *
 * Les écarts déjà présents sont FIGÉS dans `ECARTS_CONNUS` : le filet constate,
 * il ne corrige pas. Ajouter un NOUVEL appel sans route fait rougir ; corriger
 * un écart connu oblige à le retirer de la liste.
 */
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import { describe, expect, it } from 'vitest';

const RACINE = resolve(__dirname, '../..');
const PHOTO = resolve(RACINE, '../backend/tests/Feature/NonRegression/instantanes/nr20-routes.json');

/** Appels sans route dans la photo à la date du filet (R0). À ne faire que BAISSER. */
// POST propositions/{id}/{action} : le dernier segment est choisi à l'exécution (accepter ou refuser) ;
// le relevé statique ne peut pas le résoudre, ce n'est pas une route manquante.
// Filet modifié : pourquoi. DM2-CNF : écrans de conformité et de réglages d'Envoi. Les appels `/envoi/…` ne partent
// que si Envoi est actif (absent en production : routes gardées). Les deux appels `/public/desinscription/{p}` viennent
// d'une page PUBLIQUE, joignable sans connexion : elle ne les lance que si `/config/features` annonce Envoi ; sinon
// elle reste la page « en préparation » d'avant et n'appelle rien (test `DesinscriptionPubliquePage`). Tous sont
// servis par le faux serveur de la démo ; les routes serveur viendront au lot serveur d'Envoi, qui retirera ces lignes.
// Filet modifié : pourquoi. DM0-COQ (#392) : feu et compteurs de l'espace Envoi. Ces appels ne partent que si
// Envoi est actif (absent en production) et sont servis par le faux serveur de la démo ; la route serveur est
// prévue au lot S/Envoi, qui retirera ces deux lignes.
// Filet modifié : pourquoi. DM2-ASS (#404) : création de campagne et messages types d'Envoi. Ces appels ne
// partent que sur les pages d'Envoi (gardées : introuvables tant qu'Envoi est absent, donc en production) et sont
// servis par le faux serveur de la démo (`demo/handlers/envoi/creation.handlers.ts`), selon le contrat
// `backend/openapi/envoi/creation.yaml`. Les routes serveur viennent avec les lots serveur d'Envoi, qui retireront
// ces lignes. La liste reste exacte : tout AUTRE appel sans route fait toujours rougir.
// Filet modifié : pourquoi. DM1-CON (#396) : ajout rapide, import, modifier, retirer, restaurer, export, appel et
// rappel des contacts. Ces appels ne partent que si le serveur annonce les gestes (`gestes_fiches` dans les
// compteurs du hub, `fiche` dans l'historique d'une personne), ce que le vrai serveur ne fait pas : en production
// la page reste celle d'avant ; la démo les sert. Les routes serveur sont prévues au lot serveur du CRM, qui
// retirera ces lignes. Le filet reste strict : tout AUTRE appel sans route fait toujours rougir.
// Filet modifié : pourquoi. DM2-REP (#394) : boîte des réponses d'Envoi (liste, raccourcis, lecture, classer,
// rattacher, créer une fiche, répondre). Ces appels ne partent que si Envoi est actif (absent en production :
// la route répond « Page introuvable », garde `routes-envoi-gardees`) et sont servis par le faux serveur de la
// démo (`src/demo/handlers/envoi/reponses.handlers.ts`, contrat `backend/openapi/envoi/reponses.yaml`) ; la route
// serveur est prévue au lot S/Envoi, qui retirera ces sept lignes. La liste reste FERMÉE : tout autre appel
// sans route fait toujours rougir.
// Filet modifié : pourquoi. DM1-ACC (#399) : Mes tâches et Affaires. Ces appels ne partent qu'en démo
// (`VITE_DEMO`, figé au build) : hors démo, donc en production, /taches et /affaires gardent leur page
// « en attente » et le tableau de bord n'a pas d'onglets. La démo les sert ; le lot serveur du CRM reprendra
// le contrat et retirera ces six lignes. La liste reste fermée : tout autre appel sans route fait toujours rougir.
// Filet modifié : pourquoi. DM2-PIL : résultats, santé, chercher un envoi et feu vert d'Envoi. Mêmes raisons que
// ci-dessus (Envoi absent en production, servi par la démo) ; la route serveur arrive avec le lot serveur d'Envoi.
// Filet modifié : pourquoi. DM1-COM : code reçu par e-mail, appareils de confiance, codes de secours et export du
// registre des oppositions. Servis par le faux serveur de la démo ; les routes serveur sont prévues aux lots S3 et
// S12 (authentification par code e-mail) et au lot RGPD, qui retireront ces lignes.
// Filet modifié : pourquoi. DM1-ATR (#393) : « Annuler la fusion » des doublons. Cet appel ne part que si la
// liste des doublons annonce `meta.fusions_recentes`, ce que le vrai serveur n'envoie pas (il annule par la
// commande `crm:doublons:fusionner --annuler`) : en production, aucun bouton ne l'appelle ; la démo le sert
// (`src/demo/handlers/crm/a-traiter.handlers.ts`). La route serveur est prévue au lot serveur du CRM, qui
// retirera cette ligne. La liste reste FERMÉE : tout autre appel sans route fait toujours rougir.
const ECARTS_CONNUS: string[] = [
  'POST /api/v1/doublons/fusions/{p}/annuler',
  'GET /api/v1/crm/contacts/corbeille',
  'GET /api/v1/crm/contacts/doublons',
  'PATCH /api/v1/crm/contacts/{p}',
  'POST /api/v1/crm/contacts',
  'POST /api/v1/crm/contacts/export',
  'POST /api/v1/crm/contacts/import',
  'POST /api/v1/crm/contacts/import/controle',
  'POST /api/v1/crm/contacts/{p}/restaurer',
  'POST /api/v1/crm/contacts/{p}/retirer',
  'POST /api/v1/crm/persons/{p}/appels',
  'POST /api/v1/crm/persons/{p}/rappel',
  // DM2-CNF — conformité, réglages, page publique de désinscription.
  'GET /api/v1/envoi/alertes',
  'GET /api/v1/envoi/carnet',
  'GET /api/v1/envoi/opposition',
  'GET /api/v1/envoi/opposition/export',
  'GET /api/v1/envoi/puis-je-ecrire',
  'GET /api/v1/envoi/reglages/alertes',
  'GET /api/v1/envoi/reglages/apercu',
  'GET /api/v1/envoi/reglages/connexion-crm',
  'GET /api/v1/envoi/reglages/identite',
  'GET /api/v1/envoi/reglages/regles',
  'GET /api/v1/public/desinscription/{p}',
  'POST /api/v1/envoi/alertes/{p}/regler',
  'POST /api/v1/envoi/carnet',
  'POST /api/v1/envoi/opposition',
  'POST /api/v1/envoi/reglages/alertes/test',
  'POST /api/v1/envoi/reglages/connexion-crm/tester',
  'POST /api/v1/public/desinscription/{p}',
  'PUT /api/v1/envoi/reglages/absence',
  'PUT /api/v1/envoi/reglages/alertes',
  'PUT /api/v1/envoi/reglages/identite',
  'PUT /api/v1/envoi/reglages/regles',
  'GET /api/v1/crm/affaires',
  'GET /api/v1/crm/taches',
  'PATCH /api/v1/crm/affaires/{p}',
  'PATCH /api/v1/crm/taches/{p}',
  'POST /api/v1/crm/taches',
  'PUT /api/v1/crm/taches/rappel-matin',
  // DM2-PIL — résultats, santé, chercher un envoi, feu vert.
  'GET /api/v1/envoi/envois/chercher',
  'GET /api/v1/envoi/feu-vert',
  'GET /api/v1/envoi/resultats',
  'GET /api/v1/envoi/sante',
  'POST /api/v1/envoi/feu-vert/{p}/fait',
  'POST /api/v1/envoi/sante/test',
  'GET /api/v1/envoi/compteurs',
  'GET /api/v1/envoi/etat',
  'GET /api/v1/envoi/reponses',
  'GET /api/v1/envoi/reponses/raccourcis',
  'GET /api/v1/envoi/reponses/{p}',
  'POST /api/v1/envoi/reponses/{p}/classer',
  'POST /api/v1/envoi/reponses/{p}/creer-fiche',
  'POST /api/v1/envoi/reponses/{p}/rattacher',
  'POST /api/v1/envoi/reponses/{p}/repondre',
  'GET /api/v1/compte/appareils',
  'GET /api/v1/compte/codes-secours',
  'GET /api/v1/rgpd/oppositions/export',
  'POST /api/v1/auth/code-email/renvoyer',
  'POST /api/v1/auth/code-email/verifier',
  'POST /api/v1/compte/appareils/{p}/retirer',
  'POST /api/v1/compte/codes-secours/regenerer',
  'POST /api/v1/crm/propositions/{p}/{p}',
  // DM2-ASS — création de campagne.
  'GET /api/v1/envoi/creation/boites',
  'GET /api/v1/envoi/creation/cibles',
  'GET /api/v1/envoi/creation/contexte',
  'GET /api/v1/envoi/creation/destinataires-exemples',
  'POST /api/v1/envoi/creation/champs',
  'POST /api/v1/envoi/brouillons',
  'PUT /api/v1/envoi/brouillons/{p}/etapes/{p}',
  'POST /api/v1/envoi/brouillons/{p}/test',
  'POST /api/v1/envoi/brouillons/{p}/validation/code',
  'POST /api/v1/envoi/brouillons/{p}/valider',
  'POST /api/v1/envoi/campagnes/{p}/annuler-validation',
  // DM2-ASS — messages types.
  'GET /api/v1/envoi/messages',
  'POST /api/v1/envoi/messages',
  'PUT /api/v1/envoi/messages/{p}',
  'POST /api/v1/envoi/messages/apercu',
  'POST /api/v1/envoi/messages/controler',
  'POST /api/v1/envoi/messages/suggestion',
  'POST /api/v1/envoi/messages/test',
  'POST /api/v1/envoi/messages/{p}/archivage',
  'POST /api/v1/envoi/messages/{p}/versions/{p}/restaurer',
  'POST /api/v1/envoi/messages/{p}/versions/{p}/valider',
];

function fichiers(dossier: string): string[] {
  return readdirSync(dossier).flatMap((nom) => {
    const chemin = join(dossier, nom);
    if (statSync(chemin).isDirectory()) return fichiers(chemin);
    return /\.(ts|tsx)$/.test(nom) ? [chemin] : [];
  });
}

function enMotif(chemin: string): RegExp {
  const sansParametres = chemin
    .split('/')
    .map((segment) => (/^\{.+\}$/.test(segment) ? '[^/]+' : segment.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')))
    .join('/');
  return new RegExp(`^${sansParametres}$`);
}

function appels(): { cle: string; methode: string; chemin: string }[] {
  const trouves = new Map<string, { cle: string; methode: string; chemin: string }>();
  for (const fichier of fichiers(join(RACINE, 'src'))) {
    const source = readFileSync(fichier, 'utf8');
    const motif = /\bapi\.(get|post|put|patch|delete)\s*(?:<[^>(]*(?:<[^>]*>[^>(]*)*>)?\(\s*([`'"])([^`'"]*)\2/g;
    for (const m of source.matchAll(motif)) {
      const methode = (m[1] ?? '').toUpperCase();
      let chemin = (m[3] ?? '').replace(/\$\{[^}]*\}/g, '{p}').split('?')[0] ?? '';
      if (!chemin.startsWith('/')) continue;
      chemin = `/api/v1${chemin}`.replace(/\/+$/, '');
      const cle = `${methode} ${chemin}`;
      trouves.set(cle, { cle, methode, chemin });
    }
  }
  return [...trouves.values()];
}

describe('NR-20 : les appels du frontend visent des routes du serveur', () => {
  const photo = JSON.parse(readFileSync(PHOTO, 'utf8')) as string[];
  const routes = photo.map((ligne) => {
    const [methode, reste] = ligne.split(' | ')[0]!.split(' ');
    return { methode: methode!, motif: enMotif(reste!) };
  });

  it('la photo des routes du serveur est lisible et non vide', () => {
    expect(routes.length).toBeGreaterThan(150);
  });

  it('le relevé voit les appels du frontend (témoin de couverture)', () => {
    expect(appels().length).toBeGreaterThan(80);
  });

  it('chaque appel a sa route (hors écarts connus figés)', () => {
    const sansRoute = appels()
      .filter((a) => !routes.some((r) => r.methode === a.methode && r.motif.test(a.chemin)))
      .map((a) => a.cle)
      .sort();
    expect(sansRoute).toEqual([...ECARTS_CONNUS].sort());
  });
});
