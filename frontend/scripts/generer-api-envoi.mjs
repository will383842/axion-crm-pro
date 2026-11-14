/* global process, console, URL */
/**
 * Génère les types TypeScript du contrat d'API d'Envoi (lot DM0-DEMO).
 *
 *   pnpm api:envoi                       écrit src/features/envoi/api/<zone>.d.ts
 *   pnpm api:envoi --verifier            n'écrit rien ; échoue si un fichier diffère
 *   ... --source <dossier> --cible <dossier>   autres dossiers (témoins des tests)
 *
 * Source : `backend/openapi/envoi/<zone>.yaml`, un document OpenAPI 3.1 complet
 * par zone, qui référence `commun.yaml`. Chaque document est VALIDÉ avant la
 * génération : version exacte 3.1.x, puis règles Redocly « minimal »
 * (structure, références résolues, operationId uniques) appliquées par
 * openapi-typescript. Un document invalide fait échouer la commande.
 *
 * Garde : `tests/lib/contrat-envoi-a-jour.test.ts` (bloquant via `pnpm test`).
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import openapiTS, { astToString } from 'openapi-typescript';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..');

export const ZONES = ['commun', 'socle', 'campagnes', 'creation', 'pilotage', 'reponses', 'infrastructure', 'conformite', 'sequences', 'reputation'];

const ENTETE = (zone) =>
  [
    '/**',
    ` * Types du contrat d'API d'Envoi, zone « ${zone} ».`,
    ` * FICHIER GÉNÉRÉ par \`pnpm api:envoi\` depuis \`backend/openapi/envoi/${zone}.yaml\`.`,
    ' * Ne pas modifier à la main : modifier la description, puis régénérer.',
    ' */',
    '',
  ].join('\n');

function lireOptions(argv) {
  const options = {
    verifier: argv.includes('--verifier'),
    source: path.resolve(RACINE_FRONT, '..', 'backend', 'openapi', 'envoi'),
    cible: path.resolve(RACINE_FRONT, 'src', 'features', 'envoi', 'api'),
  };
  const i = argv.indexOf('--source');
  if (i >= 0 && argv[i + 1]) options.source = path.resolve(argv[i + 1]);
  const j = argv.indexOf('--cible');
  if (j >= 0 && argv[j + 1]) options.cible = path.resolve(argv[j + 1]);
  return options;
}

function verifierVersion(zone, texte) {
  const m = /^openapi:\s*['"]?([\d.]+)['"]?\s*$/m.exec(texte);
  if (!m || !/^3\.1\.\d+$/.test(m[1])) {
    throw new Error(`${zone}.yaml : version OpenAPI attendue 3.1.x, trouvée « ${m ? m[1] : 'aucune'} »`);
  }
  for (const cle of ['info', 'paths']) {
    if (!new RegExp(`^${cle}:`, 'm').test(texte)) throw new Error(`${zone}.yaml : clé « ${cle} » absente`);
  }
}

export async function genererZone(zone, source) {
  const fichier = path.join(source, `${zone}.yaml`);
  if (!existsSync(fichier)) throw new Error(`${zone}.yaml introuvable dans ${source}`);
  verifierVersion(zone, readFileSync(fichier, 'utf8'));
  const ast = await openapiTS(pathToFileURL(fichier), { silent: true, alphabetize: true });
  return ENTETE(zone) + astToString(ast);
}

async function principal() {
  const options = lireOptions(process.argv.slice(2));
  const differents = [];
  const erreurs = [];
  for (const zone of ZONES) {
    try {
      const contenu = await genererZone(zone, options.source);
      const cible = path.join(options.cible, `${zone}.d.ts`);
      const actuel = existsSync(cible) ? readFileSync(cible, 'utf8') : null;
      if (actuel === contenu) continue;
      if (options.verifier) {
        differents.push(zone);
      } else {
        mkdirSync(options.cible, { recursive: true });
        writeFileSync(cible, contenu, 'utf8');
        console.log(`écrit : ${path.relative(process.cwd(), cible)}`);
      }
    } catch (e) {
      erreurs.push(`${zone} : ${e instanceof Error ? e.message : String(e)}`);
    }
  }
  console.log(`${ZONES.length} documents examinés.`);
  if (erreurs.length > 0) {
    console.error(`Document(s) invalide(s) :\n  - ${erreurs.join('\n  - ')}`);
    process.exit(1);
  }
  if (differents.length > 0) {
    console.error(
      `Types pas à jour : ${differents.join(', ')}. Lancez « pnpm api:envoi » et ajoutez les fichiers régénérés.`,
    );
    process.exit(1);
  }
  if (options.verifier) console.log('Types à jour.');
}

if (import.meta.url === pathToFileURL(process.argv[1] ?? '').href || new URL(import.meta.url).pathname === process.argv[1]) {
  await principal();
}
