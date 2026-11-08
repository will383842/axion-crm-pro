<?php

namespace App\Envoi\ListesNoires;

use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Frein « listage » : une réponse « listé » met la ressource en pause TOUT DE SUITE
 * (même passage de contrôle) et alerte à la transition. Planifié chaque minute.
 * Aucune mesure d'ouverture. Résolveur public/relayé, sonde muette, erreur ou code inconnu
 * => « non fiable », jamais vert ; « non fiable » trop longtemps => bloqué (voir DepotControles::enPause).
 *
 * Codes de listage retenus (Spamhaus) : ZEN 127.0.0.2–127.0.0.11, DBL 127.0.1.2–127.0.1.106.
 * 127.255.255.x (refus/quota), 127.0.1.255 (requête IP refusée) et tout autre code = non fiable.
 */
final class SurveillanceListes
{
    /** Point de test de Spamhaus : doit répondre « listé » (127.0.0.x), sinon le résolveur est inutilisable. */
    public const NOM_SONDE = '2.0.0.127.zen.spamhaus.org';

    private const CODE_LISTAGE = '/^127\.0\.(?:0\.(?:[2-9]|1[01])|1\.(?:[2-9]|[1-9]\d|10[0-6]))$/';

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
        $raison = $this->raisonResolveurNonFiable();
        $bilan = [];
        foreach ($source->toutes() as $r) {
            $bilan[$r->type . ':' . $r->valeur] = $this->controlerIsole($r, $raison);
        }

        return $bilan;
    }

    public function controlerUne(Ressource $r): StatutListe
    {
        return $this->appliquer($r, $this->raisonResolveurNonFiable());
    }

    /** Une ressource qui plante ne doit ni arrêter les autres ni rester « vert ». */
    private function controlerIsole(Ressource $r, ?string $raison): StatutListe
    {
        try {
            return $this->appliquer($r, $raison);
        } catch (\Throwable $e) {
            Log::error('[ENVOI] contrôle des listes noires en échec pour ' . $r->type . ' ' . $r->valeur . ' : ' . $e->getMessage());
            try {
                $this->depot->enregistrer($r, StatutListe::NonFiable, '-', 'erreur_controle', false, ($this->horloge)());
            } catch (\Throwable) {
                // base indisponible : l'alerte ci-dessous reste le seul signal
            }
            try {
                $this->alerteur->alerterNonFiable($r, 'erreur_controle');
            } catch (\Throwable) {
            }

            return StatutListe::NonFiable;
        }
    }

    /** Résolveur inutilisable (public, relayé, absent, ou sonde non « listé ») => raison ; sinon null. */
    private function raisonResolveurNonFiable(): ?string
    {
        if (! DetecteurResolveurPublic::estFiable($this->resolveur->adresses())) {
            return 'resolveur_public';
        }
        try {
            $codes = $this->resolveur->interroger(self::NOM_SONDE);
        } catch (\Throwable) {
            return 'sonde_ko';
        }

        return array_filter($codes, static fn (string $c): bool => preg_match(self::CODE_LISTAGE, $c) === 1) !== [] ? null : 'sonde_ko';
    }

    private function appliquer(Ressource $r, ?string $raisonResolveur): StatutListe
    {
        $maintenant = ($this->horloge)();
        if ($raisonResolveur !== null) {
            return $this->nonFiable($r, '-', $raisonResolveur, $maintenant);
        }
        $nom = $this->nomRequete($r);
        if ($nom === null) {
            return $this->nonFiable($r, '-', 'ressource_invalide', $maintenant);
        }
        $zones = $r->type === Ressource::IP ? $this->zonesIp : $this->zonesDomaine;
        $final = StatutListe::NonListe;
        $alerteNonFiable = null;
        foreach ($zones as $zone) {
            try {
                $codes = $this->resolveur->interroger($nom . '.' . $zone);
            } catch (\Throwable) {
                $codes = null;
            }
            if ($codes === null) {
                $ecrit = $this->depot->enregistrer($r, StatutListe::NonFiable, $zone, 'erreur_dns', false, $maintenant);
                $alerteNonFiable = ($ecrit || $alerteNonFiable !== null) ? 'erreur_dns' : null;
                $final = StatutListe::NonFiable;

                continue;
            }
            $listes = array_values(array_filter($codes, static fn (string $c): bool => preg_match(self::CODE_LISTAGE, $c) === 1));
            if ($listes !== []) {
                $avant = $this->depot->dernierStatut($r);
                $this->depot->enregistrer($r, StatutListe::Liste, $zone, implode(',', $listes), true, $maintenant, $maintenant);
                if ($avant !== StatutListe::Liste) {
                    $this->alerteur->alerter($r, $zone, implode(',', $listes));
                }

                return StatutListe::Liste;
            }
            if ($codes !== []) {
                // refus/quota (127.255.255.x) ou code hors plages de listage connues : pas un verdict.
                $ecrit = $this->depot->enregistrer($r, StatutListe::NonFiable, $zone, implode(',', $codes), false, $maintenant);
                $alerteNonFiable = ($ecrit || $alerteNonFiable !== null) ? 'reponse_inattendue' : null;
                $final = StatutListe::NonFiable;
            }
        }
        if ($final === StatutListe::NonListe) {
            $this->depot->enregistrer($r, StatutListe::NonListe, '*', null, false, $maintenant);
        } elseif ($alerteNonFiable !== null) {
            $this->alerteur->alerterNonFiable($r, $alerteNonFiable);
        }

        return $final;
    }

    /** Alerte dès le premier passage, puis au plus une fois par heure (dédoublonnage du dépôt). */
    private function nonFiable(Ressource $r, string $zone, string $raison, DateTimeImmutable $maintenant): StatutListe
    {
        if ($this->depot->enregistrer($r, StatutListe::NonFiable, $zone, $raison, false, $maintenant)) {
            $this->alerteur->alerterNonFiable($r, $raison);
        }

        return StatutListe::NonFiable;
    }

    private function nomRequete(Ressource $r): ?string
    {
        if ($r->type === Ressource::DOMAINE) {
            $d = strtolower(trim($r->valeur, '. '));

            return $d === '' ? null : $d;
        }
        $ip = trim($r->valeur);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (str_contains($ip, ':')) {
            return implode('.', array_reverse(str_split(bin2hex((string) inet_pton($ip)))));
        }

        return implode('.', array_reverse(explode('.', $ip)));
    }
}
