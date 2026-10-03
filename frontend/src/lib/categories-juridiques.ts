/**
 * Catégories juridiques INSEE (niveau III, 4 chiffres) — `companies.legal_form`.
 *
 * Constaté en production le 03/10/2026 : la fiche entreprise affichait
 * « Forme juridique : 5710 », un code que le propriétaire de la console n'a
 * pas à connaître. L'API ne renvoie que le code.
 *
 * Pourquoi un référentiel front et pas la table `legal_forms` : la table
 * existe (migration du 16/05) mais n'est remplie que par
 * `LegalFormsSeeder`, vingt lignes dont plusieurs sont FAUSSES (5202 y est
 * « SARL » alors que c'est la société en nom collectif, 5499 y figure deux
 * fois, 7322 y est le département). S'appuyer dessus aurait affiché des
 * libellés faux avec l'aplomb d'une donnée officielle.
 *
 * Source : nomenclature des catégories juridiques de l'INSEE. Les libellés
 * sont ceux de la nomenclature ; seuls les plus courants (SAS, SARL, SA)
 * sont abrégés pour tenir sur une ligne. Un code absent de la table retombe
 * sur le libellé de sa famille (`FAMILLES`), jamais sur un libellé deviné ;
 * un code dont on ne connaît même pas la famille s'affiche tel quel.
 */
