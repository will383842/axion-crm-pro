<?php

namespace Tests\Feature\Envoi\Routes;

use App\Envoi\Contrat\ServeurEnvoi;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Base commune des tests de routes d'envoi (lot R00).
 *
 * `ServeurEnvoi` est lié à un FAUX qui lève une exception : toute route qui le résoudrait fait échouer le test.
 * Données fictives uniquement (domaine exemple.test).
 */
abstract class BaseRoutesEnvoi extends TestCase
{
    use RefreshDatabase;

    /** Nombre de résolutions de ServeurEnvoi pendant le test. */
    protected int $resolutions = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PermissionsAndRolesSeeder::class);
        $this->resolutions = 0;
        // La CI copie `.env.example` (MAIL_MAILER_AUTH vide) : sans ceci, le mailer d'authentification est absent et le code de
        // validation (R11) refuse de partir (503). Les tests lisent le courriel dans le transport `array`.
        config(['mail.auth_mailer' => 'array']);
        $this->app->bind(ServeurEnvoi::class, function () {
            $this->resolutions++;
            throw new RuntimeException('Une route d’envoi ne doit pas résoudre ServeurEnvoi.');
        });
    }

    /** Espace fictif ; `$produitEnvoi` pose le droit « envoi » actif dans ses réglages. */
    protected function espace(bool $produitEnvoi): Workspace
    {
        return Workspace::create([
            'id' => (string) Str::uuid(),
            'slug' => 'r00-' . Str::random(8),
            'name' => 'Espace R00',
            'settings' => $produitEnvoi ? ['produits' => ['envoi' => 'actif']] : [],
        ]);
    }

    protected function compte(Workspace $espace, string $role): User
    {
        $user = User::create([
            'id' => (string) Str::uuid(),
            'email' => $role . '-' . Str::random(6) . '@exemple.test',
            'name' => 'Compte Test',
            'password_hash' => password_hash('MotDePasseDeTest2026!', PASSWORD_BCRYPT, ['cost' => 4]),
            'current_workspace_id' => $espace->id,
            'first_login_completed_at' => now(),
        ]);
        setPermissionsTeamId($espace->id);
        $user->assignRole($role);

        return $user;
    }

    /** Routes d'envoi : méthode (sans HEAD) + URI, paramètres remplacés par une valeur fictive. @return list<array{0:string,1:string}> */
    protected function routesEnvoi(): array
    {
        $liste = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/envoi/') && $route->uri() !== 'api/v1/envoi') {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $methode) {
                $liste[] = [$methode, '/' . preg_replace('/\{[^}]+\}/', 'exemple', $route->uri())];
            }
        }

        return $liste;
    }
}
