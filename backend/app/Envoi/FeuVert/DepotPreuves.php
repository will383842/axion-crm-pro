<?php

namespace App\Envoi\FeuVert;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Preuves des points MANUELS (ajout seul). Un point automatique n'accepte jamais de preuve.
 * Défaire un point = nouvelle ligne `fait = false` ; la dernière ligne par code fait foi.
 */
class DepotPreuves
{
    public const MAX = 500;

    public function ajouter(string $workspaceId, string $code, string $preuve, string $auteur): void
    {
        $this->inscrire($workspaceId, $code, $preuve, $auteur, true);
    }

    /** Défait un point (nouvelle ligne ; rien n'est effacé). Le motif est obligatoire. */
    public function defaire(string $workspaceId, string $code, string $motif, string $auteur): void
    {
        $this->inscrire($workspaceId, $code, $motif, $auteur, false);
    }

    private function inscrire(string $workspaceId, string $code, string $texte, string $auteur, bool $fait): void
    {
        if (! array_key_exists($code, CatalogueManuels::POINTS)) {
            throw new InvalidArgumentException('Ce point n’accepte pas de preuve (automatique ou inconnu).');
        }
        $texte = self::nettoyer($texte);
        $auteur = self::nettoyer($auteur);
        if ($texte === '' || mb_strlen($texte) > self::MAX) {
            throw new InvalidArgumentException('La preuve doit faire de 1 à ' . self::MAX . ' caractères.');
        }
        if (str_contains($texte, '@')) {
            throw new InvalidArgumentException('La preuve ne doit contenir aucune adresse e-mail.');
        }
        if ($auteur === '' || mb_strlen($auteur) > 100 || str_contains($auteur, '@')) {
            throw new InvalidArgumentException('Auteur invalide (1 à 100 caractères, sans adresse e-mail).');
        }
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_feu_vert_preuves')->insert([
            'workspace_id' => $workspaceId, 'code' => $code, 'preuve' => $texte, 'auteur' => $auteur, 'fait' => $fait,
        ]));
    }

    /** Retire les blancs de bord, y compris Unicode (espace insécable, séparateurs, caractères de contrôle). */
    private static function nettoyer(string $s): string
    {
        $r = preg_replace('/^[\s\p{Z}\p{C}]+|[\s\p{Z}\p{C}]+$/u', '', $s);
        if ($r === null) {
            throw new InvalidArgumentException('Texte invalide (UTF-8 attendu).');
        }

        return $r;
    }

    /** @return array<string, array{preuve: string, fait: bool}> dernière ligne de chaque code */
    public function dernieres(string $workspaceId): array
    {
        $l = WorkspaceContext::run($workspaceId, fn () => DB::select(
            'SELECT DISTINCT ON (code) code, preuve, fait FROM envoi_feu_vert_preuves WHERE workspace_id = ? ORDER BY code, id DESC',
            [$workspaceId],
        ));
        $r = [];
        foreach ($l as $ligne) {
            $r[(string) $ligne->code] = ['preuve' => (string) $ligne->preuve, 'fait' => (bool) $ligne->fait];
        }

        return $r;
    }
}
