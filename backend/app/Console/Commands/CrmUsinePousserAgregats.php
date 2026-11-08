<?php

namespace App\Console\Commands;

use App\Crm\Usine\AgregatsOrigines;
use App\Crm\Usine\ClientUsine;
use App\Crm\Usine\SignatureAgregats;
use App\Socle\Alertes\Alerte;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * L7 — pousse à l'Usine les chiffres d'origines AGRÉGÉS (jamais une personne).
 *
 * Éteinte par défaut (`usine.agregats.enabled`). Sans secret d'au moins 32
 * caractères, rien ne part et une alerte le dit. Ne supprime rien.
 */
class CrmUsinePousserAgregats extends Command
{
    public const SIGNATURE_PLANIFIEE = 'crm:usine:pousser-agregats';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Pousse à l’Usine les comptages agrégés des origines (aucune donnée de personne).';

    public function handle(): int
    {
        if (! (bool) config('usine.agregats.enabled', false)) {
            $this->info('Push CRM → Usine éteint (USINE_AGREGATS_CRM_ENABLED).');

            return self::SUCCESS;
        }

        $secret = config('usine.agregats.secret');
        if (! is_string($secret) || strlen($secret) < SignatureAgregats::SECRET_MIN) {
            $this->alerter('Chiffres CRM → Usine non envoyés', 'Le secret de signature est absent ou trop court : rien n’est envoyé.');

            return self::SUCCESS;
        }

        try {
            $client = ClientUsine::depuisConfig();
        } catch (\Throwable $e) {
            $this->alerter('Chiffres CRM → Usine non envoyés', 'L’adresse de l’Usine est absente ou non sécurisée (' . $e::class . ').');

            return self::SUCCESS;
        }

        $espaces = AgregatsOrigines::espaces();
        if ($espaces === []) {
            $this->info('Aucun espace ouvert à origines.lecture : rien à envoyer.');

            return self::SUCCESS;
        }

        // Tout en UTC : le jour d'une origine est son jour UTC (date ISO en Z), pas celui de l'appli (Europe/Paris).
        $maintenant = now()->utc();
        $hier = $maintenant->copy()->subDay()->format('Y-m-d');
        $plancher = $maintenant->copy()->subDays(7)->format('Y-m-d');
        $recus = null;
        try {
            $recus = $client->joursRecus();
        } catch (\Throwable $e) {
            Log::warning('usine.agregats: jours reçus indisponibles', ['exception' => $e::class]);
        }

        // Jours reçus inconnus (l'Usine n'a pas répondu proprement) : on n'envoie que J-1, pas 7 jours à l'aveugle.
        // Sinon : les jours des 7 derniers que l'Usine n'a pas, plus J-1 (toujours renvoyé : il se complète).
        $jours = [];
        if ($recus === null) {
            $jours[] = $hier;
        } else {
            for ($i = 7; $i >= 1; $i--) {
                $jour = $maintenant->copy()->subDays($i)->format('Y-m-d');
                if ($jour >= $plancher && $jour <= $hier && ($jour === $hier || ! in_array($jour, $recus, true))) {
                    $jours[] = $jour;
                }
            }
        }

        $echecs = 0;
        foreach ($jours as $jour) {
            $lignes = [];
            foreach ($espaces as $espace) {
                $lignes = array_merge($lignes, AgregatsOrigines::pourJour($espace, $jour));
            }
            $lignes = AgregatsOrigines::masquer($lignes, (int) config('usine.agregats.seuil', 3));
            $genereLe = now()->utc()->format('Y-m-d\TH:i:s\Z');
            $statut = null;
            $erreur = null;
            try {
                $reponse = $client->pousser($jour, $genereLe, $lignes);
                $statut = $reponse->status();
                if (! $reponse->successful()) {
                    $erreur = 'HttpNon2xx';
                }
            } catch (\Throwable $e) {
                $erreur = $e::class;
            }

            DB::table('usine_agregats_envois')->insert([
                'jour' => $jour, 'genere_le' => $genereLe, 'lignes' => count($lignes),
                'statut_http' => $statut, 'erreur' => $erreur, 'cree_le' => now(),
            ]);
            // Journal : statut HTTP et nombre de lignes, jamais le corps ni la signature.
            Log::info('usine.agregats: envoi', ['jour' => $jour, 'statut' => $statut, 'lignes' => count($lignes)]);

            if ($erreur !== null) {
                $echecs++;
            }
        }

        if ($echecs > 0) {
            $this->alerter('Chiffres CRM → Usine non envoyés', $echecs . ' envoi(s) en échec sur ' . count($jours) . '. Voir le journal d’envoi.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function alerter(string $titre, string $corps): void
    {
        try {
            app(Alerte::class)->envoyer($titre, $corps, 'crm', 'attention');
        } catch (\Throwable $e) {
            Log::error('usine.agregats: alerte en échec', ['exception' => $e::class]);
        }
    }
}