export const CATEGORIES_JURIDIQUES: Readonly<Record<string, string>> = {
  '1000': 'Entrepreneur individuel',

  '5202': 'Société en nom collectif',
  '5306': 'Société en commandite simple',
  '5307': 'Société en commandite simple coopérative',
  '5308': 'Société en commandite par actions',
  '5370': 'Société de participations financières de profession libérale en commandite par actions (SPFPL SCA)',
  '5385': 'Société d’exercice libéral en commandite par actions',

  '5410': 'SARL nationale',
  '5422': 'SARL immobilière pour le commerce et l’industrie (SICOMI)',
  '5426': 'SARL immobilière de gestion',
  '5430': 'SARL d’aménagement foncier et d’équipement rural (SAFER)',
  '5458': 'SARL coopérative ouvrière de production (SCOP)',
  '5460': 'Autre SARL coopérative',
  '5470': 'Société de participations financières de profession libérale à responsabilité limitée (SPFPL SARL)',
  '5485': 'Société d’exercice libéral à responsabilité limitée (SELARL)',
  '5499': 'SARL, société à responsabilité limitée',

  '5505': 'SA à participation ouvrière à conseil d’administration',
  '5510': 'SA nationale à conseil d’administration',
  '5515': 'SA d’économie mixte à conseil d’administration',
  '5520': 'Fonds à forme sociétale à conseil d’administration',
  '5522': 'SA immobilière pour le commerce et l’industrie (SICOMI) à conseil d’administration',
  '5525': 'SA immobilière d’investissement à conseil d’administration',
  '5530': 'SA d’aménagement foncier et d’équipement rural (SAFER) à conseil d’administration',
  '5531': 'SA mixte d’intérêt agricole (SMIA) à conseil d’administration',
  '5532': 'SA d’intérêt collectif agricole (SICA) à conseil d’administration',
  '5542': 'SA d’attribution à conseil d’administration',
  '5543': 'SA coopérative de construction à conseil d’administration',
  '5546': 'SA de HLM à conseil d’administration',
  '5547': 'SA coopérative de production de HLM à conseil d’administration',
  '5548': 'SA de crédit immobilier à conseil d’administration',
  '5551': 'SA coopérative de consommation à conseil d’administration',
  '5552': 'SA coopérative de commerçants-détaillants à conseil d’administration',
  '5553': 'SA coopérative artisanale à conseil d’administration',
  '5554': 'SA coopérative d’intérêt maritime à conseil d’administration',
  '5555': 'SA coopérative de transport à conseil d’administration',
  '5558': 'SA coopérative ouvrière de production (SCOP) à conseil d’administration',
  '5559': 'SA union de sociétés coopératives à conseil d’administration',
  '5560': 'Autre SA coopérative à conseil d’administration',
  '5585': 'Société d’exercice libéral à forme anonyme à conseil d’administration',
  '5599': 'SA à conseil d’administration',

  '5605': 'SA à participation ouvrière à directoire',
  '5610': 'SA nationale à directoire',
  '5615': 'SA d’économie mixte à directoire',
  '5620': 'Fonds à forme sociétale à directoire',
  '5622': 'SA immobilière pour le commerce et l’industrie (SICOMI) à directoire',
  '5625': 'SA immobilière d’investissement à directoire',
  '5630': 'SA d’aménagement foncier et d’équipement rural (SAFER) à directoire',
  '5631': 'SA mixte d’intérêt agricole (SMIA) à directoire',
  '5632': 'SA d’intérêt collectif agricole (SICA) à directoire',
  '5642': 'SA d’attribution à directoire',
  '5643': 'SA coopérative de construction à directoire',
  '5646': 'SA de HLM à directoire',
  '5647': 'SA coopérative de production de HLM à directoire',
  '5648': 'SA de crédit immobilier à directoire',
  '5651': 'SA coopérative de consommation à directoire',
  '5652': 'SA coopérative de commerçants-détaillants à directoire',
  '5653': 'SA coopérative artisanale à directoire',
  '5654': 'SA coopérative d’intérêt maritime à directoire',
  '5655': 'SA coopérative de transport à directoire',
  '5658': 'SA coopérative ouvrière de production (SCOP) à directoire',
  '5659': 'SA union de sociétés coopératives à directoire',
  '5660': 'Autre SA coopérative à directoire',
  '5685': 'Société d’exercice libéral à forme anonyme à directoire',
  '5699': 'SA à directoire',

  '5710': 'SAS, société par actions simplifiée',
  '5720': 'SASU, société par actions simplifiée à associé unique',
  '5785': 'Société d’exercice libéral par actions simplifiée (SELAS)',
  '5800': 'Société européenne',

  '6316': 'Coopérative d’utilisation de matériel agricole en commun (CUMA)',
  '6317': 'Société coopérative agricole',
  '6318': 'Union de sociétés coopératives agricoles',
  '6411': 'Société d’assurance à forme mutuelle',
  '6511': 'Société interprofessionnelle de soins ambulatoires',
  '6521': 'Société civile de placement collectif immobilier (SCPI)',
  '6532': 'Société civile d’intérêt collectif agricole (SICA)',
  '6533': 'Groupement agricole d’exploitation en commun (GAEC)',
  '6534': 'Groupement foncier agricole',
  '6535': 'Groupement agricole foncier',
  '6536': 'Groupement forestier',
  '6537': 'Groupement pastoral',
  '6538': 'Groupement foncier et rural',
  '6539': 'Société civile foncière',
  '6540': 'Société civile immobilière (SCI)',
  '6541': 'Société civile immobilière de construction-vente',
  '6542': 'Société civile d’attribution',
  '6543': 'Société civile coopérative de construction',
  '6544': 'Société civile immobilière d’accession progressive à la propriété',
  '6551': 'Société civile coopérative de consommation',
  '6554': 'Société civile coopérative d’intérêt maritime',
  '6558': 'Société civile coopérative entre médecins',
  '6560': 'Autre société civile coopérative',
  '6561': 'SCP d’avocats',
  '6562': 'SCP d’avocats aux conseils',
  '6563': 'SCP d’avoués d’appel',
  '6564': 'SCP d’huissiers',
  '6565': 'SCP de notaires',
  '6566': 'SCP de commissaires-priseurs',
  '6567': 'SCP de greffiers de tribunal de commerce',
  '6568': 'SCP de conseils juridiques',
  '6569': 'SCP de commissaires aux comptes',
  '6571': 'SCP de médecins',
  '6572': 'SCP de dentistes',
  '6573': 'SCP d’infirmiers',
  '6574': 'SCP de masseurs-kinésithérapeutes',
  '6575': 'SCP de directeurs de laboratoire d’analyse médicale',
  '6576': 'SCP de vétérinaires',
  '6577': 'SCP de géomètres experts',
  '6578': 'SCP d’architectes',
  '6585': 'Autre société civile professionnelle',
  '6588': 'Société civile laitière',
  '6589': 'Société civile de moyens (SCM)',
  '6595': 'Caisse locale de crédit mutuel',
  '6596': 'Caisse de crédit agricole mutuel',
  '6597': 'Société civile d’exploitation agricole',
  '6598': 'Exploitation agricole à responsabilité limitée (EARL)',
  '6599': 'Autre société civile',
  '6901': 'Autre personne de droit privé inscrite au registre du commerce et des sociétés',

  '7111': 'Autorité constitutionnelle',
  '7112': 'Autorité administrative ou publique indépendante',
  '7113': 'Ministère',
  '7120': 'Service central d’un ministère',
  '7150': 'Service du ministère de la Défense',
  '7160': 'Service déconcentré à compétence nationale d’un ministère (hors Défense)',
  '7210': 'Commune et commune nouvelle',
  '7220': 'Département',
  '7225': 'Collectivité et territoire d’Outre-Mer',
  '7229': 'Autre collectivité territoriale',
  '7230': 'Région',
  '7312': 'Commune associée et commune déléguée',
  '7313': 'Section de commune',
  '7314': 'Ensemble urbain',
  '7321': 'Association syndicale autorisée',
  '7322': 'Association foncière urbaine',
  '7323': 'Association foncière de remembrement',
  '7331': 'Établissement public local d’enseignement',
  '7340': 'Pôle métropolitain',
  '7341': 'Secteur de commune',
  '7342': 'District urbain',
  '7343': 'Communauté urbaine',
  '7344': 'Métropole',
  '7345': 'Syndicat intercommunal à vocation multiple (SIVOM)',
  '7346': 'Communauté de communes',
  '7347': 'Communauté de villes',
  '7348': 'Communauté d’agglomération',
  '7349': 'Autre établissement public local de coopération non spécialisé ou entente',
  '7351': 'Institution interdépartementale ou entente',
  '7352': 'Institution interrégionale ou entente',
  '7353': 'Syndicat intercommunal à vocation unique (SIVU)',
  '7354': 'Syndicat mixte fermé',
  '7355': 'Syndicat mixte ouvert',
  '7356': 'Commission syndicale pour la gestion des biens indivis des communes',
  '7357': 'Pôle d’équilibre territorial et rural (PETR)',
  '7361': 'Centre communal d’action sociale (CCAS)',
  '7362': 'Caisse des écoles',
  '7363': 'Caisse de crédit municipal',
  '7364': 'Établissement d’hospitalisation',
  '7365': 'Syndicat inter-hospitalier',
  '7366': 'Établissement public local social et médico-social',
  '7367': 'Centre intercommunal d’action sociale (CIAS)',
  '7371': 'Office public d’habitation à loyer modéré (OPHLM)',
  '7372': 'Service départemental d’incendie et de secours (SDIS)',
  '7373': 'Établissement public local culturel',
  '7378': 'Régie d’une collectivité locale à caractère administratif',
  '7379': 'Autre établissement public administratif local',
  '7381': 'Organisme consulaire',
  '7382': 'Établissement public national ayant fonction d’administration centrale',
  '7383': 'Établissement public national à caractère scientifique, culturel et professionnel',
  '7384': 'Autre établissement public national d’enseignement',
  '7385': 'Autre établissement public national administratif à compétence territoriale limitée',
  '7389': 'Établissement public national à caractère administratif',
  '7410': 'Groupement d’intérêt public (GIP)',
  '7430': 'Établissement public des cultes d’Alsace-Lorraine',
  '7450': 'Établissement public administratif, cercle et foyer dans les armées',
  '7470': 'Groupement de coopération sanitaire à gestion publique',
  '7490': 'Autre personne morale de droit administratif',

  '8110': 'Régime général de la Sécurité sociale',
  '8120': 'Régime spécial de Sécurité sociale',
  '8130': 'Institution de retraite complémentaire',
  '8140': 'Mutualité sociale agricole',
  '8150': 'Régime maladie des non-salariés non agricoles',
  '8160': 'Régime vieillesse ne dépendant pas du régime général de la Sécurité sociale',
  '8170': 'Régime d’assurance chômage',
  '8190': 'Autre régime de prévoyance sociale',
  '8210': 'Mutuelle',
  '8250': 'Assurance mutuelle agricole',
  '8290': 'Autre organisme mutualiste',
  '8310': 'Comité social et économique d’entreprise',
  '8311': 'Comité social et économique d’établissement',
  '8410': 'Syndicat de salariés',
  '8420': 'Syndicat patronal',
  '8450': 'Ordre professionnel ou assimilé',
  '8470': 'Centre technique industriel ou comité professionnel du développement économique',
  '8490': 'Autre organisme professionnel',
  '8510': 'Institution de prévoyance',
  '8520': 'Institution de retraite supplémentaire',

  '9110': 'Syndicat de copropriété',
  '9150': 'Association syndicale libre',
  '9210': 'Association non déclarée',
  '9220': 'Association déclarée',
  '9221': 'Association déclarée d’insertion par l’économique',
  '9222': 'Association intermédiaire',
  '9223': 'Groupement d’employeurs',
  '9224': 'Association d’avocats à responsabilité professionnelle individuelle',
  '9230': 'Association déclarée, reconnue d’utilité publique',
  '9240': 'Congrégation',
  '9260': 'Association de droit local (Bas-Rhin, Haut-Rhin et Moselle)',
  '9300': 'Fondation',
  '9900': 'Autre personne morale de droit privé',
  '9970': 'Groupement de coopération sanitaire à gestion privée',
};

