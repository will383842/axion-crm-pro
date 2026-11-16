<?php

namespace App\Console\Commands;

use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\RafraichissementVerdicts;
use App\Support\WorkspaceContext;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Rafraîchit les verdicts d'adresse des inscriptions bientôt échues (toutes les heures, minute 17). N'envoie rien, aucune
 * connexion SMTP : seul le DNS est interrogé, avec des plafonds. Sans le schéma (déploiement avant `migrate`) : message
 * clair, code 0, rien d'écrit. Code 1 si au moins un espace est en erreur. Sortie : compteurs seulement.
 */
class EnvoiVerdictsRafraichir extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:verdicts:rafraichir';

    protected $signature = 'envoi:verdicts:rafraichir {--espace= : un seul espace (identifiant)} {--essai : calcule, n\'écrit rien}';

    protected $description = 'Rafraîchit les verdicts d’adresse des inscriptions bientôt échues (DNS borné ; aucun envoi, aucun SMTP)';

    /** Garde de schéma partagée avec la planification (le déploiement remplace le code AVANT `migrate`). */
    public static function schemaPret(): bool
    {
        return EcrivainVerdicts::schemaPret() && Schema::hasTable('email_domaines');
    }

    public function handle(RafraichissementVerdicts $rafraichissement): int
    {
        if (! self::schemaPret()) {
            $this->warn('Schéma des verdicts incomplet (migration pas encore passée) : rien n’est fait.');

            return self::SUCCESS;
        }
        $espace = $this->option('espace');
        if ($espace !== null && WorkspaceContext::validIdOrNull((string) $espace) === null) {
            $this->error('Identifiant d’espace invalide.');

            return self::FAILURE;
        }
        $bilan = $rafraichissement->passage(new DateTimeImmutable('now'), $espace === null ? null : (string) $espace, (bool) $this->option('essai'));
        $this->line(implode(' ', array_map(static fn (string $k, int $v): string => $k . '=' . $v, array_keys($bilan), $bilan)) . ($this->option('essai') ? ' (essai : rien écrit)' : ''));

        return $bilan['erreurs'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
