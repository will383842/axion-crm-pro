<?php

namespace App\Envoi\Sante;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\ServeurEnvoiRefuse;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Supervision K15, un passage par (espace, serveur), toutes les 5 minutes :
 *  1. sonde le serveur (silence = relevé en échec) ; 2. évalue les seuils chiffrés ; 3. ajoute le silence
 *  (attention dès 10 min, critique « homme mort » à 15 min), le refus pour horloge, la configuration manquante ;
 *  4. ajoute, UNE fois par espace (source fictive {@see self::ESPACE}), le dernier contrôle DNS (K12), les listes noires (K13)
 *     et le canal des retours muet (2 h en campagne) ;
 *  5. n'alerte qu'à l'OUVERTURE d'un constat ou quand sa gravité/son palier MONTE : pas de rafale. Une alerte non partie
 *     (e-mail en échec, alertes du Socle éteintes) est RENVOYÉE à chaque passage jusqu'à succès ;
 *  6. sur critique « serveur » ou « espace », POSE une pause, AVANT d'ouvrir le constat ; elle est reposée à chaque passage
 *     tant que le défaut critique dure et que la pause manque (échec d'un passage, ou levée humaine alors que le défaut persiste).
 *     La supervision ne LÈVE jamais une pause : seule une décision humaine le fait.
 * Les critiques DNS et listes noires ne sont pas re-pausés ici : K12 et K13 pausent déjà le domaine concerné.
 * Un constat qui échoue n'empêche pas les autres ; le passage échoue ENSUITE (exception), pour que la commande le compte.
 */
final class SuperviseurSante
{
    /** Source fictive des constats d'ESPACE (DNS, listes noires, retours) : un seul constat par (espace, défaut), quel que soit le nombre de serveurs. */
    public const ESPACE = '(espace)';

    /** @param array<string, mixed> $seuils `config('envoi.sante')` */
    public function __construct(
        private readonly SondeSante $sonde,
        private readonly DepotSante $depot,
        private readonly EmetteurAlerteSante $emetteur,
        private readonly DepotChauffe $chauffe,
        private readonly ?CommandeurServeur $commandeur,
        private readonly array $seuils,
    ) {}

    /**
     * @return list<Constat> constats en cours à cet instant (serveur + espace)
     *
     * @throws RuntimeException si un constat n'a pu être traité (les autres l'ont été)
     */
    public function superviser(string $ws, string $source, DateTimeImmutable $maintenant): array
    {
        $refusHorloge = false;
        $configuration = false;
        try {
            $mesures = $this->sonde->mesurer($ws, $source);
            $this->depot->noterReleve($ws, $source, $maintenant, true, $mesures->enTableau());
        } catch (Throwable $e) {
            $mesures = null;
            $refusHorloge = $e instanceof ServeurEnvoiRefuse && $e->estHorloge();
            $configuration = $e instanceof ServeurEnvoiRefuse && $e->estConfiguration();
            // Classe et motif (le motif d'un refus K1 ne contient ni extrait de réponse, ni URL, ni IP) ; jamais le message d'une autre exception.
            Log::warning('[ENVOI] supervision : sonde en échec.', ['exception' => $e::class, 'motif' => $e instanceof ServeurEnvoiRefuse ? $e->getMessage() : null, 'source' => $source]);
            $this->depot->noterReleve($ws, $source, $maintenant, false, []);
        }

        if ($mesures !== null) {
            $serveur = EvaluateurSante::evaluer($mesures, $maintenant, $this->seuils);
        } elseif ($refusHorloge) {
            $serveur = [new Constat('horloge', Constat::CRITIQUE, 'le serveur refuse nos requêtes : écart d’horloge supérieur à 300 s (pas un silence du serveur)')];
        } elseif ($configuration) {
            $serveur = [new Constat('configuration_serveur', Constat::ATTENTION, 'serveur actif mais secret ou adresse absent de la configuration : il n’est pas supervisé')];
        } else {
            $serveur = $this->constatsSilence($ws, $source, $maintenant);
        }
        $espace = [...$this->constatsDns($ws, $maintenant), ...$this->constatsListes($ws), ...$this->constatsRetours($ws, $maintenant)];

        $echecs = $this->appliquer($ws, $source, $serveur, $mesures !== null, $maintenant)
            + $this->appliquer($ws, self::ESPACE, $espace, true, $maintenant);
        if ($echecs > 0) {
            throw new RuntimeException('supervision : ' . $echecs . ' constat(s) non traité(s) (les autres l’ont été).');
        }

        return [...$serveur, ...$espace];
    }

    /** @return list<Constat> */
    private function constatsSilence(string $ws, string $source, DateTimeImmutable $maintenant): array
    {
        $ref = $this->depot->dernierBattementOk($ws, $source) ?? $this->depot->premierReleve($ws, $source);
        if ($ref === null) {
            return [];
        }
        $ecoule = $maintenant->getTimestamp() - $ref->getTimestamp();
        $silence = (int) ($this->seuils['silence_minutes'] ?? 15);
        $alerte = min($silence, (int) ($this->seuils['silence_alerte_minutes'] ?? 10));
        if ($ecoule >= $silence * 60) {
            return [new Constat('serveur_muet', Constat::CRITIQUE, 'aucune réponse du serveur depuis ' . $silence . ' min ou plus (homme mort)')];
        }
        if ($ecoule >= $alerte * 60) {
            return [new Constat('serveur_muet', Constat::ATTENTION, 'aucune réponse du serveur depuis ' . $alerte . ' min ou plus (tunnel coupé ?) ; pause à ' . $silence . ' min')];
        }

        return [];
    }

    /**
     * @param  list<Constat>  $constats
     * @return int nombre de constats dont le traitement a échoué
     */
    private function appliquer(string $ws, string $source, array $constats, bool $etatConnu, DateTimeImmutable $maintenant): int
    {
        $ouverts = $this->depot->constatsOuverts($ws, $source);
        $pauses = null; // motifs de pause actifs, lus une fois si besoin
        $echecs = 0;
        $presents = [];
        foreach ($constats as $c) {
            $presents[$c->code] = true;
            try {
                $this->traiter($ws, $source, $c, $ouverts[$c->code] ?? null, $maintenant, $pauses);
            } catch (Throwable $e) {
                $echecs++;
                Log::error('[ENVOI] supervision : constat non traité (les autres continuent).', ['exception' => $e::class, 'code' => $c->code]);
            }
        }
        foreach (array_keys($ouverts) as $code) {
            // Serveur injoignable : on ne sait pas si ses constats sont réglés, on les laisse ouverts (ceux de l'espace viennent de la base).
            if (! isset($presents[$code]) && $etatConnu) {
                try {
                    $this->depot->retablir($ws, $source, $code, $maintenant);
                } catch (Throwable $e) {
                    $echecs++;
                    Log::error('[ENVOI] supervision : rétablissement non noté.', ['exception' => $e::class, 'code' => $code]);
                }
            }
        }

        return $echecs;
    }

    /**
     * @param  ?array{gravite: string, detail: string, depuis: string, palier: int, alerte_emise: bool}  $avant
     * @param  ?list<string>  $pauses
     */
    private function traiter(string $ws, string $source, Constat $c, ?array $avant, DateTimeImmutable $maintenant, ?array &$pauses): void
    {
        // 1. La PAUSE d'abord : si le constat s'ouvrait avant et que la pause échouait, le constat serait « déjà signalé » et la pause ne serait jamais retentée.
        // Si elle échoue, le constat est quand même ouvert et l'alerte part (l'humain doit savoir), puis l'échec remonte : la pause est retentée au passage suivant.
        $echecPause = null;
        if ($c->gravite === Constat::CRITIQUE && ! $this->estDeDomaine($c->code)) {
            try {
                $this->assurerPause($ws, $source, $c, $avant !== null, $maintenant, $pauses);
            } catch (Throwable $e) {
                $echecPause = $e;
            }
        }
        // 2. Le constat : ouvert à l'apparition ou quand le palier MONTE ; noté (sans alerte) quand il redescend.
        $alerteDue = false;
        if ($avant === null || $c->palier > $avant['palier']) {
            $this->depot->ouvrir($ws, $source, $c->code, $c->gravite, $c->detail, $maintenant, $c->palier);
            $alerteDue = true;
        } elseif ($c->palier < $avant['palier']) {
            $this->depot->ouvrir($ws, $source, $c->code, $c->gravite, $c->detail, $maintenant, $c->palier);
            $this->depot->marquerAlerteEmise($ws, $source, $c->code, $c->gravite, $maintenant); // redescente : pas de nouvelle alerte, mais le prochain franchissement en aura une
        } elseif (! $avant['alerte_emise']) {
            $alerteDue = true; // alerte perdue lors d'un passage précédent : renvoyée
        }
        // 3. L'alerte : tant qu'elle n'est pas partie, le constat reste « alerte non émise » et elle est renvoyée à chaque passage.
        if ($alerteDue) {
            if ($this->emetteur->alerter($ws, $source, $c)) {
                $this->depot->marquerAlerteEmise($ws, $source, $c->code, $c->gravite, $maintenant);
            } else {
                Log::warning('[ENVOI] supervision : alerte non émise, elle sera renvoyée au prochain passage.', ['code' => $c->code, 'gravite' => $c->gravite, 'source' => $source]);
            }
        }
        if ($echecPause !== null) {
            throw $echecPause;
        }
    }

    /**
     * @param  ?list<string>  $pauses
     *
     * @param-out list<string>  $pauses
     */
    private function assurerPause(string $ws, string $source, Constat $c, bool $dejaOuvert, DateTimeImmutable $maintenant, ?array &$pauses): void
    {
        $motif = 'sante:' . $c->code;
        $pauses ??= array_map(static fn ($p): string => (string) $p->motif, $this->chauffe->pausesActives($ws, $maintenant));
        if (in_array($motif, $pauses, true)) {
            return;
        }
        if ($dejaOuvert) {
            Log::warning('[ENVOI] supervision : pause absente alors que le défaut critique persiste (échec antérieur ou levée humaine) : reposée.', ['code' => $c->code, 'source' => $source]);
        }
        $this->chauffe->ouvrirPause($ws, $motif, null, $maintenant); // durée nulle : jusqu'à levée humaine
        $pauses[] = $motif;
        $sources = $source === self::ESPACE ? $this->sourcesDe($ws) : [$source];
        foreach ($sources as $s) {
            try {
                $this->commandeur?->commander($ws, $s, (string) Str::uuid(), 'pause');
            } catch (Throwable $e) {
                Log::error('[ENVOI] supervision : commande de pause au serveur impossible (la pause locale est posée).', ['exception' => $e::class, 'code' => $c->code]);
            }
        }
    }

    /** @return list<string> */
    private function sourcesDe(string $ws): array
    {
        $r = [];
        foreach ($this->depot->serveursActifs() as [$w, $s]) {
            if ($w === $ws) {
                $r[] = $s;
            }
        }

        return $r;
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
            } elseif ($l['pause']) {
                // Plus listée, mais la pause K13 dure (levée humaine) : le constat reste ouvert tant qu'elle dure.
                $c[] = new Constat($code, Constat::CRITIQUE, 'liste noire : ressource plus listée, mais sa pause K13 est toujours active (levée humaine)');
            } elseif ($l['statut'] === 'non_fiable') {
                $c[] = new Constat($code, Constat::ATTENTION, 'liste noire : contrôle non fiable');
            }
        }

        return $c;
    }

    /**
     * Canal des retours muet (ADR K0 / plan 33 K15) : des messages sont partis il y a plus de N h, d'autres partent
     * encore, et aucun retour (rebond, plainte, réponse) n'est arrivé depuis N h. Sans retours, rebonds et plaintes sont invisibles.
     *
     * @return list<Constat>
     */
    private function constatsRetours(string $ws, DateTimeImmutable $maintenant): array
    {
        $heures = max(1, (int) ($this->seuils['retours_muets_heures'] ?? 2));
        $limite = $maintenant->modify('-' . $heures . ' hours');
        $dernier = $this->depot->dernierRetourRecu($ws);
        // « En campagne » : des messages partent encore. « Sans retour » : au moins un message vieux de N h ou plus est parti APRÈS le dernier
        // retour (un retour récent rend l'intervalle vide). Une campagne qui démarre ce matin ne déclenche donc rien.
        $enCampagne = $this->depot->envoisEntre($ws, $limite, null) > 0;
        $sansRetourDepuisN = $this->depot->envoisEntre($ws, $dernier, $limite) > 0;
        if ($enCampagne && $sansRetourDepuisN) {
            return [new Constat('retours_muets', Constat::CRITIQUE, 'aucun retour (rebond, plainte, réponse) reçu depuis ' . $heures . ' h alors que des envois ont eu lieu : relève IMAP en panne ?')];
        }

        return [];
    }
}
