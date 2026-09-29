<?php

namespace Tests\Support;

use App\Crm\Emails\Dns\ResolveurDns;
use App\Crm\Emails\Dns\ResultatDns;
use Closure;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;

/**
 * Un DNS SIMULÉ : aucune requête ne quitte la suite de tests.
 *
 * On lui dit ce que chaque domaine « répond » ; tout autre domaine reçoit le
 * verdict par défaut. Il NOTE chaque domaine demandé, pour qu'un test puisse
 * prouver qu'un domaine n'est pas résolu deux fois.
 */
final class ResolveurDnsSimule implements ResolveurDns
{
    /** @var list<string> tous les domaines demandés, dans l'ordre, doublons compris */
    public array $demandes = [];

    /** @var list<int> taille de chaque appel */
    public array $appels = [];

    /**
     * Joué au DÉBUT de chaque résolution — c'est-à-dire ENTRE la lecture d'un
     * lot et son écriture : de quoi simuler une modification concurrente.
     */
    public ?Closure $pendant = null;

    /**
     * @param  array<string, string>  $reponses  domaine => verdict (`ResultatDns::*`)
     * @param  list<string>  $pannes  domaines dont la résolution LÈVE (panne simulée)
     */
    public function __construct(
        private array $reponses = [],
        private string $defaut = ResultatDns::MX,
        private array $pannes = [],
    ) {}

    public function resoudre(array $domaines): array
    {
        $this->appels[] = count($domaines);
        if ($this->pendant !== null) {
            ($this->pendant)($domaines);
        }
        $sortie = [];
        foreach ($domaines as $d) {
            $this->demandes[] = $d;
            if (in_array($d, $this->pannes, true)) {
                throw new RuntimeException('panne DNS simulée');
            }
            $verdict = $this->reponses[$d] ?? $this->defaut;
            $sortie[$d] = new ResultatDns($verdict, $verdict === ResultatDns::MX ? 'mx.' . $d : null);
        }

        return $sortie;
    }

    public function nom(): string
    {
        return 'simule';
    }

    /**
     * Vérifie toutes les adresses de l'espace business avec un DNS où
     * `$defaut` répond pour tout domaine (par défaut : tout reçoit).
     *
     * Pour les tests de la liste de campagne : elle ne retient qu'une adresse
     * VÉRIFIÉE valide (`crm:emails:verifier`). Les appeler juste avant la
     * liste, et non dans le `beforeEach`, garde vrai ce qu'ils mesurent : une
     * adresse ajoutée par le test lui-même est vérifiée aussi — elle n'est
     * jamais écartée « parce que non vérifiée » à la place de la raison que
     * le test veut prouver.
     */
    public static function toutVerifier(string $defaut = ResultatDns::MX): self
    {
        $dns = new self([], $defaut);
        app()->instance(ResolveurDns::class, $dns);
        Artisan::call('crm:emails:verifier');

        return $dns;
    }

    /** @param  array<string, string>  $reponses */
    public function repondre(array $reponses): void
    {
        $this->reponses = array_merge($this->reponses, $reponses);
    }
}
