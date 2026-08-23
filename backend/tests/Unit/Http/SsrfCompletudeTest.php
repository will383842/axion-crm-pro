<?php

/**
 * GARDE DE COMPLETUDE — « la garde SSRF est-elle branchee PARTOUT ? » (C19-001).
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * POURQUOI UNE GARDE DE PLUS, ALORS QUE SsrfGardeBrancheeTest EXISTE DEJA
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * `SsrfGardeBrancheeTest` prouve que TROIS services nommes refusent une adresse
 * interne. C'est une garde de COMPORTEMENT : elle ne sait que ce qu'on lui a
 * dit de regarder. Le constat C19-001 nommait trois services ; rien ne garantit
 * qu'il n'y en avait pas un quatrieme, et surtout rien n'empeche le CINQUIEME
 * d'arriver demain. C'est exactement le patron A-011 de ce depot (25 cas
 * mesures) : le correctif existe, il n'a pas ete porte au site jumeau.
 *
 * Ce fichier-ci est donc une garde d'ENUMERATION : elle relit le code source de
 * `app/Services` et `app/Jobs`, y retrouve TOUTE emission HTTP dont l'URL n'est
 * pas une constante, et exige que la methode emettrice connaisse `SsrfGuard`.
 * Un nouveau service qui appellerait `Http::get($url)` sans garde fait rougir
 * ce fichier sans que personne ait a y penser.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * CE QUE LA MESURE A DONNE (2026-08-20, sonde jouee avant d'ecrire ce fichier)
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *     fichiers .php scannes (Services + Jobs) ....... 73
 *     fichiers capables d'emettre du HTTP ........... 20
 *     sites d'emission a URL NON constante .......... 9
 *
 * Les 9 se repartissent ainsi :
 *   - 4 dans DomainFinderService  (gardes, commit 9389121)
 *   - 2 dans MentionsLegalesScraperService (gardes, commit 9389121)
 *   - 3 a hote CONSTANT, seule la fin du chemin varie (HibpChecker, INSEE x2) :
 *     exemptes NOMMEMENT ci-dessous, avec le motif ecrit.
 *
 * ═══════════════════════════════════════════════════════════════════════════
 * ⚠️ CE QUE CETTE METHODE NE PEUT PAS VOIR — dit franchement
 * ═══════════════════════════════════════════════════════════════════════════
 *
 * Une enumeration statique est un angle mort deguise en preuve. Les siens :
 *
 *  1. Elle lit du TEXTE, pas un flot de donnees. Un appel indirect
 *     (`$methode = 'get'; $client->$methode($url);`), un `call_user_func`, une
 *     URL passee a une bibliotheque tierce, un `Http::macro()` : invisibles.
 *  2. Elle ne franchit pas la frontiere PHP → Node. `DispatchScrapeJob` depose
 *     une `target_url` issue de `companies.website` sur une liste Redis lue par
 *     un worker Playwright : aucune emission HTTP en PHP, donc rien ici. C'est
 *     `SsrfSitesJumeauxTest` qui couvre ce cas, par le comportement.
 *  3. Elle juge « garde presente » a la maille de la METHODE, pas du flot :
 *     une methode qui contiendrait `SsrfGuard::check()` sur une AUTRE URL que
 *     celle qu'elle emet passerait. La garde de comportement
 *     (`SsrfGardeBrancheeTest`) est ce qui ferme ce trou-la ; les deux se
 *     tiennent, aucune ne suffit seule.
 *  4. Elle ne regarde que `app/Services` et `app/Jobs` — le perimetre du lot.
 *     `app/Console/Commands` emet aussi du HTTP (mesure : 6 fichiers) ; ce qui
 *     y a ete trouve est consigne au rapport, non repare ici.
 *
 * Pour reduire (1), l'enumeration est faite de DEUX facons independantes
 * (tokenisation d'un cote, expression reguliere ligne a ligne de l'autre) et le
 * banc EXIGE que la premiere trouve tout ce que la seconde trouve. Les deux ont
 * deja diverge pendant la mise au point : la methode B avait vu deux sites que
 * la methode A ratait (une instruction coupee par le `;` interne d'une
 * fermeture passee a `->retry()`). C'est ce croisement qui l'a montre.
 *
 * REGLE DE MESURE : aucun `expect()->toContain($aiguille, $message)` ici — le
 * second argument de `toContain()` est VARIADIQUE en Pest, un message y devient
 * une aiguille a chercher et la garde rougit eternellement. On passe par
 * `assertStringContainsString` / `assertContains`.
 */

