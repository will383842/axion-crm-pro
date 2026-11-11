<?php

namespace App\Envoi\FeuVert;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Preuves des points MANUELS (ajout seul). Un point automatique n'accepte jamais de preuve.
 * Défaire un point = nouvelle ligne `fait = false` ; « dernière ligne par code » suit l'ORDRE D'ATTRIBUTION DES id
 * (séquence), pas l'heure de validation des transactions.
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
        if (! CatalogueManuels::estManuel($code)) {
            throw new InvalidArgumentException('Ce point n’accepte pas de preuve (automatique ou inconnu).');
        }
        $texte = self::nettoyer($texte);
        $auteur = self::nettoyer($auteur);
        if (preg_match('/\p{Cc}/u', $texte . $auteur) === 1) {
            throw new InvalidArgumentException('Caractère de contrôle interdit (preuve ou auteur).');
        }
        if ($texte === '' || mb_strlen($texte) > self::MAX) {
            throw new InvalidArgumentException('La preuve doit faire de 1 à ' . self::MAX . ' caractères.');
        }
        if (self::aArobase($texte)) {
            throw new InvalidArgumentException('La preuve ne doit contenir aucune adresse e-mail.');
        }
        if ($auteur === '' || mb_strlen($auteur) > 100 || self::aArobase($auteur)) {
            throw new InvalidArgumentException('Auteur invalide (1 à 100 caractères, sans adresse e-mail).');
        }
        if (! $fait && ! WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_feu_vert_preuves')->where('workspace_id', $workspaceId)->where('code', $code)->where('fait', true)->exists())) {
            throw new InvalidArgumentException('Ce point n’a jamais été fait : rien à défaire.');
        }
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_feu_vert_preuves')->insert([
            'workspace_id' => $workspaceId, 'code' => $code, 'preuve' => $texte, 'auteur' => $auteur, 'fait' => $fait,
        ]));
    }

    /** Arobase ASCII, pleine largeur (U+FF20) ou petite arobase (U+FE6B) : aucune adresse e-mail, même déguisée. */
    private static function aArobase(string $s): bool
    {
        return preg_match('/[@\x{FF20}\x{FE6B}]/u', $s) === 1;
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
