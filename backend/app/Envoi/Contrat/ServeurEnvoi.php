<?php

namespace App\Envoi\Contrat;

/**
 * Contrat du serveur d'envoi. Le réglage du mode témoins n'est PAS ici :
 * on le LIT (`modeTemoins`), on ne le change pas par ce contrat.
 */
interface ServeurEnvoi
{
    public function injecter(MessageAEnvoyer $message): ResultatInjection;

    public function etatEnvoi(string $idInjection): EtatMessage;

    public function pause(): void;

    public function reprise(): void;

    /** Vrai si le serveur lui-même est en mode témoins. */
    public function modeTemoins(): bool;

    public function etat(): EtatServeur;

    public function sante(): SanteServeur;

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements;
}
