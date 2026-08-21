<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Audit append-only avec chaîne cryptographique vérifiable.
 *   hash_n = sha256( hash_(n-1) || canonical_json(row_n) || secret )
 *
 * INVARIANT CENTRAL : la forme canonicalisée à l'ÉCRITURE doit être exactement
 * celle recalculée à la RELECTURE. C'est le contrat que `canonical()` garantit :
 * il accepte la forme « colonnes de la table » (event_type / status_code) et
 * tolère la forme « appelant » (method / status) pour rester rétro-compatible.
 * Sans cet alignement, `verifyChain()` retourne false sur une chaîne pourtant
 * intacte — et l'alerte d'intégrité ne veut plus rien dire.
 */
class AuditHashChain
{
    /**
     * Sentinelle du maillon zéro. 64 zéros hex = même largeur qu'un SHA-256,
     * ce qui préserve l'invariant « prev_hash est toujours un digest 64-hex ».
     * NB : la colonne `audit_logs.prev_hash` porte encore un DEFAULT SQL
     * 'GENESIS' hérité du schéma initial ; il est inatteignable, ce service
     * fournit toujours prev_hash explicitement.
     */
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Valeur historiquement livree PAR DEFAUT DANS LE CODE SOURCE.
     *
     * Un secret ecrit dans un depot n'est pas un secret : quiconque lit le code
     * peut recalculer toute la chaine. On la garde nommee pour la RECONNAITRE et
     * la refuser, pas pour s'en servir.
     */
    public const SECRET_DE_DEVELOPPEMENT = 'dev-only-secret-change-me';

    private string $secret;

    /** N'alerte qu'une fois par processus : sinon le journal noie son propre signal. */
    private static bool $alerteEmise = false;

    public function __construct()
    {
        // Constat P5-HMAC-002, deuxieme cas. Ce secret etait lu par `env()`
        // brut, sans entree `config/` -- voir `config/services.php`.
        $this->secret = (string) config('services.audit.hash_chain_secret', '');
    }

    /**
     * Le secret est-il utilisable ? Sinon la chaine ne prouve RIEN.
     *
     * 🔴 Mesure le 2026-08-19 (audit 360, B16-001, S0) : le code livrait par
     * defaut une valeur PUBLIQUE, ecrite en clair dans un depot public. Quiconque
     * peut lire cette ligne peut forger n'importe quel maillon : la chaine cesse
     * d'etre une preuve et devient une decoration.
     *
     * ⚠️ RECTIFICATION du 2026-08-20. Ce commentaire affirmait que le secret
     * « est la CHAINE VIDE en production ». C'est FAUX, et c'etait invérifiable
     * par celui qui l'a ecrit : il n'a aucun acces au serveur. L'agent 40, qui
     * l'avait, a mesure DEUX FOIS sur l'application de production en marche,
     * sans jamais afficher la valeur : longueur 64 caracteres, differente de la
     * chaine vide ET de la valeur de developpement. Le constat B16-001 est donc
     * REFUTE POUR LA PRODUCTION au registre.
     *
     * Le defaut que ce code corrige subsiste entierement : il ne porte pas sur
     * la valeur du secret, mais sur CE QUE LA VERIFICATION REPOND quand le
     * secret manque. Elle repondait `valid: true`. Un controle d'integrite qui
     * affirme « tout va bien » sans pouvoir le savoir est pire qu'un controle
     * absent : il endort celui qui le lit.
     */
    public function secretEstUtilisable(): bool
    {
        return $this->secret !== '' && $this->secret !== self::SECRET_DE_DEVELOPPEMENT;
    }

    /** Pourquoi le secret est refuse, en clair, pour l'exploitant. */
    public function raisonSecretInutilisable(): ?string
    {
        if ($this->secret === '') {
            return "AUDIT_HASH_CHAIN_SECRET n'est pas defini : la chaine d'audit ne prouve rien.";
        }

        if ($this->secret === self::SECRET_DE_DEVELOPPEMENT) {
            return 'AUDIT_HASH_CHAIN_SECRET porte encore la valeur de developpement, publiee dans le code source.';
        }

        return null;
    }

    private function signalerSecretAbsent(): void
    {
        if (self::$alerteEmise || $this->secretEstUtilisable()) {
            return;
        }

        self::$alerteEmise = true;
        Log::error("Chaine d'audit : " . $this->raisonSecretInutilisable());
    }

