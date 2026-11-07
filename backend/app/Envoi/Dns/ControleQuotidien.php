<?php

namespace App\Envoi\Dns;

use App\Envoi\Depot\DepotSocle;
use Closure;
use DateTimeImmutable;

/**
 * Contrôle quotidien de cohérence d'UN domaine. Branché sur la porte existante sans
 * changer son contrat : un domaine « pas prêt » (PTR incohérent, SPF/DKIM/DMARC en
 * échec) n'est PAS marqué vérifié ce jour, donc la porte le refuse ; DMARC absent
 * ajoute une pause journalisée.
 */
final class ControleQuotidien
{
    public const AUTEUR = 'envoi:controle-dns';

    /** @param Closure(): DateTimeImmutable $maintenant */
    public function __construct(
        private readonly ResolveurDns $dns,
        private readonly DepotControlesDns $controles,
        private readonly DepotSocle $journal,
        private readonly Closure $maintenant,
    ) {}

    public function controler(string $workspaceId, ConfigurationDomaine $c): RapportControle
    {
        $quand = ($this->maintenant)();
        $rapport = (new ControleurDns($this->dns))->controler($c);
        $this->controles->enregistrer($workspaceId, $rapport, $quand);

        if ($rapport->pause()) {
            $this->journal->journaliser($workspaceId, 'pause_domaine_dmarc_absent', self::AUTEUR, ['domaine' => $c->domaine]);
        }
        if ($rapport->pret()) {
            $this->controles->marquerVerifie($workspaceId, $c->domaine, $quand);
        }

        return $rapport;
    }
}
