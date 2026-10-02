/**
 * Détecteur de JARGON et d'ANGLAIS dans les textes affichés de la console.
 *
 * Utilisé par `tests/lib/jargon.test.ts`. Le propriétaire de la console n'est
 * pas technicien et travaille en français : « Pending », « Refresh »,
 * « Lancer scraping », « Plafond LLM », « Slug (URL) » sont des mots qu'il n'a
 * pas à déchiffrer (audit UX du 02/10/2026, P1-7 et audit visuel en prod).
 *
 * ═══ CE QU'IL LIT — PLUS ÉTROIT QUE LE DÉTECTEUR DE TUTOIEMENT ═══
 *
 * Le détecteur de tutoiement lit TOUTES les chaînes : « tu », « ton » n'ont
 * aucune chance d'être une valeur technique. Ici, c'est l'inverse : `pending`,
 * `scraper-runs`, `/llm/router`, `queryKey: ['jobs']` sont des valeurs de code
 * légitimes partout. On ne lit donc que ce qui a une chance d'ÊTRE AFFICHÉ :
 *  - le texte JSX (`<p>Texte</p>`) ;
 *  - les chaînes posées dans un attribut JSX, sauf les attributs techniques
 *    (`className`, `to`, `href`, `value`, `key`, `data-*`…) ;
 *  - les chaînes rendues comme enfant JSX (`{cond ? 'A' : 'B'}`) ;
 *  - les propriétés d'objet au NOM d'affichage (`label`, `title`,
 *    `description`, `message`, `placeholder`…) ;
 *  - les valeurs de tables de libellés (propriété dont la valeur commence par
 *    une majuscule ou contient une espace — `{ pending: 'Pending' }`) ;
 *  - les arguments de `toast.*()`, `confirm()`, `alert()` ;
 *  - les `return 'Texte'` (fonctions de libellé), mêmes critères que les tables.
 *
 * Une expression conditionnelle, un `??`, un `||` ou un `+` sont remontés
 * jusqu'à leur contexte : `title={x ? 'Aucun run' : 'Tout va bien'}` est lu.
 *
 * Les commentaires ne sont JAMAIS lus : la documentation interne parle aux
 * développeurs, dans leur langue.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import ts from 'typescript';

export interface OccurrenceJargon {
  fichier: string;
  ligne: number;
  texte: string;
  terme: string;
}

/**
 * Les termes interdits, en mot entier, sans égard à la casse. Chaque entrée
 * dit ce qu'on écrit à la place — c'est le message que lira qui fait rougir.
 */
export const TERMES_INTERDITS: ReadonlyArray<{ motif: RegExp; terme: string; remplacer: string }> = [
  { motif: /scrap(?:e|es|ing|er|ers|é|és|ée|ées)/, terme: 'scrape / scraping', remplacer: 'récupérer, collecte' },
  { motif: /refresh(?:es|ed)?/, terme: 'refresh', remplacer: 'mettre à jour, actualiser' },
  { motif: /auto-refresh/, terme: 'auto-refresh', remplacer: 'mise à jour automatique' },
  { motif: /pending/, terme: 'pending', remplacer: 'en attente, à compléter' },
  { motif: /workspaces?/, terme: 'workspace', remplacer: 'espace, mon entreprise' },
  { motif: /slugs?/, terme: 'slug', remplacer: 'adresse courte (ou ne pas l’afficher)' },
  { motif: /custom/, terme: 'custom', remplacer: 'personnalisé' },
  { motif: /jobs?/, terme: 'job', remplacer: 'tâche, collecte' },
  { motif: /runs?/, terme: 'run', remplacer: 'collecte, passage' },
  { motif: /llms?/, terme: 'LLM', remplacer: 'IA, moteur d’IA' },
  { motif: /prox(?:y|ies)/, terme: 'proxy', remplacer: 'serveur relais' },
  { motif: /preview/, terme: 'preview', remplacer: 'aperçu' },
  { motif: /dashboards?/, terme: 'dashboard', remplacer: 'tableau de bord' },
  { motif: /timeline/, terme: 'timeline', remplacer: 'historique' },
  { motif: /monitoring/, terme: 'monitoring', remplacer: 'suivi' },
  { motif: /kill-switch/, terme: 'kill-switch', remplacer: 'arrêt automatique' },
  { motif: /fallback/, terme: 'fallback', remplacer: 'solution de secours' },
  { motif: /use cases?/, terme: 'use case', remplacer: 'usage' },
  { motif: /providers?/, terme: 'provider', remplacer: 'fournisseur' },
  { motif: /seeders?/, terme: 'seeder', remplacer: '(ne pas afficher)' },
  { motif: /touchpoints?/, terme: 'touchpoint', remplacer: 'échange, contact' },
  { motif: /kpis?/, terme: 'KPI', remplacer: 'chiffre clé' },
  { motif: /batch/, terme: 'batch', remplacer: 'lot' },
  { motif: /tags?/, terme: 'tag', remplacer: 'étiquette' },
  { motif: /pipeline/, terme: 'pipeline', remplacer: 'chaîne de traitement, sources' },
  { motif: /payload/, terme: 'payload', remplacer: 'données envoyées / reçues' },
  { motif: /opt-?out/, terme: 'opt-out', remplacer: 'opposé aux envois' },
  { motif: /rbac/, terme: 'RBAC', remplacer: 'droits d’accès' },
  { motif: /append-only/, terme: 'append-only', remplacer: 'sans effacement possible' },
  { motif: /live/, terme: 'live', remplacer: 'en direct' },
  { motif: /sprint/, terme: 'sprint', remplacer: 'plus tard' },
  { motif: /cron/, terme: 'cron', remplacer: 'automatiquement' },
  { motif: /debug/, terme: 'debug', remplacer: 'diagnostic' },
  { motif: /json/, terme: 'JSON', remplacer: 'format technique' },
  { motif: /roadmap/, terme: 'roadmap', remplacer: 'feuille de route' },
  { motif: /tokens?/, terme: 'token', remplacer: 'jeton, texte' },
  { motif: /crawl\w*/, terme: 'crawl', remplacer: 'lecture du site' },
  { motif: /intent/, terme: 'intent', remplacer: 'intérêt' },
  { motif: /workers?/, terme: 'worker', remplacer: 'traitement' },
  { motif: /backlog/, terme: 'backlog', remplacer: 'en attente' },
  { motif: /api key/, terme: 'API key', remplacer: 'clé d’accès' },
  { motif: /cold e-?mail/, terme: 'cold email', remplacer: 'e-mail de prospection' },
];