    /** @param  array<string,mixed>  $row */
    public function record(array $row): int
    {
        // On CONTINUE d'ecrire meme sans secret : perdre la trace serait pire que
        // de la garder faible, et ce service tourne sur chaque requete - le faire
        // echouer rendrait l'API entierement indisponible. Mais on le DIT, une
        // fois par processus, au niveau erreur.
        $this->signalerSecretAbsent();

        return (int) DB::transaction(function () use ($row) {
            // Sérialise les écritures de la chaîne : deux inserts concurrents
            // liraient le même maillon de tête et produiraient deux lignes
            // pointant sur le même prev_hash — chaîne définitivement cassée.
            // Verrou de transaction : relâché au COMMIT/ROLLBACK.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('audit_logs.hash_chain'))");

            $prev = DB::selectOne('SELECT id, current_hash FROM audit_logs ORDER BY id DESC LIMIT 1');
            $prevHash = $prev->current_hash ?? self::GENESIS_PREV_HASH;

            // On construit la ligne TELLE QU'ELLE SERA STOCKÉE, puis on hashe
            // cette forme-là : c'est la seule que `verifyChain()` relira.
            $stored = [
                'workspace_id' => $row['workspace_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'event_type' => $row['method'] ?? 'unknown',
                'path' => $row['path'] ?? null,
                'status_code' => $row['status'] ?? null,
                'ip' => $row['ip'] ?? null,
                'user_agent' => $row['user_agent'] ?? null,
                'payload_hash' => $row['payload_hash'] ?? null,
            ];

            $currentHash = hash('sha256', $prevHash . $this->canonical($stored) . $this->secret);

            return DB::table('audit_logs')->insertGetId($stored + [
                'prev_hash' => $prevHash,
                'current_hash' => $currentHash,
                'created_at' => now(),
            ]);
        });
    }

    public function verifyChain(?int $maxRows = null): bool
    {
        // 🔴 SANS SECRET UTILISABLE, ON NE REPOND JAMAIS « VALIDE ».
        //
        // C'etait la vraie tromperie : la chaine se verifiait parfaitement
        // elle-meme avec un secret vide, et l'API repondait `valid: true` sur un
        // journal que n'importe qui pouvait reecrire de bout en bout. Un controle
        // d'integrite qui dit « tout va bien » sans pouvoir le savoir est pire
        // qu'un controle absent : il endort celui qui le lit.
        if (! $this->secretEstUtilisable()) {
            $this->signalerSecretAbsent();

            return false;
        }

        $query = DB::table('audit_logs')->orderBy('id');
        if ($maxRows !== null) {
            $query->limit($maxRows);
        }

        $prevHash = self::GENESIS_PREV_HASH;
        foreach ($query->cursor() as $row) {
            // Le maillon déclaré par la ligne doit correspondre au maillon réel.
            if (! hash_equals($prevHash, (string) $row->prev_hash)) {
                return false;
            }

            $canonical = $this->canonical((array) $row);
            $expected = hash('sha256', $prevHash . $canonical . $this->secret);
            if (! hash_equals($expected, (string) $row->current_hash)) {
                return false;
            }
            $prevHash = (string) $row->current_hash;
        }

        return true;
    }

    /**
     * Représentation canonique STABLE d'une ligne d'audit.
     *
     * Accepte indifféremment la forme « colonnes » (event_type, status_code) et
     * la forme « appelant » (method, status). Les valeurs sont normalisées en
     * type PHP fixe : Postgres peut rendre un SMALLINT en chaîne selon le
     * driver, et json_encode(200) !== json_encode("200").
     *
     * @param  array<string,mixed>  $row
     */
    private function canonical(array $row): string
    {
        $payload = [
            'workspace_id' => self::normalizeId($row['workspace_id'] ?? null),
            'user_id' => self::normalizeId($row['user_id'] ?? null),
            'method' => self::normalizeText($row['event_type'] ?? $row['method'] ?? null),
            'path' => self::normalizeText($row['path'] ?? null),
            'status' => self::normalizeInt($row['status_code'] ?? $row['status'] ?? null),
            'ip' => self::normalizeText($row['ip'] ?? null),
            'payload_hash' => self::normalizeText($row['payload_hash'] ?? null),
        ];
        ksort($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** UUID : Postgres restitue toujours en minuscules, on aligne l'écriture. */
    private static function normalizeId(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return strtolower((string) $v);
    }

    private static function normalizeText(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function normalizeInt(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
