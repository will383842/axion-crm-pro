<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UsersController extends ApiController
{
    /**
     * @OA\Get(path="/users", tags={"Users"}, summary="Liste des users du workspace",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        // Sprint 18.9 — defensive : retourne au minimum un tableau vide.
        if (! Schema::hasTable('users')) {
            return $this->ok(['data' => []]);
        }

        try {
            $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
            if (! $workspaceId) {
                return $this->ok(['data' => []]);
            }

            $users = User::query()
                ->where('current_workspace_id', $workspaceId)
                // `two_factor_enabled` N'EST PAS UNE COLONNE : cette requete partait en
                // `SQLSTATE[42703] column "two_factor_enabled" does not exist`, et
                // `GET /api/v1/users` - l'ecran par lequel on invite quelqu'un - etait
                // casse. Meme derive de schema que l'enrolement 2FA (F35-002).
                // On selectionne la colonne qui existe ; l'etat 2FA s'en deduit.
                ->select(['id', 'email', 'name', 'current_workspace_id', 'first_login_completed_at', 'totp_enabled_at', 'last_login_at'])
                ->orderBy('name')
                ->limit(200)
                ->get();

            return $this->ok(['data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'name' => $u->name,
                'current_workspace_id' => $u->current_workspace_id,
                'first_login_completed_at' => $u->first_login_completed_at,
                'two_factor_enabled' => $u->twoFactorEnabled(),
                'last_login_at' => $u->last_login_at,
            ])->all()]);
        } catch (\Throwable $e) {
            Log::error('users.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * Les quatre roles admis — par `PermissionsAndRolesSeeder` ET par le CHECK
     * de `user_workspaces.role_slug`. Ecrits ici pour que la route TRANCHE
     * elle-meme : sans cette validation, une valeur inventee remonterait
     * jusqu'a Postgres et ressortirait en 500, ce qui apprend a l'appelant
     * qu'il a casse le serveur plutot qu'il s'est trompe de mot.
     *
     * @var list<string>
     */
    public const ROLES = ['owner', 'admin', 'operator', 'viewer'];

    /**
     * INVITER QUELQU'UN — et non « creer un utilisateur ».
     *
     * 🔑 LE SCHEMA DISAIT DEJA LE GESTE. `user_workspaces` porte `role_slug`,
     * `invited_at`, `joined_at` et `revoked_at` (migration `2026_05_16_000002`).
     * Ce n'est pas une table de liaison : c'est une table d'INVITATION. La
     * route ne posait simplement pas la ligne.
     *
     * 🔴 AUCUN MOT DE PASSE N'EST INSCRIT ICI, ET C'EST LE POINT.
     * `users.password_hash` est NULLABLE, et le constat `f35-008` du meme audit
     * s'appelle « mot de passe proprietaire en clair ». Un ecran
     * d'administration qui choisit le secret d'autrui le connait — le compte
     * n'est alors plus a personne. On cree donc un compte SANS secret, et la
     * personne s'en donne un par le parcours de reinitialisation existant.
     *
     * ⚠️ CE QUE CETTE ROUTE NE FAIT PAS, ecrit plutot que taise : **aucun
     * courriel ne part**. `MAIL_MAILER` etait l'un des quatre verrous du
     * rapport final ; une route qui pretendrait « inviter » alors que rien
     * n'est remis serait une facade de plus — exactement le motif `B12-007`
     * qu'on vient de fermer ailleurs. La ligne d'invitation est ECRITE et
     * DATEE ; sa remise est un chantier distinct, et il reste ouvert.
     *
     * @OA\Post(path="/users", tags={"Users"}, summary="Invite un compte dans l'espace courant",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=201, description="Invité"),
     *     @OA\Response(response=422, description="Adresse déjà prise, ou rôle inconnu"))
     */
    public function store(Request $r): JsonResponse
    {
        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            abort(404);
        }

        $valide = $r->validate([
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in(self::ROLES)],
            'locale' => ['sometimes', 'string', 'max:16'],
            'timezone' => ['sometimes', 'string', 'max:64'],
        ]);

        // `users.email` est `CITEXT NOT NULL UNIQUE` : la casse ne distingue
        // pas deux adresses. On compare donc sans casse, et AVANT d'ecrire,
        // pour rendre un 422 qui nomme le champ au lieu d'une `QueryException`
        // que le gestionnaire transforme en 500.
        $dejaPrise = User::withTrashed()
            ->whereRaw('lower(email::text) = ?', [mb_strtolower($valide['email'])])
            ->exists();

        if ($dejaPrise) {
            throw ValidationException::withMessages([
                'email' => 'Cette adresse est déjà utilisée par un compte.',
            ]);
        }

        $compte = DB::transaction(function () use ($espace, $valide): User {
            $compte = new User;
            $compte->id = (string) Str::uuid();
            $compte->email = $valide['email'];
            $compte->name = $valide['name'];
            // Explicite, et non « oublie » : c'est la decision du bloc ci-dessus.
            $compte->password_hash = null;
            $compte->current_workspace_id = $espace;
            $compte->locale = $valide['locale'] ?? 'fr';
            $compte->timezone = $valide['timezone'] ?? 'Europe/Paris';
            $compte->save();

            // `joined_at` reste NULL : la personne n'a pas encore accepte. La
            // distinction entre « invitee » et « entree » est portee par le
            // schema ; on ne l'ecrase pas en datant les deux d'un coup.
            DB::table('user_workspaces')->insert([
                'user_id' => $compte->id,
                'workspace_id' => $espace,
                'role_slug' => $valide['role'],
                'invited_at' => now(),
                'joined_at' => null,
                'revoked_at' => null,
            ]);

            setPermissionsTeamId($espace);
            $compte->assignRole($valide['role']);

            return $compte;
        });

        return $this->ok(['data' => $this->vue($compte)], 201);
    }

    /**
     * 🔑 L'ADRESSE N'EST PAS MODIFIABLE ICI, ET C'EST DELIBERE.
     *
     * `email` est l'identifiant de connexion. Le changer depuis un ecran
     * d'administration, sans verification par la personne concernee, revient a
     * prendre son compte : il suffirait ensuite de demander une
     * reinitialisation. Un changement d'adresse doit passer par une
     * confirmation des deux cotes — c'est un parcours, pas un champ.
     *
     * Le mot de passe n'y est pas non plus, pour la raison ecrite sur `store()`.
     *
     * @OA\Put(path="/users/{user}", tags={"Users"}, summary="Modifie un compte de l'espace courant",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnu, ou hors de mon espace"),
     *     @OA\Response(response=422, description="Champs invalides"))
     */
    public function update(Request $r, User $user): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($user, 'current_workspace_id');

        $valide = $r->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'locale' => ['sometimes', 'string', 'max:16'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'role' => ['sometimes', Rule::in(self::ROLES)],
        ]);

        $espace = (string) $user->current_workspace_id;

        DB::transaction(function () use ($user, $valide, $espace): void {
            foreach (['name', 'locale', 'timezone'] as $champ) {
                if (array_key_exists($champ, $valide)) {
                    $user->{$champ} = $valide[$champ];
                }
            }
            $user->save();

            if (! array_key_exists('role', $valide)) {
                return;
            }

            // Le role vit a DEUX endroits — `user_workspaces.role_slug`, qui
            // est la verite du schema, et les tables de Spatie, qui portent les
            // permissions effectives. Les ecrire toutes les deux, ou la console
            // afficherait un role et le produit en appliquerait un autre.
            DB::table('user_workspaces')->updateOrInsert(
                ['user_id' => $user->id, 'workspace_id' => $espace],
                ['role_slug' => $valide['role'], 'invited_at' => now()],
            );

            setPermissionsTeamId($espace);
            $user->syncRoles([$valide['role']]);
        });

        return $this->ok(['data' => $this->vue($user->refresh())]);
    }

    /**
     * FERMER un compte, pas l'effacer.
     *
     * `users.deleted_at` existe et le modele porte `SoftDeletes` : le journal
     * d'audit, les vues sauvegardees et les notifications referencent leur
     * auteur. Effacer la ligne rendrait ces references orphelines — et un
     * journal d'audit dont on ne peut plus nommer l'auteur ne prouve plus rien.
     *
     * @OA\Delete(path="/users/{user}", tags={"Users"}, summary="Ferme un compte de l'espace courant",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="user", in="path", required=true, @OA\Schema(type="string", format="uuid")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnu, ou hors de mon espace"),
     *     @OA\Response(response=422, description="On ne se ferme pas soi-même"))
     */
    public function destroy(Request $r, User $user): JsonResponse
    {
        // Constat B12-001 / F36-005 : la resolution de route rendait
        // l'enregistrement sans aucun filtre d'espace. 404, jamais 403 :
        // « interdit » confirmerait son existence.
        $this->refuserHorsEspace($user, 'current_workspace_id');

        $moi = $r->user();

        // 🔑 SANS CE REFUS, le dernier administrateur connecte peut se
        // verrouiller dehors, et plus personne ne rouvre la porte PAR LE
        // PRODUIT — il faut alors une intervention en base. Un geste dont la
        // reparation exige un acces serveur ne doit pas etre a un clic.
        if ($moi !== null && (string) $moi->getKey() === (string) $user->getKey()) {
            throw ValidationException::withMessages([
                'user' => 'On ne ferme pas son propre compte : demandez-le à un autre responsable.',
            ]);
        }

        $espace = (string) $user->current_workspace_id;

        DB::transaction(function () use ($user, $espace): void {
            // L'appartenance est revoquee, pas supprimee : `revoked_at` existe
            // dans le schema precisement pour garder trace de qui est passe.
            DB::table('user_workspaces')
                ->where('user_id', $user->id)
                ->where('workspace_id', $espace)
                ->update(['revoked_at' => now()]);

            $user->delete();
        });

        return $this->ok(['closed' => (string) $user->getKey()]);
    }

    /**
     * La forme rendue par les trois ecritures — la MEME que celle de `index()`.
     *
     * Deux formes differentes pour le meme objet obligeraient l'ecran a savoir
     * de quelle route vient sa donnee. C'est ce genre d'ecart qui produit les
     * « cinq colonnes qui n'existent nulle part » de `B16-011`.
     *
     * @return array<string, mixed>
     */
    private function vue(User $u): array
    {
        return [
            'id' => $u->id,
            'email' => $u->email,
            'name' => $u->name,
            'current_workspace_id' => $u->current_workspace_id,
            'first_login_completed_at' => $u->first_login_completed_at,
            'two_factor_enabled' => $u->twoFactorEnabled(),
            'last_login_at' => $u->last_login_at,
        ];
    }
}