// ═══════════════════════════════════════════════════════════════════════════
// L'ENUMERATEUR
// ═══════════════════════════════════════════════════════════════════════════

/** Methodes qui EMETTENT une requete (le 1er argument est l'URL). */
const SSRF_METHODES_EMISSION = ['get', 'post', 'put', 'patch', 'delete', 'head', 'send', 'options'];

/**
 * Signatures qui rendent un fichier CAPABLE d'emettre du HTTP. Volontairement
 * larges : mieux vaut examiner un fichier de trop que d'en manquer un.
 */
const SSRF_SIGNATURES_EMETTEUR = '/Http::|GuzzleHttp|curl_init|ProxiedHttpClient|file_get_contents\s*\(\s*[\'"]https?/';

/**
 * Decoupe une source PHP en INSTRUCTIONS.
 *
 * ⚠️ Le point delicat, paye pendant la mise au point : on ne coupe sur `;`,
 * `{` et `}` que HORS parentheses. Sinon une chaine comme
 *
 *     Http::timeout(5)->retry(1, 500, function ($e) { return $e instanceof X; })->get($url)
 *
 * est coupee par le `;` interne de la fermeture, et le morceau qui porte
 * `->get($url)` perd le marqueur `Http::` qui le rendait reconnaissable. C'est
 * exactement ainsi que les deux emissions de MentionsLegales et celles d'INSEE
 * ont d'abord echappe a la methode A.
 *
 * @return list<array{int, string}> [ligne de debut, texte de l'instruction]
 */
function ssrfCompletudeInstructions(string $source): array
{
    $instructions = [];
    $courant = '';
    $ligne = 0;
    $profondeur = 0;

    foreach (token_get_all($source) as $jeton) {
        if (is_array($jeton)) {
            // Un commentaire n'emet aucune requete : il ne doit ni former une
            // instruction, ni en teinter une. Meme raison que
            // `ssrfCompletudeSansCommentaires()`, sur le jumeau de ce balayage.
            if ($jeton[0] === T_COMMENT || $jeton[0] === T_DOC_COMMENT) {
                continue;
            }
            if (trim($courant) === '') {
                $ligne = $jeton[2];
            }
            $courant .= $jeton[1];

            continue;
        }
        if ($jeton === '(' || $jeton === '[') {
            $profondeur++;
            $courant .= $jeton;

            continue;
        }
        if ($jeton === ')' || $jeton === ']') {
            $profondeur--;
            $courant .= $jeton;

            continue;
        }
        if (($jeton === ';' || $jeton === '{' || $jeton === '}') && $profondeur === 0) {
            if (trim($courant) !== '') {
                $instructions[] = [$ligne, $courant];
            }
            $courant = '';

            continue;
        }
        $courant .= $jeton;
    }
    if (trim($courant) !== '') {
        $instructions[] = [$ligne, $courant];
    }

    return $instructions;
}

/**
 * L'instruction porte-t-elle une CHAINE de client HTTP ?
 *
 * La liste melange deux familles de marqueurs, et c'est voulu : le point
 * d'entree (`Http::`, `$pool->`, `GuzzleHttp`, `ProxiedHttpClient`) n'est pas
 * toujours visible — `HttpInseeClient` passe par `$this->authHttp()`, un nom
 * que rien ne signale. On reconnait alors la chaine a ses MAILLONS
 * (`->timeout(`, `->withHeaders(`, `->retry(`…), qui sont propres au client
 * HTTP de Laravel. Sans ce second jeu, les deux emissions INSEE etaient ratees.
 */
