<?php

namespace App\Envoi\Sante;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Supervision K15, un passage par (espace, serveur), toutes les 5 minutes :
 *  1. sonde le serveur (silence = relevé en échec) ; 2. évalue les seuils chiffrés ; 3. ajoute le silence
 *  prolongé (homme mort), le dernier contrôle DNS (K12) et le dernier statut des listes noires (K13) ;
 *  4. n'alerte qu'à l'OUVERTURE d'un constat (ou à son passage attention -> critique) : pas de rafale ;
 *  5. sur critique « serveur », POSE une pause. Elle ne la LÈVE jamais : seule une décision humaine le fait.
 * Les critiques DNS et listes noires ne sont pas re-pausés ici : K12 et K13 pausent déjà le domaine concerné.
 */
final class SuperviseurSante
{
    /** @param array<string, mixed> $seuils `config('envoi.sante')` */
    public function __construct(
        private readonly SondeSante $sonde,
        private readonly DepotSante $depot,
        private readonly EmetteurAlerteSante $emetteur,
        private readonly DepotChauffe $chauffe,
        private readonly ?CommandeurServeur $commandeur,
        private readonly array $seuils,
    ) {}

    /** @return list<Constat> constats en cours à cet instant */
    public function superviser(string $ws, string $source, DateTimeImmutable $maintenant): array
    {
        try {
            $mesures = $this->sonde->mesurer($ws, $source);
            $this->depot->noterReleve($ws, $source, $maintenant, true, $mesures->enTableau());
        } catch (Throwable $e) {
            $mesures = null;
            $this->depot->noterReleve($ws, $source, $maintenant, false, []);
        }

        $constats = [];
        if ($mesures !== null) {
            $constats = EvaluateurSante::evaluer($mesures, $maintenant, $this->seuils);
        } else {
            $ref = $this->depot->dernierBattementOk($ws, $source) ?? $this->depot->premierReleve($ws, $source);
            $silence = (int) ($this->seuils['silence_minutes'] ?? 15);
            if ($ref !== null && $maintenant->getTimestamp() - $ref->getTimestamp() >= $silence * 60) {
                $constats[] = new Constat('serveur_muet', Constat::CRITIQUE, 'aucune réponse du serveur depuis ' . $silence . ' min ou plus (homme mort)');
            }
        }
        array_push($constats, ...$this->constatsDns($ws, $maintenant), ...$this->constatsListes($ws));

        $this->appliquer($ws, $source, $constats, $mesures !== null, $maintenant);

        return $constats;
    }

    /** @param list<Constat> $constats */
    private function appliquer(string $ws, string $source, array $constats, bool $serveurMesure, DateTimeImmutable $maintenant): void
    {
        $ouverts = $this->depot->constatsOuverts($ws, $source);
        $presents = [];
        foreach ($constats as $c) {
            $presents[$c->code] = true;
            $avant = $ouverts[$c->code] ?? null;
            if ($avant !== null && ! ($avant['gravite'] === Constat::ATTENTION && $c->gravite === Constat::CRITIQUE)) {
                continue; // déjà signalé : pas de deuxième alerte
            }
            $this->depot->ouvrir($ws, $source, $c->code, $c->gravite, $c->detail, $maintenant); // la trace AVANT l'alerte : pas de rafale si elle échoue
            $this->emetteur->alerter($ws, $source, $c);
            if ($c->gravite === Constat::CRITIQUE && ! $this->estDeDomaine($c->code)) {
                $this->pauser($ws, $source, $c, $maintenant);
            }
        }
        foreach (array_keys($ouverts) as $code) {
            // Serveur injoignable : on ne sait pas si ses constats sont réglés, on les laisse ouverts.
            if (! isset($presents[$code]) && ($serveurMesure || $this->estDeDomaine($code))) {
                $this->depot->retablir($ws, $source, $code, $maintenant);
            }
        }
    }

    private function pauser(string $ws, string $source, Constat $c, DateTimeImmutable $maintenant): void
    {
        $this->chauffe->ouvrirPause($ws, 'sante:' . $c->code, null, $maintenant); // durée nulle : jusqu'à levée humaine
        try {
            $this->commandeur?->commander($ws, $source, (string) Str::uuid(), 'pause');
        } catch (Throwable $e) {
            Log::error('[ENVOI] supervision : commande de pause au serveur impossible (la pause locale est posée).', ['exception' => $e::class, 'code' => $c->code]);
        }
    }

    private function estDeDomaine(string $code): bool
    {
        return str_starts_with($code, 'dns:') || str_starts_with($code, 'liste_noire:');
    }

    /** @return list<Constat> */
    private function constatsDns(string $ws, DateTimeImmutable $maintenant): array
    {
        $ageMax = (int) ($this->seuils['dns_age_max_heures'] ?? 36) * 3600;
        $c = [];
        foreach ($this->depot->controlesDns($ws) as $d) {
            $code = 'dns:' . $d['domaine'];
            if ($d['pause']) {
                $c[] = new Constat($code, Constat::CRITIQUE, 'DNS : domaine en pause (DMARC absent)');
            } elseif (! $d['pret']) {
                $c[] = new Constat($code, Constat::ATTENTION, 'DNS : domaine pas prêt (PTR/SPF/DKIM/DMARC)');
            } elseif ($maintenant->getTimestamp() - (new DateTimeImmutable($d['controle_le']))->getTimestamp() > $ageMax) {
                $c[] = new Constat($code, Constat::ATTENTION, 'DNS : dernier contrôle trop ancien');
            }
        }

        return $c;
    }

    /** @return list<Constat> */
    private function constatsListes(string $ws): array
    {
        $c = [];
        foreach ($this->depot->controlesListes($ws) as $l) {
            $code = 'liste_noire:' . $l['type'] . ':' . $l['ressource'];
            if ($l['statut'] === 'liste') {
                $c[] = new Constat($code, Constat::CRITIQUE, 'liste noire : ressource listée');
            } elseif ($l['statut'] === 'non_fiable') {
                $c[] = new Constat($code, Constat::ATTENTION, 'liste noire : contrôle non fiable');
            }
        }

        return $c;
    }
}
