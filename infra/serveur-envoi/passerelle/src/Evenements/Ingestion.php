<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

use Passerelle\Stockage;

/** Ingestion du log hook (K7) : un enregistrement déjà classé par le relais -> un événement numéroté par K2. */
final class Ingestion
{
    public const TYPES = ['reception', 'livre', 'rebond_dur', 'rebond_mou', 'report'];

    /** Les 50 premiers rebonds durs de chaque espace sont en observation (aucune action automatique). */
    public const OBSERVATION_MAX = 50;

    /** @param \Closure(string,array<string,string>):mixed $alerte */
    public function __construct(
        private readonly Stockage $stockage,
        private readonly JetonsConfirmation $jetons,
        private readonly \Closure $alerte,
    ) {}

    /**
     * @param array<string,mixed> $e
     * @return array{statut:'ingere'|'doublon',seq:?int,trou:bool}
     */
    public function ingerer(string $ws, array $e): array
    {
        $type = $e['type'] ?? null;
        $src = $e['source'] ?? null;
        $seq = $e['src_seq'] ?? null;
        $id = $e['id_kumo'] ?? null;
        if (! in_array($type, self::TYPES, true) || ! is_string($src) || $src === '' || strlen($src) > 64
            || ! is_int($seq) || $seq < 1 || ! is_string($id) || $id === '' || strlen($id) > 128) {
            throw new \InvalidArgumentException('enregistrement du log hook invalide');
        }
        $jeton = $e['jeton'] ?? null;
        $jeton = is_string($jeton) && preg_match('/^[A-Za-z0-9_-]{32,128}$/', $jeton) === 1 ? $jeton : null;
        $r = $this->stockage->ingererLoghook($ws, [
            'source' => $src, 'src_seq' => $seq, 'id_kumo' => $id, 'type' => $type,
            'id_injection' => is_string($e['id_injection'] ?? null) ? substr($e['id_injection'], 0, 128) : '',
            'destinataire_empreinte' => is_string($e['destinataire_empreinte'] ?? null) ? substr($e['destinataire_empreinte'], 0, 64) : '',
            'jeton' => $type === 'reception' ? $jeton : null,
        ], self::OBSERVATION_MAX);
        if ($r['trou']) {
            ($this->alerte)('loghook_trou_source', ['source' => $src]);
        }
        if ($r['statut'] === 'ingere' && $type === 'reception' && $jeton !== null) {
            try {
                if ($this->jetons->confirmer($ws, $jeton, $id)) {
                    $this->stockage->journaliserLoghook($ws, 'jeton_confirme', $id, 'reserve->confirme');
                }
            } catch (\Throwable) {
                // La séquence n'attend jamais K6 : l'événement est déjà numéroté ; le jeton reste « en attente » au journal.
                ($this->alerte)('loghook_jeton_echec', ['source' => $src]);
            }
        }

        return $r;
    }
}
