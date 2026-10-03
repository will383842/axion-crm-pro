/**
 * « ACTIVITÉ RÉCENTE » EN PHRASES (lot 3, 2026-10-02).
 *
 * Le journal d'audit enregistre deux sortes de lignes :
 *   - les requêtes d'écriture de la console (`event_type` = verbe HTTP, `path`
 *     = route : « POST api/v1/auth/password/reset ») ;
 *   - les traitements en lot (`event_type` = « IMPORT_PRESSE »,
 *     « JOIGNABILITE_LOT »…, `path` = « artisan crm:presse:importer »).
 * L'accueil les affichait tels quels. On les traduit ici en phrases ; les
 * lignes de PROGRESSION d'un lot (`…_LOT`, `…_PAQUET`) ne sont pas des
 * événements métier et ne s'affichent pas (`null`) — le serveur les écarte
 * déjà quand on lui demande `metier=1`.
 */

/** Phrase d'un code de traitement inconnu (jamais masqué). */
export const AUTRE_OPERATION = 'Autre opération';

/** Codes écrits par le serveur dans `audit_logs.event_type`, en phrases. */
export const EVENEMENTS: Record<string, string> = {
  IMPORT_PRESSE: 'Import de la liste presse',
  IMPORT_EVENEMENTS: 'Import des événements',
  IMPORT_FEDERATIONS: 'Import des fédérations',
  HARMONISER_PRESSE: 'Harmonisation des contacts presse',
  DOUBLONS_PRESSE: 'Traitement des doublons presse',
  REPARER_PRESSE_MEDIA_INCERTAIN: 'Correction des médias incertains',
  FUSION_FICHES: 'Fusion de deux fiches en double',
  DETECTION_DOUBLONS_FIN: 'Détection des doublons terminée',
  RECLASSEMENT_REFERENTIELS_FIN: 'Reclassement des fiches terminé',
  JOIGNABILITE_FIN: 'Calcul de la joignabilité terminé',
  VERIFICATION_EMAILS_FIN: 'Vérification des e-mails terminée',
  COMBLER_TROUS_FIN: 'Complétion des fiches terminée',
  RELATIONS_IMPORT_FIN: 'Import des relations terminé',
  GDPR_ERASURE_BISYSTEM: 'Effacement de données personnelles (site et CRM)',
  GDPR_PURGE_VIVIER: 'Purge du vivier de candidats (RGPD)',
  GDPR_ERASURE: 'Effacement de données personnelles',
  GDPR_PURGE_PERSONNES: 'Effacement RGPD de personnes',
  GDPR_PURGE_BUSINESS: 'Effacement RGPD de prospects',
  MOT_DE_PASSE_MODIFIE: 'Mot de passe modifié',
  FUSION_FICHES_ANNULEE: 'Fusion de fiches annulée',
  FUSION_DOUBLONS_FIN: 'Fusion des doublons terminée',
  RECLASSEMENT_ETIQUETTES_SUPPRIMEES: 'Étiquettes retirées après reclassement',
  // Codes de `business_events` (journal métier, `AuditLogger::log`), lus par
  // « Santé du système › 50 derniers événements ». La garde
  // `tests/lib/evenements-metier-codes-serveur.test.ts` exige une phrase pour
  // chacun. « Mise à jour » plutôt que « recalcul » : c'est le mot des écrans
  // Audiences (bouton « Mettre à jour »).
  'company.enriched': 'Fiche enrichie',
  'audience.refreshed': 'Audience mise à jour',
  'audience.refresh.failed': 'Échec de mise à jour d’audience',
  'company.tags_synced': 'Étiquettes synchronisées',
  'company.archived': 'Fiche archivée',
  'company.relation.saisie': 'Relation saisie à la main',
  'company.presse_media_incertain_reparee': 'Fiche presse incertaine corrigée',
  'company.presse_provenance_levee': 'Marque de provenance presse retirée',
  'email.verified': 'Adresse e-mail vérifiée',
  'liste_manuelle.creee': 'Liste créée',
  'liste_manuelle.modifiee': 'Liste modifiée',
  'liste_manuelle.corbeille': 'Liste mise à la corbeille',
  'liste_manuelle.restauree': 'Liste restaurée',
  'liste_manuelle.fiches_ajoutees': 'Fiches ajoutées à une liste',
  'liste_manuelle.fiches_retirees': 'Fiches retirées d’une liste',
  'liste_manuelle.import': 'Import dans une liste',
};

