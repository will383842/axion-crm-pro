<?php

namespace App\Envoi\Serveur;

use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\EvenementEnvoi;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Exceptions\EnvoiRefuse;
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Serveur\Contrat\CommandeurServeur;
use App\Envoi\Serveur\Kumo\CoupureTransport;
use App\Envoi\Serveur\Kumo\EchecAuthentification;
use App\Envoi\Serveur\Kumo\InterrogeurEtat;
use App\Envoi\Serveur\Kumo\RefusConnexion;
use App\Envoi\Serveur\Kumo\RegistreInjections;
use App\Envoi\Serveur\Kumo\TransportInjection;
use App\Envoi\Serveur\Kumo\TransportSmtpTunnel;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
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
 *  - 3 × 5xx REÇUS À L'INJECTION en 10 minutes => commande `pause` via la passerelle (échec de la commande : pause
 *    posée quand même, AVANT la commande). Pause et compteur vivent dans le registre, partagés entre workers.
 *  - JETON X-Envoi-Jeton PAR message (128 bits, HMAC de la clé d'idempotence) ; écriture conditionnelle (CAS) du
 *    registre avant d'envoyer : deux workers sur la même clé => un seul envoi.
 *  - pause / reprise / retrait : commandes signées (contrat v2) via ClientServeurEnvoiHmac.
 *  - liste blanche des témoins ET validité de l'enveloppe vérifiées AVANT tout état et toute connexion.
 *  - 5xx numérique (même avant DATA) = echec + compté ; AUTH/certificat refusé = alerte critique + pause, JAMAIS de
 *    réessai périodique (aurait l'air d'un forçage d'identifiants).
 *  - Tout est cloisonné par espace (registre : clés, pause, compteur de 5xx, séquence des événements).
 * L'état d'injection est écrit AVANT le réseau. Aucun suivi d'ouverture.
 */
final class ServeurEnvoiKumo implements RenvoiParMemeCle, ServeurEnvoi
{
    public const SEUIL_5XX = 3;

    public const FENETRE_5XX_SECONDES = 600;

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
        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $source) !== 1) {
            throw new InvalidArgumentException('Source d’envoi invalide (1 à 64 caractères : lettres, chiffres, . _ -).');
        }
        if (strlen($jeton) < 16) {
            throw new InvalidArgumentException('Secret de dérivation des jetons d’envoi : 16 caractères au moins.');
        }
        $this->horloge = $horloge ?? fn (): DateTimeImmutable => new DateTimeImmutable;
    }

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if ($message->workspaceId !== $this->workspaceId) {
            throw EnvoiRefuse::autreEspace();
        }
        self::refuserTransactionEnglobante();
        $this->verifierEnveloppe($message); // avant le moindre état d'injection (comme L4)
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
        $ligne = $this->registre->lire($this->workspaceId, $cle);
        if ($ligne !== null) {
            $decision = $this->decisionSurExistant($cle, $ligne);
            if ($decision !== null) {
                return $decision;
            }
        }
        if ($this->registre->enPause($this->workspaceId)) {
            return ResultatInjection::reporte(($this->horloge)()->modify('tomorrow'));
        }

        // Jeton PAR message : dérivé de la clé (HMAC), donc identique à chaque renvoi de la même clé.
        $jeton = $this->jetonPour($message);
        $id = $ligne['id'] ?? self::idDepuisJeton($jeton);

        // L'état est écrit AVANT le réseau, et de façon CONDITIONNELLE : si un autre worker a déjà pris la clé
        // (état différent de celui que nous avons lu), nous n'envoyons rien.
        $nouvelle = ['id' => $id, 'etat' => 'injection_en_cours', 'reporte_au' => null, 'instant' => ($this->horloge)()->getTimestamp(), 'jeton' => $jeton];
        if (! $this->registre->ecrireSi($this->workspaceId, $cle, $ligne['etat'] ?? null, $ligne['instant'] ?? null, $nouvelle)) {
            // Un autre worker tient la clé : son envoi est peut-être en route, la place reste comptée.
            return ResultatInjection::incertain(($this->horloge)()->modify('tomorrow'));
        }

        try {
            $code = $this->transport->envoyer($message, $jeton, $this->source);
        } catch (CoupureTransport $c) {
            if (! $c->apresData) {
                return $this->reporter($cle, $id, $jeton); // rien n'a pu partir
            }

            return $this->incertain($cle, $id, $jeton);
        } catch (EchecAuthentification) {
            return $this->authEchouee($cle, $id, $jeton);
        } catch (RefusConnexion) {
            // 5xx de bannière/EHLO/STARTTLS : le message n'est pas en cause. Alerte, pause, report ; jamais « echec ».
            return $this->connexionRefusee($cle, $id, $jeton);
        } catch (ServeurEnvoiRefuse $e) {
            // Refus LOCAL du transport (jeton, source, transaction, flux) : rien n'est parti et le message n'est pas
            // forcément en cause (l'enveloppe, elle, est vérifiée avant tout état) : report + alerte, JAMAIS « echec ».
            Log::critical('Envoi : refus local du transport d’injection, message reporté.', ['workspace_id' => $this->workspaceId, 'classe' => $e::class]);

            return $this->incertainSansEnvoi($cle, $id, $jeton);
        } catch (Throwable $e) {
            // Classe seulement : le message d'une exception réseau peut contenir des identifiants.
            Log::error('envoi.kumo_injection_incertaine', ['workspace_id' => $this->workspaceId, 'classe' => $e::class]);

            return $this->incertain($cle, $id, $jeton);
        }

        if ($code >= 200 && $code < 300) {
            $this->noter($cle, $id, 'accepte', $jeton);

            return ResultatInjection::accepte($id);
        }
        if ($code >= 400 && $code < 500) {
            return $this->reporter($cle, $id, $jeton);
        }
        if ($code >= 500 && $code < 600) {
            $this->noter($cle, $id, 'echec', $jeton);
            $this->registre->noter5xx($this->workspaceId, ($this->horloge)()->getTimestamp());
            $this->pauseSiTrop5xx();
            throw new RefusDefinitifServeur('Refus définitif du serveur d’envoi (' . $code . ').');
        }

        return $this->incertain($cle, $id, $jeton); // code inattendu : sort incertain
    }

    /**
     * Jeton interne X-Envoi-Jeton : 128 bits (32 hex), HMAC de l'espace et de la clé d'idempotence. Un jeton par
     * message ; le même pour un renvoi de la même clé. Il sert au dédoublonnage et, mis en forme d'UUID, d'identifiant
     * d'injection : l'adresse de rebond `rb-<jeton>` (Verp) se rattache donc à l'injection. Le transport SMTP pose ce VERP
     * en chemin de retour et X-Envoi-Source (testé : TransportSmtpTunnelTest). Le ComposeurMessage (List-Unsubscribe,
     * pied art. 14) n'est PAS utilisé : dette nommée dans docs/envoi/K9-adaptateur-kumo.md.
     */
    private function jetonPour(MessageAEnvoyer $message): string
    {
        $jeton = substr(hash_hmac('sha256', 'envoi-jeton:' . $this->workspaceId . ':' . $message->cleIdempotence, $this->jeton), 0, 32);
        if (preg_match('/^[0-9a-f]{32}$/', $jeton) !== 1) {
            throw new RuntimeException('Jeton d’envoi invalide.'); // inatteignable : garde de forme
        }

        return $jeton;
    }

    private static function idDepuisJeton(string $jeton): string
    {
        return (string) preg_replace('/^(.{8})(.{4})(.{4})(.{4})(.{12})$/', '$1-$2-$3-$4-$5', $jeton);
    }

    /** Aucun appel réseau dans une transaction ouverte (comme L4) : un rollback effacerait la trace d'un envoi parti. */
    private static function refuserTransactionEnglobante(): void
    {
        if (DB::transactionLevel() > 0
            && ! ((bool) config('envoi.smtp_tolere_transaction_englobante', false) && app()->environment('testing'))) {
            throw ServeurEnvoiRefuse::motif('injecter() ne s’appelle pas dans une transaction englobante.');
        }
    }

    /** Enveloppe valide (adresses RFC, partie locale ASCII) : refus AVANT tout état, sans adresse dans le journal. */
    private function verifierEnveloppe(MessageAEnvoyer $message): void
    {
        try {
            TransportSmtpTunnel::adressesValides($message->expediteur, $message->destinataire);
        } catch (Throwable) {
            Log::warning('envoi.kumo_enveloppe_invalide', ['workspace_id' => $this->workspaceId]);
            throw ServeurEnvoiRefuse::motif('enveloppe invalide (adresse expéditeur ou destinataire).');
        }
    }

    /** Filet : une injection restée « en cours » (processus tué) devient « à vérifier ». Jamais renvoyée. */
    public function reconcilierOrphelines(int $minutes = 5): int
    {
        $limite = ($this->horloge)()->getTimestamp() - max(1, $minutes) * 60;
        $n = 0;
        foreach ($this->registre->tout($this->workspaceId) as $cle => $l) {
            if ($l['etat'] === 'injection_en_cours' && $l['instant'] < $limite
                && $this->registre->ecrireSi($this->workspaceId, $cle, 'injection_en_cours', $l['instant'], ['etat' => 'a_verifier', 'instant' => ($this->horloge)()->getTimestamp()] + $l)) {
                $n++;
            }
        }

        return $n;
    }

    /** @param array{id: string, etat: string, reporte_au: ?int, instant: int, jeton?: string} $ligne */
    private function decisionSurExistant(string $cle, array $ligne): ?ResultatInjection
    {
        $demain = ($this->horloge)()->modify('tomorrow');
        switch ($ligne['etat']) {
            case 'accepte':
                return ResultatInjection::accepte($ligne['id']);
            case 'echec':
                throw new RefusDefinitifServeur('Refus définitif déjà enregistré pour cette clé.');
            case 'injection_en_cours':
                return ResultatInjection::incertain($demain);
            case 'a_verifier':
                // JAMAIS de renvoi : on interroge, et seul un sort CONNU change l'état.
                return match ($this->interroger($ligne['id'])) {
                    EtatMessage::Envoye, EtatMessage::EnFile, EtatMessage::Rebond => $this->confirmer($cle, $ligne),
                    EtatMessage::Refuse => $this->refuser($cle, $ligne),
                    EtatMessage::Inconnu => ResultatInjection::incertain($demain),
                };
            case 'reporte':
                if ($ligne['reporte_au'] !== null && $ligne['reporte_au'] > ($this->horloge)()->getTimestamp()) {
                    return ResultatInjection::reporteEchec(($this->horloge)()->setTimestamp($ligne['reporte_au']));
                }
        }

        return null;
    }

    /** @param array{id: string, jeton?: string} $ligne */
    private function confirmer(string $cle, array $ligne): ResultatInjection
    {
        $this->noter($cle, $ligne['id'], 'accepte', $ligne['jeton'] ?? '');

        return ResultatInjection::accepte($ligne['id']);
    }

    /** @param array{id: string, jeton?: string} $ligne */
    private function refuser(string $cle, array $ligne): never
    {
        // Sort Refuse LU par interrogation : ce n'est pas un 5xx reçu à l'injection, il ne compte pas pour la pause.
        $this->noter($cle, $ligne['id'], 'echec', $ligne['jeton'] ?? '');
        throw new RefusDefinitifServeur('Refus définitif constaté par le serveur d’envoi.');
    }

    private function reporter(string $cle, string $id, string $jeton): ResultatInjection
    {
        $jusqua = ($this->horloge)()->modify('+' . max(1, (int) config('envoi.smtp_delai_4xx_minutes', 15)) . ' minutes');
        $this->noter($cle, $id, 'reporte', $jeton, $jusqua->getTimestamp());

        return ResultatInjection::reporteEchec($jusqua);
    }

    private function incertain(string $cle, string $id, string $jeton): ResultatInjection
    {
        $this->noter($cle, $id, 'a_verifier', $jeton);

        return ResultatInjection::incertain(($this->horloge)()->modify('tomorrow'));
    }

    /** AUTH ou certificat refusé : alerte critique SANS secret ni trace, puis PAUSE ; au plus tôt demain, jamais +15 min. */
    private function authEchouee(string $cle, string $id, string $jeton): ResultatInjection
    {
        Log::critical('Envoi : authentification ou certificat du serveur d’envoi refusé, pause posée.', ['workspace_id' => $this->workspaceId]);
        $this->registre->fixerPause($this->workspaceId, true);
        $demain = ($this->horloge)()->modify('tomorrow');
        $this->noter($cle, $id, 'reporte', $jeton, $demain->getTimestamp());

        return ResultatInjection::reporteEchec($demain);
    }

    /** 5xx de connexion (IP non autorisée…) : alerte critique, pause, report au plus tôt demain ; pas un refus du message. */
    private function connexionRefusee(string $cle, string $id, string $jeton): ResultatInjection
    {
        Log::critical('Envoi : le serveur d’envoi refuse la connexion (5xx avant MAIL FROM), pause posée.', ['workspace_id' => $this->workspaceId]);
        $this->registre->fixerPause($this->workspaceId, true);

        return $this->incertainSansEnvoi($cle, $id, $jeton);
    }

    private function incertainSansEnvoi(string $cle, string $id, string $jeton): ResultatInjection
    {
        $demain = ($this->horloge)()->modify('tomorrow');
        $this->noter($cle, $id, 'reporte', $jeton, $demain->getTimestamp());

        return ResultatInjection::reporteEchec($demain);
    }

    private function noter(string $cle, string $id, string $etat, string $jeton, ?int $reporteAu = null): void
    {
        $ligne = ['id' => $id, 'etat' => $etat, 'reporte_au' => $reporteAu, 'instant' => ($this->horloge)()->getTimestamp(), 'jeton' => $jeton];
        if ($etat === 'accepte') {
            // Séquence STABLE assignée une fois, à l'acceptation : un événement ne se déplace jamais.
            $ligne['seq'] = $this->registre->lire($this->workspaceId, $cle)['seq'] ?? $this->registre->prochaineSequence($this->workspaceId);
        }
        $this->registre->ecrire($this->workspaceId, $cle, $ligne);
    }

    private function pauseSiTrop5xx(): void
    {
        $depuis = ($this->horloge)()->getTimestamp() - self::FENETRE_5XX_SECONDES;
        $n = $this->registre->compter5xx($this->workspaceId, $depuis);
        if ($n >= self::SEUIL_5XX) {
            Log::critical('Envoi : pause automatique du serveur (5xx répétés).', ['workspace_id' => $this->workspaceId, 'nombre_5xx' => $n]);
            $this->registre->fixerPause($this->workspaceId, true); // fail-safe : posée AVANT la commande, même si elle échoue
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
        foreach ($this->registre->tout($this->workspaceId) as $cle => $l) {
            if ($l['id'] !== $idInjection) {
                continue;
            }

            return match ($l['etat']) {
                'accepte' => EtatMessage::Envoye,
                'a_injecter', 'injection_en_cours', 'reporte' => EtatMessage::EnFile,
                'echec' => EtatMessage::Refuse,
                default => $this->etatDistant($cle, $l['id'], $l['jeton']),
            };
        }

        return EtatMessage::Inconnu;
    }

    private function etatDistant(string $cle, string $id, string $jeton): EtatMessage
    {
        // Même lecture que decisionSurExistant : Envoye/EnFile/Rebond = pris en charge, Refuse = échec définitif
        // (lu par interrogation : ne compte pas pour la pause), Inconnu = reste a_verifier.
        $etat = $this->interroger($id);
        match ($etat) {
            EtatMessage::Envoye, EtatMessage::EnFile, EtatMessage::Rebond => $this->noter($cle, $id, 'accepte', $jeton),
            EtatMessage::Refuse => $this->noter($cle, $id, 'echec', $jeton),
            EtatMessage::Inconnu => null,
        };

        return $etat;
    }

    public function pause(): void
    {
        // Pause posée AVANT la commande : passerelle injoignable => plus aucune injection (sûr par défaut).
        $this->registre->fixerPause($this->workspaceId, true);
        $this->commander('pause');
    }

    public function reprise(): void
    {
        $this->commander('reprise'); // si elle échoue, la pause reste posée
        $this->registre->fixerPause($this->workspaceId, false);
        $this->registre->reinitialiser5xx($this->workspaceId); // on ne repart pas avec les 5xx qui ont causé la pause
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
        $n = count(array_filter($this->registre->tout($this->workspaceId), fn (array $l): bool => $l['etat'] === 'accepte'));

        return new EtatServeur($this->registre->enPause($this->workspaceId), $n);
    }

    /** Aucun ping réseau. */
    public function sante(): SanteServeur
    {
        return new SanteServeur(true, $this->registre->enPause($this->workspaceId) ? 'kumo en pause' : 'kumo pret');
    }

    /** Curseur = dernier numéro de séquence rendu (stable, assigné à l'acceptation) ; illisible ou négatif => 0. */
    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $apres = $curseur === null ? 0 : max(0, (int) $curseur);
        $acceptes = array_filter(
            $this->registre->tout($this->workspaceId),
            fn (array $l): bool => $l['etat'] === 'accepte' && ($l['seq'] ?? 0) > $apres,
        );
        usort($acceptes, fn (array $a, array $b): int => $a['seq'] <=> $b['seq']);
        $tranche = array_slice($acceptes, 0, max(1, $limite));
        $evenements = [];
        foreach ($tranche as $l) {
            $evenements[] = new EvenementEnvoi('ev-' . $l['seq'], 'accepte_smtp', $l['id'], '');
        }

        return new LotEvenements($evenements, $tranche === [] ? $curseur : (string) end($tranche)['seq']);
    }
}
