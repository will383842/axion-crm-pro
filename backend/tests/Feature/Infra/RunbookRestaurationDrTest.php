<?php

/**
 * GARDE — audit 360, passe P6 + lot « exploitation » : `infra/runbooks/04-restore-dr.md`
 * decrivait une reprise apres sinistre qui ne correspondait A AUCUNE SAUVEGARDE
 * EXISTANTE.
 *
 * ── CE QUI ETAIT ECRIT, ET CE QUI EXISTE (mesure du 2026-08-20) ─────────────
 *
 * Le runbook, §« Sources de backup » et §2 :
 *
 *     1. Hetzner Object Storage (chiffre AES-256) — chaque heure full + WAL streaming
 *     2. Backblaze B2 off-site (rule 3-2-1) — replication asynchrone toutes les 6h
 *     ...
 *     s3cmd get s3://axion-crm-backups/postgres/$(date +%F)/full.tar.gz - | tar xz -C /tmp/restore
 *     docker exec axion-crm-postgres pg_basebackup -D /tmp/restore -X stream
 *     echo "restore_command = 's3cmd get s3://axion-crm-backups/wal/%f %p'" >> postgresql.conf
 *
 * La sauvegarde qui existe reellement, elle, est celle-ci — `infra/scripts/backup-postgres.sh` :
 *
 *     · un `pg_dump` plain-text gzippe, UNE FOIS PAR JOUR (cron 03:00 UTC, pose
 *       par `infra/scripts/setup-backup.sh`) ;
 *     · televerse en SFTP sur une Storage Box Hetzner (`sshpass` + `scp`) ;
 *     · retention 7 jours en local, 30 jours hors-site.
 *
 * Il n'y a NI stockage objet, NI archivage WAL, NI Backblaze, NI reprise a un
 * point dans le temps. `s3cmd` n'est meme pas installe sur le serveur : c'est
 * ecrit noir sur blanc en tete de `infra/scripts/dr-drill.sh:13`, ou le meme
 * defaut a deja ete repare le 2026-08-16 — l'exercice de restauration lisait
 * `s3cmd ls s3://axion-crm-backups/` et s'arretait a sa premiere ligne utile.
 *
 * ⚠️ C'EST LE PATRON CARACTERISTIQUE DE CE DEPOT : le correctif existait, il
 * n'avait pas ete porte au SITE JUMEAU. `dr-drill.sh` a ete desintoxique de S3
 * il y a quatre jours ; le runbook que l'humain suit a 3 h du matin, non.
 *
 * Et le vrai chemin de restauration — `infra/scripts/restore-postgres.sh` —
 * n'etait NULLE PART dans le document. L'operateur avait donc, en main, deux
 * commandes qui echouent immediatement, et aucun pointeur vers celle qui marche.
 *
 * ── LE SECOND DEFAUT, PLUS SOURNOIS : LE §5 « VERIF RLS » ───────────────────
 *
 *     docker exec axion-crm-postgres psql -U axion -d axion_crm -c "
 *       SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000';
 *       SELECT COUNT(*) FROM companies;  -- doit retourner 0
 *     "
 *
 * Mesure sur le cluster, 2026-08-20 :
 *
 *     SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin;
 *     axion      | t | t
 *     axion_app  | f | f
 *
 * `axion` est SUPERUTILISATEUR et porte BYPASSRLS. Un superutilisateur ignore
 * la Row Level Security, y compris `FORCE ROW LEVEL SECURITY` (pose par la
 * migration `2026_08_14_000001_harden_workspace_isolation` sur les tables
 * portant `workspace_id`). Le `SELECT COUNT(*)` annonce « doit retourner 0 »
 * retourne donc TOUTE la table.
 *
 * Consequence, et c'est ce qui en fait le defaut le plus couteux : l'operateur
 * qui deroule ce runbook apres un sinistre voit un chiffre non nul, conclut que
 * l'isolation multi-tenant est CASSEE, et se lance dans une chasse au fantome
 * — la nuit ou il a le moins de temps. Ou, pire, il lit « ce doit etre 0 »,
 * voit 4 812, et decide que le runbook a tort sans savoir lequel des deux l'a.
 * C'est le meme defaut que `dr-drill.sh` portait a son etape 4 (comptages joues
 * en `-U axion`), repare le 2026-08-20 par le constat A08-008 — encore un site
 * jumeau non porte.
 *
 * ── CE QUE CETTE GARDE FAIT ────────────────────────────────────────────────
 *
 *   1. elle MESURE la mecanique du §5 : sur une table en `FORCE ROW LEVEL
 *      SECURITY`, avec le contexte workspace pose sur un identifiant qui
 *      n'existe pas, le role `axion` rend la ligne et le role applicatif rend
 *      0. Sans cette mesure, la garde documentaire ne serait qu'une opinion ;
 *   2. elle exige que TOUT outil nomme dans un bloc de commandes du runbook
 *      soit invoque quelque part dans le depot, hors runbooks ;
 *   3. elle exige que TOUT chemin de depot nomme par le runbook EXISTE ;
 *   4. elle exige que le vrai chemin de restauration soit nomme ;
 *   5. elle exige qu'aucun bloc affirmant « doit retourner 0 » ne se joue avec
 *      un role qui contourne la RLS.
 *
 * Chaque balayage a son TEMOIN NEGATIF : on lui presente le defaut d'origine et
 * on exige qu'il le voie, puis la forme correcte et on exige qu'il se taise. Un
 * balayage qu'on n'a jamais vu rouge ne garde rien.
 */

use Tests\TestCase;

uses(TestCase::class);

// ─────────────────────────────────────────────────────────────────────────────
// OUTILLAGE
// ─────────────────────────────────────────────────────────────────────────────

/**
 * La racine du depot : `infra/` vit AU-DESSUS de l'application Laravel.
 */
