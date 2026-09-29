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

    /** Le domaine témoin des tests (installé dans la configuration par le constructeur). */
    public const TEMOIN = 'temoin.zz-dns.example';

    /**
     * Ce que « répond » le domaine TÉMOIN (`crm.emails_verification.domaine_temoin`),
     * sur lequel la commande juge le résolveur. Par défaut : il reçoit. Ses
     * questions ne sont PAS notées dans `demandes` (elles jugent le résolveur,
     * elles ne vérifient aucune adresse) ; elles sont comptées à part.
     */
    public string $verdictTemoin = ResultatDns::MX;

    public int $questionsTemoin = 0;

    /** @var list<string> réponses du témoin, jouées une à une avant `verdictTemoin` */
    public array $suiteTemoin = [];

    /**
     * @param  array<string, string>  $reponses  domaine => verdict (`ResultatDns::*`)
     * @param  list<string>  $pannes  domaines dont la résolution LÈVE (panne simulée)
     */
    public function __construct(
        private array $reponses = [],
        private string $defaut = ResultatDns::MX,
        private array $pannes = [],
    ) {
        config(['crm.emails_verification.domaine_temoin' => self::TEMOIN]);
    }

    public function resoudre(array $domaines): array
    {
        $sortie = [];
        if ($domaines === [self::TEMOIN]) {
            // Le résolveur est JUGÉ, aucune adresse n'est vérifiée : ni
            // `appels`, ni `pendant`.
            $this->questionsTemoin++;

            return [self::TEMOIN => new ResultatDns(array_shift($this->suiteTemoin) ?? $this->verdictTemoin)];
        }
        $this->appels[] = count($domaines);
        if ($this->pendant !== null) {
            ($this->pendant)($domaines);
        }
        foreach ($domaines as $d) {
            if ($d === self::TEMOIN) {
                $this->questionsTemoin++;
                $sortie[$d] = new ResultatDns($this->verdictTemoin);

                continue;
            }
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
