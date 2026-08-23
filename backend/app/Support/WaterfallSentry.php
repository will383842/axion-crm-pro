<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Support\Facades\Log;
use Sentry\State\Hub;
use Sentry\State\Scope;

/**
 * Sprint H4 — Helper centralisé pour capturer les exceptions waterfall
 * dans Sentry avec contexte company standardisé.
 *
 * Usage :
 *   try { … } catch (\Throwable $e) {
 *       WaterfallSentry::capture($company, 'auto-classify', $e);
 *       throw $e;
 *   }
 *
 * Si Sentry n'est pas installé (classe absente) → no-op silencieux.
 * Si le DSN est vide → no-op AUDIBLE : un avertissement dans les journaux, une
 * fois par processus (constat C18-014). Un no-op silencieux à cet endroit-là
 * laissait croire que dix points de capture étaient supervisés.
 */
class WaterfallSentry
{
    /**
     * Une supervision muette ne se distingue pas d'une supervision qui marche.
     * On ne le dit qu'UNE FOIS par processus : la waterfall passe ici à chaque
     * exception, et un avertissement par échec noierait le journal sous le
     * message qui décrit le journal.
     */
    private static bool $absenceDeDsnDejaSignalee = false;

    public static function capture(?Company $company, string $service, \Throwable $throwable): void
    {
        if (! class_exists(Hub::class)) {
            return;
        }

        // 🔴 C18-014 — DIX captures qui partaient dans le vide, sans un mot.
        //
        // La porte ci-dessus est ouverte : `sentry/sentry-laravel` est en
        // `require` (composer.json:37), la classe existe. La panne était plus
        // bas : `config/sentry.php:12` lit `SENTRY_LARAVEL_DSN`, et mesure du
        // 2026-08-22, cette variable n'était déclarée NULLE PART dans le dépôt
        // — ni dans `.env.example`, ni dans un `docker-compose*.yml`. Sans DSN
        // le SDK est inerte : `captureException` accepte tout et n'envoie rien.
        //
        // Le défaut n'est PAS que le DSN manque — le laisser vide en local est
        // légitime. Le défaut est que RIEN ne le disait : on croyait dix points
        // de capture branchés sur une supervision qui n'existait pas.
        //
        // (Mesure du 2026-08-22 : neuf appels dans `WaterfallOrchestrator`, un
        // dans `RefreshAudienceChunkJob`. Le registre d'audit en annonçait onze
        // — il comptait l'exemple d'usage du docblock ci-dessus.)
        //
        // On ne rétablit pas la capture ici (poser un DSN est une décision
        // d'exploitation, et il transporte des données d'entreprise vers un
        // service tiers, cf. le contexte `company` plus bas) : on rend
        // l'absence AUDIBLE.
        $dsn = config('sentry.dsn');
        if (! is_string($dsn) || trim($dsn) === '') {
            if (! self::$absenceDeDsnDejaSignalee) {
                self::$absenceDeDsnDejaSignalee = true;
                Log::warning(
                    'Capture des exceptions INACTIVE : SENTRY_LARAVEL_DSN est vide. '
                    . 'Les exceptions de la waterfall ne partent nulle part. '
                    . 'Geste : renseigner SENTRY_LARAVEL_DSN dans le .env du serveur '
                    . '(cf. .env.example), puis redemarrer le conteneur.',
                    ['service' => $service, 'constat' => 'C18-014'],
                );
            }

            return;
        }

        try {
            \Sentry\configureScope(function (Scope $scope) use ($company, $service) {
                $scope->setTag('service', $service);
                $scope->setTag('layer', 'waterfall');
                if ($company !== null) {
                    $scope->setContext('company', [
                        'id' => $company->id ?? null,
                        'siren' => $company->siren ?? null,
                        'workspace_id' => $company->workspace_id ?? null,
                        'denomination' => $company->denomination ?? null,
                    ]);
                }
            });
            \Sentry\captureException($throwable);
        } catch (\Throwable $sentryFailure) {
            // Sentry lui-même casse → on ne propage pas, on log discrètement
            Log::debug('Sentry capture failed', [
                'sentry_error' => $sentryFailure->getMessage(),
                'original' => $throwable->getMessage(),
            ]);
        }
    }
}
