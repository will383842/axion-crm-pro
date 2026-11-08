<?php

use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\Kumo\CoupureTransport;
use App\Envoi\Serveur\Kumo\InterrogeurEtat;
use App\Envoi\Serveur\Kumo\TransportInjection;
use App\Envoi\Contrat\EtatMessage;

/** Faux serveur de test : scénarios scriptés (code SMTP ou coupure), compte les connexions. Aucun réseau. */
final class FauxTransportKumo implements TransportInjection, InterrogeurEtat
{
    public int $connexions = 0;

    public int $interrogations = 0;

    /** @var list<int|string> code SMTP, ou 'coupure_apres_data' / 'coupure_avant_data' */
    public array $scenario = [];

    public EtatMessage $etatDistant = EtatMessage::Inconnu;

    public ?string $dernierJeton = null;

    public function envoyer(MessageAEnvoyer $message, string $jeton): int
    {
        $this->connexions++;
        $this->dernierJeton = $jeton;
        $suite = array_shift($this->scenario) ?? 250;
        if ($suite === 'coupure_apres_data') {
            throw new CoupureTransport(true);
        }
        if ($suite === 'coupure_avant_data') {
            throw new CoupureTransport(false);
        }

        return (int) $suite;
    }

    public function etat(string $idInjection): EtatMessage
    {
        $this->interrogations++;

        return $this->etatDistant;
    }
}
