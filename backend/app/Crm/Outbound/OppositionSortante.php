<?php

namespace App\Crm\Outbound;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Point UNIQUE par lequel une opposition NÉE AU CRM part vers le site (STOP-T4-CRM).
 *
 * Règles :
 *  - le « stop » gagne toujours, rien n'est jamais supprimé : on ne fait qu'AJOUTER une ligne
 *    d'outbox (`crm_outbound_events`), après l'écriture `opt_out`, dans la même transaction ;
 *  - ANTI-BOUCLE en liste BLANCHE : on n'émet que si la ligne `opt_out` (empreinte, portée) porte une
 *    source de {@see self::SOURCES_CRM}. Opposition venue du site (`site-sync:*`,
 *    `gdpr_erasure_bisystem`, `gdpr_erasure_demande_site`), source inconnue ou ancienne : rien ne part ;
 *  - rejeu = une seule ligne : si un événement `consent_optout` existe déjà (en attente, à reprendre ou
 *    envoyé) pour (empreinte, portée), rien de plus n'est écrit ;
 *  - le payload reste LOCAL et n'a jamais d'adresse : motif et canal seulement.
 *
 * Ne pas appeler depuis le code d'ingestion venu du site (`app/Crm/Ingest/`, `SiteGdprService`) : une
 * garde de structure le vérifie.
 */
final class OppositionSortante
{
    /** Sources `opt_out` écrites par un geste CRM (`campagne:` est traité en préfixe). */
    public const SOURCES_CRM = [
        'desinscription_lien',
        'console:journalists',
        'gdpr_art21_console',
        'gdpr_erasure',
        'campagne:*',
        'registre_manuel',
    ];

    /** Source posée quand la demande RGPD a été créée par le site : jamais émise. */
    public const SOURCE_DEMANDE_SITE = 'gdpr_erasure_demande_site';

    /** Motifs autorisés : texte court, jamais d'adresse. */
    public const MOTIFS = [
        'desinscription_lien',
        'opposition_presse',
        'effacement_presse',
        'rgpd_art21',
        'rgpd_art17',
        'retour_campagne_desinscription',
        'retour_campagne_plainte',
        'registre_manuel',
        'rattrapage',
    ];

    private const PORTEES = ['business', 'vivier'];

    /** Une source est-elle née au CRM (liste blanche) ? */
    public static function sourceEstCrm(?string $source): bool
    {
        if ($source === null || $source === '') {
            return false;
        }
        if (str_starts_with($source, 'campagne:')) {
            return true;
        }

        return in_array($source, self::SOURCES_CRM, true);
    }