/** Frontière de mot pour les lettres accentuées (`\b` les ignore). */
const AVANT = '(?<![\\p{L}\\p{N}_])';
const APRES = '(?![\\p{L}\\p{N}_])';

const MOTIFS_COMPILES = TERMES_INTERDITS.map((t) => ({
  ...t,
  re: new RegExp(`${AVANT}(?:${t.motif.source})${APRES}`, 'iu'),
}));

/** Premier terme interdit trouvé dans `texte`, ou `null`. */
export function termeJargon(texte: string): string | null {
  for (const t of MOTIFS_COMPILES) {
    if (t.re.test(texte)) return t.terme;
  }
  return null;
}

/** Ce qu'on écrit à la place d'un terme renvoyé par `termeJargon`. */
export function remplacementDe(terme: string): string {
  return TERMES_INTERDITS.find((t) => t.terme === terme)?.remplacer ?? '';
}

/** Attributs JSX dont la valeur n'est JAMAIS affichée telle quelle. */
const ATTRIBUTS_TECHNIQUES = new Set([
  'className', 'key', 'to', 'href', 'src', 'id', 'type', 'name', 'variant', 'size', 'tone',
  'role', 'method', 'target', 'rel', 'htmlFor', 'aria-controls', 'aria-labelledby',
  'aria-describedby', 'autoComplete', 'inputMode', 'pattern', 'form', 'value', 'defaultValue',
  'side', 'align', 'as', 'dataTour', 'queryKey', 'contexte', 'download', 'accept', 'lang',
  'dir', 'step', 'min', 'max', 'viewBox', 'd', 'fill', 'stroke', 'xmlns', 'style', 'from',
  'search', 'params', 'hash', 'mode', 'activeOptions', 'testId',
]);

/** Propriétés d'objet dont la valeur est un texte lu par l'utilisateur. */
const PROPRIETES_AFFICHEES = new Set([
  'label', 'title', 'subtitle', 'description', 'message', 'placeholder', 'texte', 'libelle',
  'hint', 'aide', 'eyebrow', 'content', 'header', 'emptyText', 'tooltip', 'text', 'help',
  'cta', 'titre', 'sousTitre', 'explication', 'legende', 'detail', 'ariaLabel',
]);

/** Propriétés d'objet techniques, même quand leur valeur ressemble à une phrase. */
const PROPRIETES_TECHNIQUES = new Set([
  'className', 'to', 'href', 'url', 'path', 'env', 'method', 'id', 'key', 'icon', 'color',
  'queryKey', 'dataTour', 'target', 'endpoint', 'route', 'value', 'code', 'slug', 'pattern',
  'format', 'locale', 'timeZone', 'currency', 'style', 'unit', 'type',
]);

function nomDePropriete(nom: ts.PropertyName): string | null {
  if (ts.isIdentifier(nom) || ts.isStringLiteral(nom) || ts.isNumericLiteral(nom)) return nom.text;
  return null;
}

/** Ressemble à un libellé : majuscule de tête ou au moins une espace. */
function ressembleAUnLibelle(texte: string): boolean {
  return /^\s*\p{Lu}/u.test(texte) || /\S\s+\S/u.test(texte);
}

/**
 * Le contexte qui décide si la chaîne est affichée : on remonte à travers
 * les parenthèses, les ternaires, `??`, `||`, `&&`, `+`, les gabarits.
 */
