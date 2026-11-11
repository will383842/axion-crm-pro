<?php

namespace App\Envoi\Imap;

use App\Support\WorkspaceContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L8-F12 : relève IMAP planifiée de toutes les boîtes actives (une relève par boîte, isolée des autres).
 *
 * Inerte (code 0) tant que `ENVOI_REEL_ACTIF` ET `ENVOI_RELEVE_ACTIF` ne sont pas vrais : AVANT toute résolution
 * du lecteur, donc aucune connexion IMAP n'est tentée. Le lecteur et la relève viennent du conteneur (jamais de
 * secret ici). La boîte est lue seule ; seuls des ajouts sont écrits (curseur, événements).
 */
final class ReleveImapCommande extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:releve-imap';

    private const CLE_PANNE = 'envoi:releve-imap:panne:';

    private const DELAI_RELOG_SECONDES = 3600;

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Envoi : relève IMAP des retours (rebonds, plaintes, réponses) de chaque boîte active';

    public function handle(): int
    {
        if (config('envoi.reel_actif') !== true || config('envoi.releve_actif') !== true) {
            $this->info('Relève inerte (ENVOI_REEL_ACTIF et ENVOI_RELEVE_ACTIF doivent tous deux être vrais).');

            return self::SUCCESS;
        }
        $echecs = 0;
        foreach (DB::table('workspaces')->whereNull('deleted_at')->pluck('id') as $ws) {
            $boites = WorkspaceContext::run((string) $ws, fn () => DB::table('envoi_boites')
                ->where('workspace_id', $ws)->where('etat', 'active')->pluck('id'));
            foreach ($boites as $boite) {
                try {
                    app(ReleveImap::class)->relever((string) $ws, (string) $boite);
                    $this->journaliserRetablie((string) $boite);
                } catch (Throwable $e) {
                    $echecs++;
                    $this->journaliserPanne((string) $boite, $e);
                    $this->error('relève impossible : ' . $e::class);
                }
            }
        }

        return $echecs > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Une panne n'est journalisée qu'UNE fois par changement d'état (nouvelle panne, ou autre classe d'exception)
     * et par boîte, puis au plus une fois par heure tant qu'elle dure : pas de Log::error chaque minute.
     * Classe seulement : le message d'une panne réseau peut porter un hôte.
     */
    private function journaliserPanne(string $boite, Throwable $e): void
    {
        $cle = self::CLE_PANNE . $boite;
        if (Cache::get($cle) === $e::class) {
            return; // même panne, déjà journalisée il y a moins d'une heure
        }
        Log::error('[ENVOI] relève IMAP impossible pour une boîte.', ['boite' => $boite, 'exception' => $e::class]);
        Cache::put($cle, $e::class, self::DELAI_RELOG_SECONDES);
    }

    private function journaliserRetablie(string $boite): void
    {
        if (Cache::pull(self::CLE_PANNE . $boite) !== null) {
            Log::info('[ENVOI] relève IMAP rétablie pour une boîte.', ['boite' => $boite]);
        }
    }
}
