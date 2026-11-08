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
                // L'ESPACE est dans le titre : le regroupement du Socle (titre + produit + gravité) ne doit pas
                // absorber, dans sa fenêtre de 15 min, l'alerte d'un espace pour celle d'un autre.
                'Santé des envois : ' . $constat->code . ' (espace ' . $workspaceId . ', ' . $source . ', ' . $constat->gravite . ($constat->palier === 2 ? ', palier 2' : '') . ')',
                $constat->detail . ($constat->gravite === Constat::CRITIQUE ? "\nLa ressource est mise en pause ; la levée est une décision humaine (si la pause est levée alors que le défaut persiste, la supervision la repose)." : ''),
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
            function () use ($adresse, $domaine): bool {
                if (DB::table('envoi_boites')->whereRaw('lower(adresse) = ?', [$adresse])->exists()) {
                    return true;
                }
                $organisation = self::domaineOrganisationnel($domaine);
                foreach (DB::table('envoi_domaines')->pluck('nom') as $nom) {
                    // Même domaine, sous-domaine, ou même domaine ORGANISATIONNEL (même politique DMARC, même réputation).
                    if (self::domaineOrganisationnel(mb_strtolower((string) $nom)) === $organisation) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /** Domaine organisationnel approché : 2 derniers libellés, 3 si le second est un suffixe public courant (co.uk, com.fr…). */
    public static function domaineOrganisationnel(string $domaine): string
    {
        $parties = array_values(array_filter(explode('.', trim(mb_strtolower($domaine), '.')), static fn (string $p): bool => $p !== ''));
        $n = count($parties);
        if ($n <= 2) {
            return implode('.', $parties);
        }
        $garde = (strlen($parties[$n - 1]) === 2 && in_array($parties[$n - 2], ['co', 'com', 'org', 'net', 'gov', 'gouv', 'ac', 'asso', 'edu'], true)) ? 3 : 2;

        return implode('.', array_slice($parties, -$garde));
    }
}
