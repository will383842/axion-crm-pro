<?php

namespace App\Envoi\Imap;

use Illuminate\Support\Facades\DB;

/**
 * Retrouve l'adresse d'un destinataire à partir de son EMPREINTE (SHA-256 de l'adresse en minuscules), dans les
 * contacts de l'espace. Lecture seule ; l'adresse reste en mémoire (jamais journalisée). Introuvable => null :
 * le retour reste « à rattacher » (revue humaine), jamais d'effet deviné.
 */
final class AdresseParEmpreinteCrm
{
    public static function trouver(string $workspaceId, string $empreinte): ?string
    {
        $adresse = DB::table('contacts')->where('workspace_id', $workspaceId)->whereNull('deleted_at')->whereNotNull('email')
            ->whereRaw("encode(sha256(convert_to(lower(btrim(email::text)), 'UTF8')), 'hex') = ?", [$empreinte])
            ->orderBy('id')->value('email');

        return $adresse === null ? null : (string) $adresse;
    }
}
