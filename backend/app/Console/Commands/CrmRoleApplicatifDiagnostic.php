<?php

namespace App\Console\Commands;

use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * G4-0 — Diagnostic, en LECTURE SEULE, du rôle sous lequel l'application parle
 * à Postgres.
 *
 * Constat, pas test : code de sortie 0 dans tous les cas lisibles, 1 seulement
 * si la base est injoignable. Aucune écriture (la transaction est `READ ONLY` :
 * toute écriture lèverait 25006), aucun réglage modifié.
 *
 * Dépôt et journaux PUBLICS : la sortie ne porte que des booléens, des nombres
 * et des noms de rôles déjà publics. Jamais un mot de passe, un hôte, ni le
 * message d'une erreur de connexion (il peut contenir l'hôte).
 */
class CrmRoleApplicatifDiagnostic extends Command
{
    protected $signature = 'crm:role-applicatif:diagnostic';

    protected $description = 'Diagnostic en lecture seule du rôle applicatif Postgres (G4-0)';

    public function handle(): int
    {
        try {
            $lignes = $this->enLectureSeule(fn (): array => $this->constat());
        } catch (Throwable) {
            // Pas de message : il pourrait citer l'hôte.
            $this->line('BASE=injoignable');

            return self::FAILURE;
        }

        foreach ($lignes as $cle => $valeur) {
            $this->line($cle . '=' . $valeur);
        }

        return self::SUCCESS;
    }

    /**
     * Joue $corps dans une transaction dont la PREMIÈRE instruction est
     * `SET TRANSACTION READ ONLY`.
     */
    public function enLectureSeule(Closure $corps): mixed
    {
        return DB::transaction(function () use ($corps) {
            DB::statement('SET TRANSACTION READ ONLY');

            return $corps();
        });
    }

    /** @return array<string, string> */
    private function constat(): array
    {
        $flag = filter_var(config('crm.db_app_role'), FILTER_VALIDATE_BOOLEAN);

        $courant = DB::selectOne(
            'SELECT current_user AS role, r.rolsuper, r.rolbypassrls
               FROM pg_roles r WHERE r.rolname = current_user',
        );
        $role = (string) $courant->role;
        $super = (bool) $courant->rolsuper;

        $proprietaire = DB::selectOne(
            "SELECT count(*) AS n FROM pg_tables
              WHERE schemaname = current_schema() AND tablename = 'contacts' AND tableowner = current_user",
        );

        $nomApp = (string) config('database.connections.pgsql_app.username');
        $app = DB::selectOne(
            'SELECT rolcanlogin, rolsuper, rolbypassrls FROM pg_roles WHERE rolname = ?',
            [$nomApp],
        );

        // Recopie (pas appel) de la requête de PentestSelfCheck : tables à
        // workspace_id sans FORCE ROW LEVEL SECURITY.
        $sansForce = DB::selectOne(
            "SELECT count(*) AS n
               FROM pg_class c
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN pg_attribute a ON a.attrelid = c.oid
                                  AND a.attname = 'workspace_id'
                                  AND a.attnum > 0
                                  AND NOT a.attisdropped
              WHERE n.nspname = current_schema()
                AND c.relkind = 'r'
                AND NOT c.relispartition
                AND NOT c.relforcerowsecurity",
        );

        $appSuper = $app !== null && (bool) $app->rolsuper;

        if ($flag && $role === $nomApp && ! $super) {
            $verdict = 'role_applicatif_actif';
        } elseif (! $flag && $super) {
            $verdict = 'superutilisateur_actif';
        } else {
            $verdict = 'incoherent';
        }

        return [
            'DRAPEAU_CONFIG' => $flag ? 'true' : 'false',
            'CONNEXION_DEFAUT_ROLE' => $role,
            'CONNEXION_DEFAUT_SUPERUSER' => $this->ouiNon($super),
            'CONNEXION_DEFAUT_BYPASSRLS' => $this->ouiNon((bool) $courant->rolbypassrls),
            'CONNEXION_DEFAUT_EST_PROPRIETAIRE' => $this->ouiNon((int) $proprietaire->n > 0),
            'ROLE_APP_EXISTE' => $this->ouiNon($app !== null),
            'ROLE_APP_LOGIN' => $this->ouiNon($app !== null && (bool) $app->rolcanlogin),
            'ROLE_APP_SUPERUSER' => $this->ouiNon($appSuper),
            'ROLE_APP_BYPASSRLS' => $this->ouiNon($app !== null && (bool) $app->rolbypassrls),
            'ROLE_APP_CONNEXION' => $this->connexionApp(),
            'TABLES_ESPACE_SANS_FORCE' => (string) (int) $sansForce->n,
            'VERDICT' => $verdict,
        ];
    }

    /** `SELECT 1` sur `pgsql_app` ; l'erreur n'est jamais affichée. */
    private function connexionApp(): string
    {
        if ((string) config('database.connections.pgsql_app.password') === '') {
            return 'non_configuree';
        }

        try {
            DB::connection('pgsql_app')->select('SELECT 1');

            return 'ok';
        } catch (Throwable) {
            return 'refusee';
        }
    }

    private function ouiNon(bool $v): string
    {
        return $v ? 'oui' : 'non';
    }
}
