<?php

/**
 * GARDE DU PATRON HMAC DE RÉFÉRENCE — constats P5-HMAC-001 (S2) et P5-HMAC-002 (S3).
 *
 * POURQUOI UNE GARDE SUR DES COMMENTAIRES.
 *
 * Le CRM a deux canaux machine-à-machine signés HMAC. L'un était bon, l'autre
 * était troué :
 *
 *   /internal/site-sync ........ classe durcie `HmacSignature`, secret vide =
 *                                porte fermée, horodatage DANS la signature
 *   /internal/scraper-result ... vérification réimplémentée à la main, secret
 *                                vide = porte ouverte, aucun horodatage
 *
 * Le trou est bouché depuis `a6aceb0` (constat F37-001). Mais **deux
 * commentaires, écrits par deux mains différentes, désignaient au développeur
 * suivant le mauvais des deux canaux comme étant le patron à copier** :
 *
 *   routes/api.php ........... « /site-sync … (même patron que scraper-result) »
 *   HmacSignature.php ........ « Reprend le patron déjà en place sur
 *                                POST /internal/scraper-result … le seul canal
 *                                machine authentifié du CRM »
 *
 * C'est la mécanique de propagation du défaut, prise sur le fait : le prochain
 * canal machine-à-machine sera écrit en copiant ce que ces lignes désignent.
 * **Boucher la fuite sans corriger le plan qui y mène, c'est laisser le plan
 * au mur.** Et depuis `a6aceb0`, l'affirmation est devenue circulaire : la
 * classe durcie déclare dériver de `scraper-result`, qui vient précisément de
 * se mettre à dériver d'elle.
 *
 * Un commentaire n'a pas de test — c'est justement pour cela qu'il survit à
 * dix-neuf correctifs. Celui-ci en a un.
 *
 * ⚠️ RÈGLE DE MESURE, payée par la contre-vérification adversariale elle-même :
 * un contrôle sur du texte français se joue sur une sous-chaîne SANS LETTRE
 * ACCENTUÉE. Le contradicteur a cherché « meme patron que scraper-result » dans
 * un fichier qui écrit « même », a lu zéro résultat comme une absence dans le
 * code alors qu'elle était dans sa requête, et a déclaré corrigé ce qui ne
 * l'était pas. Les motifs ci-dessous sont donc tous sans accent.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 🔴 `RefreshDatabase` EST INDISPENSABLE ICI, ET IL A COUTE UNE SUITE COMPLETE.
 *
 * Deux tests de ce fichier appellent reellement
 * `POST /api/internal/scraper-result`. Le middleware `AuditHashChainLogger`
 * ecrit une ligne dans `audit_logs` a chaque requete. Sans `RefreshDatabase`,
 * ces lignes sont COMMITEES et survivent au fichier.
 *
 * Consequence mesuree le 2026-08-20, sur la suite complete : six tests de la
 * chaine d'audit -- `AuditHashChainTest`, `AuditHashChainExtendedTest`,
 * `ChaineAuditSecretTest` -- sont passes au rouge, dans des fichiers que rien
 * n'avait touches. Le plus parlant :
 *
 *     first record uses the 64-zero genesis prev hash
 *     -'0000...0000'
 *     +'e2f287a4f28c63da34edb0951b3f02a242a47a5bcfd2125c450c6ea854e97b1e'
 *
 * Ce n'etait pas « le premier maillon » : c'etait le maillon suivant ceux que
 * CE fichier avait laisses derriere lui. Les six tests etaient verts en
 * isolation et rouges dans la suite -- la pire forme de rouge, parce qu'elle
 * accuse le mauvais fichier.
 *
 * La lecon vaut au-dela de ce lot : un test qui emet une VRAIE requete HTTP
 * ecrit au journal d'audit, meme s'il ne parle pas de journal d'audit.
 */
uses(TestCase::class, RefreshDatabase::class);

/**
 * Résout un chemin donné depuis la RACINE DU DÉPÔT, où que l'application soit
 * montée.
 *
 * ⚠️ PIÈGE PAYÉ, ET CONSIGNÉ POUR LE SUIVANT. La première version faisait
 * `base_path('..') . '/backend/routes/api.php'`. C'est juste dans le dépôt
 * (`<depot>/backend/...`) et FAUX sur le banc de mesure, où le conteneur monte
 * `backend/` sur `/var/www/html` : le chemin devenait `/var/www/backend/...`,
 * qui n'existe pas.
 *
 * Le témoin de montage l'a attrapé — c'est exactement son office — mais la garde
 * aurait été rouge en local et verte en CI, c'est-à-dire inutilisable. Un chemin
 * de test doit valoir dans les deux, sinon il mesure l'atelier et non le produit.
 *
 * On passe donc par `base_path()`, que Laravel résout toujours vers le dossier
 * de l'application, et on n'utilise `base_path('..')` que pour ce qui vit
 * réellement au-dessus (infra, Dockerfile, workflows).
 */