function ssrfCompletudeEstChaineHttp(string $instruction): bool
{
    foreach ([
        'Http::', '$pool->', 'ProxiedHttpClient', '->http->', 'GuzzleHttp',
        '->timeout(', '->connectTimeout(', '->withHeaders(', '->withToken(',
        '->withOptions(', '->withBasicAuth(', '->acceptJson(', '->asForm(',
        '->asJson(', '->withBody(', '->retry(',
    ] as $marqueur) {
        if (str_contains($instruction, $marqueur)) {
            return true;
        }
    }

    return false;
}

/**
 * Extrait, dans une instruction, les emissions dont le 1er argument porte une
 * VARIABLE — c'est-a-dire dont l'URL vient (au moins en partie) de la donnee.
 *
 * @return list<string> expressions normalisees, ex. `get($base . $path)`
 */
function ssrfCompletudeEmissionsVariables(string $instruction): array
{
    $trouves = [];

    foreach (SSRF_METHODES_EMISSION as $methode) {
        foreach (['->' . $methode . '(', 'Http::' . $methode . '('] as $motif) {
            $decalage = 0;
            while (($position = strpos($instruction, $motif, $decalage)) !== false) {
                $decalage = $position + 1;
                $i = $position + strlen($motif);
                $profondeur = 1;
                $argument = '';
                for ($n = strlen($instruction); $i < $n; $i++) {
                    $c = $instruction[$i];
                    if ($c === '(' || $c === '[') {
                        $profondeur++;
                    }
                    if ($c === ')' || $c === ']') {
                        $profondeur--;
                        if ($profondeur === 0) {
                            break;
                        }
                    }
                    if ($c === ',' && $profondeur === 1) {
                        break;
                    }
                    $argument .= $c;
                }
                if (str_contains($argument, '$')) {
                    $trouves[] = $methode . '(' . preg_replace('/\s+/', ' ', trim($argument)) . ')';
                }
            }
        }
    }

    return $trouves;
}

/**
 * METHODE B — enumeration INDEPENDANTE, ligne a ligne, sans tokenisation.
 * Elle rate ce qui est ecrit sur plusieurs lignes ; elle attrape ce que la
 * decoupe en instructions rate. Les deux servent a se contredire.
 *
 * @return list<int> numeros de ligne
 */
function ssrfCompletudeLignesSuspectes(string $source): array
{
    $lignes = [];
    foreach (explode("\n", $source) as $i => $texte) {
        if (preg_match(
            '/->(get|post|put|patch|delete|head|send)\(\s*(\$|"[^"]*\{\$|[A-Za-z_:\\\\]+\s*\.\s*\$)/',
            $texte,
        )) {
            $lignes[] = $i + 1;
        }
    }

    return $lignes;
}

/**
 * Plages de lignes de chaque fonction/methode/fermeture, avec leur texte.
 *
 * ⚠️ La ligne de FIN est celle du dernier jeton nomme rencontre avant
 * l'accolade fermante : `token_get_all()` ne donne pas de ligne aux jetons d'un
 * seul caractere. L'imprecision est d'au plus une ligne, et elle ne peut que
 * RETRECIR la plage — donc jamais faire passer une emission pour gardee.
 *
 * @return list<array{int, int, string}> [debut, fin, texte]
 */
