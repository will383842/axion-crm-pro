<?php

namespace App\Envoi\FeuVert;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Preuves des points MANUELS (ajout seul). Un point automatique n'accepte jamais de preuve. */
class DepotPreuves
{
    public const MAX = 500;

    public function ajouter(string $workspaceId, string $code, string $preuve, string $auteur): void
    {
        if (! array_key_exists($code, ListeFeuVert::MANUELS)) {
            throw new InvalidArgumentException('Ce point n’accepte pas de preuve (automatique ou inconnu).');
        }
        $preuve = trim($preuve);
        $auteur = trim($auteur);
        if ($preuve === '' || mb_strlen($preuve) > self::MAX) {
            throw new InvalidArgumentException('La preuve doit faire de 1 à ' . self::MAX . ' caractères.');
        }
        if (str_contains($preuve, '@')) {
            throw new InvalidArgumentException('La preuve ne doit contenir aucune adresse e-mail.');
        }
        if ($auteur === '' || mb_strlen($auteur) > 100) {
            throw new InvalidArgumentException('Auteur invalide.');
        }
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_feu_vert_preuves')->insert([
            'workspace_id' => $workspaceId, 'code' => $code, 'preuve' => $preuve, 'auteur' => $auteur,
        ]));
    }

    /** @return array<string, string> dernière preuve de chaque code */
    public function dernieres(string $workspaceId): array
    {
        $l = WorkspaceContext::run($workspaceId, fn () => DB::select(
            'SELECT DISTINCT ON (code) code, preuve FROM envoi_feu_vert_preuves WHERE workspace_id = ? ORDER BY code, id DESC',
            [$workspaceId],
        ));
        $r = [];
        foreach ($l as $ligne) {
            $r[(string) $ligne->code] = (string) $ligne->preuve;
        }

        return $r;
    }
}
