<?php

use App\Models\User;
use App\Policies\BasePolicy;

/**
 * 🔴 B12-012 — `BasePolicy::sameWorkspace()` COMPARAIT DEUX UUID CASTÉS EN ENTIER.
 *
 * `app/Policies/BasePolicy.php:25` écrivait :
 *
 *     return (int) $user->current_workspace_id === (int) $model->workspace_id;
 *
 * Or `users.current_workspace_id` et `<table>.workspace_id` sont des colonnes
 * UUID (migration 2026_05_16_000002 : `current_workspace_id UUID REFERENCES
 * workspaces(id)`). Le cast en entier ne garde que les chiffres de tête :
 *
 *   MESURÉ dans le conteneur a35r, PHP 8.x :
 *     (int) '1db106f5-4b47-4c8a-9b3e-0000000000aa'  vaut  1
 *     (int) '1c9f0000-0000-4000-8000-000000000001'  vaut  1     ← deux étrangers, ÉGAUX
 *     (int) 'a1b2c3d4-1111-4000-8000-000000000001'  vaut  0
 *     (int) 'f9e8d7c6-2222-4000-8000-000000000002'  vaut  0     ← deux étrangers, ÉGAUX
 *
 * Un UUID v4 commence par une lettre dans 6 cas sur 16 (a–f) : la majorité des
 * paires tombe donc sur `0 === 0`. Et deux UUID commençant par le même chiffre
 * tombent sur le même entier. La comparaison était VRAIE pour l'écrasante
 * majorité des couples, y compris entre deux espaces sans aucun rapport.
 *
 * Conséquence : `view()`, `update()` et `delete()` des DIX policies qui héritent
 * de `BasePolicy` (AuditLog, Company, Contact, LlmUseCase, ProxyProvider,
 * RgpdRequest, ScraperRun, Tag, User, Workspace) laissaient passer un modèle
 * appartenant à un AUTRE espace de travail.
 *
 * ── PATRON A-011 : LE CORRECTIF EXISTAIT DÉJÀ, IL N'AVAIT PAS ÉTÉ PORTÉ ──────
 *
 * Le défaut EXACT a été corrigé dans `routes/channels.php` le 2026-08-16
 * (`hash_equals((string) $user->current_workspace_id, $workspaceId)`), avec sa
 * garde `tests/Unit/Broadcasting/AutorisationCanauxTest.php`. Le site jumeau
 * qu'est `BasePolicy` n'avait jamais reçu le même traitement. Ce fichier est la
 * même démonstration, portée sur la policy.
 *
 * ⚠️ Ces tests ne touchent pas la base : `sameWorkspace()` ne lit que deux
 * attributs. C'est bien la COMPARAISON qui est en cause, pas une requête.
 */

/**
 * Policy concrète minimale : `BasePolicy` est abstraite, et `sameWorkspace()`
 * est `protected`. On l'expose telle quelle, sans rien réécrire — si on
 * recopiait la comparaison ici, le test ne prouverait plus rien sur le code
 * de production.
 */
final class PolicySondeCloisonnement extends BasePolicy
{
    public function memeEspace(User $utilisateur, object $modele): bool
    {
        return $this->sameWorkspace($utilisateur, $modele);
    }
}

/**
 * Utilisateur qui ne consulte PAS la base pour ses rôles.
 *
 * `update()` et `delete()` combinent `sameWorkspace()` ET `hasAnyRole()`, lequel
 * passe par Spatie/Permission et donc par Postgres. Ces tests sont unitaires :
 * on neutralise le second facteur en le rendant TOUJOURS VRAI. C'est le pire
 * cas pour la garde — si `update()` refuse quand même, c'est forcément
 * `sameWorkspace()` qui a tranché, et rien d'autre.
 */
final class UtilisateurToujoursHabilite extends User
{
    public function hasAnyRole(...$roles): bool
    {
        return true;
    }
}

/** Modèle de test : un objet qui porte un `workspace_id`, comme toute table scopée. */
function sondeModeleDeLEspace(string $identifiantEspace): object
{
    return (object) ['workspace_id' => $identifiantEspace];
}

