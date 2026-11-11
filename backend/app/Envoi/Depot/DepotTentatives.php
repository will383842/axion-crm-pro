<?php

namespace App\Envoi\Depot;

use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Écriture des tentatives d'envoi (R06). À appeler DANS la transaction de conclusion : annulée avec elle.
 * Idempotent : la même clé (celle de la réservation) ne crée jamais une seconde ligne.
 */
final class DepotTentatives
{
    public const ACCEPTEE = 'acceptee';

    public const REPORTEE = 'reportee';

    public const REFUSEE = 'refusee';

    /** @return bool vrai si une ligne a été ajoutée (faux : rejeu, ou fournisseur non classé) */
    public function noter(string $workspaceId, string $fournisseur, string $issue, string $cleIdempotence, DateTimeImmutable $instant, ?int $codeSmtp = null): bool
    {
        if (! in_array($issue, [self::ACCEPTEE, self::REPORTEE, self::REFUSEE], true)) {
            throw new InvalidArgumentException('Issue de tentative inconnue.');
        }
        // Fournisseur « inconnu » (échec DNS) : déjà bloqué par toute pause de fournisseur, rien à compter ici.
        if (! in_array($fournisseur, ['gmail', 'microsoft', 'autre'], true)) {
            return false;
        }
        $code = ($codeSmtp !== null && $codeSmtp >= 200 && $codeSmtp <= 599) ? $codeSmtp : null;

        return WorkspaceContext::run($workspaceId, fn (): bool => DB::affectingStatement(
            'INSERT INTO envoi_tentatives (workspace_id, fournisseur, issue, code_smtp, cle_idempotence, cree_le)
             VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (workspace_id, cle_idempotence) DO NOTHING',
            [$workspaceId, $fournisseur, $issue, $code, mb_substr($cleIdempotence, 0, 300), $instant->format('Y-m-d H:i:sP')],
        ) > 0);
    }
}