/**
 * Libellé de FAMILLE, pour un code bien formé mais absent de la table :
 * mieux vaut « SAS » qu'un code nu, et mieux vaut un code nu qu'un libellé
 * inventé. Du préfixe le plus long au plus court.
 */
const FAMILLES: ReadonlyArray<readonly [string, string]> = [
  ['57', 'SAS'],
  ['54', 'SARL'],
  ['55', 'SA'],
  ['56', 'SA'],
  ['65', 'Société civile'],
  ['92', 'Association loi 1901 ou assimilé'],
  ['1', 'Entrepreneur individuel'],
  ['7', 'Personne morale de droit public'],
];

/** Le code à 4 chiffres (« 57.10 », « 5710 » → « 5710 »), ou `null`. */
export function normaliserCategorieJuridique(code: string | null | undefined): string | null {
  if (code === null || code === undefined) return null;
  const chiffres = code.replace(/[^0-9]/g, '');
  return chiffres.length === 4 ? chiffres : null;
}

/**
 * Libellé lisible d'une catégorie juridique INSEE (« 5710 » → « SAS, société
 * par actions simplifiée »), le libellé de sa famille à défaut, ou `null`
 * quand le code est vide, mal formé ou d'une famille inconnue.
 */
export function libelleCategorieJuridique(code: string | null | undefined): string | null {
  const normalise = normaliserCategorieJuridique(code);
  if (normalise === null) return null;
  const exact = CATEGORIES_JURIDIQUES[normalise];
  if (exact !== undefined) return exact;
  for (const [prefixe, libelle] of FAMILLES) {
    if (normalise.startsWith(prefixe)) return libelle;
  }
  return null;
}
