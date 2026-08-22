<?php

namespace App\Crm\Outbound;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PRODUCTEUR de la mini-outbox CRM → site (lot L5).
 *
 * Appelé aux endroits où une opposition NAÎT côté CRM — c'est-à-dire là où un
 * humain de la console décide (traitement d'une demande RGPD art. 17,
 * opposition d'un journaliste). Il écrit une ligne `crm_outbound_events` ;
 * l'émission réelle est le travail de `crm:flush-outbound`, gaté.
 *
 * ── CE QU'IL NE FAIT PAS, ET POURQUOI ───────────────────────────────────────
 * Il n'est JAMAIS branché sur SiteSyncIngestService ni SiteGdprService : ces
 * deux services inscrivent des oppositions venues DU SITE. Les renvoyer au site
 * serait une boucle. Le garde-fou n'est pas une convention d'appel mais un
 * refus explicite (`record()` n'accepte que `origin = 'crm'`), doublé d'une
 * contrainte CHECK en base.
 *
 * ── INERTIE ─────────────────────────────────────────────────────────────────
 * Le recorder n'est PAS gaté par `CRM_OUTBOUND_ENABLED` : il remplit un
 * journal local, ce qui ne change aucune réponse d'API et ne sort pas du
 * serveur. Le drapeau garde la seule chose qui soit observable de l'extérieur —
 * l'émission HTTP. Conséquence assumée : à l'activation, le backlog accumulé
 * part d'un coup ; `crm:flush-outbound --limit` le débite par lots.
 */
final class ConsentOutboundRecorder
{
    /**
     * Version du contrat CRM → site, transportée dans CHAQUE corps émis.
     *
     * 🔴 B14-008 : le sens site → CRM exigeait une version et refusait dur toute
     * autre valeur (SiteSyncEvent::SCHEMA_VERSION, rejet
     * `unsupported_schema_version`), alors que le sens CRM → site n'en portait
     * AUCUNE — mesure du 2026-08-22 : `grep -n schema_version` sur
     * CrmFlushOutbound.php ne rendait aucune ligne. Un contrat sans version est
     * un contrat qu'on ne peut pas faire évoluer sans casser le récepteur en
     * silence : le jour où une clé change de sens, le site n'a aucun moyen de
     * savoir à quelle génération il parle.
     *
     * Pourquoi une constante ICI plutôt que SiteSyncEvent::SCHEMA_VERSION : ce
     * sont DEUX contrats distincts, aux vocabulaires disjoints (`consent_optout`
     * d'un côté, `form_submission` de l'autre). Les faire partager une constante
     * les ferait avancer ensemble sans raison.
     *
     * ⚠️ Si le site passe un jour en schéma STRICT à l'entrée (constat I49-002),
     * `schema_version` doit figurer dans SA liste blanche, sinon tout le canal
     * d'opposition RGPD se ferme en 422.
     */
    public const SCHEMA_VERSION = 1;

    /**
     * Vocabulaire FERMÉ, aligné sur la contrainte CHECK de la table
     * (`2026_08_14_000007_crm_outbound_events.php`, ligne 59).
     *
     * 🔴 B14-001 / I49-001 — mesure du 2026-08-22 : un `grep` de
     * `consent_optin` sur tout le backend ne rend QUE deux lignes — cette
     * déclaration et le CHECK. Aucun producteur, nulle part. Le défaut n'était
     * pas la valeur : c'était le SILENCE. Rien ne permettait de distinguer
     * « réserve assumée » de « producteur oublié », et un lecteur n'avait aucun
     * moyen de trancher.
     *
     * C'est une RÉSERVE, et la preuve est chez le récepteur : le site déclare
     * les trois mêmes types (site : `src/server/crm-sync/inbound.ts:58`, et son
     * `EVENT_TYPES` l.133) et IGNORE délibérément l'opt-in (`applyEffect`,
     * l.246 : `if (payload.event_type === "consent_optin") return "ignored"` —
     * « l'opposition gagne toujours » : un opt-in venu du CRM ne doit jamais
     * ressusciter une désinscription faite sur le site, où le consentement a
     * été recueilli). Retirer la valeur ici rétrécirait donc, d'un seul côté,
     * un contrat que l'autre bout tient pour ouvert — et le CHECK SQL, lui,
     * continuerait de l'accepter.
     */
    public const EVENT_TYPES = ['consent_optout', 'consent_optin', 'erasure'];

    /**
     * Les types DÉCLARÉS que le CRM n'émet pas, et qu'il assume de ne pas
     * émettre. Cette liste n'existe pas pour excuser un trou : elle existe pour
     * que la garde B14-001 puisse exiger un producteur pour TOUS les autres.
     * Y ajouter une valeur est un aveu — il se motive juste au-dessus.
     *
     * Le jour où quelqu'un décide qu'un opt-in recueilli DANS la console doit
     * remonter au site, le producteur s'écrit ET la valeur sort d'ici : la
     * garde refuse qu'un type soit à la fois réservé et produit.
     *
     * @var list<string>
     */
    public const EVENT_TYPES_SANS_PRODUCTEUR = ['consent_optin'];

    public const SCOPES = ['business', 'vivier'];

    /** Le CRM n'émet que ce dont il est l'origine. Voir OutboundRejection::originNotCrm(). */
    public const ORIGIN_CRM = 'crm';

    /**
     * Met en file un événement de consentement à destination du site.
     *
     * @param  ?string  $personKey  ⚠️ B14-012, NON TRANCHÉ — mesure du
     *                              2026-08-22 : aucun des trois sites d'appel du dépôt
     *                              (`JournalistsController::optOut`, `::destroy`,
     *                              `RgpdRequestsController::queueOutbound`) ne le renseigne ; la colonne
     *                              part donc TOUJOURS à null, et `crm:flush-outbound` l'émet telle quelle.
     *                              Ce n'est pas un oubli à combler à la légère : `person_key` est dérivée
     *                              de l'adresse, donc pseudonymisante — la renseigner ferait SORTIR du
     *                              serveur une corrélation qui n'en sortait pas. C'est une décision de
     *                              conformité, pas un patch : ne pas la remplir sans qu'elle soit prise.
     * @param  array<string, mixed>  $payload  Contexte non identifiant (motif, surface console…).
     * @return string L'`event_id` (UUID) mis en file, ou celui d'un doublon déjà en attente.
     *
     * @throws OutboundRejection
     */
    public function record(
        string $eventType,
        string $emailHash,
        string $scope,
        ?string $personKey = null,
        array $payload = [],
        string $origin = self::ORIGIN_CRM,
    ): string {
        // ── Anti-boucle : le contrôle vient EN PREMIER ──────────────────────
        // Avant même la validation du type : un événement venu du site est
        // refusé quoi qu'il contienne par ailleurs.
        if ($origin !== self::ORIGIN_CRM) {
            throw OutboundRejection::originNotCrm($origin);
        }

        if (! in_array($eventType, self::EVENT_TYPES, true)) {
            throw OutboundRejection::unknownEventType($eventType);
        }

        if (! in_array($scope, self::SCOPES, true)) {
            throw OutboundRejection::unknownScope($scope);
        }

        $emailHash = trim($emailHash);
        if ($emailHash === '') {
            throw OutboundRejection::missingEmailHash();
        }

        // Anti-doublon : une même opposition cliquée deux fois dans la console
        // ne doit pas produire deux POST. On ne dédoublonne QUE contre les
        // lignes encore en attente — un événement déjà envoyé puis re-décidé
        // est un événement neuf, qui doit repartir.
        //
        // 🔴 B14-014 — la recherche et l'écriture sont DANS LA MÊME transaction,
        // avec verrou de ligne. Auparavant la lecture (`value('event_id')`)
        // vivait seule : deux gestes simultanés lisaient tous deux « rien en
        // attente » et inséraient deux lignes. Le dédoublonnage ne tenait que
        // par la chance de l'ordonnancement.
        return DB::transaction(function () use ($eventType, $emailHash, $scope, $personKey, $payload): string {
            $pending = DB::table('crm_outbound_events')
                ->where('event_type', $eventType)
                ->where('email_hash', $emailHash)
                ->where('scope', $scope)
                ->whereIn('status', ['pending', 'failed'])
                ->lockForUpdate()
                ->first(['event_id', 'payload']);

            if ($pending !== null) {
                // 🔴 B14-014, mesuré le 2026-08-22 : le retour anticipé JETAIT
                // le contexte du second geste. Deux décisions prises sur deux
                // surfaces (console journalistes, puis demande RGPD art. 21) ne
                // laissaient dans le journal que la trace de la PREMIÈRE — la
                // ligne restait vraie sur QUI est opposé, et fausse sur POURQUOI
                // et QUAND. Le message qui partira au site reste unique (c'est
                // bien un seul événement), mais sa justification doit être la
                // plus récente : les clés du nouveau contexte écrasent l'ancien.
                $ancien = json_decode((string) $pending->payload, true);
                $fusion = array_merge(is_array($ancien) ? $ancien : [], $payload);

                DB::table('crm_outbound_events')
                    ->where('event_id', $pending->event_id)
                    ->update([
                        'payload' => json_encode($fusion, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                        // `next_attempt_at` reste INTACT, délibérément : le
                        // rafraîchir remettrait à zéro le backoff d'une ligne
                        // `failed`, et un doublon re-cliqué en boucle relancerait
                        // le site sans jamais attendre.
                    ]);

                return (string) $pending->event_id;
            }

            $eventId = (string) Str::uuid();

            DB::table('crm_outbound_events')->insert([
                'event_id' => $eventId,
                'event_type' => $eventType,
                'person_key' => $personKey,
                'email_hash' => $emailHash,
                'scope' => $scope,
                'origin' => self::ORIGIN_CRM,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'status' => 'pending',
                'attempts' => 0,
                // Dû immédiatement : le premier essai n'attend pas un backoff.
                'next_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $eventId;
        });
    }

    /**
     * Variante à partir de l'email en clair — le hash est calculé ici, et
     * l'email n'est JAMAIS stocké : la table de sortie ne porte pas de PII.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws OutboundRejection
     */
    public function recordForEmail(
        string $eventType,
        string $email,
        string $scope,
        ?string $personKey = null,
        array $payload = [],
        string $origin = self::ORIGIN_CRM,
    ): string {
        return $this->record($eventType, self::hashEmail($email), $scope, $personKey, $payload, $origin);
    }

    /**
     * sha256 de l'email normalisé, SANS sel. Définition partagée avec
     * `SiteSyncEvent::emailHash()` et `opt_out.email_hash` : les deux systèmes
     * doivent pouvoir la calculer indépendamment, sinon aucune comparaison
     * d'états d'opposition n'est possible.
     */
    public static function hashEmail(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }
}
