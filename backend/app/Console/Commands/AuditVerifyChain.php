<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditHashChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AuditVerifyChain extends Command
{
    protected $signature = 'audit:verify-chain {--max=}';

    protected $description = 'Vérifie l\'intégrité de la chaîne cryptographique des audit_logs.';

    /**
     * Marqueur en tete de TOUTE alerte d'integrite, quel qu'en soit le motif.
     *
     * Sans accent, deliberement : c'est la chaine sur laquelle se pose une regle
     * de supervision (grep, filtre GlitchTip, alerte Loki) — elle ne doit pas
     * dependre de l'encodage du journal qui la transporte.
     */
    public const PREFIXE_ALERTE = 'ALERTE INTEGRITE';

    /**
     * 🔴 CONSTAT B16-006 / F39-006 / B17-003 (S1) — mesure le 2026-08-20.
     *
     * Cette commande est planifiee chaque nuit a 03:00 (`routes/console.php`).
     * Elle detectait la rupture, puis ecrivait :
     *
     *     $this->error('Audit hash chain INVALIDE — possible falsification detectee.');
     *     // En prod : envoi Slack/Telegram + ouverture incident.
     *     return self::FAILURE;
     *
     * Trois defauts, dans cet ordre de gravite :
     *
     * 1. LE COMMENTAIRE TENAIT LIEU DE CODE. « En prod : envoi Slack/Telegram »
     *    decrit une intention ; rien ne l'executait. C'est le meme motif que
     *    `AnomalyDetect::handle()` (« Sprint 11 : send TelegramAlert::dispatch »).
     *
     * 2. `$this->error()` ECRIT SUR UN FLUX QUE PERSONNE NE LIT. Lancee par le
     *    planificateur, la commande n'a pas de terminal : sa sortie part dans le
     *    vide (aucun `sendOutputTo`, aucun `emailOutputOnFailure` sur la ligne
     *    planifiee). L'alerte devait passer par le JOURNAL D'APPLICATION, seul
     *    canal reellement collecte.
     *
     * 3. LE CODE DE SORTIE NE REMONTAIT NULLE PART. Le planificateur de Laravel
     *    n'interprete pas le code de retour d'une commande planifiee : sans
     *    `onFailure()`, un `self::FAILURE` disparait. Corrige au meme moment
     *    dans `routes/console.php`.
     *
     * Une chaine d'audit dont la rupture n'alerte personne ne prouve rien :
     * elle n'a de valeur que si quelqu'un apprend qu'elle est cassee.
     *
     * ── Second point, tout aussi important ─────────────────────────────────
     * `verifyChain()` rend `false` DANS DEUX CAS distincts : la chaine est
     * rompue, OU le secret est inutilisable (constat B16-001, l'un des deux
     * seuls verdicts possibles quand `AUDIT_HASH_CHAIN_SECRET` manque). La
     * commande annoncait « possible falsification » dans les deux. Envoyer
     * quelqu'un chercher une falsification a 03:00 quand la cause est une
     * variable d'environnement absente, c'est bruler la credibilite de
     * l'alerte suivante. Les deux cas sont desormais separes AVANT toute
     * verification.
     */
    public function handle(AuditHashChain $chain): int
    {
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;

        // Cas 1 — on ne PEUT PAS verifier. Ce n'est pas une falsification, et
        // ce n'est pas non plus « tout va bien » : c'est un controle aveugle,
        // et il doit reveiller quelqu'un aussi surement qu'une rupture.
        if (! $chain->secretEstUtilisable()) {
            $message = self::PREFIXE_ALERTE . " : la chaine d'audit n'est pas verifiable. "
                . $chain->raisonSecretInutilisable();

            $this->error($message);
            $this->alerter($message);

            return self::FAILURE;
        }

        // Cas 2 — verification reelle.
        if ($chain->verifyChain($max)) {
            $this->info('Audit hash chain OK — aucune anomalie détectée.');

            return self::SUCCESS;
        }

        $message = self::PREFIXE_ALERTE . " : la chaine d'audit est rompue. "
            . 'Un maillon ne correspond plus a son condense : falsification ou perte de lignes. '
            . "N'effacez rien, ne relancez pas de purge, et gelez l'etat courant.";

        $this->error($message);
        $this->alerter($message);

        return self::FAILURE;
    }

    /**
     * Emet l'alerte sur les canaux reellement collectes.
     *
     * `Log::critical` d'abord : c'est le canal que la pile ramasse toujours
     * (stderr du conteneur → collecteur). `report()` ensuite, pour GlitchTip
     * quand il est configure — enveloppe dans un `try` car `report()` peut
     * lui-meme lever si le client d'erreurs est mal configure, et une alerte
     * qui plante en s'envoyant est une alerte perdue (meme precaution que
     * `AuditHashChainLogger`).
     */
    private function alerter(string $message): void
    {
        Log::critical($message);

        try {
            report(new RuntimeException($message));
        } catch (\Throwable) {
            // On a deja le journal : ne jamais faire echouer l'alerte sur son
            // second canal.
        }
    }
}
