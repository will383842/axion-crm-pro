<?php

namespace App\Envoi\Smtp;

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
use App\Envoi\Exceptions\RefusDefinitifServeur;
use App\Envoi\Serveur\Kumo\EsmtpSuiviData;
use App\Support\WorkspaceContext;
use Closure;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\RawMessage;

/**
 * Adaptateur SMTP d'UNE boîte (ou relais). Injecte un message déjà composé et
 * signé DKIM, sans le réécrire. Un seul envoi à la fois par boîte (verrou
 * consultatif Postgres + garde d'état).
 *
 * Règles d'état : a_injecter → injection_en_cours → accepte | a_verifier |
 * reporte (4xx) | echec (5xx). Toute coupure ou réponse inconnue => a_verifier :
 * JAMAIS de renvoi automatique d'un message dont le sort est incertain.
 * 3 × 5xx en 10 minutes => pause automatique de la boîte + alerte.
 * Aucune mesure d'ouverture. Aucun effacement. Aucun secret journalisé.
 *
 * Garanties de sécurité :
 *  - jamais d'identifiants sans TLS (TLS implicite, ou STARTTLS EXIGÉ), certificat validé ;
 *  - l'adresse IP de l'hôte est contrôlée (publique) et ÉPINGLÉE à chaque connexion ;
 *  - injecter() est refusé dans une transaction englobante : l'état « injection_en_cours »
 *    doit être COMMITÉ avant le réseau, sinon un rollback effacerait la trace d'un envoi parti ;
 *  - tout ce qui peut être refusé (enveloppe, expéditeur, CR/LF isolés, hôte) l'est AVANT
 *    le passage à « injection_en_cours ».
 */
final class AdaptateurSmtp implements ServeurEnvoi
{
    public const SEUIL_5XX = 3;

    public const FENETRE_5XX_MINUTES = 10;

    /** @var Closure(BoiteSmtp): TransportInterface */
    private readonly Closure $fabriqueTransport;

    private readonly ResolveurHote $resolveur;

    /** @param ?Closure(BoiteSmtp): TransportInterface $fabriqueTransport */
    public function __construct(
        private readonly string $workspaceId,
        private readonly string $boiteId,
        private readonly SourceMessageSigne $source,
        private readonly DepotBoites $boites,
        private readonly DepotSocle $depot,
        ?Closure $fabriqueTransport = null,
        ?ResolveurHote $resolveur = null,
    ) {
        $this->resolveur = $resolveur ?? new ResolveurDns;
        $this->fabriqueTransport = $fabriqueTransport ?? fn (BoiteSmtp $b): TransportInterface => self::transportParDefaut($b, $this->resolveur);
    }

    /**
     * Transport réel. Jamais d'identifiants en clair :
     *  - « tls » : TLS implicite ; « starttls » : STARTTLS EXIGÉ (setRequireTls) ; certificat validé
     *    contre le NOM de la boîte (peer_name) alors que la connexion vise l'IP contrôlée ;
     *  - « aucun » : n'existe qu'en boucle locale de test, et n'envoie AUCUN identifiant.
     * L'adresse est résolue et contrôlée ICI, à la connexion : un nom devenu privé (rebinding) est refusé.
     */
    public static function transportParDefaut(BoiteSmtp $boite, ?ResolveurHote $resolveur = null): TransportInterface
    {
        $ip = GardeHoteSmtp::controlerBoite($boite->hote, $boite->port, $boite->chiffrement, $resolveur ?? new ResolveurDns);

        if ($boite->chiffrement === 'aucun') {
            $t = new EsmtpSuiviData($ip, $boite->port, false);
            $t->setAutoTls(false);

            return $t; // jamais d'identifiants sans TLS
        }

        $t = new EsmtpSuiviData(str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $boite->port, $boite->chiffrement === 'tls');
        if ($boite->chiffrement === 'starttls') {
            $t->setAutoTls(true);
            $t->setRequireTls(true);
        }
        $flux = $t->getStream();
        if (! $flux instanceof SocketStream) {
            throw EnvoiSmtpRefuse::motif('flux SMTP inattendu : validation du certificat impossible.');
        }
        $flux->setStreamOptions(['ssl' => [
            'peer_name' => $boite->hote,
            'SNI_enabled' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
        ]]);
        if ($boite->utilisateur !== '') {
            $t->setUsername($boite->utilisateur);
            $t->setPassword($boite->secret());
        }

        return $t;
    }

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if ($message->workspaceId !== $this->workspaceId) {
            throw EnvoiRefuse::autreEspace();
        }
        self::refuserTransactionEnglobante();
        $boite = $this->boites->trouver($this->workspaceId, $this->boiteId)
            ?? throw EnvoiSmtpRefuse::motif('Boîte d\'envoi introuvable.'); // refus CERTAIN, avant tout envoi

