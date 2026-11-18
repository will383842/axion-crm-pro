<?php

namespace App\Console\Commands;

use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use App\Support\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * G3-F — passe UN compte en double authentification par courriel (« email »), ou le ramène à l’application (« totp », seulement si un TOTP est inscrit).
 *
 * Commande LOCALE : aucune route, aucun écran. Une seule colonne change (`users.double_auth_methode`), sur un seul
 * compte ; rien n'est supprimé.
 *
 * Échec fermé : « email » est refusé tant que le canal n'est pas un VRAI canal. Le vrai canal n'existe que si
 * `ENVOI_REEL_ACTIF` est allumé (comparaison stricte) hors simulacre ({@see CodesConnexionCourriel::canal()}) ;
 * donc, drapeau éteint, l'activation est impossible. « aucune » et toute autre valeur sont refusées : retirer la
 * double authentification d'un compte n'est pas un geste de cette commande.
 *
 * La sortie ne contient ni adresse ni identifiant.
 */
class CrmCompteDoubleAuth extends Command
{
    protected $signature = 'crm:compte:double-auth {email : adresse du compte} {--methode= : email ou totp}';

    protected $description = 'Passe un compte en double authentification par courriel (canal réel exigé) ou le ramène à l\'application';

    public function handle(CodesConnexionCourriel $codes): int
    {
        $methode = $this->option('methode');
        if (! in_array($methode, ['email', 'totp'], true)) {
            $this->error('Méthode refusée : indiquez --methode=email ou --methode=totp.');

            return self::FAILURE;
        }

        if ($methode === 'email' && (! $codes->canal()->estReel() || config('double_auth.actif') !== true)) {
            $this->error('Refusé : canal faux, aucun courriel ne partirait. Rien n\'a été changé.');

            return self::FAILURE;
        }

        $compte = DB::table('users')->where('email', (string) $this->argument('email'))
            ->first(['id', 'current_workspace_id', 'totp_enabled_at']);
        if ($compte === null) {
            $this->error('Compte introuvable. Rien n\'a été changé.');

            return self::FAILURE;
        }

        // Échec fermé : sans TOTP inscrit, « totp » ne protégerait rien (la méthode effective tomberait à « aucune »).
        if ($methode === 'totp' && $compte->totp_enabled_at === null) {
            $this->error('Refusé : aucune application d\'authentification n\'est inscrite sur ce compte. Rien n\'a été changé.');

            return self::FAILURE;
        }

        // Une seule écriture. En « email », le premier passage est considéré fait (sinon EnforceFirstLoginSetup bloque).
        $maj = ['double_auth_methode' => $methode];
        if ($methode === 'email') {
            $maj['first_login_completed_at'] = DB::raw('coalesce(first_login_completed_at, now())');
        }
        DB::table('users')->where('id', $compte->id)->update($maj);

        AuditLogger::log('compte.double_auth.methode', [
            'workspace_id' => $compte->current_workspace_id,
            'user_id' => (string) $compte->id,
            'methode' => $methode,
        ]);
        $this->info("Méthode posée : {$methode}.");

        return self::SUCCESS;
    }
}
