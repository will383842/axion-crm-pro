/**
 * GARDE H46-008 — « les ecrans RGPD, Utilisateurs, Reglages et Societe
 * recoivent des reponses d'API entierement non typees ; cinq
 * `no-unsafe-return` sont geles dans les suppressions ESLint ».
 *
 * ── CE QUI ETAIT MESURE ───────────────────────────────────────────────────
 *
 * Mesure du 2026-08-22, `frontend/eslint-suppressions.json` : CINQ
 * `@typescript-eslint/no-unsafe-return` geles, et exactement sur ces quatre
 * ecrans — `RgpdRequestsPage` (2), `UsersPage` (1), `SettingsPage` (1),
 * `CompanyDetailPage` (1). La cause etait la meme partout : `api.post(…)` sans
 * parametre generique rend `AxiosResponse<any>`, donc `.data` vaut `any`, donc
 * le `mutationFn` rend `any` — et tout ce qu'on en tire ensuite echappe a
 * `tsc`.
 *
 * ⚠️ NUANCE, portee au registre : « entierement non typees » est trop fort.
 * Les LECTURES de ces memes ecrans etaient deja typees
 * (`api.get<{ data: RgpdRequest[] }>`, `api.get<Workspace>`,
 * `api.get<CompanyDetail>`). Ce sont les MUTATIONS qui ne l'etaient pas.
 *
 * ── CE QUE CETTE GARDE MESURE ────────────────────────────────────────────
 *
 *   A. Le gel a disparu : plus aucune entree `no-unsafe-return` dans
 *      `eslint-suppressions.json`. Sans cette face, on pourrait typer les cinq
 *      appels et laisser les suppressions derriere — la dette resterait
 *      inscrite, et la regle resterait eteinte pour ces fichiers.
 *   B. La cause a disparu : toute reponse de MUTATION dont on lit `.data`
 *      declare sa forme. C'est la face qui RETIENT : `pnpm lint` refuserait un
 *      sixieme cas, mais rien n'empecherait quelqu'un de le geler a nouveau
 *      d'un `--suppress-all`. Ici, il devra effacer une garde nommee.
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS. Les LECTURES : il reste un
 * `(await api.get(…)).data` sans generique (`AudienceDetailPage.tsx:100`, ou
 * la valeur est passee a `enveloppe<EmailAudience>()`). Il n'appartient pas a
 * ce constat, il n'a jamais figure dans les suppressions
 * `no-unsafe-return`, et l'inclure ici ferait rougir un lot voisin pour un
 * defaut qu'on n'a pas mesure. Il est nomme, pas couvert.
 */
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, relative, sep } from 'node:path';
import { describe, expect, it } from 'vitest';

function trouverRacineFront(): string {
  let courant = process.cwd();
  for (let i = 0; i < 6; i += 1) {
    if (existsSync(join(courant, 'src', 'components', 'ui'))) return courant;
    const parent = dirname(courant);
    if (parent === courant) break;
    courant = parent;
  }
  const secours = join(process.cwd(), 'frontend');
  if (existsSync(join(secours, 'src', 'components', 'ui'))) return secours;
  return process.cwd();
}

const RACINE_FRONT = trouverRacineFront();
const RACINE_SRC = join(RACINE_FRONT, 'src');
const SUPPRESSIONS = join(RACINE_FRONT, 'eslint-suppressions.json');

function modules(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) modules(chemin, acc);
    else if (chemin.endsWith('.ts') || chemin.endsWith('.tsx')) acc.push(chemin);
  }
  return acc;
}

function cheminRelatif(chemin: string): string {
  return relative(RACINE_SRC, chemin).split(sep).join('/');
}

/**
 * Les appels de MUTATION dont on lit immediatement `.data`.
 *
 * On ne vise QUE la forme `(await api.post(…))`, celle qui destructure la
 * reponse pour en rendre le corps : c'est elle que `no-unsafe-return` accusait.
 * Un `await api.post(…)` en fin de course, dont personne ne lit le resultat, ne
 * propage aucun `any` — l'exiger typé serait du bruit.
 *
 * Rend, pour chaque site, le caractere qui suit le nom de methode : `<` quand
 * la forme est declaree, autre chose sinon.
 */
