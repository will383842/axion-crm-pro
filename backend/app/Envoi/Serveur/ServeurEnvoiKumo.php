<?php

namespace App\Envoi\Serveur;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\CoupureTransport;
use App\Envoi\Serveur\Kumo\InterrogeurEtat;
use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\TransportInjection;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Adaptateur CRM du serveur d'envoi KumoMTA (lot K9, interface D4). Reprend la machine à états de L4
 * (AdaptateurSmtp) : a_injecter → injection_en_cours → accepte | a_verifier | reporte (4xx) | echec (5xx).
 *
 * Règles :
 *  - VERROU : tant que `envoi.reel_actif` ET `envoi.k1_actif` ne sont pas vrais, AUCUNE connexion (ni injection
 *    ni interrogation) : l'injection est reportée.
 *  - 4xx = report de tranche, UNE tentative par appel, jamais de boucle ; rappel avant l'échéance = zéro connexion.
 *  - Coupure ou délai (30 s) APRÈS DATA => a_verifier. Un message a_verifier n'est JAMAIS renvoyé : etatEnvoi est
 *    interrogé avant toute décision ; sort inconnu => il reste a_verifier.
 *  - 3 × 5xx en 10 minutes => commande `pause` via la passerelle (échec de la commande : pause locale quand même).
 *  - pause / reprise / retrait : commandes signées (contrat v2) via ClientServeurEnvoiHmac.
 *  - liste blanche des témoins vérifiée AVANT tout état et toute connexion.
 * L'état d'injection est écrit AVANT le réseau. Aucun suivi d'ouverture.
 */
final class ServeurEnvoiKumo implements ServeurEnvoi
{
    public const SEUIL_5XX = 3;

    public const FENETRE_5XX_SECONDES = 600;

    private bool $enPause = false;

    /** @var Closure(): DateTimeImmutable */
    private readonly Closure $horloge;

    /** @param ?Closure(): DateTimeImmutable $horloge */
    public function __construct(
        private readonly string $workspaceId,
        private readonly string $source,
        private readonly TransportInjection $transport,
        private readonly InterrogeurEtat $interrogeur,
        private readonly RegistreInjections $registre,
        private readonly CommandeurServeur $commandeur,
        private readonly DepotSocle $depot,
        #[\SensitiveParameter]
        private readonly string $jeton,
        ?Closure $horloge = null,
    ) {
        $this->horloge = $horloge ?? fn (): DateTimeImmutable => new DateTimeImmutable;
    }

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if ($message->workspaceId !== $this->workspaceId) {
            throw EnvoiRefuse::autreEspace();
        }
        if ($this->verrouFerme()) {
            return ResultatInjection::reporte(($this->horloge)()->modify('tomorrow'));
        }
        if ($this->depot->toutArreter($this->workspaceId)) {
            throw EnvoiRefuse::toutArreter();
        }
        if ($this->depot->modeTemoins($this->workspaceId) && ! $this->depot->estTemoin($this->workspaceId, $message->destinataire)) {
            throw EnvoiRefuse::horsTemoins();
        }

        $cle = $message->cleIdempotence;
        $ligne = $this->registre->lire($cle);
        if ($ligne !== null) {
            $decision = $this->decisionSurExistant($cle, $ligne);
            if ($decision !== null) {
                return $decision;
            }
        }
        if ($this->enPause) {
            return ResultatInjection::reporte(($this->horloge)()->modify('tomorrow'));
        }

        // L'état est écrit AVANT le réseau : une coupure laisse la trace d'un envoi peut-être parti.
        $id = $ligne['id'] ?? (string) Str::uuid();
        $this->noter($cle, $id, 'injection_en_cours');

        try {
            $code = $this->transport->envoyer($message, $this->jeton);
        } catch (CoupureTransport $c) {
            if (! $c->apresData) {
                return $this->reporter($cle, $id); // rien n'a pu partir
            }

            return $this->incertain($cle, $id);
        } catch (Throwable) {
            return $this->incertain($cle, $id);
        }

        if ($code >= 200 && $code < 300) {
            $this->noter($cle, $id, 'accepte');

            return ResultatInjection::accepte($id);
        }
        if ($code >= 400 && $code < 500) {
            return $this->reporter($cle, $id);
        }
        if ($code >= 500 && $code < 600) {
            $this->noter($cle, $id, 'echec');
            $this->pauseSiTrop5xx();
            throw new RuntimeException('Refus définitif du serveur d’envoi (' . $code . ').');
        }

