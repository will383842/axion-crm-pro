<?php

namespace App\Envoi\Simulateur;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use Illuminate\Support\Facades\DB;

/** Simulateur en mémoire. Aucune connexion réseau, jamais. */
final class FauxServeurEnvoi implements RenvoiParMemeCle, ServeurEnvoi
{
    /** @var array<string, string> clé d'idempotence => id d'injection */
    private array $parCle = [];

    /** @var array<string, EtatMessage> */
    private array $etats = [];

    /** @var list<EvenementEnvoi> */
    private array $evenements = [];

    private bool $enPause = false;

    public int $appels = 0;

    /**
     * Niveau de transaction « normal » de l'appelant. Les suites sous RefreshDatabase tournent déjà à un niveau ≥ 1 : le
     * niveau au moment de la construction fait foi (null) ; une suite peut imposer le sien. Injecter à un niveau PLUS
     * ÉLEVÉ = injection dans une transaction englobante (comme le refusent Kumo et l'adaptateur SMTP).
     */
    private readonly int $niveauDeBase;

    public function __construct(private readonly bool $temoins = true, ?int $niveauDeBase = null)
    {
        $this->niveauDeBase = $niveauDeBase ?? self::niveauActuel();
    }

    private static function niveauActuel(): int
    {
        try {
            return DB::transactionLevel();
        } catch (\Throwable) {
            return 0; // hors application (test unitaire pur) : aucune transaction
        }
    }

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if (self::niveauActuel() > $this->niveauDeBase) {
            throw ServeurEnvoiRefuse::motif('injecter() ne s’appelle pas dans une transaction englobante.');
        }
        $this->appels++;
        if ($this->enPause) {
            return ResultatInjection::reporte(new \DateTimeImmutable('tomorrow'));
        }
        $id = $this->parCle[$message->cleIdempotence] ??= 'sim-' . (count($this->parCle) + 1);
        if (! isset($this->etats[$id])) {
            $this->etats[$id] = EtatMessage::Envoye;
            $this->evenements[] = new EvenementEnvoi('ev-' . count($this->evenements), 'livre', $id, $message->destinataire);
        }

        return ResultatInjection::accepte($id);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return $this->etats[$idInjection] ?? EtatMessage::Inconnu;
    }

    public function pause(): void
    {
        $this->enPause = true;
    }

    public function reprise(): void
    {
        $this->enPause = false;
    }

    public function modeTemoins(): bool
    {
        return $this->temoins;
    }

    public function etat(): EtatServeur
    {
        return new EtatServeur($this->enPause, count($this->etats));
    }

    public function sante(): SanteServeur
    {
        return new SanteServeur(true, 'simulateur');
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $debut = $curseur === null ? 0 : (int) $curseur;
        $tranche = array_slice($this->evenements, $debut, max(1, $limite));
        $suivant = $debut + count($tranche);

        return new LotEvenements($tranche, $tranche === [] ? $curseur : (string) $suivant);
    }
}