export function mutationsDontOnLitLeCorps(source: string): Array<{ methode: string; typee: boolean }> {
  // `\(\s*await` et non `\(await` : quand la ligne devient longue, le
  // formateur remonte le `await` sur la ligne suivante — la sonde ne verrait
  // plus le site, et rendrait un faux vert. Mesure du 2026-08-22 : c'est le
  // cas de `RgpdRequestsPage.processMut`.
  const motif = /\(\s*await\s+api\.(post|put|patch|delete)\s*(<?)/g;
  const trouves: Array<{ methode: string; typee: boolean }> = [];
  for (;;) {
    const m = motif.exec(source);
    if (m === null) break;
    trouves.push({ methode: m[1] ?? '', typee: m[2] === '<' });
  }
  return trouves;
}

// ---------------------------------------------------------------------------
// TEMOIN — sans lui, les deux faces peuvent etre vertes sans rien inspecter
// ---------------------------------------------------------------------------

describe('H46-008 — TEMOIN : la sonde retrouve le defaut tel qu il etait ecrit', () => {
  it('voit l appel non type de RgpdRequestsPage, et le distingue du corrige', () => {
    // Recopie fidele de `RgpdRequestsPage.tsx:82` avant reparation.
    const avant =
      "mutationFn: async () => (await api.post('/rgpd/requests', { type: newType })).data,";
    expect(mutationsDontOnLitLeCorps(avant)).toEqual([{ methode: 'post', typee: false }]);

    const apres =
      "mutationFn: async () => (await api.post<RgpdRequest>('/rgpd/requests', { type: newType })).data,";
    expect(mutationsDontOnLitLeCorps(apres)).toEqual([{ methode: 'post', typee: true }]);
  });

  it('ne reclame rien d un appel dont personne ne lit le corps', () => {
    // `await api.delete(…)` seul ne propage aucun `any` : la sonde doit
    // l ignorer, sinon la garde reclamerait un type pour rien et finirait
    // desactivee.
    expect(mutationsDontOnLitLeCorps('await api.delete(`/users/${id}`);')).toEqual([]);
  });

  it('lit vraiment des fichiers', () => {
    expect(
      modules(RACINE_SRC).length,
      `H46-008 : aucun module lu sous « ${RACINE_SRC} » — la garde ne mesure RIEN.`,
    ).toBeGreaterThan(50);
    expect(
      existsSync(SUPPRESSIONS),
      `H46-008 : « ${SUPPRESSIONS} » est introuvable. La garde ne peut pas verifier que le gel ` +
        'a ete leve.',
    ).toBe(true);
  });
});

// ---------------------------------------------------------------------------
// A. Le gel a ete leve
// ---------------------------------------------------------------------------

describe('H46-008 — plus aucun `no-unsafe-return` gele', () => {
  it('eslint-suppressions.json ne fige plus cette regle', () => {
    const brut = readFileSync(SUPPRESSIONS, 'utf8');
    const suppressions = JSON.parse(brut) as Record<string, Record<string, { count: number }>>;

    const restants = Object.entries(suppressions)
      .filter(([, regles]) => '@typescript-eslint/no-unsafe-return' in regles)
      .map(([fichier, regles]) => `${fichier} (${regles['@typescript-eslint/no-unsafe-return']?.count ?? '?'})`);

    expect(
      restants,
      'H46-008 : `@typescript-eslint/no-unsafe-return` est de nouveau gele pour : ' +
        `${restants.join(', ')}.\n\n` +
        'Mesure du 2026-08-22 : les cinq entrees d origine ont ete retirees en meme temps que ' +
        'les generiques ont ete poses sur les cinq `api.post` / `api.put` correspondants.\n' +
        '`eslint-suppressions.README.md` le dit : ce fichier ne doit que DECROITRE — on ne ' +
        'relance jamais `--suppress-all`.\n' +
        'GESTE : declarer la forme de la reponse (`api.post<MonType>(…)`), puis ' +
        '`pnpm exec eslint . --prune-suppressions`.',
    ).toEqual([]);
  });
});

// ---------------------------------------------------------------------------
// B. La cause a disparu
// ---------------------------------------------------------------------------

describe('H46-008 — toute mutation dont on lit le corps declare sa forme', () => {
  it('aucun `(await api.post/put/patch/delete(` sans generique dans src', () => {
    const nus: string[] = [];

    for (const chemin of modules(RACINE_SRC)) {
      const source = readFileSync(chemin, 'utf8');
      for (const site of mutationsDontOnLitLeCorps(source)) {
        if (!site.typee) nus.push(`${cheminRelatif(chemin)} — api.${site.methode}(`);
      }
    }

    expect(
      nus,
      'H46-008 : ces mutations rendent le corps de la reponse sans en declarer la forme :\n' +
        `${nus.join('\n')}\n\n` +
        'Sans generique, `axios` rend `AxiosResponse<any>` : le `any` traverse le `mutationFn`, ' +
        '`useMutation` en herite, et tout ce qu on en lit ensuite echappe a `tsc`. C est ce que ' +
        'les cinq suppressions gelees mesuraient.\n' +
        'GESTE : poser le generique en LISANT le controleur, pas en le supposant — les points ' +
        "d entree de ce depot ne repondent PAS tous sous une clef `data` (`ApiController::ok()` " +
        'rend la valeur telle quelle, seuls les paginateurs enveloppent). Quand la forme n est ' +
        'pas connue, `unknown` est la reponse honnete.',
    ).toEqual([]);
  });

  it('les cinq sites du constat portent bien leur generique', () => {
    // Le registre nomme, pour que la garde dise quelque chose meme si
    // quelqu'un remaniait la sonde ci-dessus. Chaque fragment a ete lu dans le
    // fichier le 2026-08-22.
    const REGISTRE: Array<[string, string]> = [
      ['features/rgpd/RgpdRequestsPage.tsx', 'api.post<RgpdRequest>'],
      ['features/rgpd/RgpdRequestsPage.tsx', 'api.post<{ request: RgpdRequest; result: unknown }>'],
      // 🔴 X39-027, 2026-08-24 — ce site portait `api.post<unknown>`, et la
      // consigne de cette garde disait deja quoi faire le jour ou le point
      // d'entree existerait : « remplacer `unknown` par la forme LUE dans le
      // controleur, et corriger ce registre ». C'est ce jour.
      //
      // L'appel ne visait pas la bonne route : `POST /users/invite` n'a JAMAIS
      // existe, alors que `POST /users` (`UsersController::store`) est cable
      // depuis le 2026-08-23 et rend `201 { data: <compte> }` — forme lue dans
      // le controleur, pas supposee.
      ['features/users/UsersPage.tsx', 'api.post<{ data: UserRow }>'],
      // ⚠️ `SettingsPage.tsx` reste `unknown` A DESSEIN DANS CETTE PR, et ce
      // n'est plus vrai pour longtemps : `WorkspaceController::update()` ne
      // rend PLUS 501 depuis le 2026-08-23, il ecrit reellement. L'ecran, lui,
      // n'a pas suivi — c'est le constat X39-034, traite dans sa PR propre.
      // Le corriger ici melangerait deux ecrans dans un meme changement.
      ['features/settings/SettingsPage.tsx', 'api.put<unknown>'],
      ['features/companies/CompanyDetailPage.tsx', 'api.post<CompanyDetail>'],
    ];

    for (const [relatif, fragment] of REGISTRE) {
      const source = readFileSync(join(RACINE_SRC, relatif), 'utf8');
      expect(
        source.includes(fragment),
        `H46-008 : « ${fragment} » a disparu de ${relatif}.\n` +
          'Les deux `unknown` ne sont PAS un oubli : mesure du 2026-08-22, `POST /users/invite` ' +
          "n existe pas dans `backend/routes/api.php`, et `WorkspaceController::update()` rend " +
          'un 501 (`notImplemented`). Declarer une forme de succes pour ces deux-la serait ' +
          'affirmer sans mesure.\n' +
          'GESTE : si le point d entree existe desormais, remplacer `unknown` par la forme LUE ' +
          'dans le controleur, et corriger ce registre.',
      ).toBe(true);
    }
  });

  /**
   * 🔴 X39-027 — LA GARDE QUI MANQUAIT, et qui aurait vu le defaut.
   *
   * Le bouton « Inviter un utilisateur » a poste vers `POST /users/invite`
   * — une route qui n'a JAMAIS existe cote serveur — pendant tout le temps ou
   * l'ecran passait pour fini. Rien ne rougissait : `tsc` ne connait pas les
   * routes, le lint non plus, et la garde ci-dessus ne verifiait que le
   * GENERIQUE de l'appel, pas sa CIBLE. Le commentaire du code nommait meme le
   * defaut sans que rien ne le mesure.
   *
   * Cette garde ferme l'angle mort pour ce cas precis : la chaine `/users/
   * invite` ne doit reapparaitre nulle part dans `src/`. Elle est volontairement
   * ETROITE — une garde generale « toute route appelee existe cote serveur »
   * demanderait de lire `routes/api.php` depuis le frontend, ce qui melangerait
   * les deux paquets ; elle reste a ecrire, et elle vaudrait mieux que celle-ci.
   */
  it('X39-027 — aucun ecran ne poste vers `/users/invite`, qui n existe pas cote serveur', () => {
    const fautifs: string[] = [];

    const parcourir = (repertoire: string): void => {
      for (const entree of readdirSync(repertoire)) {
        const chemin = join(repertoire, entree);
        if (statSync(chemin).isDirectory()) {
          parcourir(chemin);

          continue;
        }
        if (! /\.(ts|tsx)$/.test(entree)) {
          continue;
        }
        const source = readFileSync(chemin, 'utf8');
        // On cherche la chaine DANS UN APPEL, pas dans un commentaire : le
        // commentaire de `UsersPage.tsx` cite la route pour expliquer le
        // correctif, et le faire rougir pour cela serait absurde.
        if (/['"`]\/users\/invite['"`]/.test(source)) {
          fautifs.push(relative(RACINE_SRC, chemin));
        }
      }
    };
    parcourir(RACINE_SRC);

    expect(
      fautifs,
      `X39-027 : « /users/invite » est appelee dans ${fautifs.join(', ')}.\n` +
        "Cette route n existe pas dans `backend/routes/api.php`, qui ne declare `/users` qu en " +
        'index / store / update / destroy. Un appel qui la vise rend 404, et le bouton ne cree ' +
        'aucun compte.\n' +
        'GESTE : viser `POST /users` (`UsersController::store`), et envoyer `name` — il y est ' +
        '`required`, un corps `{ email, role }` seul rend 422.',
    ).toEqual([]);
  });
});