        return $this->incertain($cle, $id); // code inattendu : sort incertain
    }

    /** @param array{id: string, etat: string, reporte_au: ?int, instant: int} $ligne */
    private function decisionSurExistant(string $cle, array $ligne): ?ResultatInjection
    {
        $demain = ($this->horloge)()->modify('tomorrow');
        switch ($ligne['etat']) {
            case 'accepte':
                return ResultatInjection::accepte($ligne['id']);
            case 'echec':
                throw new RuntimeException('Refus définitif déjà enregistré pour cette clé.');
            case 'injection_en_cours':
                return ResultatInjection::reporte($demain);
            case 'a_verifier':
                // JAMAIS de renvoi : on interroge, et seul un sort CONNU change l'état.
                return match ($this->interroger($ligne['id'])) {
                    EtatMessage::Envoye, EtatMessage::EnFile, EtatMessage::Rebond => $this->confirmer($cle, $ligne['id']),
                    EtatMessage::Refuse => $this->refuser($cle, $ligne['id']),
                    EtatMessage::Inconnu => ResultatInjection::reporte($demain),
                };
            case 'reporte':
                if ($ligne['reporte_au'] !== null && $ligne['reporte_au'] > ($this->horloge)()->getTimestamp()) {
                    return ResultatInjection::reporte((new DateTimeImmutable)->setTimestamp($ligne['reporte_au']));
                }
        }

        return null;
    }

    private function confirmer(string $cle, string $id): ResultatInjection
    {
        $this->noter($cle, $id, 'accepte');

        return ResultatInjection::accepte($id);
    }

    private function refuser(string $cle, string $id): never
    {
        $this->noter($cle, $id, 'echec');
        throw new RuntimeException('Refus définitif constaté par le serveur d’envoi.');
    }

    private function reporter(string $cle, string $id): ResultatInjection
    {
        $jusqua = ($this->horloge)()->modify('+' . max(1, (int) config('envoi.smtp_delai_4xx_minutes', 15)) . ' minutes');
        $this->noter($cle, $id, 'reporte', $jusqua->getTimestamp());

        return ResultatInjection::reporte($jusqua);
    }

    private function incertain(string $cle, string $id): ResultatInjection
    {
        $this->noter($cle, $id, 'a_verifier');

        return ResultatInjection::reporte(($this->horloge)()->modify('tomorrow'));
    }

    private function noter(string $cle, string $id, string $etat, ?int $reporteAu = null): void
    {
        $this->registre->ecrire($cle, ['id' => $id, 'etat' => $etat, 'reporte_au' => $reporteAu, 'instant' => ($this->horloge)()->getTimestamp()]);
    }

    private function pauseSiTrop5xx(): void
    {
        $depuis = ($this->horloge)()->getTimestamp() - self::FENETRE_5XX_SECONDES;
        $n = count(array_filter($this->registre->tout(), fn (array $l): bool => $l['etat'] === 'echec' && $l['instant'] >= $depuis));
        if ($n >= self::SEUIL_5XX) {
            Log::critical('Envoi : pause automatique du serveur (5xx répétés).', ['nombre_5xx' => $n]);
            $this->enPause = true; // fail-safe : même si la commande échoue
            $this->commander('pause');
        }
    }

    private function verrouFerme(): bool
    {
        return config('envoi.reel_actif') !== true || config('envoi.k1_actif') !== true;
    }

    private function interroger(string $idInjection): EtatMessage
    {
        if ($this->verrouFerme()) {
            return EtatMessage::Inconnu; // zéro connexion
        }
        try {
            return $this->interrogeur->etat($idInjection);
        } catch (Throwable) {
            return EtatMessage::Inconnu;
        }
    }

    private function commander(string $commande): void
    {
        try {
            $this->commandeur->commander($this->workspaceId, $this->source, (string) Str::uuid(), $commande);
        } catch (ServeurEnvoiRefuse $e) {
            Log::critical('Envoi : commande refusée ou injoignable.', ['commande' => $commande]);
            throw $e;
        }
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        foreach ($this->registre->tout() as $cle => $l) {
            if ($l['id'] !== $idInjection) {
                continue;
            }

            return match ($l['etat']) {
                'accepte' => EtatMessage::Envoye,
                'a_injecter', 'injection_en_cours', 'reporte' => EtatMessage::EnFile,
                'echec' => EtatMessage::Refuse,
                default => $this->etatDistant($cle, $l['id']),
            };
        }

        return EtatMessage::Inconnu;
    }

    private function etatDistant(string $cle, string $id): EtatMessage
    {
        $etat = $this->interroger($id);
        if ($etat === EtatMessage::Envoye) {
            $this->noter($cle, $id, 'accepte');
        }

        return $etat;
    }

    public function pause(): void
    {
        $this->commander('pause');
        $this->enPause = true;
    }

    public function reprise(): void
    {
        $this->commander('reprise');
        $this->enPause = false;
    }

    /** Retrait urgent (opposition) : file du serveur. Hors `ServeurEnvoi` (D4) : l'appelant teste `instanceof`. */
    public function retirer(): void
    {
        $this->commander('retrait');
    }

    public function modeTemoins(): bool
    {
        return $this->depot->modeTemoins($this->workspaceId);
    }

    public function etat(): EtatServeur
    {
        $n = count(array_filter($this->registre->tout(), fn (array $l): bool => $l['etat'] === 'accepte'));

        return new EtatServeur($this->enPause, $n);
    }

    /** Aucun ping réseau. */
    public function sante(): SanteServeur
    {
        return new SanteServeur(true, $this->enPause ? 'kumo en pause' : 'kumo pret');
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $debut = $curseur === null ? 0 : (int) $curseur;
        $acceptes = array_values(array_filter($this->registre->tout(), fn (array $l): bool => $l['etat'] === 'accepte'));
        $tranche = array_slice($acceptes, $debut, max(1, $limite));
        $evenements = [];
        foreach ($tranche as $i => $l) {
            $evenements[] = new EvenementEnvoi('ev-' . ($debut + $i), 'accepte_smtp', $l['id'], '');
        }

        return new LotEvenements($evenements, $tranche === [] ? $curseur : (string) ($debut + count($tranche)));
    }
}
