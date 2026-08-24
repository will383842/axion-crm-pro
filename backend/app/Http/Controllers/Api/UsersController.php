<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Auth\PasswordResetController;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class UsersController extends ApiController
{
    use VerrouOptimiste;

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
     * ✅ LA REMISE EXISTE DEPUIS LE 2026-08-24, et ce paragraphe disait le
     * contraire. Il disait : « aucun courriel ne part [...] sa remise est un
     * chantier distinct, et il reste ouvert ». Ce chantier est fait —
     * `remettreInvitation()`, plus bas, envoie un lien de definition de mot de
     * passe par le meme parcours que la reinitialisation.
     *
     * ⚠️ MAIS ELLE RESTE CONDITIONNELLE, et c'est la reponse qui le dit, pas ce
     * commentaire : `invitation_envoyee` vaut `false` tant que `MOCK_MODE` est
     * `true` ou que le transport echoue. DEUX verrous en serie, tous deux fermes
     * par defaut (`MOCK_MODE=true`, `MAIL_MAILER=log`) — un `.env` de production
     * doit lever les deux, sans quoi un compte cree ne peut pas se connecter DU
     * TOUT : il naît sans mot de passe, et le lien pour s'en donner un n'est
     * jamais remis.
     *
     * Une route qui pretendrait « inviter » sans rien remettre serait une facade
     * de plus — le motif `B12-007`. D'ou le drapeau : la reponse dit ce qui
     * s'est passe, l'ecran le relaie, personne n'affirme a la place du serveur.
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
        // 🔴 C21-001/twins — ICI, `lower()` SERAIT REDONDANT ET LA GARDE L'A DIT.
        // Ma premiere version ecrivait `whereRaw('lower(email::text) = ?')`.
        // L'inventaire fige de `lower(<colonne>::text)` a rougi, avec le bon
        // conseil dans son message : « la colonne visee est-elle citext ? Si
        // oui, lower() est redondant ». `users.email` EST `CITEXT NOT NULL
        // UNIQUE` — la comparaison ignore deja la casse, cote base.
        //
        // Le `lower()` ne servait a rien et coutait cher : il rend l'index
        // unique inutilisable, donc un balayage sequentiel de la table des
        // comptes a chaque invitation.
        $dejaPrise = User::withTrashed()->where('email', $valide['email'])->exists();

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

        // 🔴 L'INVITATION PART D'ICI — et elle ne partait pas.
        //
        // Le commentaire de cette methode disait, et c'etait vrai : « aucun
        // courriel ne part [...] la remise est un chantier distinct, et il reste
        // ouvert ». Ce chantier est celui-ci.
        //
        // ⚠️ HORS DE LA TRANSACTION, deliberement. Un envoi SMTP peut prendre
        // plusieurs secondes, ou pendre jusqu'au delai d'attente ; le tenir dans
        // la transaction garderait des verrous ouverts sur `users` et
        // `user_workspaces` pendant tout ce temps.
        //
        // ⚠️ ET IL NE PEUT PAS FAIRE ECHOUER LA CREATION. Le compte EXISTE une
        // fois la transaction close. Si le courriel ne part pas, la reponse doit
        // le DIRE — pas defaire un compte valide, ni pretendre l'avoir remis.
        $envoyee = $this->remettreInvitation($compte);

        return $this->ok([
            'data' => $this->vue($compte),
            // 🔑 L'ECRAN NE PEUT PAS DEVINER, ET NE DOIT PAS SUPPOSER.
            // `MOCK_MODE` et `MAIL_MAILER` sont des reglages SERVEUR : le
            // frontend ne les voit pas. Sans ce drapeau il ne lui reste qu'a
            // affirmer quelque chose au hasard — c'est exactement ce qu'il
            // faisait en annoncant « Invitation envoyee » alors que rien ne
            // partait (constat D25-001, meme ecran).
            'invitation_envoyee' => $envoyee,
        ], 201);
    }

    /**
     * Remet a la personne invitee le lien qui lui permet de se donner un mot de
     * passe. Rend `true` si un courriel est REELLEMENT parti.
     *
     * 🔑 ON REUTILISE LE PARCOURS DE REINITIALISATION, ON N'EN INVENTE PAS UN
     * SECOND. Meme table `password_reset_tokens`, meme condensat SHA-256 du
     * jeton, meme page `/password-reset`, meme duree de vie, meme transport
     * `mail.auth_mailer`. C'est le §28.5 — « on etend, on ne reinvente pas » —
     * et ici il porte une consequence de securite : un second mecanisme de
     * remise de mot de passe, c'est une seconde surface a auditer, a expirer et
     * a limiter en debit.
     *
     * ⚠️ LE JETON EN CLAIR NE VIT QUE DANS LE COURRIEL. La base ne garde que son
     * condensat, exactement comme `PasswordResetController::forgot()`. Un
     * administrateur qui lit la table ne peut donc pas prendre le compte qu'il
     * vient de creer.
     *
     * ⚠️ `mock_mode` d'abord, par `config()` et JAMAIS `env()`. C'est le defaut
     * F40-002, deja paye : avec `config:cache` — que l'entrypoint de production
     * lance a chaque demarrage — le `.env` n'est plus lu, et `env('MOCK_MODE',
     * true)` rendait TRUE en production. Le courriel n'etait alors jamais
     * envoye, meme avec un SMTP correct.
     */
    private function remettreInvitation(User $compte): bool
    {
        $jeton = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $compte->email],
            ['token' => hash('sha256', $jeton), 'created_at' => now()],
        );

        $lien = rtrim((string) config('app.frontend_url'), '/')
            . '/password-reset?token=' . $jeton
            . '&email=' . urlencode((string) $compte->email);

        if (config('crm.mock_mode', true)) {
            Log::info('Mock invitation link (would be emailed)', [
                'email' => $compte->email,
                'link' => $lien,
            ]);

            return false;
        }

        // ⚠️ LE COMPTE EST DEJA CREE. Un echec d'envoi — SMTP injoignable, jeton
        // ZeptoMail refuse, domaine non verifie — ne doit pas remonter en 500 :
        // il rendrait la creation invisible a l'ecran alors qu'elle a eu lieu,
        // et la personne se retrouverait avec un compte fantome impossible a
        // recreer (l'adresse serait « deja utilisee »).
        try {
            $ttl = PasswordResetController::TOKEN_TTL_MINUTES;

            Mail::mailer(config('mail.auth_mailer'))->raw(
                "Bonjour {$compte->name},\n\n"
                . "Un compte vient d'être créé pour vous sur Axion CRM Pro.\n\n"
                . "Choisissez votre mot de passe :\n\n{$lien}\n\n"
                . "Ce lien est valable {$ttl} minutes. Passé ce délai, utilisez\n"
                . "« mot de passe oublié » depuis l'écran de connexion.\n",
                function ($m) use ($compte) {
                    $m->to($compte->email)->subject('Votre accès à Axion CRM Pro');
                },
            );

            return true;
        } catch (\Throwable $e) {
            // On journalise SANS le lien : il vaut mot de passe pendant une
            // heure, et les journaux sont lus par plus de monde qu'une boite aux
            // lettres.
            Log::error('invitation.envoi_echoue', [
                'user_id' => $compte->id,
                'exception' => $e->getMessage(),
            ]);
            report($e);

            return false;
        }
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

        // 🔑 G43-005 — VERROU OPTIMISTE. La garde `VerrouOptimisteEtenduTest`
        // avait ANTICIPE ce moment : « un update() qui rend 501 n'ecrit rien :
        // il n'y a pas de saisie a perdre. Le jour ou il est cable, il
        // apparaitra ici. » Il l'a fait, le 2026-08-23, et sa liste de
        // derogations est vide A DESSEIN — « ce n'est pas une derogation, c'est
        // le registre de ce qui reste a faire ».
        //
        // Sans ce controle, deux saisies concurrentes perdent du travail EN
        // SILENCE : la seconde ecrase la premiere sans que personne l'apprenne.
        $this->refuserSiVersionPerimee($r, $user);

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
