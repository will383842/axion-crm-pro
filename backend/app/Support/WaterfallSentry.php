<?php

namespace App\Support;

use App\Models\Company;

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
 */
class WaterfallSentry
{
    public static function capture(?Company $company, string $service, \Throwable $throwable): void
    {
        if (! class_exists(\Sentry\State\Hub::class)) {
            return;
        }

        try {
            \Sentry\configureScope(function (\Sentry\State\Scope $scope) use ($company, $service) {
                $scope->setTag('service', $service);
                $scope->setTag('layer', 'waterfall');
                if ($company !== null) {
                    $scope->setContext('company', [
                        'id'           => $company->id ?? null,
                        'siren'        => $company->siren ?? null,
                        'workspace_id' => $company->workspace_id ?? null,
                        'denomination' => $company->denomination ?? null,
                    ]);
                }
            });
            \Sentry\captureException($throwable);
        } catch (\Throwable $sentryFailure) {
            // Sentry lui-même casse → on ne propage pas, on log discrètement
            \Illuminate\Support\Facades\Log::debug('Sentry capture failed', [
                'sentry_error' => $sentryFailure->getMessage(),
                'original'     => $throwable->getMessage(),
            ]);
        }
    }
}