// Deux paires d'UUID choisies pour leur pouvoir de démonstration : la première
// se casse en 1 des deux côtés, la seconde en 0.
const SONDE_ESPACE_SIEN = '1db106f5-4b47-4c8a-9b3e-0000000000aa';
const SONDE_ESPACE_AUTRE_MEME_CHIFFRE = '1c9f0000-0000-4000-8000-000000000001';
const SONDE_ESPACE_LETTRE_A = 'a1b2c3d4-1111-4000-8000-000000000001';
const SONDE_ESPACE_LETTRE_F = 'f9e8d7c6-2222-4000-8000-000000000002';

// ─────────────────────────────────────────────────────────────────────────────
// 1. TÉMOIN — la nature du défaut, mesurée, pas déduite.
//    Sans lui, on ne saurait pas que ces UUID-là exercent VRAIMENT le défaut :
//    une garde bâtie sur deux UUID qui se castent en entiers DIFFÉRENTS serait
//    passée au vert AVANT correctif, et n'aurait jamais rien prouvé.
// ─────────────────────────────────────────────────────────────────────────────

test('TEMOIN : le cast en entier rend EGAUX deux UUID etrangers', function () {
    $casse = static fn (string $a, string $b): bool => (int) $a === (int) $b;

    // Paire « même chiffre de tête » : 1 === 1.
    expect((int) SONDE_ESPACE_SIEN)->toBe(1)
        ->and((int) SONDE_ESPACE_AUTRE_MEME_CHIFFRE)->toBe(1)
        ->and($casse(SONDE_ESPACE_SIEN, SONDE_ESPACE_AUTRE_MEME_CHIFFRE))->toBeTrue();

    // Paire « commence par une lettre » : 0 === 0. C'est le cas le plus courant.
    expect((int) SONDE_ESPACE_LETTRE_A)->toBe(0)
        ->and((int) SONDE_ESPACE_LETTRE_F)->toBe(0)
        ->and($casse(SONDE_ESPACE_LETTRE_A, SONDE_ESPACE_LETTRE_F))->toBeTrue();

    // Et les chaînes, elles, sont bel et bien différentes : le défaut vient du
    // cast, pas d'un jeu d'essai mal choisi.
    expect(SONDE_ESPACE_SIEN)->not->toBe(SONDE_ESPACE_AUTRE_MEME_CHIFFRE)
        ->and(SONDE_ESPACE_LETTRE_A)->not->toBe(SONDE_ESPACE_LETTRE_F);
});

// ─────────────────────────────────────────────────────────────────────────────
// 2. LA GARDE — sur le code de production, pas sur une copie.
// ─────────────────────────────────────────────────────────────────────────────

test('un modele d un AUTRE espace n est pas vu — paire qui se caste en 1', function () {
    $policy = new PolicySondeCloisonnement;
    $utilisateur = new UtilisateurToujoursHabilite(['current_workspace_id' => SONDE_ESPACE_SIEN]);
    $intrus = sondeModeleDeLEspace(SONDE_ESPACE_AUTRE_MEME_CHIFFRE);

    expect($policy->memeEspace($utilisateur, $intrus))->toBeFalse();
    expect($policy->view($utilisateur, $intrus))->toBeFalse();
});

test('un modele d un AUTRE espace n est pas vu — paire qui se caste en 0', function () {
    $policy = new PolicySondeCloisonnement;
    $utilisateur = new UtilisateurToujoursHabilite(['current_workspace_id' => SONDE_ESPACE_LETTRE_A]);
    $intrus = sondeModeleDeLEspace(SONDE_ESPACE_LETTRE_F);

    expect($policy->memeEspace($utilisateur, $intrus))->toBeFalse();
    expect($policy->view($utilisateur, $intrus))->toBeFalse();
});

