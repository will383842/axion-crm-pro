<?php

namespace App\Services\Rgpd;

use App\Crm\Outbound\OppositionSortante;
use App\Crm\Presse\LienJournalisteContact;
use App\Crm\Rgpd\EffacementCoordonneesFiches;
use App\Crm\Rgpd\MiseAEcart;
use App\Services\Audit\AuditHashChain;
use App\Services\Dedup\DeduplicationService;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Effacement RGPD art. 17 — transaction multi-tables atomique.
 *
 * ⚠️ L3c-0 (ADR contrat v3 § 6, I3 — règle du fondateur : AUCUNE suppression de
 * personne ni de données de personne) : `erase()` ne détruit, n'anonymise et
 * n'écrase RIEN. Personnes, abonnements, contacts, candidats, journalistes,
 * praticiens et comptes sont MASQUÉS de façon réversible (opposition,
 * `deleted_at` doux, `App\Crm\Rgpd\MiseAEcart`) ; un opt-out cross-workspace
 * bloque tout envoi et toute réinsertion. L'effacement définitif n'est pas
 * codé : le fondateur décide. Les commentaires historiques du fichier décrivent
 * l'ancien comportement.
 */
class GdprErasureService
{
    public function __construct(
        private readonly AuditHashChain $audit,
        private readonly DeduplicationService $dedup,
    ) {}