function ssrfCompletudePlagesFonctions(string $source): array
{
    $jetons = token_get_all($source);
    $n = count($jetons);
    $plages = [];

    for ($i = 0; $i < $n; $i++) {
        if (! is_array($jetons[$i]) || $jetons[$i][0] !== T_FUNCTION) {
            continue;
        }
        $debut = $jetons[$i][2];

        // Trouver l'accolade ouvrante ; un `;` avant = methode abstraite.
        $j = $i + 1;
        $ouvrante = null;
        for (; $j < $n; $j++) {
            $texte = is_array($jetons[$j]) ? $jetons[$j][1] : $jetons[$j];
            if ($texte === '{') {
                $ouvrante = $j;
                break;
            }
            if ($texte === ';') {
                break;
            }
        }
        if ($ouvrante === null) {
            continue;
        }

        $profondeur = 1;
        $corps = '';
        $fin = $debut;
        for ($k = $ouvrante + 1; $k < $n; $k++) {
            $jeton = $jetons[$k];
            if (is_array($jeton)) {
                $fin = $jeton[2];
                $corps .= $jeton[1];
                // `"...{$x}"` ouvre une accolade sous forme de jeton nomme.
                if ($jeton[0] === T_CURLY_OPEN || $jeton[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $profondeur++;
                }

                continue;
            }
            $corps .= $jeton;
            if ($jeton === '{') {
                $profondeur++;
            }
            if ($jeton === '}') {
                $profondeur--;
                if ($profondeur === 0) {
                    break;
                }
            }
        }

        $plages[] = [$debut, $fin, $corps];
    }

    return $plages;
}

/**
 * Le RESULTAT DE L'ENUMERATION sur une source donnee.
 *
 * @return list<array{ligne: int, expression: string, gardee: bool}>
 */
function ssrfCompletudeAnalyser(string $source): array
{
    $plages = ssrfCompletudePlagesFonctions($source);
    $sites = [];

    foreach (ssrfCompletudeInstructions($source) as [$ligne, $instruction]) {
        if (! ssrfCompletudeEstChaineHttp($instruction)) {
            continue;
        }
        foreach (ssrfCompletudeEmissionsVariables($instruction) as $expression) {
            // Methode ENGLOBANTE = la plus EXTERIEURE qui contient la ligne.
            // C'est le bon choix : dans `Http::pool(function () { … })` la garde
            // peut etre posee AVANT la fermeture (cas `revalidateBatch`, ou
            // `SsrfGuard::check()` filtre la liste en amont du pool).
            $texteMethode = null;
            $meilleurDebut = PHP_INT_MAX;
            foreach ($plages as [$debut, $fin, $corps]) {
                if ($ligne >= $debut && $ligne <= $fin && $debut < $meilleurDebut) {
                    $meilleurDebut = $debut;
                    $texteMethode = $corps;
                }
            }

            $sites[] = [
                'ligne' => $ligne,
                'expression' => $expression,
                'gardee' => $texteMethode !== null && str_contains($texteMethode, 'SsrfGuard'),
            ];
        }
    }

    return $sites;
}

/** @return list<string> chemins relatifs a `base_path()`, tries */
function ssrfCompletudeFichiers(): array
{
    $fichiers = [];
    foreach (['app/Services', 'app/Jobs'] as $sousDossier) {
        $racine = base_path($sousDossier);
        if (! is_dir($racine)) {
            continue;
        }
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine));
        foreach ($iterateur as $fichier) {
            if ($fichier->isFile() && $fichier->getExtension() === 'php') {
                $fichiers[] = str_replace('\\', '/', substr($fichier->getPathname(), strlen(base_path()) + 1));
            }
        }
    }
    sort($fichiers);

    return $fichiers;
}

/** @return list<string> ceux d'entre eux qui savent emettre du HTTP */
/**
 * La source AMPUTEE DE SES COMMENTAIRES.
 *
 * 🔴 Pourquoi, mesure du 2026-08-23. `ssrfCompletudeEmetteurs()` faisait un
 * `preg_match` sur la source BRUTE. Elle a donc designe comme « nouvel emetteur
 * HTTP jamais examine pour SSRF » le fichier
 * `app/Services/Proxies/VerificationTlsSonde.php` — qui est un TRAIT n'emettant
 * rien du tout : il rend un booleen et journalise. Ce qui a declenche la
 * signature, c'est son commentaire d'en-tete, qui CITE l'appel fautif qu'il
 * repare (`Http::withOptions([... 'verify' => false])`).
 *
 * Un commentaire n'emet aucune requete. Le laisser compter, c'est fabriquer un
 * rouge sur du texte et, pire, faire entrer dans l'inventaire des « emetteurs a
 * verifier » un fichier qui n'en est pas un — l'inventaire perdrait son sens.
 *
 * C'est le SECOND site du meme defaut trouve ce jour-la ; l'autre est
 * `ContexteEspaceDesJobsTest`, qui lisait « construit un `new self(...)` neuf »
 * dans un commentaire comme un point de mise en file. Le patron A-011 de ce
 * depot joue aussi entre gardes : on porte donc le correctif sur les deux.
 */
