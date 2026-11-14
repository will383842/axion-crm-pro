<?php

namespace Tests\Support;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use Closure;
use Illuminate\Support\Facades\DB;

/** Serveur d'essai SANS dédoublonnage par clé (comme un SMTP direct) : le comportement d'injection est fourni par le test. */
class ServeurEspion implements ServeurEnvoi
{
    public int $appels = 0;

    /** @var list<int> niveau de transaction vu à chaque appel */
    public array $niveaux = [];

    /** @param  Closure(MessageAEnvoyer, self): ResultatInjection  $comportement */
    public function __construct(private readonly Closure $comportement) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        $this->appels++;
        $this->niveaux[] = DB::transactionLevel();

        return ($this->comportement)($message, $this);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return EtatMessage::Inconnu;
    }

    public function pause(): void {}

    public function reprise(): void {}

    public function modeTemoins(): bool
    {
        return true;
    }

    public function etat(): EtatServeur
    {
        return new EtatServeur(false, 0);
    }

    public function sante(): SanteServeur
    {
        return new SanteServeur(true, 'espion');
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        return new LotEvenements([], $curseur);
    }
}
