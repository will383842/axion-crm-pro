<?php

namespace App\Console\Commands;

use App\Services\Auth\CodeCourriel\CodesConnexionCourriel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * G3-F — passe UN compte en double authentification par courriel (« email »), ou le ramène à l'application (« totp »).
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
    protected $signature = 'crm:compte-double-auth {email : adresse du compte} {--methode= : email ou totp}';

    protected $description = 'Passe un compte en double authentification par courriel (canal réel exigé) ou le ramène à l\'application';

    public function handle(CodesConnexionCourriel $codes): int
    {
        $methode = $this->option('methode');
        if (! in_array($methode, ['email', 'totp'], true)) {
            $this->error('Méthode refusée : indiquez --methode=email ou --methode=totp.');

            return self::FAILURE;
        }

        if ($methode === 'email' && ! $codes->canal()->estReel()) {
            $this->error('Refusé : le canal courriel n\'est pas réel (envoi réel éteint). Rien n\'a été changé.');

            return self::FAILURE;
        }

        $id = DB::table('users')->where('email', (string) $this->argument('email'))->value('id');
        if ($id === null) {
            $this->error('Compte introuvable. Rien n\'a été changé.');

            return self::FAILURE;
        }

        DB::table('users')->where('id', $id)->update(['double_auth_methode' => $methode]);
        Log::info('crm.compte_double_auth', ['user_id' => (string) $id, 'methode' => $methode]);
        $this->info("Méthode posée : {$methode}.");

        return self::SUCCESS;
    }
}