function racineDepotRunbookDr(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function cheminRunbookDr(): string
{
    return racineDepotRunbookDr() . '/infra/runbooks/04-restore-dr.md';
}

function contenuRunbookDr(): string
{
    return (string) file_get_contents(cheminRunbookDr());
}

/**
 * Les blocs de COMMANDES du document, dans l'ordre.
 *
 * ⚠️ Seuls les blocs ETIQUETES `bash` / `sh` / `shell` comptent. Un runbook
 * contient aussi des blocs de SORTIE — le §7.2 montre le resultat d'un
 * `SELECT … FROM pg_roles`, soit trois lignes de tableau Postgres. Les traiter
 * comme des commandes fabriquait des outils imaginaires : la colonne
 * `rolbypassrls` vaut « t », et « t » devenait un binaire introuvable.
 *
 * Le trou que cela ouvre — cacher une commande dans un bloc non etiquete — est
 * ferme separement, par `fencesNonEtiqueteesHorsCitation()` : un bloc non
 * etiquete n'est tolere que dans une citation, c'est-a-dire dans du commentaire.
 *
 * @return list<string>
 */
function blocsDeCommandesDr(string $contenu): array
{
    preg_match_all('/```(?:bash|sh|shell)\R(.*?)```/s', $contenu, $trouves);

    return array_values($trouves[1]);
}

/**
 * Les numeros de ligne des blocs NON ETIQUETES qui ne sont pas dans une
 * citation.
 *
 * C'est le verrou de la regle ci-dessus. Sans lui, il suffirait d'ouvrir un
 * bloc sans dire « bash » pour y prescrire n'importe quel outil fantome sans
 * qu'aucun balayage ne le voie.
 *
 * @return list<int>
 */
function fencesNonEtiqueteesHorsCitation(string $contenu): array
{
    $suspects = [];
    $dansUnBloc = false;

    foreach (preg_split('/\R/', $contenu) ?: [] as $numero => $ligne) {
        $nue = ltrim($ligne);
        $citation = str_starts_with($nue, '>');
        if ($citation) {
            $nue = ltrim(substr($nue, 1));
        }

        if (! str_starts_with($nue, '```')) {
            continue;
        }

        if ($dansUnBloc) {
            $dansUnBloc = false;

            continue;
        }

        $dansUnBloc = true;
        $etiquette = trim(substr($nue, 3));

        if ($etiquette === '' && ! $citation) {
            $suspects[] = $numero + 1;
        }
    }

    return $suspects;
}

/**
 * Les OUTILS que le runbook demande a l'operateur d'invoquer.
 *
 * On ne lit que les blocs de commandes : la prose peut nommer un outil pour
 * dire qu'il n'existe pas (c'est exactement ce que fait `dr-drill.sh:13`), et
 * l'interdire serait absurde. Ce qu'on traque, c'est ce qui sera TAPE.
 *
 * Trois precautions, chacune motivee par une chose vue dans ce fichier :
 *
 *  · les lignes DANS une chaine ouverte sont ignorees. `psql -c "` ouvre un
 *    guillemet et le referme trois lignes plus bas ; sans ce suivi, `SET` et
 *    `SELECT` — du SQL, pas des commandes — seraient pris pour des outils ;
 *  · les BUILTINS du shell sont ecartes : `cd`, `export`, `echo`... ne sont pas
 *    des binaires, exiger qu'ils figurent dans le depot n'a pas de sens ;
 *  · le jeton qui SUIT `docker exec <conteneur>` est capture lui aussi. Sans
 *    cela, `docker exec … pg_basebackup` ne rendrait que « docker », et le
 *    fantome passerait — c'est precisement la forme qu'il avait au §2.
 *
 * @return list<string>
 */
function outilsInvoquesParLeRunbookDr(string $contenu): array
{
    $builtins = [
        'cd', 'export', 'set', 'echo', 'exit', 'if', 'then', 'else', 'elif', 'fi',
        'for', 'do', 'done', 'while', 'case', 'esac', 'function', 'source', 'local',
        'return', 'sudo', 'true', 'false', 'printf', 'read', 'unset', 'eval', 'trap',
    ];

    $outils = [];

    foreach (blocsDeCommandesDr($contenu) as $bloc) {
        $chaineOuverte = false;

        foreach (preg_split('/\R/', $bloc) ?: [] as $ligne) {
            $nue = trim($ligne);

            // Etat AVANT la ligne : une ligne qui ouvre une chaine est encore
            // une ligne de commande, celles qui suivent ne le sont plus.
            $etaitDansUneChaine = $chaineOuverte;
            $chaineOuverte = $chaineOuverte !== (substr_count($ligne, '"') % 2 === 1);

            if ($etaitDansUneChaine || $nue === '' || str_starts_with($nue, '#')) {
                continue;
            }

            foreach (preg_split('/\|\||&&|[|;]/', $nue) ?: [] as $segment) {
                $segment = trim((string) preg_replace('/^\$\s+/', '', trim($segment)));
                if ($segment === '') {
                    continue;
                }

                if (
                    preg_match('/^docker\s+exec\s+(?:-\S+\s+)*\S+\s+([A-Za-z_][\w.-]*)/', $segment, $sous) === 1
                    && ! in_array($sous[1], $builtins, true)
                ) {
                    $outils[] = $sous[1];
                }

                $jeton = (preg_split('/\s+/', $segment) ?: [''])[0];

                // Un outil Unix s'ecrit en minuscules et ne porte ni `=` (ce
                // serait une affectation) ni `/` (ce serait un chemin, traite
                // par l'autre balayage).
                if (preg_match('/^[a-z_][a-z0-9_.-]*$/', $jeton) !== 1) {
                    continue;
                }
                if (in_array($jeton, $builtins, true)) {
                    continue;
                }

                $outils[] = $jeton;
            }
        }
    }

    $outils = array_values(array_unique($outils));
    sort($outils);

    return $outils;
}

/**
 * Les fichiers du depot qui font foi sur « cet outil, on s'en sert ici ».
 *
 * Les RUNBOOKS en sont volontairement exclus : un document ne peut pas se
 * porter caution a lui-meme. Sans cette exclusion, le §2 aurait prouve
 * l'existence de `s3cmd` en la citant.
 *
 * @return list<string>
 */
function corpusOutilsDuDepot(): array
{
    $racine = racineDepotRunbookDr();
    $fichiers = [];

    foreach (['/infra/scripts', '/infra/terraform', '/infra/postgres', '/.github/workflows'] as $dossier) {
        if (! is_dir($racine . $dossier)) {
            continue;
        }
        /** @var SplFileInfo $entree */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine . $dossier)) as $entree) {
            if ($entree->isFile()) {
                $fichiers[] = $entree->getPathname();
            }
        }
    }

    foreach (glob($racine . '/docker-compose*.yml') ?: [] as $fichier) {
        $fichiers[] = $fichier;
    }
    foreach (glob($racine . '/Dockerfile*') ?: [] as $fichier) {
        $fichiers[] = $fichier;
    }

    return $fichiers;
}

/**
 * Cet outil est-il reellement INVOQUE quelque part dans le depot ?
 *
 * ⚠️ Les lignes de COMMENTAIRE ne comptent pas, et c'est tout le sel de ce
 * balayage. `s3cmd` figure bien dans `dr-drill.sh` — deux fois — mais dans deux
 * commentaires qui disent « `s3cmd` n'est pas installe sur le serveur ». Une
 * recherche naive l'aurait declare present, et la garde serait verte sur le
 * defaut qu'elle est censee attraper.
 *
 * @param  list<string>  $fichiers
 */