function contexteDe(noeud: ts.Node): ts.Node {
  let courant: ts.Node = noeud;
  for (;;) {
    const parent: ts.Node | undefined = courant.parent;
    if (parent === undefined) return courant;
    if (
      ts.isParenthesizedExpression(parent) ||
      ts.isAsExpression(parent) ||
      ts.isTemplateSpan(parent) ||
      ts.isTemplateExpression(parent) ||
      (ts.isConditionalExpression(parent) && parent.condition !== courant) ||
      (ts.isBinaryExpression(parent) &&
        [
          ts.SyntaxKind.QuestionQuestionToken,
          ts.SyntaxKind.BarBarToken,
          ts.SyntaxKind.AmpersandAmpersandToken,
          ts.SyntaxKind.PlusToken,
        ].includes(parent.operatorToken.kind) &&
        !(parent.operatorToken.kind === ts.SyntaxKind.AmpersandAmpersandToken && parent.left === courant))
    ) {
      courant = parent;
      continue;
    }
    return parent;
  }
}

function estAffichee(noeud: ts.Node, texte: string): boolean {
  const ctx = contexteDe(noeud);

  if (ts.isJsxExpression(ctx)) {
    const p = ctx.parent;
    if (ts.isJsxAttribute(p)) {
      const nom = p.name.getText();
      return !ATTRIBUTS_TECHNIQUES.has(nom) && !nom.startsWith('data-');
    }
    // Enfant JSX : `{'Texte'}`.
    return ts.isJsxElement(p) || ts.isJsxFragment(p);
  }
  if (ts.isJsxAttribute(ctx)) {
    const nom = ctx.name.getText();
    return !ATTRIBUTS_TECHNIQUES.has(nom) && !nom.startsWith('data-');
  }
  if (ts.isPropertyAssignment(ctx) && ctx.initializer !== undefined) {
    const nom = nomDePropriete(ctx.name);
    if (nom === null || PROPRIETES_TECHNIQUES.has(nom)) return false;
    if (PROPRIETES_AFFICHEES.has(nom)) return true;
    return ressembleAUnLibelle(texte);
  }
  if (ts.isCallExpression(ctx)) {
    const appel = ctx.expression.getText();
    return /^(?:toast(?:\.\w+)?|(?:window\.)?confirm|(?:window\.)?alert)$/.test(appel);
  }
  if (ts.isReturnStatement(ctx) || ts.isArrowFunction(ctx)) {
    return ressembleAUnLibelle(texte);
  }
  return false;
}

/** Les textes affichés d'un module, avec leur ligne. */
export function textesAffichesDuModule(
  chemin: string,
  source: string,
): Array<{ ligne: number; texte: string }> {
  const fichier = ts.createSourceFile(
    chemin,
    source,
    ts.ScriptTarget.Latest,
    true,
    chemin.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  const sortie: Array<{ ligne: number; texte: string }> = [];
  const noter = (noeud: ts.Node, texte: string) => {
    if (texte.trim() === '') return;
    const { line } = fichier.getLineAndCharacterOfPosition(noeud.getStart(fichier));
    sortie.push({ ligne: line + 1, texte });
  };

  const visiter = (noeud: ts.Node): void => {
    if (ts.isImportDeclaration(noeud) || ts.isExportDeclaration(noeud)) return;
    if (ts.isJsxText(noeud)) {
      noter(noeud, noeud.text);
    } else if (ts.isStringLiteral(noeud) || ts.isNoSubstitutionTemplateLiteral(noeud)) {
      // Une CLÉ d'objet (`{ 'workspace required': '…' }`) n'est pas affichée :
      // seule sa valeur peut l'être.
      const estUneCle = ts.isPropertyAssignment(noeud.parent) && noeud.parent.name === noeud;
      if (!estUneCle && estAffichee(noeud, noeud.text)) noter(noeud, noeud.text);
    } else if (ts.isTemplateExpression(noeud)) {
      const texte = [noeud.head.text, ...noeud.templateSpans.map((s) => s.literal.text)].join(' … ');
      if (estAffichee(noeud, texte)) noter(noeud, texte);
      // Les sous-expressions du gabarit sont visitées à part.
    }
    ts.forEachChild(noeud, visiter);
  };
  visiter(fichier);
  return sortie;
}

function fichiers(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) fichiers(chemin, acc);
    else if (/\.tsx?$/.test(chemin) && !chemin.endsWith('.d.ts')) acc.push(chemin);
  }
  return acc;
}

/** Toutes les occurrences de jargon dans les textes affichés sous `racineSrc`. */
export function chercherJargon(racineSrc: string): OccurrenceJargon[] {
  const trouve: OccurrenceJargon[] = [];
  const rel = (c: string) => relative(racineSrc, c).split(sep).join('/');
  for (const chemin of fichiers(racineSrc)) {
    const source = readFileSync(chemin, 'utf8');
    for (const { ligne, texte } of textesAffichesDuModule(chemin, source)) {
      const terme = termeJargon(texte);
      if (terme !== null) trouve.push({ fichier: rel(chemin), ligne, texte: texte.trim(), terme });
    }
  }
  return trouve;
}
