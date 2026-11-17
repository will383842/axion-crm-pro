<?php

namespace App\Services\Auth\CodeCourriel;

use App\Crm\Opposition\StatutOpposition;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * G3-A — émission et vérification des codes de connexion par courriel (aucune route ici).
 *
 * Rien ne s'efface : « remplacé » et « consommé » sont des dates. Cloisonnement par compte : tout est filtré sur `user_id`.
 * Le code n'est jamais stocké ni journalisé en clair (HMAC-SHA256 avec la clé de l'application).
 */
final class CodesConnexionCourriel
{
    private const FENETRE_RENVOIS_MINUTES = 10;

    /**
     * Le vrai canal SEULEMENT si l'envoi réel est allumé (comparaison stricte) ET hors simulacre ; sinon le faux canal.
     * Un canal lié au conteneur (tests) est lu en premier.
     */
    public function canal(): CanalCodeConnexion
    {
        if (app()->bound(CanalCodeConnexion::class)) {
            return app(CanalCodeConnexion::class);
        }

        if (config('envoi.reel_actif') === true && ! config('crm.mock_mode')) {
            return new CanalCodeConnexionCourriel;
        }

        return new FauxCanalCodeConnexion;
    }

    /** @throws CanalFermeException drapeau éteint */
    public function emettre(User $u, string $objet, ?string $ip): void
    {
        if (config('double_auth.actif') !== true) {
            throw new CanalFermeException('Double authentification par courriel éteinte.');
        }

        // Échec fermé : une adresse opposée ne reçoit rien ; l'appelant répond comme pour un envoi normal (pas d'oracle).
        if ($this->adresseOpposee($u)) {
            Log::warning('auth.code_courriel.adresse_opposee', ['user_id' => (string) $u->id, 'objet' => $objet]);

            return;
        }

        $maintenant = CarbonImmutable::now();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::transaction(function () use ($u, $objet, $ip, $maintenant, $code) {
            DB::table('codes_connexion_courriel')
                ->where('user_id', $u->id)->where('objet', $objet)
                ->whereNull('consomme_le')->whereNull('remplace_le')
                ->update(['remplace_le' => $maintenant->toIso8601String()]);

            DB::table('codes_connexion_courriel')->insert([
                'user_id' => $u->id,
                'objet' => $objet,
                'code_hash' => $this->empreinte($code),
                'expire_le' => $maintenant->addMinutes((int) config('double_auth.duree_code_minutes', 10))->toIso8601String(),
                'ip' => $ip,
                'cree_le' => $maintenant->toIso8601String(),
            ]);
        });

        // APRÈS l'insertion : si le canal lève, la ligne reste (l'appelant répond 503 canal_indisponible).
        $this->canal()->envoyer($u, $code, $objet);
    }

    /** @throws TropDeRenvois @throws CanalFermeException */
    public function renvoyer(User $u, ?string $ip): void
    {
        $depuis = CarbonImmutable::now()->subMinutes(self::FENETRE_RENVOIS_MINUTES);
        $emises = DB::table('codes_connexion_courriel')
            ->where('user_id', $u->id)->where('objet', 'connexion')
            ->where('cree_le', '>', $depuis->toIso8601String())
            ->count();

        if ($emises >= (int) config('double_auth.renvois_max', 3)) {
            throw new TropDeRenvois('Trop de codes demandés.');
        }

        $this->emettre($u, 'connexion', $ip);
    }

    public function verifier(User $u, string $objet, string $code): bool
    {
        return DB::transaction(function () use ($u, $objet, $code) {
            $ligne = DB::table('codes_connexion_courriel')
                ->where('user_id', $u->id)->where('objet', $objet)
                ->whereNull('consomme_le')->whereNull('remplace_le')
                ->orderByDesc('cree_le')
                ->lockForUpdate()
                ->first();

            if ($ligne === null) {
                return false;
            }
            if (CarbonImmutable::parse($ligne->expire_le)->lte(CarbonImmutable::now())) {
                return false;
            }
            if ((int) $ligne->essais >= (int) config('double_auth.essais_max', 5)) {
                return false;
            }

            DB::table('codes_connexion_courriel')->where('id', $ligne->id)->where('user_id', $u->id)
                ->update(['essais' => (int) $ligne->essais + 1]);

            if (! hash_equals((string) $ligne->code_hash, $this->empreinte($code))) {
                return false;
            }

            DB::table('codes_connexion_courriel')->where('id', $ligne->id)->where('user_id', $u->id)
                ->update(['consomme_le' => CarbonImmutable::now()->toIso8601String()]);

            return true;
        });
    }

    private function empreinte(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }

    /** Lecture seule : listes de refus d'univers `business` + les espaces du compte (au moins un appel, même sans espace). */
    private function adresseOpposee(User $u): bool
    {
        $espaces = DB::table('user_workspaces')->where('user_id', $u->id)->pluck('workspace_id')->map(fn ($w) => (string) $w)->all();
        if ($u->current_workspace_id !== null) {
            $espaces[] = (string) $u->current_workspace_id;
        }
        $espaces = array_values(array_unique($espaces)) ?: ['00000000-0000-0000-0000-000000000000'];

        foreach ($espaces as $espace) {
            $r = app(StatutOpposition::class)->pourLignes($espace, [0 => ['email' => (string) $u->email, 'siren' => null]]);
            if (($r[0]['opposee'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }
}