        // Tout ce qui peut être refusé l'est ICI, avant le moindre état d'injection.
        $enveloppe = self::enveloppe($message, $boite);

        // Chemin rapide : un sort déjà connu ne déclenche JAMAIS un nouvel envoi (revérifié sous verrou).
        $deja = $this->decisionSurExistant($message);
        if ($deja !== null) {
            return $deja;
        }

        $verrou = 'envoi:boite:' . $this->boiteId;
        if (! (bool) DB::selectOne('SELECT pg_try_advisory_lock(hashtextextended(?, 0)) AS ok', [$verrou])->ok) {
            return ResultatInjection::reporte(new DateTimeImmutable('+1 minute'));
        }
        try {
            return $this->injecterSousVerrou($message, $enveloppe);
        } finally {
            DB::select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', [$verrou]);
        }
    }

    /**
     * Dans une transaction englobante, « injection_en_cours » ne serait pas commité avant le
     * réseau : un rollback ultérieur effacerait la trace d'un message DÉJÀ parti => doublon au
     * rejeu. Dérogation de test (RefreshDatabase) : honorée UNIQUEMENT sous APP_ENV=testing.
     */
    private static function refuserTransactionEnglobante(): void
    {
        if (DB::transactionLevel() > 0
            && ! ((bool) config('envoi.smtp_tolere_transaction_englobante', false) && app()->environment('testing'))) {
            throw EnvoiSmtpRefuse::motif('injecter() ne s\'appelle pas dans une transaction englobante (l\'état injection_en_cours doit être commité avant le réseau).');
        }
    }

    /** Valide l'enveloppe (adresses RFC) ET que l'expéditeur est l'adresse de la boîte. */
    private static function enveloppe(MessageAEnvoyer $message, BoiteSmtp $boite): Envelope
    {
        try {
            $expediteur = new Address($message->expediteur);
            $destinataire = new Address($message->destinataire);
        } catch (\Throwable) {
            throw EnvoiSmtpRefuse::motif('enveloppe invalide (adresse expéditeur ou destinataire).');
        }
        if (strtolower($expediteur->getAddress()) !== strtolower($boite->adresse)) {
            throw EnvoiSmtpRefuse::motif('l\'expéditeur n\'est pas l\'adresse de la boîte d\'envoi.');
        }

        return new Envelope($expediteur, [$destinataire]);
    }

    /** CR ou LF isolé : contrebande SMTP possible. Seul CRLF est admis. */
    private static function verifierFinsDeLigne(string $brut): void
    {
        if ($brut === '' || preg_match('/\r(?!\n)|(?<!\r)\n/', $brut) === 1) {
            throw EnvoiSmtpRefuse::motif('message brut vide ou contenant un CR/LF isolé.');
        }
    }

    /** Sort déjà connu pour cette clé : résultat à rendre sans rien envoyer, ou null si l'envoi est permis. */
    private function decisionSurExistant(MessageAEnvoyer $message): ?ResultatInjection
    {
        $existant = $this->ligne($message->cleIdempotence);
        if ($existant === null) {
            return null;
        }
        switch ($existant->etat) {
            case 'accepte':
                return ResultatInjection::accepte((string) $existant->id_injection);
            case 'echec':
                throw new RefusDefinitifServeur('Refus définitif (5xx) déjà enregistré pour cette clé.');
            case 'injection_en_cours':
            case 'a_verifier':
                return ResultatInjection::incertain(new DateTimeImmutable('tomorrow'));
            case 'reporte':
                if ($existant->reporte_au !== null && new DateTimeImmutable((string) $existant->reporte_au) > new DateTimeImmutable) {
                    return ResultatInjection::reporte(new DateTimeImmutable((string) $existant->reporte_au));
                }
                break;
        }

        return null;
    }

    private function injecterSousVerrou(MessageAEnvoyer $message, Envelope $enveloppe): ResultatInjection
    {
        // Tout est relu SOUS le verrou : un autre processus a pu agir depuis les lectures précédentes.
        $deja = $this->decisionSurExistant($message);
        if ($deja !== null) {
            return $deja;
        }
        $boite = $this->boites->trouver($this->workspaceId, $this->boiteId)
            ?? throw EnvoiSmtpRefuse::motif('Boîte d\'envoi introuvable.'); // rien n'est parti : refus CERTAIN
        if ($boite->enPause || $this->limiteAtteinte()) {
            return ResultatInjection::reporte(new DateTimeImmutable('tomorrow'));
        }

        // Rien n'est parti tant que le brut n'est pas obtenu et validé : une erreur ici n'a aucun effet.
        $brut = $this->source->brut($message);
        self::verifierFinsDeLigne($brut);

        // Un seul envoi à la fois : une autre injection en cours (ou incertaine) bloque la boîte.
        $bloquante = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('boite_id', $this->boiteId)->whereIn('etat', ['injection_en_cours', 'a_verifier'])
            ->where('cle_idempotence', '<>', $message->cleIdempotence)->exists());
        if ($bloquante) {
            return ResultatInjection::reporte(new DateTimeImmutable('+1 minute'));
        }

        // Transport (résolution + contrôle de l'adresse, épinglée) AVANT l'état : un refus d'hôte ne laisse aucune trace.
        $transport = ($this->fabriqueTransport)($boite);

        WorkspaceContext::run($this->workspaceId, fn () => DB::statement(
            "INSERT INTO envoi_injections_smtp (workspace_id, boite_id, cle_idempotence, etat) VALUES (?, ?, ?, 'a_injecter')
             ON CONFLICT (workspace_id, cle_idempotence) DO NOTHING",
            [$this->workspaceId, $this->boiteId, $message->cleIdempotence],
        ));
        // Hors transaction : chaque instruction est commitée IMMÉDIATEMENT, donc AVANT le réseau.
        $pris = $this->maj($message->cleIdempotence, ['etat' => 'injection_en_cours'], ['a_injecter', 'reporte']);
        if (! $pris) {
            return ResultatInjection::incertain(new DateTimeImmutable('+1 minute'));
        }
        $id = (string) $this->ligne($message->cleIdempotence)?->id_injection;

        try {
            $transport->send(new RawMessage($brut), $enveloppe);
        } catch (TransportExceptionInterface $e) {
            return $this->classer($message->cleIdempotence, $id, $e, $transport);
        } catch (\Throwable) {
            // Coupure, délai dépassé, réponse illisible : sort INCERTAIN.
            $this->maj($message->cleIdempotence, ['etat' => 'a_verifier'], ['injection_en_cours']);

            return ResultatInjection::incertain(new DateTimeImmutable('tomorrow'));
        } finally {
            try {
                if ($transport instanceof EsmtpTransport) {
                    $transport->stop();
                }
            } catch (\Throwable) {
                // Fermeture : sans effet sur l'état.
            }
        }
        $this->maj($message->cleIdempotence, ['etat' => 'accepte', 'code_smtp' => 250], ['injection_en_cours']);

        return ResultatInjection::accepte($id);
    }

    private function classer(string $cle, string $id, TransportExceptionInterface $e, TransportInterface $transport): ResultatInjection
    {
        // Le code SMTP est le code NUMÉRIQUE de la réponse du serveur, jamais celui lu dans un texte.
        $code = $e instanceof UnexpectedResponseException && $e->getCode() >= 100 && $e->getCode() < 600 ? (int) $e->getCode() : null;

        if ($code !== null && $code >= 400 && $code < 500) {
            $delai = new DateTimeImmutable('+' . max(1, (int) config('envoi.smtp_delai_4xx_minutes', 15)) . ' minutes');
            $this->maj($cle, ['etat' => 'reporte', 'code_smtp' => $code, 'reporte_au' => $delai->format('c')], ['injection_en_cours']);

            return ResultatInjection::reporte($delai);
        }
        if ($code !== null && $code >= 500 && $transport instanceof EsmtpSuiviData && ! $transport->mailEmis && ! $transport->donneesEngagees) {
            // 5xx AVANT MAIL FROM (bannière, EHLO, STARTTLS) : refus de la CONNEXION, pas du message. Rien n'a pu partir :
            // report, alerte critique et pause ; jamais « echec », jamais compté dans les 5xx de messages.
            return $this->refusConnexion($cle, $code);
        }
        if ($code !== null && $code >= 500) {
            $this->maj($cle, ['etat' => 'echec', 'code_smtp' => $code], ['injection_en_cours']);
            $this->pauseSiTrop5xx();
            throw new RefusDefinitifServeur('Refus définitif du serveur SMTP (' . $code . ').');
        }
        if (str_contains($e->getMessage(), 'Connection could not be established')) {
            // Aucune connexion : rien n'a pu être transmis, report simple.
            $delai = new DateTimeImmutable('+' . max(1, (int) config('envoi.smtp_delai_4xx_minutes', 15)) . ' minutes');
            $this->maj($cle, ['etat' => 'reporte', 'reporte_au' => $delai->format('c')], ['injection_en_cours']);

            return ResultatInjection::reporte($delai);
        }
        // Coupure / réponse inconnue : a_verifier, aucun renvoi automatique.
        $this->maj($cle, ['etat' => 'a_verifier', 'code_smtp' => $code], ['injection_en_cours']);

        return ResultatInjection::incertain(new DateTimeImmutable('tomorrow'));
    }

    private function refusConnexion(string $cle, int $code): ResultatInjection
    {
        $delai = new DateTimeImmutable('+' . max(1, (int) config('envoi.smtp_delai_4xx_minutes', 15)) . ' minutes');
        $this->maj($cle, ['etat' => 'reporte', 'code_smtp' => $code, 'reporte_au' => $delai->format('c')], ['injection_en_cours']);
        $this->boites->mettreEnPause($this->workspaceId, $this->boiteId, 'refus_connexion_5xx');
        Log::critical('Envoi : connexion SMTP refusée (5xx avant MAIL FROM), boîte mise en pause, message reporté.', ['boite' => $this->boiteId, 'code' => $code]);

        return ResultatInjection::reporte($delai);
    }

    private function pauseSiTrop5xx(): void
    {
        $n = (int) WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('boite_id', $this->boiteId)->where('etat', 'echec')
            ->whereRaw("maj_le >= now() - interval '" . self::FENETRE_5XX_MINUTES . " minutes'")->count());
        if ($n >= self::SEUIL_5XX) {
            $this->boites->mettreEnPause($this->workspaceId, $this->boiteId, 'auto_3x5xx_10min');
            Log::critical('Envoi : boîte mise en pause automatique (5xx répétés).', ['boite' => $this->boiteId, 'nombre_5xx' => $n]);
            Event::dispatch(new BoitePauseAutomatique($this->workspaceId, $this->boiteId, $n));
        }
    }

    private function limiteAtteinte(): bool
    {
        $b = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_boites')->where('id', $this->boiteId)->first(['limite_jour', 'limite_heure']));
        if ($b === null) {
            return true;
        }
        $compte = fn (string $depuis): int => (int) WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('boite_id', $this->boiteId)->whereIn('etat', ['accepte', 'a_verifier', 'injection_en_cours'])
            ->whereRaw("maj_le >= {$depuis}")->count());

        return $compte("date_trunc('day', now())") >= (int) $b->limite_jour
            || $compte("now() - interval '1 hour'") >= (int) $b->limite_heure;
    }

    /**
     * @param  array<string, mixed>  $valeurs
     * @param  list<string>  $depuis  états de départ admis (transition gardée)
     */
    private function maj(string $cle, array $valeurs, array $depuis): bool
    {
        return WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('workspace_id', $this->workspaceId)->where('cle_idempotence', $cle)
            ->whereIn('etat', $depuis)
            ->update($valeurs + ['maj_le' => now()])) > 0;
    }

    /** @return (\stdClass&object{etat: string, id_injection: string, reporte_au: ?string})|null */
    private function ligne(string $cle): ?object
    {
        /** @var (\stdClass&object{etat: string, id_injection: string, reporte_au: ?string})|null $ligne */
        $ligne = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('workspace_id', $this->workspaceId)->where('cle_idempotence', $cle)->first());

        return $ligne;
    }

    /** Filet : une injection restée « en cours » (processus tué) devient « à vérifier ». Jamais renvoyée. */
    public function marquerOrphelinesAVerifier(int $minutes = 5): int
    {
        return WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('boite_id', $this->boiteId)->where('etat', 'injection_en_cours')
            ->whereRaw('maj_le < now() - make_interval(mins => ?)', [$minutes])
            ->update(['etat' => 'a_verifier', 'maj_le' => now()]));
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        if (preg_match('/^[0-9a-f-]{36}$/i', $idInjection) !== 1) {
            return EtatMessage::Inconnu;
        }
        $etat = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('workspace_id', $this->workspaceId)->where('id_injection', $idInjection)->value('etat'));

        return match ($etat) {
            'accepte' => EtatMessage::Envoye,
            'a_injecter', 'injection_en_cours', 'reporte' => EtatMessage::EnFile,
            'echec' => EtatMessage::Refuse,
            default => EtatMessage::Inconnu, // a_verifier ou absent
        };
    }

    public function pause(): void
    {
        $this->boites->mettreEnPause($this->workspaceId, $this->boiteId, 'manuelle');
    }

    public function reprise(): void
    {
        $this->boites->reprendre($this->workspaceId, $this->boiteId);
    }

    public function modeTemoins(): bool
    {
        return $this->depot->modeTemoins($this->workspaceId);
    }

    public function etat(): EtatServeur
    {
        $boite = $this->boites->trouver($this->workspaceId, $this->boiteId);
        $n = (int) WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('boite_id', $this->boiteId)->where('etat', 'accepte')->count());

        return new EtatServeur($boite->enPause ?? true, $n);
    }

    /** Aucun ping réseau : la santé reflète l'existence et l'état de la boîte. */
    public function sante(): SanteServeur
    {
        $boite = $this->boites->trouver($this->workspaceId, $this->boiteId);

        return new SanteServeur($boite !== null, $boite === null ? 'boite introuvable' : ($boite->enPause ? 'boite en pause' : 'boite prete'));
    }

    /** Événements dérivés des acceptations SMTP. Jamais d'ouverture. */
    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        $debut = $curseur === null ? 0 : (int) $curseur;
        $lignes = WorkspaceContext::run($this->workspaceId, fn () => DB::table('envoi_injections_smtp')
            ->where('boite_id', $this->boiteId)->where('etat', 'accepte')->where('id', '>', $debut)
            ->orderBy('id')->limit(max(1, $limite))->get(['id', 'id_injection']));
        $evenements = [];
        foreach ($lignes as $l) {
            $evenements[] = new EvenementEnvoi('ev-' . $l->id, 'accepte_smtp', (string) $l->id_injection, '');
        }
        $suivant = $lignes->isEmpty() ? $curseur : (string) $lignes->last()->id;

        return new LotEvenements($evenements, $suivant);
    }
}
