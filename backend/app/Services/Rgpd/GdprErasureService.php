<?php

namespace App\Services\Rgpd;

use App\Services\Audit\AuditHashChain;
use App\Services\Dedup\DeduplicationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Effacement RGPD art. 17 — transaction multi-tables atomique.
 * Toutes les données PII d'un sujet (email/phone) sont supprimées en cascade,
 * un opt-out cross-workspace est créé pour bloquer toute future collecte.
 */
class GdprErasureService
{
    public function __construct(
        private readonly AuditHashChain $audit,
        private readonly DeduplicationService $dedup,
    ) {}

    /** @return array{deleted: array<string,int>, opt_out_added: bool} */
    public function erase(string $subjectEmail, ?string $phone = null, ?string $reason = 'gdpr_art17'): array
    {
        return DB::transaction(function () use ($subjectEmail, $phone, $reason) {
            $email = strtolower(trim($subjectEmail));
            $deleted = [];

            // On releve les `person_key` AVANT de supprimer : c'est par elles que
            // la timeline (`activities`) est rattachee a la personne. Les
            // supprimer d'abord rendrait leurs activites orphelines et
            // introuvables - or ce sont elles qui portent le telephone en clair.
            $clesPersonne = array_values(array_filter(array_unique(array_merge(
                DB::table('contacts')->where('email', $email)->pluck('person_key')->all(),
                DB::table('candidates')->where('email', $email)->pluck('person_key')->all(),
            ))));

            $deleted['contacts'] = DB::table('contacts')->where('email', $email)->delete();
            $deleted['email_validations'] = DB::table('email_validations')->where('email', $email)->delete();

            // 🔴 LE JUMEAU D'`email_validations` (B10-004, mesure 2026-08-20).
            //
            // Deux tables portent le verdict de delivrabilite d'une adresse :
            // `email_validations` (cache 30 j de la deduplication) et
            // `email_verification_logs` (journal du fournisseur Hunter, ecrit
            // par `HunterEmailVerifier::journaliser`). La premiere etait
            // effacee et exportee ; la seconde ne l'etait NI l'une NI l'autre,
            // alors qu'elle garde en plus la reponse BRUTE du fournisseur
            // (`raw_response` JSONB), c'est-a-dire tout ce que le prestataire a
            // dit de cette personne.
            //
            // C'est le patron A-011 : le correctif existait sur une table et
            // n'avait jamais ete porte sur sa jumelle.
            $deleted['email_verification_logs'] = DB::table('email_verification_logs')
                ->whereRaw('lower(email::text) = ?', [$email])
                ->delete();
            $deleted['rgpd_requests'] = DB::table('rgpd_requests')->where('subject_email', $email)->where('type', '!=', 'erasure')->delete();
            $deleted['notifications'] = DB::table('notifications')->whereRaw('body ILIKE ?', ['%' . $email . '%'])->delete();
            $deleted['magic_links'] = DB::table('magic_links')->where('email', $email)->delete();

            // Journalistes (données personnelles B2B) : anonymisation + opt-out + soft-delete
            // plutôt que suppression dure, pour conserver la traçabilité de l'effacement.
            $deleted['journalists'] = DB::table('journalists')
                ->whereNull('deleted_at')
                ->where(function ($q) use ($email, $phone) {
                    $q->where('email', $email);
                    if ($phone !== null && $phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->update([
                    'email' => null,
                    'phone' => null,
                    'opt_out' => true,
                    'deleted_at' => now(),
                ]);

            // Médias : neutralise l'email ET LE TELEPHONE du contact redaction.
            // Le telephone restait en clair : neutraliser la seule adresse laissait
            // un moyen de joindre la personne (B15-006).
            $deleted['media_email'] = DB::table('media')->where('email', $email)->update([
                'email' => null,
                'phone' => null,
            ]);

            // ── CANDIDATS ────────────────────────────────────────────────────
            // `candidates` porte `email`, `phone`, nom et prenom. Le service
            // d'effacement du CANAL (SiteGdprService) les supprimait pour le
            // vivier ; celui-ci, appele depuis la console et l'API, ne les
            // touchait pas du tout. Une meme personne etait donc effacee ou non
            // selon la porte par laquelle la demande arrivait (B15-006).
            $deleted['candidates'] = DB::table('candidates')
                ->where(function ($q) use ($email, $phone) {
                    $q->where('email', $email);
                    if ($phone !== null && $phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->delete();

            // ── LA TIMELINE, ET C'EST LA QUE LE TELEPHONE SURVIVAIT ──────────
            //
            // 🔴 `activities.payload` est un JSONB qui garde `{"tel":"+33…",
            // "email":"jean.dupont@…"}` EN CLAIR. La cle etrangere vers le
            // contact est en `SET NULL` : supprimer le contact laissait donc la
            // ligne d'activite, et avec elle le telephone et l'adresse de la
            // personne qui venait de demander son effacement. Mesure le
            // 2026-08-19 (audit 360, B15-006, S0).
            //
            // On supprime par `person_key` - le lien stable - ET par contenu,
            // parce qu'une activite nee de la COLLECTE peut porter l'adresse sans
            // porter la cle. Le balayage est couteux ; un effacement est rare, et
            // la justesse prime ici sur la vitesse.
            $requeteActivites = DB::table('activities')->where(function ($q) use ($clesPersonne, $email, $phone) {
                if ($clesPersonne !== []) {
                    $q->orWhereIn('person_key', $clesPersonne);
                }
                $q->orWhereRaw('payload::text ILIKE ?', ['%' . $email . '%'])
                    ->orWhereRaw("coalesce(content, '') ILIKE ?", ['%' . $email . '%'])
                    ->orWhereRaw("coalesce(title, '') ILIKE ?", ['%' . $email . '%']);
                if ($phone !== null && $phone !== '') {
                    $q->orWhereRaw('payload::text ILIKE ?', ['%' . $phone . '%']);
                }
            });
            $deleted['activities'] = $requeteActivites->delete();

            // ── COURRIELS ECHANGES ───────────────────────────────────────────
            // `email_messages` garde l'expediteur et les destinataires en clair.
            $deleted['email_messages'] = DB::table('email_messages')
                ->where(function ($q) use ($email) {
                    $q->whereRaw('lower(from_address::text) = ?', [$email])
                        ->orWhereRaw('to_addresses::text ILIKE ?', ['%' . $email . '%']);
                })
                ->delete();

            // ── CE QU'ON NE SUPPRIME **PAS**, ET POURQUOI ────────────────────
            //
            // `opt_out` et `dnc_entries` ne sont PAS purgees, et ce n'est pas un
            // oubli : ce sont les listes qui EMPECHENT de recontacter la personne.
            // Les effacer ferait exactement l'inverse de ce que la personne
            // demande - elle redeviendrait joignable a la prochaine collecte.
            // `opt_out` ne conserve d'ailleurs qu'un HACHAGE d'adresse, jamais
            // l'adresse elle-meme (cf. SiteGdprService::optOut).
            // Le journal d'audit garde de meme la PREUVE de l'effacement, sous
            // forme de hachage : detruire la preuve d'un effacement rendrait
            // l'effacement indemontrable.

            // 🔴 PRATICIENS DE SANTÉ — DONNÉE DE L'ARTICLE 9 (catégorie particulière).
            //
            // Cette table était visée par AUCUN des deux services d'effacement,
            // AUCUNE purge, AUCUNE politique de rétention. Constaté le
            // 2026-08-16. Sa propre migration l'annonce pourtant :
            // « ⚠️ Donnée nominative de SANTÉ (RGPD art. 9) ».
            //
            // SUPPRESSION FERME, et non anonymisation comme pour `journalists` :
            // la ligne porte `nom`, `prenom`, `specialite`, `address`,
            // `postcode`, `city` et `rpps`. Nullifier l'email et le téléphone
            // laisserait un praticien parfaitement identifiable — nom + spécialité
            // + adresse, c'est-à-dire précisément la donnée de santé.
            // Le modèle utilise `SoftDeletes` ; on passe donc par le
            // constructeur de requêtes, qui supprime réellement la ligne.
            $deleted['health_practitioners'] = DB::table('health_practitioners')
                ->where(function ($q) use ($email, $phone) {
                    $q->where('email', $email);
                    if ($phone !== null && $phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->delete();

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
                source: 'gdpr_erasure',
                reason: $reason,
                scopes: DeduplicationService::UNIVERS_OPPOSITION,
            );

            Log::info('GDPR erasure complete', ['email' => $email, 'deleted' => $deleted]);

            return ['deleted' => $deleted, 'opt_out_added' => true];
        });
    }
}
