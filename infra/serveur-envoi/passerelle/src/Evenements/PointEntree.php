<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

/**
 * Point d'entrée LOCAL du log hook : 127.0.0.1 NE SUFFIT PAS (tout processus de la machine, ou un proxy local, y
 * arrive). Il faut AUSSI le secret partagé local (en-tête, comparaison en temps constant, fourni hors dépôt) et une
 * source de la liste fermée. Secret serveur vide = tout est refusé.
 */
final class PointEntree
{
    /** @param list<string> $sources liste fermée des valeurs de `source` acceptées */
    public function __construct(private readonly Ingestion $ingestion, private readonly string $secret, private readonly array $sources) {}

    /** @return array{0:int,1:string} statut HTTP et corps JSON */
    public function traiter(string $adresseDistante, string $corps, string $secretRecu = ''): array
    {
        if ($adresseDistante !== '127.0.0.1' || strlen($this->secret) < 32 || ! hash_equals($this->secret, $secretRecu)) {
            return [403, '{"erreur":"interdit"}'];
        }
        $d = strlen($corps) <= 65536 ? json_decode($corps, true, 8) : null;
        $ws = is_array($d) ? ($d['workspace_id'] ?? null) : null;
        if (! is_array($d) || ! is_string($ws) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $ws) !== 1) {
            return [400, '{"erreur":"requete"}'];
        }
        if (! is_string($d['source'] ?? null) || ! in_array($d['source'], $this->sources, true)) {
            return [403, '{"erreur":"source"}'];
        }
        try {
            $r = $this->ingestion->ingerer($ws, $d);
        } catch (\InvalidArgumentException) {
            return [400, '{"erreur":"requete"}'];
        } catch (\Throwable) {
            return [503, '{"erreur":"indisponible"}']; // le relais réessaie : la passerelle dédoublonne
        }

        try {
            $this->ingestion->reconfirmer($ws); // jetons encore « en attente » : rejoués à chaque passage
        } catch (\Throwable) {
            // Sans effet sur l'ingestion déjà faite.
        }

        return [200, json_encode(['statut' => $r['statut']], JSON_THROW_ON_ERROR)];
    }
}