test('update et delete d un modele etranger sont refuses meme avec tous les roles', function () {
    // `hasAnyRole` est forcé à VRAI (cf. UtilisateurToujoursHabilite) : si ces
    // deux appels rendent `false`, c'est `sameWorkspace()` qui a tranché.
    $policy = new PolicySondeCloisonnement;
    $utilisateur = new UtilisateurToujoursHabilite(['current_workspace_id' => SONDE_ESPACE_LETTRE_A]);
    $intrus = sondeModeleDeLEspace(SONDE_ESPACE_LETTRE_F);

    expect($utilisateur->hasAnyRole(['owner']))->toBeTrue()
        ->and($policy->update($utilisateur, $intrus))->toBeFalse()
        ->and($policy->delete($utilisateur, $intrus))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 3. TÉMOIN INVERSE — la garde ne rend pas `false` par principe.
//    Une comparaison cassée dans l'autre sens (« toujours faux ») rendrait les
//    tests ci-dessus verts sans rien cloisonner, et casserait l'application.
// ─────────────────────────────────────────────────────────────────────────────

test('TEMOIN INVERSE : un modele de SON espace reste accessible', function () {
    $policy = new PolicySondeCloisonnement;
    $utilisateur = new UtilisateurToujoursHabilite(['current_workspace_id' => SONDE_ESPACE_SIEN]);
    $sien = sondeModeleDeLEspace(SONDE_ESPACE_SIEN);

    expect($policy->memeEspace($utilisateur, $sien))->toBeTrue()
        ->and($policy->view($utilisateur, $sien))->toBeTrue()
        ->and($policy->update($utilisateur, $sien))->toBeTrue()
        ->and($policy->delete($utilisateur, $sien))->toBeTrue();
});

test('un utilisateur SANS espace courant ne voit aucun modele scope', function () {
    // Cas réel : compte fraîchement créé, ou dont l'espace a été supprimé
    // (`ON DELETE SET NULL` sur `users.current_workspace_id`). Le cast rendait
    // ici `0 === 0` dès que le modèle commençait par une lettre : accès ouvert.
    $policy = new PolicySondeCloisonnement;
    $orphelin = new UtilisateurToujoursHabilite(['current_workspace_id' => null]);

    expect($policy->memeEspace($orphelin, sondeModeleDeLEspace(SONDE_ESPACE_LETTRE_A)))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────────────
// 4. GARDE STRUCTURELLE — le cast ne peut pas revenir en silence.
// ─────────────────────────────────────────────────────────────────────────────

test('BasePolicy ne caste plus ces identifiants en entier', function () {
    // ⚠️ ON RETIRE LES COMMENTAIRES AVANT D'ANALYSER — même piège que dans
    // `tests/Unit/Broadcasting/AutorisationCanauxTest.php` : le fichier CITE
    // l'ancien code cassé dans son bloc d'explication. Un test statique qui lit
    // ses propres commentaires rougit sur sa documentation et ne prouve rien.
    $codeSeul = '';
    foreach (token_get_all(file_get_contents(app_path('Policies/BasePolicy.php'))) as $jeton) {
        if (is_array($jeton)) {
            if (in_array($jeton[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $codeSeul .= $jeton[1];
        } else {
            $codeSeul .= $jeton;
        }
    }

    $this->assertStringNotContainsString(
        '(int) $user->current_workspace_id',
        $codeSeul,
        'Le cast en entier est revenu dans BasePolicy : deux UUID etrangers redeviennent egaux.',
    );
    $this->assertStringNotContainsString(
        '(int) $model->workspace_id',
        $codeSeul,
        'Le cast en entier est revenu dans BasePolicy : deux UUID etrangers redeviennent egaux.',
    );
    $this->assertStringContainsString(
        'hash_equals',
        $codeSeul,
        'BasePolicy doit comparer les UUID en CHAINES, comme routes/channels.php depuis le 2026-08-16.',
    );

    // TÉMOIN du détecteur lui-même : le fichier lu n'est pas vide, et le
    // dépouillement des commentaires n'a pas tout emporté. Sans ce contrôle,
    // un `file_get_contents` qui échoue rendrait les assertions « ne contient
    // pas » vertes par vacuité.
    $this->assertStringContainsString(
        'sameWorkspace',
        $codeSeul,
        'Le source lu ne contient meme plus la methode : le detecteur est aveugle.',
    );
});
