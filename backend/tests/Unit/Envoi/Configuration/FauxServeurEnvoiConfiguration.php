<?php

namespace Tests\Unit\Envoi\Configuration;

use App\Envoi\Configuration\ConfigurationRefusee;
use App\Envoi\Configuration\Paquet;
use App\Envoi\Configuration\ServeurEnvoiConfiguration;
use App\Envoi\Configuration\VerdictConfiguration;
use Throwable;

/** Faux serveur d'envoi : un état par espace, refuse un domaine déjà tenu par un autre espace. */
class FauxServeurEnvoiConfiguration implements ServeurEnvoiConfiguration
{
    /** @var array<string, array<string, mixed>> espace => contenu du dernier paquet appliqué */
    public array $etat = [];

    /** @var list<string> */
    public array $appels = [];

    /** @var list<string> */
    public array $espacesVus = [];

    public function __construct(
        public bool $echouer = false,
        public ?Throwable $transport = null,
    ) {}

    public function validerConfiguration(Paquet $paquet): VerdictConfiguration
    {
        $this->appels[] = 'valider';
        $ws = (string) $paquet->contenu['espace'];
        foreach ($this->etat as $autre => $contenu) {
            if ($autre === $ws) {
                continue;
            }
            $tenus = array_column($contenu['domaines'], 'domaine');
            foreach ($paquet->contenu['domaines'] as $d) {
                if (in_array($d['domaine'], $tenus, true)) {
                    return new VerdictConfiguration(false, 'domaine déjà tenu par un autre espace : ' . $d['domaine']);
                }
            }
        }

        return new VerdictConfiguration(true);
    }

    public function appliquerConfiguration(Paquet $paquet): void
    {
        $this->appels[] = 'appliquer';
        $this->espacesVus[] = (string) $paquet->contenu['espace'];
        if ($this->transport !== null) {
            throw $this->transport;
        }
        if ($this->echouer) {
            throw new ConfigurationRefusee('refusé par le serveur');
        }
        $this->etat[(string) $paquet->contenu['espace']] = $paquet->contenu;
    }
}