function ssrfCompletudeSansCommentaires(string $source): string
{
    $plat = '';

    foreach (token_get_all($source) as $jeton) {
        if (is_array($jeton) && ($jeton[0] === T_COMMENT || $jeton[0] === T_DOC_COMMENT)) {
            // Seuls les sauts de ligne sont conserves : les numeros de ligne
            // restent ceux du fichier reel.
            $plat .= str_repeat('
', substr_count($jeton[1], '
'));

            continue;
        }

        $plat .= is_array($jeton) ? $jeton[1] : $jeton;
    }

    return $plat;
}

function ssrfCompletudeEmetteurs(): array
{
    $emetteurs = [];
    foreach (ssrfCompletudeFichiers() as $relatif) {
        $source = ssrfCompletudeSansCommentaires((string) file_get_contents(base_path($relatif)));
        if (preg_match(SSRF_SIGNATURES_EMETTEUR, $source)) {
            $emetteurs[] = $relatif;
        }
    }

    return $emetteurs;
}

// ═══════════════════════════════════════════════════════════════════════════
// LES EXEMPTIONS — chacune porte son motif, en toutes lettres
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Emissions a URL non constante dont l'HOTE, lui, est une constante : seule la
 * FIN DU CHEMIN varie. `SsrfGuard` n'y changerait rien (il ne juge que
 * l'hote), et l'exiger reviendrait a decorer du code pour faire taire un banc.
 *
 * Cle : `chemin::expression`. Volontairement PAS le numero de ligne — un
 * deplacement de code ne doit pas faire rougir la garde, mais une REECRITURE de
 * l'expression, si : elle sort alors de la table et redevient a justifier.
 *
 * @return array<string, string>
 */
function ssrfCompletudeExemptions(): array
{
    return [
        // `HibpChecker::getBreachCount()` — mesure faite dans le fichier :
        //     $sha1   = strtoupper(sha1($plainPassword));
        //     $prefix = substr($sha1, 0, 5);
        // `$prefix` vaut donc 5 caracteres pris dans [0-9A-F]. Il ne peut porter
        // ni `/`, ni `:`, ni `@`, ni `?` : rien qui puisse deplacer l'hote hors
        // de `api.pwnedpasswords.com`. Un `SsrfGuard::check()` y rendrait
        // toujours le meme verdict, sur la meme constante.
        'app/Services/Auth/HibpChecker.php::get(self::API_BASE_URL . $prefix)' => 'hote constant (API_BASE_URL) + 5 caracteres hexadecimaux issus de sha1() ; '
            . 'aucune donnee ne peut deplacer l\'hote.',

        // `HttpInseeClient::searchPaginated()` — mesure faite dans le fichier :
        //     $endpoint = $hasGeo ? '/siret' : '/siren';
        // Deux litteraux, choisis par un booleen. La donnee (`$criteria`) decide
        // LEQUEL des deux, jamais leur contenu.
        'app/Services/Insee/HttpInseeClient.php::get(self::BASE_URL . $endpoint)' => 'hote constant (BASE_URL) + $endpoint choisi entre deux litteraux \'/siret\' et '
            . '\'/siren\' ; la donnee choisit la branche, pas la chaine.',

        // `AlerteTelegram::envoyer()` — mesure faite dans le fichier :
        //     post("https://api.telegram.org/bot{$token}/sendMessage")
        // L'HOTE est ecrit en dur : `api.telegram.org`. Ce qui varie, `$token`,
        // vient de `config('alertes.telegram.token')`, donc de l'ENVIRONNEMENT du
        // conteneur — jamais d'une donnee utilisateur, jamais de la base. Et il
        // vit dans le CHEMIN, apres le troisieme `/` : meme un jeton fantaisiste
        // ne peut pas deplacer l'hote. `SsrfGuard` ne juge que l'hote ; l'exiger
        // ici reviendrait a decorer du code pour faire taire un banc.
        'app/Services/Alertes/AlerteTelegram.php::post("https://api.telegram.org/bot{$token}/sendMessage")' => 'hote ecrit en dur (api.telegram.org) ; le jeton vient de la configuration du '
            . 'conteneur, pas de la donnee, et vit dans le CHEMIN : il ne peut pas deplacer l hote.',
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// 0. TEMOINS — sans eux, tout le reste peut etre vert pour rien
// ═══════════════════════════════════════════════════════════════════════════

test('TEMOIN — l enumerateur trouve quelque chose (il ne juge pas zero fichier)', function () {
    $fichiers = ssrfCompletudeFichiers();
    $emetteurs = ssrfCompletudeEmetteurs();

    // Chiffres mesures le 2026-08-20 : 73 fichiers, 20 emetteurs. Les planchers
    // sont volontairement bas (une reorganisation legitime ne doit pas rougir),
    // mais NON NULS : une garde qui passe au vert parce que le dossier a change
    // de nom, ou parce que `base_path()` ne pointe plus la ou on croit, est pire
    // qu'absente — elle rassure.
    expect(count($fichiers))->toBeGreaterThan(
        40,
        'L\'enumerateur ne voit presque plus de fichiers dans app/Services + app/Jobs (mesure du '
        . '2026-08-20 : 73). Tout le reste de ce fichier passerait au vert SANS RIEN LIRE.',
    );
    expect(count($emetteurs))->toBeGreaterThan(
        10,
        'L\'enumerateur ne reconnait presque plus d\'emetteur HTTP (mesure du 2026-08-20 : 20). '
        . 'La signature de detection ne correspond plus au code : la completude n\'est plus mesuree.',
    );
});

test('TEMOIN — l enumerateur VOIT un appel non garde qu on lui presente', function () {
    // Reproduction fidele de ce qu'etait `MentionsLegalesScraperService` AVANT
    // le commit 9389121 : une URL venue de la base, aucune garde.
    $avant = <<<'PHP'
    <?php
    class Coupable
    {
        public function fetch(string $website): ?string
        {
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'x'])
                ->get($website . '/mentions-legales');

            return $response->body();
        }
    }
    PHP;

    $sites = ssrfCompletudeAnalyser($avant);

    expect($sites)->toHaveCount(
        1,
        "L'enumerateur ne voit pas `Http::…->get(\$website . '/…')`. C'est LE cas que le constat "
        . 'C19-001 decrit ; s\'il passe inapercu, la garde de completude ne mesure rien du tout.',
    );
    expect($sites[0]['gardee'])->toBeFalse(
        'L\'enumerateur declare gardee une methode qui ne mentionne meme pas SsrfGuard : le '
        . 'critere « garde presente » est casse, et tous les verdicts de ce fichier sont faux.',
    );
});

test('TEMOIN — l enumerateur ne crie PAS sur un appel correctement garde', function () {
    $apres = <<<'PHP'
    <?php
    class Repare
    {
        public function fetch(string $website): ?string
        {
            if (! SsrfGuard::check($website)['ok']) {
                return null;
            }
            $response = Http::timeout(8)
                ->withOptions(SsrfGuard::redirectOptions())
                ->get($website . '/mentions-legales');

            return $response->body();
        }
    }
    PHP;

    $sites = ssrfCompletudeAnalyser($apres);

    expect($sites)->toHaveCount(1);
    expect($sites[0]['gardee'])->toBeTrue(
        'Une methode qui appelle pourtant SsrfGuard est declaree NON gardee : la garde de '
        . 'completude rougirait eternellement, ce qui la rend inutilisable — donc desactivee, '
        . 'donc absente. Un faux positif tue une garde aussi surement qu\'un faux negatif.',
    );
});

test('TEMOIN — sur le VRAI service, garde retiree, l enumerateur rougit aux 4 endroits', function () {
    // Le temoin le plus fort de ce fichier : on prend la source REELLE de
    // DomainFinderService, on en efface toute trace de `SsrfGuard`, et on
    // verifie que l'enumerateur retrouve alors exactement l'etat d'AVANT le
    // commit 9389121. Sans ceci, « tout est garde » pourrait n'etre qu'un
    // enumerateur qui ne trouve rien.
    $reel = (string) file_get_contents(base_path('app/Services/Domain/DomainFinderService.php'));

    $sitesReels = ssrfCompletudeAnalyser($reel);
    expect(count($sitesReels))->toBe(
        4,
        'DomainFinderService ne presente plus 4 emissions a URL variable (mesure du 2026-08-20 : '
        . 'lignes 235, 325, 357, 513). Le service a change de forme : cette garde doit etre relue, '
        . 'pas simplement remise au vert.',
    );

    $mutant = str_replace('SsrfGuard', 'AucuneGarde', $reel);
    $sitesMutants = ssrfCompletudeAnalyser($mutant);

    $nonGardes = array_values(array_filter($sitesMutants, fn ($s) => $s['gardee'] === false));
    expect(count($nonGardes))->toBe(
        4,
        'Garde retiree, l\'enumerateur ne signale pas les 4 emissions de DomainFinderService. '
        . 'Il aurait donc laisse passer l\'etat d\'AVANT la reparation : cette garde de completude '
        . 'ne prouve rien.',
    );
});

test('TEMOIN — les deux methodes d enumeration ne se contredisent pas', function () {
    // La methode B (regex ligne a ligne) est independante de la methode A
    // (tokenisation). Tout ce que B voit, A doit le voir. Pendant la mise au
    // point, B a trouve deux sites que A ratait — c'est ce croisement qui a
    // revele que la decoupe en instructions coupait sur le `;` interne d'une
    // fermeture passee a `->retry()`.
    $manquants = [];

    foreach (ssrfCompletudeEmetteurs() as $relatif) {
        $source = (string) file_get_contents(base_path($relatif));
        $lignesA = array_column(ssrfCompletudeAnalyser($source), 'ligne');
        foreach (ssrfCompletudeLignesSuspectes($source) as $ligneB) {
            // A note la ligne de DEBUT de l'instruction, B la ligne de l'appel :
            // une chaine s'etale sur quelques lignes. Tolerance de 20 lignes.
            $vu = false;
            foreach ($lignesA as $ligneA) {
                if ($ligneB >= $ligneA - 2 && $ligneB <= $ligneA + 20) {
                    $vu = true;
                    break;
                }
            }
            if (! $vu) {
                $manquants[] = "{$relatif}:{$ligneB}";
            }
        }
    }

    expect($manquants)->toBe(
        [],
        'La methode B (regex) voit des emissions que la methode A (tokenisation) ne voit pas : '
        . implode(', ', $manquants)
        . '. L\'enumeration principale a un angle mort — ces sites ne sont donc PAS verifies, '
        . 'et le vert de la garde de completude ci-dessous ne couvre pas ce qu\'il pretend couvrir.',
    );
});

// ═══════════════════════════════════════════════════════════════════════════
// 1. LA GARDE DE COMPLETUDE
// ═══════════════════════════════════════════════════════════════════════════

test('C19-001 — toute emission HTTP a URL non constante passe par SsrfGuard', function () {
    $exemptions = ssrfCompletudeExemptions();
    $consommees = [];
    $nus = [];
    $total = 0;

    foreach (ssrfCompletudeEmetteurs() as $relatif) {
        $source = (string) file_get_contents(base_path($relatif));
        foreach (ssrfCompletudeAnalyser($source) as $site) {
            $total++;
            $cle = $relatif . '::' . $site['expression'];
            if (isset($exemptions[$cle])) {
                $consommees[$cle] = true;

                continue;
            }
            if (! $site['gardee']) {
                $nus[] = "{$relatif}:{$site['ligne']}  {$site['expression']}";
            }
        }
    }

    expect($total)->toBeGreaterThan(
        4,
        'L\'enumerateur ne trouve presque plus d\'emission a URL variable (mesure du 2026-08-20 : '
        . '9). Le verdict « tout est garde » serait vrai par vacuite.',
    );

    expect($nus)->toBe(
        [],
        'Des emissions HTTP consomment une URL issue de la DONNEE sans que leur methode ne '
        . "connaisse SsrfGuard :\n  " . implode("\n  ", $nus)
        . "\n\nC'est le constat C19-001, non ferme. Soit la garde y est branchee, soit l'hote y est "
        . 'prouve constant et le site rejoint la table des exemptions AVEC SON MOTIF ECRIT.',
    );

    // Une exemption qui ne correspond plus a rien est un mensonge qui dort :
    // elle laisse croire qu'un site est examine alors qu'il a disparu ou change.
    $orphelines = array_values(array_diff(array_keys($exemptions), array_keys($consommees)));
    expect($orphelines)->toBe(
        [],
        'Ces exemptions ne correspondent plus a aucun site du code : '
        . implode(', ', $orphelines)
        . '. Une exemption perimee est une dispense accordee a personne — elle finit par couvrir '
        . 'un site voisin qu\'on n\'a jamais examine.',
    );
});

test('C19-001 — l inventaire des emetteurs HTTP est a jour', function () {
    // Mesure du 2026-08-20. Toute ARRIVEE dans cette liste est un fichier neuf
    // capable d'emettre du HTTP : il doit etre lu, pas ajoute machinalement.
    $attendus = [
        // Ajoute le 2026-08-22 avec le canal d'alerte. LU AVANT D'ETRE AJOUTE :
        // son unique emission vise `api.telegram.org`, hote ecrit en dur, et le
        // seul element variable est le jeton du bot — issu de la configuration
        // du conteneur, place dans le CHEMIN. Exemption motivee plus haut.
        'app/Services/Alertes/AlerteTelegram.php',
        'app/Services/AnnuaireEntreprises/HttpAnnuaireEntreprisesClient.php',
        'app/Services/Auth/HibpChecker.php',
        'app/Services/Ban/HttpBanGeocoder.php',
        'app/Services/Bodacc/HttpBodaccClient.php',
        'app/Services/Domain/DomainFinderService.php',
        'app/Services/Email/HunterEmailVerifier.php',
        'app/Services/FranceTravail/FranceTravailDiscoveryClient.php',
        'app/Services/FranceTravail/HttpFranceTravailClient.php',
        'app/Services/Http/ProxiedHttpClient.php',
        // RETIRE le 2026-08-23, apres mesure, et surtout pas machinalement.
        // Ce fichier n'y figurait QUE par ses commentaires : les quatre
        // occurrences de la signature (`Http::`, `GuzzleHttp`, `ProxiedHttpClient`)
        // sont, aux lignes 25, 90, 164 et 187, des explications — jamais du code.
        // Mesure faite en amputant le fichier de ses commentaires : il ne reste
        // AUCUNE emission HTTP, seulement deux `dns_get_record`, qui sont la
        // resolution meme dont ce garde-fou a besoin pour juger un hote.
        //
        // Et c'est coherent : `SsrfGuard` n'est pas un emetteur a examiner, c'est
        // ce qui EXAMINE les emetteurs. L'y garder revenait a demander au juge de
        // comparaitre. Sa propre couverture est ailleurs : `tests/Unit/Http/`
        // et, cote collecte, `workers/tests/ssrf-guard{,-dns}.test.ts`.
        'app/Services/Insee/HttpInseeClient.php',
        'app/Services/LLM/Providers/AnthropicProvider.php',
        'app/Services/LLM/Providers/GroqProvider.php',
        'app/Services/LLM/Providers/MistralProvider.php',
        'app/Services/LLM/Providers/OpenAIProvider.php',
        'app/Services/LLM/Providers/TogetherProvider.php',
        'app/Services/Legal/MentionsLegalesScraperService.php',
        'app/Services/Proxies/IPRoyalProvider.php',
        'app/Services/Proxies/WebshareProvider.php',
        'app/Services/Scraping/GooglePlacesClient.php',
    ];

    $reels = ssrfCompletudeEmetteurs();

    $nouveaux = array_values(array_diff($reels, $attendus));
    $disparus = array_values(array_diff($attendus, $reels));

    expect($nouveaux)->toBe(
        [],
        'Nouveaux emetteurs HTTP dans le perimetre, jamais examines pour SSRF : '
        . implode(', ', $nouveaux)
        . '. Le patron A-011 de ce depot est precisement celui-la : un service de plus, et le '
        . 'correctif qui n\'est pas porte. Lire le fichier, puis l\'ajouter a cette liste.',
    );
    expect($disparus)->toBe(
        [],
        'Ces emetteurs ont disparu de l\'inventaire : ' . implode(', ', $disparus)
        . '. Soit ils ont ete supprimes (retirer la ligne), soit la detection ne les reconnait '
        . 'plus — et dans ce second cas ils ne sont PLUS verifies du tout.',
    );
});
