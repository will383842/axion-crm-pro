<?php

namespace App\ProspectionLinkedIn;

use App\Support\WorkspaceContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * PONT-LINKEDIN LI-B1 — SEUL écrivain de `pl_journal` (ajout seul, idempotent par clé). Les `details` passent par une liste
 * blanche de clés ; une valeur qui contient une adresse (« @ ») ou qui est trop longue est écartée. Rien ici n'appelle LinkedIn.
 */
final class JournalProspection
{
    public const CLES_AUTORISEES = ['motif', 'note', 'source', 'variante', 'rapprochement_ambigu', 'canal'];

    /** @var list<string> */
    private const EVENEMENTS = [
        'ajout', 'controle_refuse', 'arret', 'reprise', 'plafonds', 'alerte', 'invitation_faite', 'invitation_acceptee', 'message_fait',
        'a_repondu', 'invitation_retiree', 'plus_tard', 'interesse', 'rdv_pris', 'non', 'hors_cible', 'opposition',
    ];

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, scalar>
     */
    public static function filtrer(array $details): array
    {
        $sortie = [];
        foreach (self::CLES_AUTORISEES as $cle) {
            if (! array_key_exists($cle, $details)) {
                continue;
            }
            $v = $details[$cle];
            if (is_bool($v) || is_int($v)) {
                $sortie[$cle] = $v;
            } elseif (is_string($v) && mb_strlen($v) <= 200 && ! str_contains($v, '@')) {
                $sortie[$cle] = $v;
            }
        }

        return $sortie;
    }

    /**
     * Écrit une ligne ; `false` si la clé d'idempotence existe déjà dans l'espace (rien d'écrit).
     *
     * @param  array<string, mixed>  $details
     */
    public function ecrire(
        string $workspaceId,
        ?string $prospectId,
        string $evenement,
        string $origine,
        string $auteur,
        string $cle,
        array $details = [],
        ?string $etatAvant = null,
        ?string $etatApres = null,
        bool $sansEffet = false,
    ): bool {
        if (! in_array($evenement, self::EVENEMENTS, true) || ! in_array($origine, ['humain', 'systeme'], true)) {
            throw new InvalidArgumentException('Événement ou origine de journal inconnu.');
        }
        if (trim($auteur) === '' || mb_strlen($cle) < 16 || mb_strlen($cle) > 128) {
            throw new InvalidArgumentException('Auteur requis et clé d’idempotence de 16 à 128 caractères.');
        }

        try {
            return WorkspaceContext::run($workspaceId, static fn (): bool => DB::table('pl_journal')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'prospect_id' => $prospectId,
                'evenement' => $evenement,
                'origine' => $origine,
                'auteur' => $auteur,
                'cle_idempotence' => $cle,
                'details' => json_encode(self::filtrer($details), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'etat_avant' => $etatAvant,
                'etat_apres' => $etatApres,
                'sans_effet' => $sansEffet,
            ]) === 1);
        } catch (QueryException) {
            throw new RuntimeException('Échec de l’écriture du journal de prospection.');
        }
    }

    /** La ligne portant cette clé dans l'espace, ou null. */
    public function trouver(string $workspaceId, string $cle): ?\stdClass
    {
        try {
            return WorkspaceContext::run($workspaceId, static fn (): ?\stdClass => DB::table('pl_journal')
                ->where('workspace_id', $workspaceId)->where('cle_idempotence', $cle)->first());
        } catch (QueryException) {
            throw new RuntimeException('Journal de prospection illisible.');
        }
    }
}
