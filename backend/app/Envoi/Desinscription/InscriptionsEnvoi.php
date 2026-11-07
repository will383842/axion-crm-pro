<?php

namespace App\Envoi\Desinscription;

use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Crée l'inscription à laquelle un lien de désinscription est lié. */
final class InscriptionsEnvoi
{
    /** @return string la référence opaque (UUID) à placer dans le jeton */
    public static function creer(string $workspaceId, string $adresse, string $portee = 'business'): string
    {
        $adresse = mb_strtolower(trim($adresse));
        $reference = (string) Str::uuid();
        WorkspaceContext::run($workspaceId, function () use ($workspaceId, $adresse, $portee, $reference): void {
            DB::table('envoi_inscriptions')->insert([
                'workspace_id' => $workspaceId,
                'reference' => $reference,
                'adresse' => $adresse,
                'empreinte_adresse' => ListeSuppression::empreinte($adresse),
                'portee' => $portee,
            ]);
        });

        return $reference;
    }

    /** Lecture seule : cette référence existe-t-elle dans cet espace ? */
    public static function existe(string $workspaceId, string $reference): bool
    {
        return WorkspaceContext::run($workspaceId, fn (): bool => DB::table('envoi_inscriptions')->where('reference', $reference)->exists());
    }
}