function lireFichierHmac(string $relatif): string
{
    $relatif = ltrim($relatif, '/');

    $chemin = str_starts_with($relatif, 'backend/')
        ? base_path(substr($relatif, strlen('backend/')))
        : (realpath(base_path('..')) ?: base_path('..')) . '/' . $relatif;

    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$relatif} (cherche en {$chemin}). Une garde qui n'a rien a "
        . 'inspecter passe au vert sans rien prouver : monte la racine du depot avant de la croire.',
    );

    return (string) file_get_contents($chemin);
}

test('P5-HMAC-001 — TEMOIN : le banc voit bien les deux fichiers a inspecter', function () {
    expect(strlen(lireFichierHmac('backend/routes/api.php')))->toBeGreaterThan(1000);
    expect(strlen(lireFichierHmac('backend/app/Support/HmacSignature.php')))->toBeGreaterThan(500);
});

test('P5-HMAC-001 — aucun commentaire ne designe scraper-result comme le patron a copier', function () {
    $fichiers = [
        'backend/routes/api.php',
        'backend/app/Support/HmacSignature.php',
    ];

    // Motifs SANS ACCENT, par construction (voir l'en-tete).
    $formulesFautives = [
        'patron que scraper-result',
        'patron deja en place sur `POST /internal/scraper-result',
        'patron deja en place sur POST /internal/scraper-result',
        'seul canal machine authentifie',
    ];

    foreach ($fichiers as $relatif) {
        $contenu = lireFichierHmac($relatif);

        foreach ($formulesFautives as $formule) {
            $this->assertStringNotContainsString(
                $formule,
                $contenu,
                "{$relatif} designe encore `/internal/scraper-result` comme le patron de reference. "
                . "C'est le canal qui portait le defaut F37-001 (secret vide = porte ouverte, aucun "
                . 'horodatage). Le patron reel est `HmacSignature` + `/internal/site-sync`. '
                . 'Le prochain canal machine-a-machine sera ecrit en copiant ce que cette ligne designe.',
            );
        }
    }
});

/**
 * TÉMOIN NÉGATIF — un `assertStringNotContainsString` à zéro résultat ne vaut
 * rien tant qu'on n'a pas prouvé que le contrôle SAIT trouver la formule quand
 * elle est là. C'est la règle 3 du mandat, et c'est exactement ce qui a manqué
 * au contradicteur.
 */
test('P5-HMAC-001 — TEMOIN NEGATIF : la garde SAIT reperer une designation fautive', function () {
    $faux = "// Nouveau canal signe, meme patron que scraper-result.\n";

    expect(str_contains($faux, 'patron que scraper-result'))->toBeTrue(
        'Le motif de recherche ne reconnait meme pas un cas fabrique : la garde ci-dessus '
        . 'passerait au vert sur n\'importe quoi.',
    );
});

test('P5-HMAC-002 — le secret du canal interne a une entree config, comme tous les autres', function () {
    expect(config('services.worker_internal.hmac_secret'))->not->toBeNull(
        "`services.worker_internal.hmac_secret` n'existe pas. WORKER_INTERNAL_HMAC_SECRET etait le "
        . "SEUL secret du depot sans entree `config/` : il fonctionnait par la grace d'un detail de "
        . '`docker-compose` (env_file injecte la variable dans l\'environnement du processus, si bien '
        . 'que `env()` la lit encore sous `config:cache`). Le jour ou la production passe en '
        . '`environment:` explicite ou en secrets Docker, il tombe a vide.',
    );
});

test('P5-HMAC-002 — le controleur lit son secret par config(), pas par env() brut', function () {
    $controleur = lireFichierHmac('backend/app/Http/Controllers/Internal/ScraperResultController.php');

    $this->assertStringNotContainsString(
        "env('WORKER_INTERNAL_HMAC_SECRET'",
        $controleur,
        'Le controleur lit encore le secret par `env()` brut. `env()` hors de `config/` est '
        . 'explicitement deconseille par Laravel : sous `config:cache`, il ne rend la vraie valeur '
        . "que si la variable est dans l'environnement du PROCESSUS. C'est vrai aujourd'hui par "
        . '`env_file:`, et cela cesse de l\'etre au premier changement de mode d\'injection.',
    );

    // La parenthese fermante n'est PAS dans le motif : le controleur ecrit
    // `config('services.worker_internal.hmac_secret', '')`, avec un defaut. Un
    // motif trop long ne mesure pas ce qu'il annonce -- il mesure une mise en
    // forme. Meme piege que le second argument de `toContain()`, deux fichiers
    // plus tot.
    $this->assertStringContainsString(
        "config('services.worker_internal.hmac_secret'",
        $controleur,
        'Le controleur doit lire son secret par la configuration.',
    );
});

