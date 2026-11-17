<?php

namespace App\Envoi\Desinscription;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Met une adresse (par son empreinte) à l'écart : un lien par adresse (réutilisé au rejeu), puis UNE opposition
 * (`EnregistrerOpposition`, idempotente). N'efface rien, ne lit ni n'écrit d'adresse en clair. Le canal dit d'où vient le geste.
 */
final class OpposerAdresse
{
    public function __construct(private readonly EnregistrerOpposition $opposition) {}

    public function executer(string $ws, string $empreinte, string $canal): void
    {
        $reference = DB::transaction(function () use ($ws, $empreinte): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['envoi_stop|' . $ws . '|' . $empreinte]);
            $lien = DB::table('envoi_liens_desinscription')->where('workspace_id', $ws)->where('empreinte_adresse', $empreinte)->orderBy('id')->first();
            if ($lien !== null) {
                return (string) $lien->reference;
            }
            $reference = (string) Str::uuid();
            DB::table('envoi_liens_desinscription')->insert([
                'workspace_id' => $ws, 'reference' => $reference, 'empreinte_adresse' => $empreinte, 'portee' => 'business',
            ]);

            return $reference;
        });
        $this->opposition->executer($ws, $reference, $canal);
    }
}
