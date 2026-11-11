<?php

namespace Tests\Unit\Envoi;

use App\Envoi\Depot\DepotSocle;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\EmpreinteTemoin;
use App\Envoi\Exceptions\TemoinRefuse;
use Closure;
use DateTimeImmutable;
use InvalidArgumentException;

final class DepotMemoire implements DepotSocle
{
    public bool $arret = false;

    /**
     * @param  string|null  $temoinInitial  adresse d'un témoin déjà présent (comme un dépôt réel déjà garni) ;
     *                                      null = valeur historique du double.
     * @param  string  $fournisseurInitial  fournisseur du témoin initial
     */
    public function __construct(?string $temoinInitial = null, string $fournisseurInitial = 'gmail')
    {
        if ($temoinInitial !== null) {
            $this->temoins = [];
            $this->ajouterTemoin('00000000-0000-0000-0000-000000000000', $temoinInitial, $fournisseurInitial, 'Témoin initial', 'initialisation');
            $this->journal = [];
            $this->detailsJournal = [];
        }
    }

    public bool $temoinsAllumes = true;

    /** @var list<string> */
    public array $temoins = ['temoin@example.test'];

    /** @var list<array{empreinte: string, libelle: string, fournisseur: ?string, cree_le: string}> */
    public array $detailsTemoins = [];

    /** @var array<string, int> */
    public array $compteurs = [];

    /** @var list<array{string, string}> */
    public array $journal = [];

    /** @var list<array<string, mixed>> */
    public array $detailsJournal = [];

    public function toutArreter(string $workspaceId): bool
    {
        return $this->arret;
    }

    public function modeTemoins(string $workspaceId): bool
    {
        return $this->temoinsAllumes;
    }

    public function estTemoin(string $workspaceId, string $adresse): bool
    {
        return in_array(EmpreinteTemoin::normaliser($adresse), $this->temoins, true);
    }

    public function injectesLe(string $workspaceId, DateTimeImmutable $jour): int
    {
        return $this->compteurs[$jour->format('Y-m-d')] ?? 0;
    }

    /** @var list<string> */
    public array $cles = [];

    public int $verrous = 0;

    public function injectesLeGlobal(DateTimeImmutable $jour): int
    {
        return $this->compteurs[$jour->format('Y-m-d')] ?? 0;
    }

    public function dejaInjecte(string $workspaceId, string $cleIdempotence): bool
    {
        return in_array($cleIdempotence, $this->cles, true);
    }

    public function sousVerrouInjection(DateTimeImmutable $jour, Closure $f): mixed
    {
        $this->verrous++;

        return $f();
    }

    public function noterInjection(string $workspaceId, DateTimeImmutable $jour, string $cleIdempotence): void
    {
        if ($this->dejaInjecte($workspaceId, $cleIdempotence)) {
            return;
        }
        $this->cles[] = $cleIdempotence;
        $this->compteurs[$jour->format('Y-m-d')] = $this->injectesLe($workspaceId, $jour) + 1;
    }

    public function ajouterTemoin(string $workspaceId, string $adresse, string $fournisseur, string $libelle, string $auteur): bool
    {
        $empreinte = EmpreinteTemoin::pourDecision($adresse);
        if ($empreinte === null || ! in_array($fournisseur, DepotSocleDb::FOURNISSEURS_TEMOINS, true)) {
            throw new InvalidArgumentException('Témoin refusé : adresse non ASCII ou fournisseur inconnu.');
        }
        $nouveau = ! $this->estTemoin($workspaceId, $adresse);
        if ($nouveau) {
            if (count($this->detailsTemoins) >= TemoinRefuse::PLAFOND) {
                throw TemoinRefuse::plafondAtteint();
            }
            foreach ($this->detailsTemoins as $t) {
                if ($t['fournisseur'] === $fournisseur) {
                    throw TemoinRefuse::fournisseurDejaCouvert($fournisseur);
                }
            }
            $this->temoins[] = EmpreinteTemoin::normaliser($adresse);
            $this->detailsTemoins[] = ['empreinte' => $empreinte, 'libelle' => $libelle, 'fournisseur' => $fournisseur, 'cree_le' => '2026-01-01 00:00:00'];
        }
        // Comme la base : trace courte (préfixe d'empreinte), jamais l'adresse.
        $this->journaliser($workspaceId, 'temoin_ajoute', $auteur, ['empreinte' => substr($empreinte, 0, 12), 'fournisseur' => $fournisseur, 'nouveau' => $nouveau]);

        return $nouveau;
    }

    public function listerTemoins(string $workspaceId): array
    {
        return array_values($this->detailsTemoins);
    }

    public function armerToutArreter(string $workspaceId, string $auteur): void
    {
        $this->arret = true;
        $this->journaliser($workspaceId, 'tout_arreter', $auteur);
    }

    public function leverToutArreter(string $workspaceId, string $auteur): bool
    {
        if (! $this->arret) {
            return false;
        }
        $this->arret = false;
        $this->journaliser($workspaceId, 'reprise', $auteur);

        return true;
    }

    public function eteindreTemoins(string $workspaceId, string $auteur): void
    {
        $this->temoinsAllumes = false;
        $this->journaliser($workspaceId, 'temoins_off', $auteur);
    }

    public function journaliser(string $workspaceId, string $commande, string $auteur, array $details = [], ?DateTimeImmutable $jour = null): void
    {
        $this->journal[] = [$commande, $auteur];
        $this->detailsJournal[] = $details;
    }
}