function outilInvoqueDansLeDepot(string $outil, array $fichiers): bool
{
    $motif = '/(^|[\s|;&(=`"\'])' . preg_quote($outil, '/') . '(\s|$|")/';

    foreach ($fichiers as $fichier) {
        foreach (preg_split('/\R/', (string) file_get_contents($fichier)) ?: [] as $ligne) {
            $nue = trim($ligne);
            if (
                $nue === ''
                || str_starts_with($nue, '#')
                || str_starts_with($nue, '*')
                || str_starts_with($nue, '//')
            ) {
                continue;
            }
            if (preg_match($motif, $nue) === 1) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Les CHEMINS DE DEPOT nommes par le document, normalises.
 *
 * On accepte le prefixe `/opt/axion-crm-pro/` : c'est la ou le depot est
 * deploye sur le serveur, et un runbook a de bonnes raisons d'ecrire le chemin
 * absolu. On le retire pour verifier le fichier ici.
 *
 * @return list<string>
 */
function cheminsDeDepotDuRunbookDr(string $contenu): array
{
    preg_match_all(
        '#(?:/opt/axion-crm-pro/)?((?:infra|backend|spec|docs)/[A-Za-z0-9_./-]+)#',
        $contenu,
        $trouves,
    );

    $chemins = [];
    foreach ($trouves[1] as $brut) {
        // Une phrase se termine par un point ; un chemin, non.
        $chemins[] = rtrim($brut, './,;:)');
    }

    $chemins = array_values(array_unique(array_filter($chemins)));
    sort($chemins);

    return $chemins;
}

/**
 * Ce chemin de depot pointe-t-il sur quelque chose ?
 *
 * ⚠️ `backend/` merite un traitement a part, et ce n'est pas une complaisance :
 * le banc de mesure monte l'application Laravel sur `/var/www/html`, pas sur
 * `/var/www/backend`. Un chemin `backend/tests/…` parfaitement valide dans le
 * depot y serait declare mort. On le resout donc par `base_path()`, qui EST le
 * repertoire de l'application, ou qu'il soit monte.
 */
function cheminDeDepotExiste(string $chemin): bool
{
    if (file_exists(racineDepotRunbookDr() . '/' . $chemin)) {
        return true;
    }

    if (str_starts_with($chemin, 'backend/')) {
        return file_exists(base_path(substr($chemin, strlen('backend/'))));
    }

    return false;
}

/**
 * Un bloc qui AFFIRME UN CHIFFRE ATTENDU se joue-t-il avec un role qui
 * contourne la RLS ?
 *
 * `-U axion` sans rien derriere, c'est le superutilisateur. `-U axion_app`,
 * c'est le role applicatif : la negation `(?![_\w])` est ce qui separe les
 * deux, et sans elle le balayage crierait sur le correctif lui-meme.
 */
function blocJoueAvecLeRoleQuiContourneLaRls(string $bloc): bool
{
    return preg_match('/-U\s+axion(?![_\w])/', $bloc) === 1;
}

/** Ce bloc annonce-t-il un resultat attendu de zero ? */
function blocAffirmeUnComptageNul(string $bloc): bool
{
    return preg_match('/(doit retourner|attendu\s*:?|=>|-->|→)\s*0\b/iu', $bloc) === 1;
}

// ─────────────────────────────────────────────────────────────────────────────
// 1. LE BANC EST-IL EN ETAT DE MESURER ?  (temoins d'infrastructure)
// ─────────────────────────────────────────────────────────────────────────────

test('P6-DR — TEMOIN : le banc voit le runbook, et c est bien celui de la reprise', function () {
    $chemin = cheminRunbookDr();

    $this->assertFileExists(
        $chemin,
        'Le runbook de reprise est introuvable. Racine vue : ' . racineDepotRunbookDr(),
    );

    $contenu = contenuRunbookDr();

    // Un fichier vide ferait passer TOUS les balayages ci-dessous au vert : ils
    // ne trouveraient ni outil fantome, ni chemin mort, ni bloc fautif.
    expect(strlen($contenu))->toBeGreaterThan(
        800,
        'Le runbook fait moins de 800 octets : tronque ou vide, les balayages qui suivent '
        . 'seraient verts sans rien avoir regarde.',
    );

    // Sous-chaines SANS LETTRE ACCENTUEE : le document est en francais, la
    // garde ne doit pas dependre de son encodage.
    $this->assertStringContainsString(
        'RPO',
        $contenu,
        "Ce n'est pas le runbook de reprise apres sinistre.",
    );
    $this->assertStringContainsString(
        'RTO',
        $contenu,
        "Ce n'est pas le runbook de reprise apres sinistre.",
    );

    expect(blocsDeCommandesDr($contenu))->not->toBeEmpty(
        'Le runbook ne contient AUCUN bloc de commandes. Le balayage des outils et celui '
        . 'des roles porteraient sur zero bloc : verts par vacuite.',
    );
});

test('P6-DR — TEMOIN : le corpus du depot est bien peuple', function () {
    $corpus = corpusOutilsDuDepot();

    // Si le corpus etait vide, `outilInvoqueDansLeDepot()` rendrait faux pour
    // TOUT, et le balayage des outils rougirait sur des outils parfaitement
    // legitimes. C'est l'echec inverse, et il est tout aussi trompeur.
    expect(count($corpus))->toBeGreaterThan(
        20,
        'Le corpus de reference compte moins de 20 fichiers : le balayage des outils '
        . 'declarerait absent a peu pres n importe quoi. Racine vue : ' . racineDepotRunbookDr(),
    );

    // Et il contient bien la chaine de sauvegarde reelle, celle sur laquelle le
    // runbook doit etre reecrit.
    foreach (['backup-postgres.sh', 'restore-postgres.sh', 'dr-drill.sh', 'setup-backup.sh'] as $script) {
        expect(is_file(racineDepotRunbookDr() . '/infra/scripts/' . $script))->toBeTrue(
            "`infra/scripts/{$script}` est introuvable : le runbook ne peut pas etre "
            . 'verifie contre une chaine de sauvegarde absente.',
        );
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA MECANIQUE DU §5, MESUREE
//    « psql -U axion … doit retourner 0 » — non. Il retourne tout.
// ─────────────────────────────────────────────────────────────────────────────

test('P6-DR — MESURE : le role `axion` contourne la RLS, son COUNT ne peut PAS rendre 0', function () {
    $proprietaire = (string) config('database.connections.pgsql_owner.username');
    $mdpProprietaire = (string) config('database.connections.pgsql_owner.password');
    $roleApp = (string) config('database.connections.pgsql_app.username');
    $mdpApp = (string) config('database.connections.pgsql_app.password');

    // TEMOIN — sans role applicatif distinct, la mesure ne prouverait rien : on
    // comparerait un role avec lui-meme. On ECHOUE en le disant plutot que de
    // sauter le test : un test ignore est un vert deguise.
    expect($roleApp)->not->toBe(
        $proprietaire,
        "Le role applicatif et le role proprietaire sont le MEME (« {$roleApp} ») sur ce banc : "
        . 'la mesure ci-dessous comparerait un role avec lui-meme.',
    );
    expect($mdpApp)->not->toBe(
        '',
        '`DB_APP_PASSWORD` est vide : impossible d ouvrir une session avec le role applicatif, '
        . 'la mesure ne porterait sur rien.',
    );

    $hote = (string) config('database.connections.pgsql_owner.host');
    $port = (string) config('database.connections.pgsql_owner.port');
    $ouvrir = static fn (string $base, string $utilisateur, string $mdp): PDO => new PDO(
        "pgsql:host={$hote};port={$port};dbname={$base}",
        $utilisateur,
        $mdp,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );

    $admin = $ouvrir('postgres', $proprietaire, $mdpProprietaire);

    // TEMOIN — c'est bien la propriete `rolbypassrls` qu'on met en cause, et
    // elle est bien portee par le role que le runbook nommait.
    $ligne = $admin->query(
        'SELECT rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ' . $admin->quote($proprietaire),
    )->fetch(PDO::FETCH_ASSOC);

    expect($ligne)->not->toBeFalse("Le role « {$proprietaire} » n'existe pas dans ce cluster.");
    expect((bool) $ligne['rolbypassrls'] || (bool) $ligne['rolsuper'])->toBeTrue(
        "Le role « {$proprietaire} » ne contourne PAS la RLS sur ce banc (rolsuper="
        . var_export($ligne['rolsuper'], true) . ', rolbypassrls=' . var_export($ligne['rolbypassrls'], true)
        . '). La mesure qui suit ne demontrerait alors rien du defaut de production, ou '
        . 'l on a mesure le 2026-08-20 : axion | t | t.',
    );

    $base = 'axion_crm_test_lot14_a35_runbook_rls';
    $admin->exec("DROP DATABASE IF EXISTS {$base} WITH (FORCE)");
    $admin->exec("CREATE DATABASE {$base}");

    try {
        $proprio = $ouvrir($base, $proprietaire, $mdpProprietaire);

        // On reproduit EXACTEMENT ce que pose la migration
        // `2026_08_14_000001_harden_workspace_isolation` : ENABLE + FORCE ROW
        // LEVEL SECURITY, et une policy STRICTE (aucun repli permissif).
        $proprio->exec('CREATE TABLE public.fiches_isolees (id integer PRIMARY KEY, workspace_id uuid, nom text)');
        $proprio->exec(
            'INSERT INTO public.fiches_isolees VALUES '
            . "(1, '11111111-1111-1111-1111-111111111111', 'une fiche du workspace reel')",
        );
        $proprio->exec('ALTER TABLE public.fiches_isolees ENABLE ROW LEVEL SECURITY');
        $proprio->exec('ALTER TABLE public.fiches_isolees FORCE ROW LEVEL SECURITY');
        $proprio->exec(
            'CREATE POLICY fiches_isolees_workspace_isolation ON public.fiches_isolees FOR ALL '
            . "USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))",
        );
        $proprio->exec('GRANT USAGE ON SCHEMA public TO ' . $roleApp);
        $proprio->exec('GRANT SELECT ON public.fiches_isolees TO ' . $roleApp);

        // ── LE GESTE DU RUNBOOK, A LA LETTRE ────────────────────────────────
        // Contexte pose sur un workspace qui n'existe pas, puis COUNT(*).
        // Le runbook annonce « doit retourner 0 ».
        $proprio->exec("SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000'");
        $vuParLeSuperutilisateur = (int) $proprio
            ->query('SELECT COUNT(*) FROM public.fiches_isolees')
            ->fetchColumn();

        expect($vuParLeSuperutilisateur)->toBe(
            1,
            "Le role « {$proprietaire} » ne voit pas la ligne alors qu'il contourne la RLS : "
            . 'la mesure est cassee (table vide ? policy mal posee ?), et le constat ci-dessous '
            . 'ne reposerait sur rien.',
        );

        // ── ET AVEC LE ROLE APPLICATIF, LE MEME GESTE REND BIEN 0 ───────────
        // C'est le TEMOIN INVERSE, et il est indispensable : sans lui, le `1`
        // ci-dessus pourrait venir d'une policy inoperante plutot que du
        // contournement, et la garde accuserait le mauvais coupable.
        $app = $ouvrir($base, $roleApp, $mdpApp);
        $app->exec("SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000'");
        $vuParLeRoleApplicatif = (int) $app
            ->query('SELECT COUNT(*) FROM public.fiches_isolees')
            ->fetchColumn();

        expect($vuParLeRoleApplicatif)->toBe(
            0,
            "Le role applicatif « {$roleApp} » voit {$vuParLeRoleApplicatif} ligne(s) alors que "
            . 'le contexte workspace pointe sur un identifiant inexistant : la policy ne mord '
            . 'pas, et la mesure ne separe donc pas les deux roles.',
        );

        // Le constat, dit en une ligne : MEME base, MEME requete, MEME contexte,
        // deux resultats. C'est le role qui decide, pas la donnee.
        expect($vuParLeSuperutilisateur)->not->toBe(
            $vuParLeRoleApplicatif,
            'Les deux roles rendent le meme chiffre : le §5 du runbook serait alors correct, '
            . 'et cette garde n aurait pas lieu d etre.',
        );

        unset($app, $proprio);
    } finally {
        // On rend le cluster propre meme si la garde a rougi.
        $admin->exec("DROP DATABASE IF EXISTS {$base} WITH (FORCE)");
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. LES TEMOINS NEGATIFS DES BALAYAGES
//    On leur presente le defaut d'origine, puis la forme correcte.
// ─────────────────────────────────────────────────────────────────────────────

test('P6-DR — TEMOIN NEGATIF : le balayage des outils voit les fantomes du §2 d origine', function () {
    // Le §2 tel qu'il etait ecrit. Trois fantomes a trouver.
    $ancienSection2 = <<<'TEXTE'
    ## 2. Restaurer Postgres
    ```bash
    # Récupérer le dernier full + WAL
    s3cmd get s3://axion-crm-backups/postgres/$(date +%F)/full.tar.gz - | tar xz -C /tmp/restore
    docker exec axion-crm-postgres pg_basebackup -D /tmp/restore -X stream
    ```
    TEXTE;

    $outils = outilsInvoquesParLeRunbookDr($ancienSection2);

    $this->assertContains('s3cmd', $outils, 'Le balayage rate `s3cmd`, l outil qui a motive cette garde.');
    $this->assertContains('tar', $outils, 'Le balayage rate `tar` apres un tube.');
    $this->assertContains(
        'pg_basebackup',
        $outils,
        'Le balayage rate `pg_basebackup` : il ne regarde donc pas le jeton qui suit '
        . '`docker exec <conteneur>`, et tout fantome cache derriere `docker exec` passerait.',
    );

    // Et il ne prend pas le SQL pour des commandes — le §5 en contient trois
    // lignes, dans une chaine ouverte par `psql -c "`.
    $ancienSection5 = <<<'TEXTE'
    ```bash
    docker exec axion-crm-postgres psql -U axion -d axion_crm -c "
      SET app.current_workspace_id = '00000000-0000-0000-0000-000000000000';
      SELECT COUNT(*) FROM companies;
    "
    ```
    TEXTE;

    $outils5 = outilsInvoquesParLeRunbookDr($ancienSection5);
    $this->assertContains('psql', $outils5, 'Le balayage rate `psql` derriere `docker exec`.');
    $this->assertNotContains('select', $outils5, 'Le balayage prend du SQL pour une commande.');
    $this->assertNotContains('set', $outils5, 'Le balayage prend du SQL pour une commande.');

    // Ni la PROSE pour des commandes : `dr-drill.sh` a le droit d ecrire, en
    // toutes lettres, que `s3cmd` n existe pas.
    $prose = 'Elle lisait `s3cmd ls s3://axion-crm-backups/`, alors que la sauvegarde est en SFTP.';
    expect(outilsInvoquesParLeRunbookDr($prose))->toBe(
        [],
        'Le balayage lit la prose : il interdirait de NOMMER un outil pour dire qu il est absent.',
    );
});

test('P6-DR — TEMOIN NEGATIF : « present dans le depot » ignore les commentaires', function () {
    // ── D'ABORD SUR UNE PIECE FABRIQUEE ─────────────────────────────────────
    // Le balayage doit distinguer « cet outil est invoque » de « cet outil est
    // MENTIONNE ». On ne fait pas dependre ce temoin des commentaires d'un
    // fichier reel : un autre agent peut les reecrire demain, et le temoin
    // deviendrait vert sans plus rien mesurer.
    $fabrique = sys_get_temp_dir() . '/a35-temoin-outil-mentionne.sh';
    file_put_contents($fabrique, <<<'SH'
        #!/usr/bin/env bash
        # elle lisait `s3cmd ls s3://axion-crm-backups/` — s3cmd n'est pas installe.
        sshpass -p "$SB_PASSWORD" scp -P 23 dump.sql.gz cible:/chemin/
        SH);

    try {
        expect(outilInvoqueDansLeDepot('s3cmd', [$fabrique]))->toBeFalse(
            'Le balayage compte une MENTION en commentaire comme une invocation. Il aurait '
            . 'blanchi `s3cmd`, l outil qui a motive cette garde, sur la foi du commentaire '
            . 'de `dr-drill.sh` qui dit precisement qu il n est PAS installe.',
        );
        expect(outilInvoqueDansLeDepot('sshpass', [$fabrique]))->toBeTrue(
            'Le balayage ne reconnait pas une invocation reelle : il rougirait sur tout.',
        );
    } finally {
        @unlink($fabrique);
    }

    // ── PUIS SUR LE DEPOT REEL ──────────────────────────────────────────────
    $corpus = corpusOutilsDuDepot();

    // `s3cmd` figure DEUX FOIS dans `dr-drill.sh` — dans deux commentaires qui
    // disent qu il n est pas installe. C'est le piege exact de ce balayage.
    expect(outilInvoqueDansLeDepot('s3cmd', $corpus))->toBeFalse(
        '`s3cmd` est declare present dans le depot. Or il n y figure que dans les commentaires '
        . 'de `dr-drill.sh`, qui disent le contraire : « s3cmd n est pas installe sur le '
        . 'serveur ». Le balayage lit donc les commentaires, et il aurait blanchi le §2.',
    );

    // Temoin inverse : il sait dire oui. `sshpass` est le coeur de la
    // sauvegarde reelle, sur des lignes executables.
    expect(outilInvoqueDansLeDepot('sshpass', $corpus))->toBeTrue(
        '`sshpass` est declare absent alors que `backup-postgres.sh` l invoque : le balayage '
        . 'ne sait pas reconnaitre une invocation, et rougirait sur tout.',
    );
    expect(outilInvoqueDansLeDepot('docker', $corpus))->toBeTrue(
        '`docker` est declare absent : le balayage est casse.',
    );

    // Et il ne confond pas un outil inexistant avec un outil reel.
    expect(outilInvoqueDansLeDepot('cf-cli', $corpus))->toBeFalse(
        '`cf-cli` est declare present : il ne figure nulle part dans le depot.',
    );
});

test('P6-DR — TEMOIN NEGATIF : les balayages de chemins et de roles voient le defaut', function () {
    // Chemins : le vrai et un mort.
    $chemins = cheminsDeDepotDuRunbookDr(
        'Cf. `infra/scripts/restore-postgres.sh` et /opt/axion-crm-pro/infra/scripts/dr-drill.sh, '
        . 'ainsi que `infra/scripts/replication-wal.sh` qui n existe pas.',
    );
    $this->assertContains('infra/scripts/restore-postgres.sh', $chemins);
    $this->assertContains(
        'infra/scripts/dr-drill.sh',
        $chemins,
        'Le prefixe /opt/axion-crm-pro/ n est pas normalise : un chemin absolu de serveur '
        . 'ne serait jamais verifie.',
    );
    $this->assertContains('infra/scripts/replication-wal.sh', $chemins);

    // Le point final d une phrase ne fait pas partie du chemin.
    $this->assertContains(
        'infra/scripts/backup-postgres.sh',
        cheminsDeDepotDuRunbookDr('Tout part de infra/scripts/backup-postgres.sh.'),
        'Le point final de la phrase est colle au chemin : la garde rougirait sur un chemin valide.',
    );

    // Roles : `-U axion` est le superutilisateur, `-U axion_app` ne l est pas.
    expect(blocJoueAvecLeRoleQuiContourneLaRls('docker exec pg psql -U axion -d axion_crm -c "…"'))->toBeTrue();
    expect(blocJoueAvecLeRoleQuiContourneLaRls('docker exec pg psql -U axion_app -d axion_crm -c "…"'))->toBeFalse(
        'Le balayage crie sur `-U axion_app` : il rougirait sur le correctif lui-meme.',
    );

    // Affirmation d un comptage nul, sous ses formes courantes.
    expect(blocAffirmeUnComptageNul('SELECT COUNT(*) FROM companies;  -- doit retourner 0'))->toBeTrue();
    expect(blocAffirmeUnComptageNul('SELECT COUNT(*) FROM companies;  -- attendu : 0'))->toBeTrue();
    expect(blocAffirmeUnComptageNul('SELECT COUNT(*) FROM companies;  -- on regarde le chiffre'))->toBeFalse();

    // Existence d un chemin : le banc monte l application sur `html/`, pas sur
    // `backend/`. CE FICHIER-CI est la preuve vivante que la resolution marche.
    expect(cheminDeDepotExiste('backend/tests/Feature/Infra/RunbookRestaurationDrTest.php'))->toBeTrue(
        'Le balayage ne resout pas un chemin `backend/…` : il declarerait morts tous les '
        . 'renvois vers le code de l application, y compris vers cette garde.',
    );
    expect(cheminDeDepotExiste('infra/scripts/backup-postgres.sh'))->toBeTrue();
    expect(cheminDeDepotExiste('infra/scripts/replication-wal.sh'))->toBeFalse(
        'Le balayage declare present un fichier inexistant : il ne verifierait plus rien.',
    );
});

test('P6-DR — TEMOIN NEGATIF : un bloc non etiquete hors citation est repere', function () {
    // Le balayage des outils ne lit QUE les blocs etiquetes `bash`. Le verrou,
    // c'est qu'un bloc non etiquete ne soit tolere qu'en citation. Sans lui, il
    // suffirait d'omettre le mot « bash » pour prescrire `s3cmd` sans etre vu.
    $smuggle = "## 2\n```\ns3cmd get s3://axion-crm-backups/full.tar.gz\n```\n";
    expect(fencesNonEtiqueteesHorsCitation($smuggle))->toBe(
        [2],
        'Le verrou ne voit pas un bloc non etiquete pose au fil du document : le balayage '
        . 'des outils serait contournable en retirant un seul mot.',
    );

    // Et il ne crie ni sur un bloc etiquete, ni sur une SORTIE citee — c'est la
    // forme du §7.2, qui montre le resultat de `SELECT … FROM pg_roles`.
    expect(fencesNonEtiqueteesHorsCitation("```bash\ndocker compose up -d\n```\n"))->toBe([]);
    expect(fencesNonEtiqueteesHorsCitation("> ```\n>  axion | t | t\n> ```\n"))->toBe(
        [],
        'Le verrou crie sur une sortie citee : il interdirait de MONTRER la mesure qui '
        . 'justifie le correctif.',
    );

    // Le corollaire, sur le vrai balayage : « t » (la colonne rolbypassrls) ne
    // doit pas etre pris pour un binaire.
    expect(outilsInvoquesParLeRunbookDr("> ```\n>  axion      | t | t\n> ```\n"))->toBe(
        [],
        'Le balayage des outils lit un tableau de sortie Postgres comme des commandes.',
    );
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. LES GARDES
// ─────────────────────────────────────────────────────────────────────────────

test('P6-DR — le runbook ne nomme QUE des outils invoques ailleurs dans le depot', function () {
    $contenu = contenuRunbookDr();

    // LE VERROU D'ABORD. Le balayage qui suit ne lit que les blocs etiquetes
    // `bash`. Un bloc non etiquete pose au fil du document echapperait donc a
    // tout controle tout en se lisant, pour l'operateur, exactement comme une
    // commande a taper.
    expect(fencesNonEtiqueteesHorsCitation($contenu))->toBe(
        [],
        "Le runbook porte un bloc de code NON ETIQUETE hors citation (lignes ci-dessus).\n\n"
        . "Le balayage des outils ne lit que les blocs `bash` / `sh` / `shell` — parce qu'un "
        . 'runbook contient aussi des blocs de SORTIE. Un bloc non etiquete au fil du texte '
        . "se lit comme une commande a taper mais n'est verifie par personne : c'est la porte "
        . "de service par laquelle un outil fantome reviendrait.\n\n"
        . 'Correctif : etiqueter le bloc `bash`, ou le mettre en citation si ce sont des '
        . 'lignes de resultat.',
    );

    $outils = outilsInvoquesParLeRunbookDr($contenu);

    // TEMOIN — le document doit prescrire quelque chose. Un runbook de reprise
    // sans une seule commande passerait ce balayage sans rien prouver.
    expect(count($outils))->toBeGreaterThan(
        3,
        'Le runbook n invoque que ' . count($outils) . ' outil(s) : trop peu pour un runbook de '
        . 'reprise, et ce balayage serait vert par vacuite. Trouves : ' . implode(', ', $outils),
    );

    $corpus = corpusOutilsDuDepot();
    $fantomes = [];
    foreach ($outils as $outil) {
        if (! outilInvoqueDansLeDepot($outil, $corpus)) {
            $fantomes[] = $outil;
        }
    }

    expect($fantomes)->toBe(
        [],
        'Le runbook de reprise prescrit des outils qui ne sont invoques NULLE PART dans le '
        . 'depot : ' . implode(', ', $fantomes) . ".\n\n"
        . "C'est un document qu'on suit a 3 heures du matin, apres un sinistre, sans le temps "
        . "de verifier. `s3cmd` par exemple n'est meme pas installe sur le serveur — c'est "
        . 'ecrit en tete de `infra/scripts/dr-drill.sh`, ou le meme defaut a ete repare le '
        . "2026-08-16 sans etre porte ici.\n\n"
        . 'La sauvegarde qui EXISTE est un `pg_dump` quotidien televerse en SFTP par '
        . '`infra/scripts/backup-postgres.sh` ; elle se restaure par '
        . "`infra/scripts/restore-postgres.sh`. Il n'y a ni stockage objet, ni archivage WAL, "
        . "ni reprise a un point dans le temps.\n\n"
        . 'Correctif : reecrire le geste sur ce qui existe. Si un outil est reellement requis, '
        . "il doit d'abord etre installe ET invoque par un script du depot.",
    );
});

test('P6-DR — tous les chemins de depot nommes par le runbook EXISTENT', function () {
    $contenu = contenuRunbookDr();
    $chemins = cheminsDeDepotDuRunbookDr($contenu);

    // TEMOIN — un runbook qui ne nomme aucun chemin est precisement le defaut
    // d'origine : il ne pointait vers AUCUN des scripts qui font le travail.
    expect(count($chemins))->toBeGreaterThan(
        2,
        'Le runbook ne nomme que ' . count($chemins) . ' chemin(s) du depot. Un operateur en '
        . 'sinistre a besoin de savoir QUEL fichier jouer. Trouves : ' . implode(', ', $chemins),
    );

    $morts = [];
    foreach ($chemins as $chemin) {
        if (! cheminDeDepotExiste($chemin)) {
            $morts[] = $chemin;
        }
    }

    expect($morts)->toBe(
        [],
        "Le runbook renvoie vers des chemins qui n'existent pas : " . implode(', ', $morts) . ".\n\n"
        . 'Racine vue : ' . racineDepotRunbookDr() . ' (application : ' . base_path() . ')',
    );
});

test('P6-DR — le runbook nomme le VRAI chemin de restauration', function () {
    $contenu = contenuRunbookDr();

    // Le defaut d'origine n'etait pas seulement d'ecrire des faussetes : c'etait
    // de ne PAS nommer la seule chose qui marche. Les quatre scripts de la
    // chaine reelle doivent etre cites, parce qu'un operateur en sinistre a
    // besoin des quatre : produire, restaurer, s'entrainer, remettre en place.
    $attendus = [
        'infra/scripts/restore-postgres.sh' => "c'est LE chemin de restauration, et il n'etait "
            . 'nomme nulle part dans le document.',
        'infra/scripts/backup-postgres.sh' => "c'est ce qui produit l'archive : sans lui, "
            . "l'operateur ne sait pas ou chercher ni sous quel nom.",
        'infra/scripts/dr-drill.sh' => "c'est l'exercice, et il documente le format de l'archive.",
        'infra/scripts/verifier-sauvegarde.sh' => 'il repond en une commande a « la sauvegarde '
            . 'a-t-elle eu lieu », question numero un apres un sinistre.',
    ];

    foreach ($attendus as $chemin => $pourquoi) {
        $this->assertStringContainsString(
            $chemin,
            $contenu,
            "Le runbook de reprise ne nomme pas `{$chemin}` : {$pourquoi}",
        );
    }
});

test('P6-DR — aucun controle du runbook ne s appuie sur un role qui contourne la RLS', function () {
    $contenu = contenuRunbookDr();
    $fautifs = [];

    foreach (blocsDeCommandesDr($contenu) as $index => $bloc) {
        if (blocAffirmeUnComptageNul($bloc) && blocJoueAvecLeRoleQuiContourneLaRls($bloc)) {
            $fautifs[] = $index;
        }
    }

    expect($fautifs)->toBe(
        [],
        "Le runbook annonce un comptage attendu de 0 dans un bloc joue avec `-U axion`.\n\n"
        . "Mesure du 2026-08-20 sur le cluster :\n"
        . "    SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin;\n"
        . "    axion      | t | t\n"
        . "    axion_app  | f | f\n\n"
        . '`axion` est SUPERUTILISATEUR et porte BYPASSRLS : il ignore la Row Level Security, '
        . 'y compris `FORCE ROW LEVEL SECURITY`. Le `SELECT COUNT(*)` annonce « doit retourner '
        . '0 » retourne TOUTE la table. La mesure directe est plus haut dans ce fichier : '
        . 'meme base, meme requete, meme contexte workspace inexistant — 1 ligne vue par '
        . "`axion`, 0 par `axion_app`.\n\n"
        . "Consequence : l'operateur, apres un sinistre, lit un chiffre non nul et conclut que "
        . "l'isolation multi-tenant est cassee. Il part en chasse au fantome la nuit ou il a "
        . "le moins de temps.\n\n"
        . 'Correctif : jouer ce controle avec le role applicatif (`-U axion_app`), le seul qui '
        . "soit soumis a la RLS. C'est le meme correctif que celui pose le 2026-08-20 dans "
        . '`dr-drill.sh` (constat A08-008).',
    );
});

test('P6-DR — le runbook ne promet plus une sauvegarde horaire ni un stockage qui n existe pas', function () {
    $contenu = contenuRunbookDr();

    // Ces promesses-la ne sont pas des commandes : elles sont dans la prose, et
    // le balayage des outils ne les voit pas. Elles sont pourtant ce qui fixe
    // les ATTENTES de l'operateur — donc son RPO, donc ce qu'il annoncera.
    //
    // ⚠️ Comparaison en minuscules sur des sous-chaines SANS ACCENT.
    $minuscules = mb_strtolower($contenu);

    foreach ([
        'backblaze' => "aucun compte Backblaze n'est configure nulle part dans le depot ; la "
            . 'seule copie hors-site est une Storage Box Hetzner en SFTP.',
        'wal streaming' => "aucun archivage WAL n'est configure : `archive_mode` n'apparait dans "
            . 'aucun fichier du depot. Promettre du WAL, c est promettre un RPO d une minute '
            . 'sur une sauvegarde QUOTIDIENNE.',
        'object storage' => 'la sauvegarde ne va pas sur du stockage objet mais sur une Storage '
            . 'Box, en SFTP (`sshpass` + `scp`), cf. `infra/scripts/backup-postgres.sh`.',
        's3://' => "aucun bucket S3 ne recoit de sauvegarde ; `s3cmd` n'est meme pas installe.",
    ] as $fantome => $pourquoi) {
        $this->assertStringNotContainsString(
            $fantome,
            $minuscules,
            "Le runbook promet encore « {$fantome} » : {$pourquoi}\n\n"
            . 'Un operateur qui lit cette promesse croit pouvoir revenir a T-5 min. La realite '
            . 'mesurable est un `pg_dump` par jour a 03:00 UTC : la perte maximale est de '
            . '24 heures, et il faut le DIRE.',
        );
    }
});

// ─────────────────────────────────────────────────────────────────────────────
// F39-009 (S2) — LE MENSONGE SURVIT AILLEURS QUE DANS LE RUNBOOK
//
// Le runbook a ete corrige : il annonce 24 h depuis le 2026-08-16
// (`infra/runbooks/04-restore-dr.md:38`). C'est exactement le patron de ce depot
// — « le correctif existe mais n'a pas ete porte au site jumeau » — et il s'est
// referme une fois de plus ici. Mesure du 2026-08-22, DEUX autres documents
// promettaient encore un RPO d'une heure :
//
//     Makefile:160        dr-drill: ## DR drill (RPO <= 1h, RTO <= 4h)
//     ARCHITECTURE.md:94  Backups : Hetzner Object Storage hourly + Backblaze B2 (3-2-1)
//     ARCHITECTURE.md:95  DR : RPO 1h / RTO 4h
//
// Facteur 24. Le depot etablit lui-meme la sauvegarde QUOTIDIENNE a trois
// endroits : `infra/scripts/dr-drill.sh:15`, `.github/workflows/surveillance-
// sauvegarde.yml:28-30` (cron 05:00 UTC, « deux heures apres la sauvegarde de
// 03:00 UTC ») et le runbook lui-meme.
//
// Le risque n'est pas technique : ARCHITECTURE.md est le document qu'on montre.
// Un RPO d'une heure promis a un client est un engagement que l'infrastructure
// ne tient pas.
//
// ⚠️ CE QUE CETTE GARDE NE PEUT PAS DIRE. Le constat affirmait aussi « la
// profondeur reelle des sauvegardes est de trois jours, pas trente ». C'est
// INDECIDABLE depuis le depot : la configuration dit bien 30
// (`infra/scripts/backup-postgres.sh:55`, `RETENTION_REMOTE_DAYS=30`), et lire
// le contenu reel de la Storage Box exige de s'y connecter. Cette garde ne
// certifie donc RIEN sur la profondeur — elle ne mesure que le RPO annonce.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Les documents qui annoncent un RPO au lecteur, hors runbook.
 *
 * @return array<string, string> chemin relatif => contenu
 */
function documentsQuiAnnoncentLeRpo(): array
{
    $racine = racineDepotRunbookDr();
    $documents = [];

    foreach (['Makefile', 'ARCHITECTURE.md'] as $relatif) {
        $chemin = $racine . '/' . $relatif;
        $documents[$relatif] = file_exists($chemin) ? (string) file_get_contents($chemin) : '';
    }

    return $documents;
}

/**
 * Les lignes qui promettent un RPO d'une heure.
 *
 * ⚠️ `\brpo\b` et non `rpo` : `ARCHITECTURE.md:32` contient `BRPOP`, la commande
 * Redis. Sans la frontiere de mot, le balayage inspecterait le schema
 * d'architecture des workers et rendrait un faux positif que personne ne saurait
 * lire.
 *
 * @return list<string>
 */
function promessesDeRpoDUneHeure(string $texte): array
{
    $fautives = [];

    foreach (preg_split('/\R/u', $texte) ?: [] as $ligne) {
        if (preg_match('/\brpo\b.{0,14}?\b1\s*h/iu', $ligne) === 1) {
            $fautives[] = trim($ligne);
        }
    }

    return $fautives;
}

/**
 * Les promesses de sauvegarde qui n'existent nulle part dans l'infrastructure.
 *
 * @return list<string>
 */
function fantomesDeSauvegardeDansLaDoc(string $texte): array
{
    $minuscules = mb_strtolower($texte);
    $trouves = [];

    foreach (['hourly', 'backblaze', 'object storage', 'wal streaming', 'sauvegarde horaire'] as $fantome) {
        if (str_contains($minuscules, $fantome)) {
            $trouves[] = $fantome;
        }
    }

    return $trouves;
}

test('F39-009 — TEMOIN : le banc voit bien le Makefile et ARCHITECTURE.md', function () {
    foreach (documentsQuiAnnoncentLeRpo() as $relatif => $contenu) {
        expect(mb_strlen($contenu))->toBeGreaterThan(
            200,
            "Le banc lit {$relatif} comme un fichier vide ou minuscule. Les assertions qui "
            . "suivent n'y trouveraient aucun mensonge et passeraient au vert sans rien "
            . 'inspecter. Monte la racine du depot avant de les croire.',
        );
    }

    // L'ancre doit etre la : sans elle, quelqu'un pourrait renommer la recette et
    // la garde inspecterait un fichier qui ne parle plus de reprise.
    expect(str_contains(documentsQuiAnnoncentLeRpo()['Makefile'], 'dr-drill:'))->toBeTrue(
        "La recette `dr-drill` a disparu du Makefile : la garde F39-009 n'a plus rien a "
        . 'inspecter la-bas. Verifie ou l exercice de reprise est declare desormais.',
    );
});

test('F39-009 — TEMOIN NEGATIF : les deux balayages voient bien le mensonge d origine', function () {
    // Les trois lignes exactes mesurees le 2026-08-22, avant correction.
    $avant = "dr-drill: ## DR drill (RPO ≤ 1h, RTO ≤ 4h)\n"
        . "- **Backups** : Hetzner Object Storage hourly + Backblaze B2 réplication off-site\n"
        . "- **DR** : RPO 1h / RTO 4h drillé via `infra/scripts/dr-drill.sh`\n";

    expect(promessesDeRpoDUneHeure($avant))->toHaveCount(
        2,
        'Le balayage ne reconnait plus le mensonge qu il a ete ecrit pour voir. Une garde '
        . "qu'on n'a jamais vue rouge ne garde rien : repare `promessesDeRpoDUneHeure()`.",
    );

    expect(fantomesDeSauvegardeDansLaDoc($avant))->not->toBeEmpty(
        'Le balayage des sauvegardes fantomes ne voit plus « hourly » ni « Backblaze ». '
        . 'Repare `fantomesDeSauvegardeDansLaDoc()`.',
    );

    // Et il doit se TAIRE sur la forme corrigee, sinon il rougira eternellement.
    $apres = "dr-drill: ## DR drill (RPO réel ≤ 24 h — sauvegarde quotidienne —, RTO ≤ 4 h)\n"
        . "- **DR** : **RPO réel ≤ 24 h** — la sauvegarde étant quotidienne, il n'y a pas de\n"
        . "                         │ Node Workers ×N  │ (Playwright stealth + BRPOP)\n";

    expect(promessesDeRpoDUneHeure($apres))->toBe(
        [],
        'Le balayage crie au loup sur la formulation CORRIGEE (ou sur `BRPOP`, la commande '
        . 'Redis du schema des workers). Une garde qui rougit sur le correctif finit '
        . 'desactivee : resserre `promessesDeRpoDUneHeure()`.',
    );
});

test('F39-009 — aucun document du depot ne promet plus un RPO d une heure', function () {
    $fautifs = [];

    foreach (documentsQuiAnnoncentLeRpo() as $relatif => $contenu) {
        foreach (promessesDeRpoDUneHeure($contenu) as $ligne) {
            $fautifs[] = "{$relatif}  →  {$ligne}";
        }
        foreach (fantomesDeSauvegardeDansLaDoc($contenu) as $fantome) {
            $fautifs[] = "{$relatif}  →  promesse fantome « {$fantome} »";
        }
    }

    expect($fautifs)->toBe(
        [],
        "Un document annonce encore un RPO d'une heure, ou une sauvegarde qui n'existe pas.\n\n"
        . "La seule sauvegarde qui existe est QUOTIDIENNE : `pg_dump` a 03:00 UTC, cron pose par "
        . "`infra/scripts/setup-backup.sh`, televerse en SFTP sur une Storage Box Hetzner. La "
        . "perte maximale reelle est donc de 24 heures — facteur 24 sur ce qui etait annonce. Le "
        . "depot l'etablit lui-meme a trois endroits : `infra/scripts/dr-drill.sh:15`, "
        . "`.github/workflows/surveillance-sauvegarde.yml:28-30` et "
        . "`infra/runbooks/04-restore-dr.md:26-38`.\n\n"
        . "ARCHITECTURE.md est le document qu'on MONTRE : un RPO d'une heure promis a un client "
        . "est un engagement que l'infrastructure ne tient pas.\n\n"
        . 'GESTE : aligne la ligne sur `infra/runbooks/04-restore-dr.md:24-38`. Fermer le vrai '
        . "ecart demande un archivage continu des journaux de transaction — un chantier, pas une "
        . "ligne de documentation ; tant qu'il n'est pas fait, on annonce 24 h. Constat F39-009.\n\n"
        . "Lignes :\n  - " . implode("\n  - ", $fautifs),
    );
});
