<?php

namespace App\Envoi\Sante;

use App\Socle\Alertes\Alerte;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Alerte transactionnelle par le Socle (cloche + e-mail de l'application). JAMAIS par une boîte de prospection :
 * si l'expéditeur de l'application est une boîte ou un domaine d'envoi, l'alerte est REFUSÉE (journal seul).
 */
final class EmetteurAlerteSocle implements EmetteurAlerteSante
{
    public function __construct(private readonly Alerte $alerte) {}

    public function alerter(string $workspaceId, string $source, Constat $constat): bool
    {
        try {
            if (self::expediteurEstDeProspection((string) config('mail.from.address', ''))) {
                Log::critical('[ENVOI] alerte de santé NON ENVOYÉE : l’expéditeur de l’application est une boîte ou un domaine de prospection.', ['code' => $constat->code]);

                return false;
            }

            return $this->alerte->envoyer(
                'Santé des envois : ' . $constat->code . ' (' . $source . ', ' . $constat->gravite . ')',
                $constat->detail . ($constat->gravite === Constat::CRITIQUE ? "\nLa ressource est mise en pause ; la levée est une décision humaine." : ''),
                'envoi',
                $constat->gravite,
                '/envoi/sante',
                ['workspace_id' => $workspaceId, 'source' => $source, 'code' => $constat->code],
            );
        } catch (\Throwable $e) {
            Log::error('[ENVOI] alerte de santé en échec.', ['exception' => $e::class]);

            return false;
        }
    }

    public static function expediteurEstDeProspection(string $adresse): bool
    {
        $adresse = mb_strtolower(trim($adresse));
        $domaine = substr(strrchr($adresse, '@') ?: '', 1);
        if ($adresse === '' || $domaine === '') {
            return true; // expéditeur illisible : fail-closed
        }

        return WorkspaceContext::runWithoutScope(
            'alerte de santé : l’expéditeur de l’application ne doit être ni une boîte ni un domaine de prospection (tous espaces)',
            fn (): bool => DB::table('envoi_boites')->whereRaw('lower(adresse) = ?', [$adresse])->exists()
                || DB::table('envoi_domaines')->whereRaw('lower(nom) = ? OR ? LIKE \'%.\' || lower(nom)', [$domaine, $domaine])->exists(),
        );
    }
}