    /**
     * Met en file `consent_optout` pour (empreinte, portée). LÈVE en cas d'erreur.
     *
     * @return ?string l'`event_id`, ou null si rien ne part (origine site, source inconnue, déjà signalé)
     */
    public function signaler(string $empreinte, string $portee, string $motif, string $canal): ?string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $empreinte) !== 1) {
            throw new InvalidArgumentException('Empreinte invalide : 64 caractères hexadécimaux minuscules attendus.');
        }
        if (! in_array($portee, self::PORTEES, true)) {
            throw new InvalidArgumentException('Portée hors business/vivier.');
        }
        if (! in_array($motif, self::MOTIFS, true)) {
            throw new InvalidArgumentException('Motif inconnu.');
        }

        return DB::transaction(function () use ($empreinte, $portee, $motif, $canal): ?string {
            // Ferme la course « deux gestes lisent rien puis insèrent deux fois » : le verrou de ligne du
            // recorder ne couvre pas le cas où aucune ligne n'existe encore.
            DB::select(
                'select pg_advisory_xact_lock(hashtextextended(?, 0))',
                ['crm_outbound|consent_optout|' . $portee . '|' . $empreinte],
            );

            $source = DB::table('opt_out')
                ->where('scope', $portee)
                ->where('email_hash', $empreinte)
                ->orderBy('created_at')
                ->orderBy('id')
                ->value('source');

            if (! self::sourceEstCrm(is_string($source) ? $source : null)) {
                return null; // anti-boucle : absente ou venue du site
            }

            $dejaSignale = DB::table('crm_outbound_events')
                ->where('event_type', 'consent_optout')
                ->where('email_hash', $empreinte)
                ->where('scope', $portee)
                ->whereIn('status', ['pending', 'failed', 'sent'])
                ->exists();
            if ($dejaSignale) {
                return null;
            }

            return (new ConsentOutboundRecorder)->record(
                'consent_optout',
                $empreinte,
                $portee,
                null,
                ['motif' => $motif, 'canal' => $canal],
            );
        });
    }

    /** Idem {@see self::signaler()} à partir de l'adresse (l'empreinte est calculée ici, l'adresse n'est pas gardée). */
    public function signalerAdresse(string $email, string $portee, string $motif, string $canal): ?string
    {
        return $this->signaler(ConsentOutboundRecorder::hashEmail($email), $portee, $motif, $canal);
    }

    /**
     * Comme {@see self::signaler()} mais ne fait JAMAIS échouer l'appelant : point de sauvegarde (transaction
     * imbriquée) + journal sans adresse. L'opposition de l'appelant est toujours validée ; le rattrapage
     * (`crm:outbound-rattraper`) reprend ce qui n'est pas parti.
     */
    public function signalerSansBloquer(string $empreinte, string $portee, string $motif, string $canal): ?string
    {
        try {
            return $this->signaler($empreinte, $portee, $motif, $canal);
        } catch (Throwable $e) {
            // JAMAIS l'exception brute : voir exceptionSansFuite().
            $court = substr($empreinte, 0, 12);
            Log::error('crm.outbound.record_failed', [
                'empreinte' => $court,
                'portee' => $portee,
                'motif' => $motif,
            ]);
            report(self::exceptionSansFuite($e, $empreinte));

            return null;
        }
    }

    /**
     * Exception neuve, sans précédente, à donner à `report()` à la place de celle d'un signalement raté : le message
     * d'une QueryException embarque le SQL et les valeurs liées (empreinte complète, clé de verrou advisory) et
     * `report()` les enverrait au journal, à stderr et à Sentry, y compris via getPrevious(). Message fixe : SQLSTATE
     * et empreinte tronquée à 12 caractères seulement.
     */
    public static function exceptionSansFuite(Throwable $e, string $empreinte): RuntimeException
    {
        if ($empreinte === '') {
            return new RuntimeException(sprintf('Rattrapage sortant impossible (SQLSTATE %s).', self::sqlstateDe($e)));
        }

        return new RuntimeException(sprintf(
            'Signalement sortant impossible (SQLSTATE %s, empreinte %s).',
            self::sqlstateDe($e),
            substr($empreinte, 0, 12),
        ));
    }

    /** Vrai si l'exception ou l'une de ses précédentes est une QueryException ou une PDOException (SQL et valeurs liées dans le message). */
    public static function porteUneRequete(Throwable $e): bool
    {
        for ($c = $e; $c !== null; $c = $c->getPrevious()) {
            if ($c instanceof QueryException || $c instanceof PDOException) {
                return true;
            }
        }

        return false;
    }

    /** SQLSTATE (5 caractères) de l'exception ou de sa chaîne, « inconnu » sinon ; jamais de texte libre. */
    private static function sqlstateDe(Throwable $e): string
    {
        for ($c = $e; $c !== null; $c = $c->getPrevious()) {
            $code = ($c instanceof QueryException || $c instanceof PDOException) ? ($c->errorInfo[0] ?? $c->getCode()) : $c->getCode();
            if (is_string($code) && preg_match('/^[0-9A-Z]{5}$/', $code) === 1) {
                return $code;
            }
        }

        return 'inconnu';
    }

    public function signalerAdresseSansBloquer(string $email, string $portee, string $motif, string $canal): ?string
    {
        return $this->signalerSansBloquer(ConsentOutboundRecorder::hashEmail($email), $portee, $motif, $canal);
    }
}