    /**
     * Traitement d'une demande d'effacement (bouton « traiter ») : MISE À
     * L'ÉCART réversible, aucune donnée détruite. Aucune preuve différée n'est
     * due : rien n'est supprimé, il n'y a pas de résidu à rechercher.
     * `personnels` (numéros en clair) n'est jamais archivé ni rendu.
     *
     * STOP-T4-CRM : `$signalement` (['motif' => …, 'canal' => …]) demande d'envoyer l'opposition au site
     * (`consent_optout`, les deux portées) DANS cette transaction. `$demandeDuSite` vrai (demande créée par
     * le site) : source `gdpr_erasure_demande_site`, hors liste blanche, donc RIEN n'est émis (anti-boucle).
     *
     * @param  ?array{motif: string, canal: string}  $signalement
     * @return array{deleted: array<string,int>, opt_out_added: bool, verification: string, personnels: list<string>}
     */
    public function erase(
        string $subjectEmail,
        ?string $phone = null,
        ?string $reason = 'gdpr_art17',
        ?array $signalement = null,
        bool $demandeDuSite = false,
    ): array {
        $sourceOpposition = $demandeDuSite ? OppositionSortante::SOURCE_DEMANDE_SITE : 'gdpr_erasure';

        return DB::transaction(function () use ($subjectEmail, $phone, $reason, $signalement, $demandeDuSite, $sourceOpposition) {
            $email = strtolower(trim($subjectEmail));
            $deleted = [];

            // Les numéros de la personne : celui de la demande, et ceux que
            // portent SES fiches personnes — relevés AVANT la mise à l'écart.
            $telephones = array_values(array_unique(array_filter(array_merge(
                [$phone ?? ''],
                EffacementCoordonneesFiches::telephonesDesContacts($email),
            ), static fn (string $t): bool => trim($t) !== '')));
            $clesNom = EffacementCoordonneesFiches::clesNomDesContacts($email);

            // Propositions de tiers (N13) : MISES À L'ÉCART par espace (marque
            // réversible, aucune valeur écrasée), relevées AVANT le masquage des
            // fiches personnes.
            $deleted['propositions_champs_ecartees'] = self::ecarterPropositions(
                $email,
                EffacementCoordonneesFiches::numerosPersonnels($telephones, $email, $clesNom),
            );

            // Numéros PERSONNELS et numéros opposables (anti-réinsertion),
            // relevés SANS RIEN MODIFIER, avant le masquage des contacts.
            $releve = EffacementCoordonneesFiches::releverPartout($email, $telephones, $clesNom);
            $personnels = $releve['personnels'];
            $opposables = $releve['opposables'];

            // L3c-0 (ADR contrat v3 § 6, I3) : la personne est MISE À L'ÉCART,
            // jamais supprimée. Les clés du bilan sont conservées (nombre de
            // lignes mises à l'écart).
            //
            // ESPACE PAR ESPACE : `personnes`, `abonnements`, `candidates` et
            // `contacts` sont sous RLS forcée ; une lecture ou un masquage sans
            // contexte ne verrait aucune ligne (masquage silencieusement vide).
            $deleted['abonnements'] = 0;
            $deleted['personnes'] = 0;
            $deleted['contacts'] = 0;
            $deleted['candidates'] = 0;
            $conditionCandidat = static function ($q) use ($email, $phone): void {
                $q->where('email', $email);
                if ($phone !== null && $phone !== '') {
                    $q->orWhere('phone', $phone);
                }
            };
            foreach (EffacementCoordonneesFiches::espaces() as $espace) {
                $r = WorkspaceContext::run($espace, static function () use ($espace, $email, $conditionCandidat): array {
                    $ecart = MiseAEcart::personnes(
                        self::personnesDe($email)->where('workspace_id', $espace)->pluck('id')->all(),
                        'demande_effacement',
                    );

                    return [
                        'abonnements' => $ecart['abonnements'],
                        'personnes' => $ecart['personnes'],
                        'contacts' => MiseAEcart::contacts(
                            DB::table('contacts')->where('workspace_id', $espace)->where('email', $email)->pluck('id')->all(),
                            'demande_effacement',
                        ),
                        // Candidats : corbeille douce + trace (`MiseAEcart::candidats`).
                        'candidates' => MiseAEcart::candidats(
                            DB::table('candidates')->where('workspace_id', $espace)->where($conditionCandidat)->pluck('id')->all(),
                            'demande_effacement',
                        ),
                    ];
                });
                foreach ($r as $cle => $n) {
                    $deleted[$cle] += $n;
                }
            }

            // Journalistes : MASQUÉS (corbeille douce + opposition), jamais
            // vidés de leurs coordonnées. Le CONTACT lié est mis à l'écart
            // lui aussi et inscrit au registre des retraits.
            $journalistesMasques = array_values(array_map('intval', DB::table('journalists')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($email, $phone) {
                    $q->where('email', $email);
                    if ($phone !== null && $phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->pluck('id')->all()));
            $deleted['contacts'] += LienJournalisteContact::masquerContactsDe($journalistesMasques, 'demande_effacement');
            $deleted['journalists'] = DB::table('journalists')
                ->whereIn('id', $journalistesMasques)
                ->update([
                    'opt_out' => true,
                    'deleted_at' => now(),
                ]);

            // Praticiens de santé (art. 9) : corbeille douce, ligne gardée.
            $deleted['health_practitioners'] = DB::table('health_practitioners')
                ->where(function ($q) use ($email, $phone) {
                    $q->where('email', $email);
                    if ($phone !== null && $phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now()]);

            // Comptes du CRM : compte FERMÉ (`deleted_at`, que `config/auth.php`
            // rend opposable : sessions et jetons Sanctum perdent leur effet).
            // Rien n'est anonymisé ni écrasé : adresse, nom, secrets restent
            // en base ; rouvrir le compte = remettre `deleted_at` à NULL.
            $deleted['users'] = DB::table('users')->where('email', $email)->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            // CE QUI N'EST PAS TOUCHÉ, ET C'EST VOULU (règle du fondateur) :
            // `activities`, `email_messages`, `email_verification_logs`,
            // `email_validations`, `notifications`, `rgpd_requests`,
            // `magic_links`, `media`, `sessions`, `invitations`,
            // `password_reset_tokens` et les fiches d'organisation gardent
            // leurs lignes. Aucun envoi ne part vers la personne (opposition,
            // `opt_out`, contacts et personnes masqués) ; l'effacement
            // définitif de ces tables n'est PAS codé, le fondateur décide.

            // Audit log — la suppression elle-même
            $this->audit->record([
                'workspace_id' => null,
                'user_id' => null,
                'method' => 'GDPR_ERASURE',
                'path' => '/internal/gdpr/erase',
                'status' => 200,
                'ip' => null,
                'user_agent' => null,
                'payload_hash' => hash('sha256', $email . '|' . ($phone ?? '')),
            ]);

            // ── OPPOSITION, DANS LES DEUX UNIVERS (B15-001, S0) ──────────────
            //
            // Cet appel n'ecrivait qu'`opt_out.scope = 'business'` (le DEFAULT
            // SQL de la colonne). La garde du VIVIER interroge l'autre univers
            // — `SiteSyncIngestService::hasOpposed()` filtre
            // `where('scope', 'vivier')` pour une `application_submitted` :
            // **la personne effacee ici revenait au vivier a la candidature
            // suivante**, nom, adresse et telephone compris.
            //
            // `addOptOut()` ecrit desormais les deux univers par defaut ; le
            // parametre reste disponible pour un appelant qui n'en voudrait
            // qu'un. Le voisin, `SiteGdprService::erase()`, le faisait deja
            // (patron A-011 : le correctif existait, il n'avait pas ete porte).
            $this->dedup->addOptOut(
                $email,
                $phone,
                source: $sourceOpposition,
                reason: $reason,
                scopes: DeduplicationService::UNIVERS_OPPOSITION,
            );

            // Une opposition par univers, après l'écriture `opt_out`, dans la même transaction.
            // Les numéros seuls ne partent pas (pas d'empreinte d'adresse, hors contrat).
            if ($signalement !== null && ! $demandeDuSite && trim($email) !== '') {
                $sortante = new OppositionSortante;
                foreach (DeduplicationService::UNIVERS_OPPOSITION as $portee) {
                    $sortante->signalerAdresseSansBloquer($email, $portee, $signalement['motif'], $signalement['canal']);
                }
            }

            // Chaque AUTRE numéro PERSONNEL est opposé lui aussi : sans cela, le
            // prochain import le remettrait sur la fiche de l'organisation. Un
            // standard partagé ne l'est PAS : `opt_out.phone` est global et
            // fermerait l'organisation entière (la personne reste protégée par
            // l'opposition sur son adresse et par `contacts_retires`). Et un
            // numéro personnel ici mais standard dans un autre espace ne l'est
            // pas non plus (relecture E2 : jamais par union).
            foreach ($opposables as $telephone) {
                if ($telephone !== $phone) {
                    $this->dedup->addOptOut(
                        null,
                        $telephone,
                        source: $sourceOpposition,
                        reason: $reason,
                        scopes: DeduplicationService::UNIVERS_OPPOSITION,
                    );
                }
            }

            // La preuve (« plus rien ne reste ») est DIFFÉRÉE : elle parcourt des
            // tables entières. Le journal ne porte JAMAIS l'adresse en clair.
            Log::info('GDPR erasure request executed as masking (nothing deleted)', ['email_hash' => hash('sha256', $email), 'deleted' => $deleted]);

            return ['deleted' => $deleted, 'opt_out_added' => true, 'verification' => 'aucune', 'personnels' => $personnels];
        });
    }

    /**
     * N13 — met à l'écart les propositions de tiers de cette adresse et/ou de
     * ces numéros : une MARQUE (`propositions_champs_ecartees`), jamais un
     * écrasement des valeurs ni une suppression. Espace par espace (RLS).
     * Rend le nombre de propositions nouvellement écartées.
     *
     * @param  list<string>  $telephones
     */
    private static function ecarterPropositions(string $email, array $telephones): int
    {
        $variantes = [];
        foreach ($telephones as $t) {
            array_push($variantes, ...ListeSuppression::variantesTelephone($t));
        }
        $chiffres = array_values(array_unique(array_filter($variantes, static fn (string $v): bool => ctype_digit($v))));
        $courriel = mb_strtolower(trim($email));

        $total = 0;
        foreach (EffacementCoordonneesFiches::espaces() as $espace) {
            $total += WorkspaceContext::run($espace, static fn (): int => DB::affectingStatement(
                "INSERT INTO propositions_champs_ecartees (proposition_id, workspace_id, motif)
                 SELECT pc.id, pc.workspace_id, 'demande_effacement'
                   FROM propositions_champs pc
                  WHERE pc.workspace_id = ?::uuid
                    AND (
                        (? <> '' AND pc.entite = 'personne' AND pc.entite_id IN (
                            SELECT ct.id FROM contacts ct WHERE ct.workspace_id = ?::uuid AND lower(ct.email::TEXT) = ?
                        ))
                        OR (cardinality(?::text[]) > 0 AND (
                            regexp_replace(pc.valeur_proposee, '\D', '', 'g') = ANY (?::text[])
                            OR regexp_replace(coalesce(pc.valeur_actuelle, ''), '\D', '', 'g') = ANY (?::text[])
                            OR regexp_replace(coalesce(pc.valeur_remplacee, ''), '\D', '', 'g') = ANY (?::text[])
                        ))
                    )
                 ON CONFLICT (proposition_id) DO NOTHING",
                [$espace, $courriel, $espace, $courriel, '{' . implode(',', $chiffres) . '}', '{' . implode(',', $chiffres) . '}', '{' . implode(',', $chiffres) . '}', '{' . implode(',', $chiffres) . '}'],
            ));
        }

        return $total;
    }

    /**
     * Lot L4-C — les personnes d'une adresse : par l'adresse, OU par son
     * empreinte (`email_hash`, sha256 de l'adresse normalisée). Une personne
     * créée par un désabonnement ne porte PAS l'adresse en clair : sans
     * l'empreinte, l'effacement et la portabilité ne la trouveraient pas.
     */
    public static function personnesDe(string $email): Builder
    {
        $email = mb_strtolower(trim($email));

        return DB::table('personnes')->where(function (Builder $q) use ($email): void {
            $q->where('email', $email)->orWhere('email_hash', hash('sha256', $email));
        });
    }
}
