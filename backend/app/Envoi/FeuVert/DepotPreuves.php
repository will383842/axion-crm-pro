<?php

namespace App\Envoi\FeuVert;

use App\Support\WorkspaceContext;
use Carbon\CarbonImmutable;
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

    /** Longueur minimale d'une preuve, après nettoyage. */
    public const MIN = 10;

    /** Libellé neutre quand l'auteur n'a pas de nom affichable (jamais une adresse e-mail). */
    public const AUTEUR_NEUTRE = 'responsable';

    /** Phrases génériques refusées, comparées sans casse, sans accent de ponctuation ni apostrophe (voir `generique()`). */
    private const GENERIQUES = ['faitconstatedepuislecran', 'faitconstate', 'constatedepuislecran', 'depuislecran', 'faitdepuislecran', 'verifie', 'verifiedepuislecran'];

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
        if (! mb_check_encoding($texte . $auteur, 'UTF-8')) {
            throw new InvalidArgumentException('Texte invalide (UTF-8 attendu).');
        }
        // Les caractères de format (U+200B, U+202E, U+FEFF…) sont refusés AVANT le nettoyage, qui les rognerait en silence.
        if (preg_match('/\p{Cf}/u', $texte . $auteur) === 1) {
            throw new InvalidArgumentException('Caractère de format interdit (preuve ou auteur).');
        }
        $texte = self::nettoyer($texte);
        $auteur = self::nettoyer($auteur);
        if (preg_match('/\p{Cc}/u', $texte . $auteur) === 1) {
            throw new InvalidArgumentException('Caractère de contrôle interdit (preuve ou auteur).');
        }
        if (mb_strlen($texte) < self::MIN || mb_strlen($texte) > self::MAX) {
            throw new InvalidArgumentException('La preuve doit faire de ' . self::MIN . ' à ' . self::MAX . ' caractères.');
        }
        if (self::generique($texte)) {
            throw new InvalidArgumentException('Preuve trop générique : décrire ce qui a été constaté (date, référence, source).');
        }
        if (self::ressembleAUneAdresse($texte)) {
            throw new InvalidArgumentException('La preuve ne doit contenir aucune adresse e-mail.');
        }
        if ($auteur === '' || mb_strlen($auteur) > 100 || self::ressembleAUneAdresse($auteur)) {
            throw new InvalidArgumentException('Auteur invalide (1 à 100 caractères, sans adresse e-mail).');
        }
        // Défaire : la DERNIÈRE ligne du point doit être « fait » (pas seulement qu'une ligne fait ait existé un jour).
        if (! $fait && ! (self::derniere($workspaceId, $code)['fait'] ?? false)) {
            throw new InvalidArgumentException('Ce point n’est pas fait actuellement : rien à défaire.');
        }
        WorkspaceContext::run($workspaceId, fn () => DB::table('envoi_feu_vert_preuves')->insert([
            'workspace_id' => $workspaceId, 'code' => $code, 'preuve' => $texte, 'auteur' => $auteur, 'fait' => $fait,
        ]));
    }

    /** Nom affiché d'un utilisateur : son nom s'il est présentable, sinon un libellé neutre. Jamais une adresse e-mail. */
    public static function auteurAffichable(?string $nom): string
    {
        if ($nom === null || ! mb_check_encoding($nom, 'UTF-8') || preg_match('/\p{Cf}/u', $nom) === 1) {
            return self::AUTEUR_NEUTRE;
        }
        try {
            $n = self::nettoyer($nom);
        } catch (InvalidArgumentException) {
            return self::AUTEUR_NEUTRE;
        }

        return $n === '' || mb_strlen($n) > 100 || preg_match('/\p{Cc}/u', $n) === 1 || self::ressembleAUneAdresse($n) ? self::AUTEUR_NEUTRE : $n;
    }

    /** Phrase générique (dont la phrase fixe de l'écran) : on ne garde que lettres et chiffres, sans casse ni accent. */
    private static function generique(string $s): bool
    {
        $r = preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($s));
        $r = strtr((string) $r, ['é' => 'e', 'è' => 'e', 'ê' => 'e']);

        return in_array($r, self::GENERIQUES, true);
    }

    /**
     * Adresse e-mail, même déguisée : arobase ASCII, pleine chasse (U+FF20) ou petite (U+FE6B) quel que soit
     * l'espacement, « arobase » en lettres, ou la forme ÉPELÉE « <nom> chez <domaine> point <fin> » (« ouvert chez ovh.com » reste accepté).
     */
    private static function ressembleAUneAdresse(string $s): bool
    {
        if (! mb_check_encoding($s, 'UTF-8')) {
            return true;
        }
        $mot = '[\p{L}\p{N}_-]+';

        return preg_match('/[@\x{FF20}\x{FE6B}]/u', $s) === 1
            || preg_match('/arobase/iu', $s) === 1
            || preg_match('/(^|[^\p{L}\p{N}])chez\s+' . $mot . '\s+(point|dot)\s+\p{L}{2,}/iu', $s) === 1;
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

    /** @return array{preuve: string, fait: bool, par: string, le: string}|null dernière ligne d'un code */
    public static function derniere(string $workspaceId, string $code): ?array
    {
        return self::dernieresDe($workspaceId, $code)[$code] ?? null;
    }

    /** @return array<string, array{preuve: string, fait: bool, par: string, le: string}> dernière ligne de chaque code */
    public function dernieres(string $workspaceId): array
    {
        return self::dernieresDe($workspaceId, null);
    }

    /** @return array<string, array{preuve: string, fait: bool, par: string, le: string}> */
    private static function dernieresDe(string $workspaceId, ?string $code): array
    {
        $l = WorkspaceContext::run($workspaceId, fn () => DB::select(
            'SELECT DISTINCT ON (code) code, preuve, fait, auteur, cree_le FROM envoi_feu_vert_preuves WHERE workspace_id = ?'
            . ($code === null ? '' : ' AND code = ?') . ' ORDER BY code, id DESC',
            $code === null ? [$workspaceId] : [$workspaceId, $code],
        ));
        $r = [];
        foreach ($l as $ligne) {
            $r[(string) $ligne->code] = [
                'preuve' => (string) $ligne->preuve,
                'fait' => (bool) $ligne->fait,
                'par' => (string) $ligne->auteur,
                'le' => CarbonImmutable::parse((string) $ligne->cree_le)->utc()->format('Y-m-d\TH:i:s\Z'),
            ];
        }

        return $r;
    }
}