/**
 * TÉMOIN DE BOUT EN BOUT — la garde de fond de `a6aceb0` ne doit pas bouger
 * pendant qu'on déplace la lecture du secret : secret absent = 503, jamais un
 * 200. Sans ce cas, un correctif qui casserait la lecture du secret passerait
 * les deux tests ci-dessus.
 */
test('P5-HMAC-002 — TEMOIN : deplacer la lecture du secret ne rouvre pas la porte', function () {
    config(['services.worker_internal.hmac_secret' => '']);

    $reponse = $this->postJson('/api/internal/scraper-result', ['run_id' => 'garde'], [
        'X-Worker-Signature' => 'peu-importe',
    ]);

    expect($reponse->status())->toBe(
        503,
        'Secret absent : le canal doit se fermer (503), jamais repondre 200. '
        . "C'est la garde de F37-001, et elle doit survivre au passage a config().",
    );
});

test('P5-HMAC-002 — TEMOIN : avec un secret pose, une signature juste passe et une fausse non', function () {
    $secret = 'un-secret-de-garde-suffisamment-long-2026';
    config(['services.worker_internal.hmac_secret' => $secret]);
    config(['crm.scrape_funnel.enabled' => false]);

    $corps = json_encode(['run_id' => 'garde-hmac', 'source' => 'test', 'status' => 'ok']);

    // Signature juste -> passe.
    $this->call(
        'POST',
        '/api/internal/scraper-result',
        [],
        [],
        [],
        [
            'HTTP_X-Worker-Signature' => hash_hmac('sha256', $corps, $secret),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $corps,
    )->assertOk();

    // Signature fausse -> 401. Sans ce second cas, un controleur qui accepterait
    // tout passerait le premier.
    $this->call(
        'POST',
        '/api/internal/scraper-result',
        [],
        [],
        [],
        [
            'HTTP_X-Worker-Signature' => hash_hmac('sha256', $corps, 'le-mauvais-secret'),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ],
        $corps,
    )->assertStatus(401);
});

/**
 * P5-HMAC-003 (neuf, S2) — LE REJEU TARDIF DEVIENT UN CONSTAT, PAS UNE NOTE.
 *
 * `a6aceb0` déclare honnêtement ce qu'il ne couvre pas : le corps signé de
 * `/internal/scraper-result` reste le corps BRUT, sans horodatage, parce qu'y
 * ajouter la fenêtre casserait les workers Node en place. Une requête légitime
 * interceptée reste donc rejouable indéfiniment.
 *
 * La contre-vérification adversariale demande que cette limite **devienne un
 * constat au registre et non une note de commentaire, sinon elle disparaîtra
 * avec la PR**. Ce test est ce constat : il fige l'écart entre les deux canaux
 * et il rougira le jour où quelqu'un croira l'avoir comblé sans le faire.
 *
 * Il n'exige PAS l'horodatage — ce serait casser les workers en production, un
 * choix de conception qui ne se prend pas au détour d'un correctif. Il exige
 * que l'écart soit **écrit à l'endroit où on le lira**.
 */
test('P5-HMAC-003 — le rejeu tardif reste ouvert sur scraper-result, et le code le DIT', function () {
    $controleur = lireFichierHmac('backend/app/Http/Controllers/Internal/ScraperResultController.php');

    // L'etat des lieux, mesure : la classe durcie sait signer avec horodatage
    // (signedPayload + timestampWithinWindow), et ce canal-ci ne s'en sert pas.
    $signature = lireFichierHmac('backend/app/Support/HmacSignature.php');
    expect($signature)->toContain('signedPayload');
    expect($signature)->toContain('timestampWithinWindow');

    $utiliseHorodatage = str_contains($controleur, 'signedPayload')
        || str_contains($controleur, 'timestampWithinWindow');

    if ($utiliseHorodatage) {
        // Quelqu'un a ferme le rejeu : tant mieux, mais alors le commentaire qui
        // le declare ouvert doit disparaitre, sinon le code ment dans l'autre sens.
        $this->assertStringNotContainsString(
            'rejeu tardif reste donc ouvert',
            $controleur,
            "Le controleur emploie desormais l'horodatage signe : le commentaire qui declare le "
            . 'rejeu ouvert est devenu faux. Un commentaire perime est un piege a la relecture.',
        );

        return;
    }

    $this->assertStringContainsString(
        'rejeu',
        $controleur,
        'Le canal /internal/scraper-result signe le corps BRUT, sans horodatage : une requete '
        . 'interceptee reste rejouable indefiniment, alors que /internal/site-sync est protege. '
        . "C'est un ecart REEL entre les deux canaux, et il doit etre ecrit la ou on le lira. "
        . 'Constat P5-HMAC-003, ouvert au registre.',
    );
});
