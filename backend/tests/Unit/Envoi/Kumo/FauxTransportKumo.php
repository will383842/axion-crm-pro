<?php

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Serveur\Kumo\CoupureTransport;
use App\Envoi\Serveur\Kumo\EchecAuthentification;
use App\Envoi\Serveur\Kumo\InterrogeurEtat;
use App\Envoi\Serveur\Kumo\RefusConnexion;
use App\Envoi\Serveur\Kumo\TransportInjection;
use App\Envoi\Serveur\ServeurEnvoiRefuse;

/** Faux serveur de test : scénarios scriptés (code SMTP ou coupure), compte les connexions. Aucun réseau. */
final class FauxTransportKumo implements InterrogeurEtat, TransportInjection
{
    public int $connexions = 0;

    public int $interrogations = 0;

    /** @var list<int|string> code SMTP, ou 'coupure_apres_data' / 'coupure_avant_data' */
    public array $scenario = [];

    public EtatMessage $etatDistant = EtatMessage::Inconnu;

    public ?string $dernierJeton = null;

    public ?string $derniereSource = null;

    public function envoyer(MessageAEnvoyer $message, string $jeton, string $source = ''): int
    {
        $this->connexions++;
        $this->dernierJeton = $jeton;
        $this->derniereSource = $source;
        $suite = array_shift($this->scenario) ?? 250;
        if ($suite === 'coupure_apres_data') {
            throw new CoupureTransport(true);
        }
        if ($suite === 'echec_auth') {
            throw new EchecAuthentification;
        }
        if ($suite === 'refus_connexion') {
            throw new RefusConnexion;
        }
        if ($suite === 'envoi_refuse_local') {
            throw ServeurEnvoiRefuse::motif('enveloppe invalide');
        }
        if ($suite === 'panne_secret') {
            throw new RuntimeException('panne contenant secret-visible-xyz');
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