/** Requêtes de la console : [motif sur la route, verbe (ou * ), phrase]. */
const ROUTES: Array<[RegExp, string, string]> = [
  [/auth\/login$/, '*', 'Connexion'],
  [/auth\/logout$/, '*', 'Déconnexion'],
  [/auth\/password\/forgot$/, '*', 'Demande de réinitialisation du mot de passe'],
  [/auth\/password\/reset$/, '*', 'Mot de passe réinitialisé'],
  [/auth\/password$/, '*', 'Mot de passe modifié'],
  [/auth\/magic-link\/verify$/, '*', 'Connexion par lien'],
  [/auth\/magic-link$/, '*', 'Lien de connexion demandé'],
  [/campaigns\/\d+\/start$/, '*', 'Collecte démarrée'],
  [/campaigns\/\d+\/cancel$/, '*', 'Collecte annulée'],
  [/campaigns\/\d+\/pause$/, '*', 'Collecte mise en pause'],
  [/campaigns\/\d+\/resume$/, '*', 'Collecte reprise'],
  [/campaigns\/\d+\/archive$/, '*', 'Collecte archivée'],
  [/campaigns$/, 'POST', 'Collecte créée'],
  [/users$/, 'POST', 'Utilisateur invité'],
  [/users\/[^/]+$/, 'DELETE', 'Utilisateur retiré'],
  [/workspace$/, '*', 'Paramètres de l’espace modifiés'],
  [/audiences\/(preview|apercu-destinataires)$/, '*', 'Aperçu d’une audience'],
  [/audiences\/\d+\/refresh$/, '*', 'Mise à jour d’une audience demandée'],
  [/audiences$/, 'POST', 'Audience créée'],
  [/companies\/\d+\/enrich$/, '*', 'Enrichissement d’une fiche demandé'],
  [/companies$/, 'POST', 'Fiche entreprise créée'],
  [/companies\/\d+$/, 'PUT', 'Fiche entreprise modifiée'],
  [/companies\/\d+$/, 'DELETE', 'Fiche entreprise supprimée'],
  [/internal\/site-sync\/gdpr$/, '*', 'Effacement de données personnelles reçu du site'],
  [/internal\/site-sync$/, '*', 'Synchronisation avec le site'],
  [/internal\/email\//, '*', 'Retour d’envoi d’e-mail'],
  [/internal\/scraper-result$/, '*', 'Résultat de collecte reçu'],
];

const VERBES: Record<string, string> = {
  POST: 'Ajout',
  PUT: 'Modification',
  PATCH: 'Modification',
  DELETE: 'Suppression',
};

/**
 * La phrase d'une ligne du journal, ou `null` si la ligne n'est pas un
 * événement à montrer (progression d'un traitement en lot).
 */
export function libelleActivite(eventType?: string | null, path?: string | null): string | null {
  const type = typeof eventType === 'string' ? eventType.trim() : '';
  const route = typeof path === 'string' ? path.trim().replace(/^\/+/, '') : '';

  if (/_(LOT|PAQUET)$/.test(type)) return null;
  if (type in EVENEMENTS) return EVENEMENTS[type] ?? null;

  if (type in VERBES || type === 'GET') {
    for (const [motif, verbe, phrase] of ROUTES) {
      if ((verbe === '*' || verbe === type) && motif.test(route)) return phrase;
    }
    return VERBES[type] ? `${VERBES[type]} dans la console` : 'Action dans la console';
  }

  // Un code INCONNU du dictionnaire ne s'affiche plus déguisé en phrase
  // (« Nouvel evenement », sans accents), mais il ne disparaît pas non plus :
  // un événement ne doit jamais sortir du fil sans bruit. La garde
  // `tests/lib/activite-codes-serveur.test.ts` exige une phrase pour chaque
  // code que le serveur écrit.
  return AUTRE_OPERATION;
}
