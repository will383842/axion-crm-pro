<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

/** Point d'entrée LOCAL du log hook (127.0.0.1 seulement, pas de HMAC : joignable depuis la machine uniquement). */
final class PointEntree
{
    public function __construct(private readonly Ingestion $ingestion) {}

    /** @return array{0:int,1:string} statut HTTP et corps JSON */
    public function traiter(string $adresseDistante, string $corps): array
    {
        if ($adresseDistante !== '127.0.0.1') {
            return [403, '{"erreur":"interdit"}'];
        }
        $d = strlen($corps) <= 65536 ? json_decode($corps, true, 8) : null;
        $ws = is_array($d) ? ($d['workspace_id'] ?? null) : null;
        if (! is_array($d) || ! is_string($ws) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $ws) !== 1) {
            return [400, '{"erreur":"requete"}'];
        }
        try {
            $r = $this->ingestion->ingerer($ws, $d);
        } catch (\InvalidArgumentException) {
            return [400, '{"erreur":"requete"}'];
        } catch (\Throwable) {
            return [503, '{"erreur":"indisponible"}']; // le relais réessaie : la passerelle dédoublonne
        }

        return [200, json_encode(['statut' => $r['statut']], JSON_THROW_ON_ERROR)];
    }
}
