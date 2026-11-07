<?php

namespace App\Envoi\ListesNoires;

use DateTimeImmutable;

/**
 * Frein « listage » : une réponse « listé » met la ressource en pause TOUT DE SUITE
 * (même passage de contrôle) et alerte. Planifié chaque minute => bien < 5 min.
 * Aucune mesure d'ouverture. Résolveur public ou en erreur => « non fiable », jamais vert.
 */
final class SurveillanceListes
{
    /**
     * @param  list<string>  $zonesIp
     * @param  list<string>  $zonesDomaine
     * @param  callable(): DateTimeImmutable  $horloge
     */
    public function __construct(
        private readonly ResolveurDns $resolveur,
        private readonly DepotControles $depot,
        private readonly Alerteur $alerteur,
        private readonly array $zonesIp,
        private readonly array $zonesDomaine,
        private $horloge,
    ) {}

    /** @return array<string, StatutListe> clé « type:valeur » */
    public function controler(SourceRessources $source): array
    {
        $bilan = [];
        foreach ($source->toutes() as $r) {
            $bilan[$r->type . ':' . $r->valeur] = $this->controlerUne($r);
        }

        return $bilan;
    }

    public function controlerUne(Ressource $r): StatutListe
    {
        $zones = $r->type === Ressource::IP ? $this->zonesIp : $this->zonesDomaine;
        $maintenant = ($this->horloge)();
        if (DetecteurResolveurPublic::estPublic($this->resolveur->adresse())) {
            $this->depot->enregistrer($r, StatutListe::NonFiable, '-', 'resolveur_public', false, $maintenant);

            return StatutListe::NonFiable;
        }
        $nom = $this->nomRequete($r);
        $final = StatutListe::NonListe;
        foreach ($zones as $zone) {
            try {
                $codes = $this->resolveur->interroger($nom . '.' . $zone);
            } catch (\Throwable) {
                $this->depot->enregistrer($r, StatutListe::NonFiable, $zone, 'erreur_dns', false, $maintenant);
                $final = StatutListe::NonFiable;

                continue;
            }
            // 127.255.255.x = refus/quota côté Spamhaus : pas un verdict.
            $erreur = array_filter($codes, static fn (string $c): bool => str_starts_with($c, '127.255.255.'));
            $listes = array_values(array_diff($codes, $erreur));
            if ($listes !== []) {
                $this->depot->enregistrer($r, StatutListe::Liste, $zone, implode(',', $listes), true, $maintenant, $maintenant);
                $this->alerteur->alerter($r, $zone, implode(',', $listes));

                return StatutListe::Liste;
            }
            if ($erreur !== []) {
                $this->depot->enregistrer($r, StatutListe::NonFiable, $zone, implode(',', $erreur), false, $maintenant);
                $final = StatutListe::NonFiable;
            }
        }
        if ($final === StatutListe::NonListe) {
            $this->depot->enregistrer($r, StatutListe::NonListe, '*', null, false, $maintenant);
        }

        return $final;
    }

    private function nomRequete(Ressource $r): string
    {
        if ($r->type === Ressource::DOMAINE) {
            return strtolower(trim($r->valeur, '.'));
        }
        if (str_contains($r->valeur, ':')) {
            $hex = bin2hex((string) inet_pton($r->valeur));

            return implode('.', array_reverse(str_split($hex)));
        }

        return implode('.', array_reverse(explode('.', $r->valeur)));
    }
}
