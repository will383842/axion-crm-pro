<?php

declare(strict_types=1);

namespace Passerelle\Evenements;

use Passerelle\Stockage;

/** Ingestion du log hook (K7) : un enregistrement déjà classé par le relais -> un événement numéroté par K2. */
final class Ingestion
{
    public const TYPES = ['reception', 'livre', 'rebond_dur', 'rebond_mou', 'report', 'inconnu', 'alerte_config'];

    /** Un trou de numéro source n'est jugé qu'après ce délai (secondes) : désordre et rejeu ne sont pas des trous. */
    public const DELAI_TROU = 600;

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
     * @return array{statut:'ingere'|'doublon',seq:?int}
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
        $brut = $e['jeton'] ?? null;
        $jeton = is_string($brut) && preg_match('/^[A-Za-z0-9_-]{32,128}$/', $brut) === 1 ? $brut : null;
        if ($type === 'reception' && $brut !== null && $jeton === null) {
            ($this->alerte)('loghook_jeton_malforme', ['source' => $src]);
        }
        $r = $this->stockage->ingererLoghook($ws, [
            'source' => $src, 'src_seq' => $seq, 'id_kumo' => $id, 'type' => $type,
            'id_injection' => is_string($e['id_injection'] ?? null) ? substr($e['id_injection'], 0, 128) : '',
            'destinataire_empreinte' => is_string($e['destinataire_empreinte'] ?? null) ? substr($e['destinataire_empreinte'], 0, 64) : '',
            'jeton' => $type === 'reception' ? $jeton : null,
        ], self::OBSERVATION_MAX);
        if ($r['statut'] === 'ingere' && $type === 'alerte_config') {
            ($this->alerte)('loghook_alerte_config', ['source' => $src]); // ex. 5.7.26 : SPF/DKIM/DMARC à corriger
        }
        if ($r['statut'] === 'ingere' && $type === 'inconnu') {
            $this->stockage->journaliserLoghook($ws, 'inconnu', $id, 'bounce sans règle');
        }
        foreach ($this->stockage->trousSourceAnciens($ws, $src, self::DELAI_TROU) as $t) {
            ($this->alerte)('loghook_trou_source', ['source' => $src, 'de' => (string) $t[0], 'a' => (string) $t[1]]);
        }
        // Reception (premier passage OU rejeu d'un doublon) : tant que le jeton n'est pas confirmé, on retente.
        if ($type === 'reception' && $jeton !== null) {
            $this->confirmerJeton($ws, $jeton, $id, $src);
        }

        return $r;
    }

    /** Rejoue la confirmation des jetons encore « en attente » (K6 revenu, ou K6a enfin fusionné). Rend le nombre confirmé. */
    public function reconfirmer(string $ws, int $limite = 20): int
    {
        $n = 0;
        foreach ($this->stockage->jetonsEnAttente($ws, $limite) as $j) {
            $n += $this->confirmerJeton($ws, $j['jeton'], $j['id_kumo'], '-') ? 1 : 0;
        }

        return $n;
    }

    private function confirmerJeton(string $ws, string $jeton, string $idMessage, string $src): bool
    {
        if ($this->dejaConfirme($ws, $idMessage)) {
            return false;
        }
        try {
            $ok = $this->jetons->confirmer($ws, $jeton, $idMessage); // idempotent : true = confirmé (déjà ou à l'instant)
        } catch (\Throwable) {
            ($this->alerte)('loghook_jeton_echec', ['source' => $src]); // le jeton reste « en attente » : il sera rejoué

            return false;
        }
        if (! $ok) {
            return false;
        }
        try {
            $this->stockage->journaliserLoghook($ws, 'jeton_confirme', $idMessage, 'reserve->confirme');
        } catch (\Throwable) {
            // K6 a confirmé : pas de fausse alerte « échec ». Au pire le jeton sera rejoué (confirmer est idempotent).
        }

        return true;
    }

    private function dejaConfirme(string $ws, string $idMessage): bool
    {
        return $this->stockage->jetonDejaConfirme($ws, $idMessage);
    }
}
