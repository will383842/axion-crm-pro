<?php

namespace App\Services\Rgpd;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Portabilité RGPD art. 20 — export JSON structuré + chiffré.
 * Exporte toutes les données détenues sur un sujet (par email), produit un ZIP chiffré
 * AES-256 stocké dans s3/local 7 jours, fournit un token téléchargement one-shot.
 */
class GdprPortabilityService
{
    /**
     * Les types de demande que CET export solde. L'article 15 (`access`) et
     * l'article 20 (`portability`) rendent le meme inventaire — l'en-tete de
     * cette classe le dit depuis le commit ad7ae55 — et il n'y a donc qu'une
     * seule archive a produire pour les deux.
     *
     * La constante existe pour qu'`export()` et le point d'entree
     * (`RgpdRequestsController::process`) ne puissent pas diverger en silence :
     * un type route vers cet export mais absent d'ici recevrait un jeton que
     * `retrieve()` ne retrouverait jamais.
     */
    public const TYPES_AVEC_EXPORT = ['access', 'portability'];

    public function export(string $subjectEmail): array
    {
        $email = strtolower(trim($subjectEmail));

        // 🔴 CET EXPORT NE COUVRAIT QUE QUATRE TABLES SUR TRENTE ET UNE.
        //
        // L'article 15 donne droit a TOUTES les donnees detenues sur une
        // personne, pas a celles qu'il est commode de rassembler. Manquaient
        // notamment sa timeline, sa fiche candidat, sa fiche journaliste ou
        // praticien, et ses courriels echanges - c'est-a-dire l'essentiel de ce
        // que le CRM sait d'elle. Mesure le 2026-08-19 (audit 360, B15-003).
        //
        // INVARIANT QU'ON SE DONNE ICI : *ce qu'on sait EFFACER, on doit savoir
        // l'EXPORTER*. Les deux services se repondent desormais table pour table
        // (cf. GdprErasureService). Si l'un apprend une table, l'autre aussi -
        // sinon on effacerait une donnee qu'on aurait refuse de montrer.
        $clesPersonne = array_values(array_filter(array_unique(array_merge(
            DB::table('contacts')->where('email', $email)->pluck('person_key')->all(),
            DB::table('candidates')->where('email', $email)->pluck('person_key')->all(),
        ))));

        $data = [
            'subject' => $email,
            'exported' => now()->toIso8601String(),
            'contacts' => DB::table('contacts')->where('email', $email)->get()->toArray(),
            'candidates' => DB::table('candidates')->where('email', $email)->get()->toArray(),
            'email_validations' => DB::table('email_validations')->where('email', $email)->get()->toArray(),
            'rgpd_requests' => DB::table('rgpd_requests')->where('subject_email', $email)->get()->toArray(),
            'magic_links_history' => DB::table('magic_links')->where('email', $email)->get(['id', 'expires_at', 'consumed_at', 'created_at'])->toArray(),

            // La timeline : c'est la que vit l'essentiel de ce que le CRM sait
            // d'une personne. Meme selection que l'effacement - par cle stable ET
            // par contenu, car une activite nee de la collecte peut porter
            // l'adresse sans porter la cle.
            'activities' => DB::table('activities')
                ->where(function ($q) use ($clesPersonne, $email) {
                    if ($clesPersonne !== []) {
                        $q->orWhereIn('person_key', $clesPersonne);
                    }
                    $q->orWhereRaw('payload::text ILIKE ?', ['%' . $email . '%'])
                        ->orWhereRaw("coalesce(content, '') ILIKE ?", ['%' . $email . '%'])
                        ->orWhereRaw("coalesce(title, '') ILIKE ?", ['%' . $email . '%']);
                })
                ->get()->toArray(),

            'journalists' => DB::table('journalists')->where('email', $email)->get()->toArray(),
            'media_contacts' => DB::table('media')->where('email', $email)->get()->toArray(),

            // Donnee de l'article 9 (sante) : elle est detenue, elle doit donc
            // etre communiquee a la personne qui la demande.
            'health_practitioners' => DB::table('health_practitioners')->where('email', $email)->get()->toArray(),

            'email_messages' => DB::table('email_messages')
                ->where(function ($q) use ($email) {
                    $q->whereRaw('lower(from_address::text) = ?', [$email])
                        ->orWhereRaw('to_addresses::text ILIKE ?', ['%' . $email . '%']);
                })
                ->get()->toArray(),

            // 🔴 LE VERDICT DE DELIVRABILITE — LE JUMEAU OUBLIE (B10-004).
            //
            // `email_validations` etait exportee ET effacee ;
            // `email_verification_logs` ne l'etait NI l'une NI l'autre. Les deux
            // portent la meme chose : son adresse, un statut, un score, et la
            // reponse brute du fournisseur (`raw_response`). C'est le patron
            // A-011 dans sa forme la plus pure - un correctif pose sur une
            // table, jamais porte sur sa jumelle. Mesure le 2026-08-20.
            'email_verification_logs' => DB::table('email_verification_logs')
                ->whereRaw('lower(email::text) = ?', [$email])
                ->get(['status', 'score', 'provider', 'raw_response', 'verified_at'])->toArray(),

            // Les NOTIFICATIONS internes qui la nomment. L'effacement les
            // supprime (`body ILIKE '%adresse%'`) : par l'invariant que ce
            // service se donne - « ce qu'on sait effacer, on doit savoir
            // l'exporter » - il faut donc savoir les montrer.
            // On ne rend QUE le contenu : `user_id` designe un TIERS
            // (l'operateur destinataire), dont l'identifiant n'a rien a faire
            // dans l'export d'une autre personne.
            'notifications' => DB::table('notifications')
                ->whereRaw('body ILIKE ?', ['%' . $email . '%'])
                ->get(['type', 'title', 'body', 'created_at'])->toArray(),

            // ── LES QUATRE REGISTRES D'OPPOSITION ────────────────────────────
            //
            // Ils ne sont PAS effaces - les effacer rendrait la personne
            // joignable a la prochaine collecte, exactement l'inverse de ce
            // qu'elle demande. Mais ils la CONCERNENT : elle a le droit de
            // savoir qu'elle y figure, pourquoi, et depuis quand. Seul
            // `opt_out` etait exporte avant le 2026-08-20 ; les trois autres ne
            // figuraient dans AUCUN des deux services (B10-004).
            //
            // 🔴 On n'exporte JAMAIS l'empreinte. Elle n'apprendrait rien a la
            // personne - c'est le hachage de sa propre adresse - et la publier
            // affaiblirait la seule protection qui subsiste apres un
            // effacement. Un test verifie qu'aucune empreinte ne fuit ici.

            // 1. OPPOSITION : une volonte exprimee.
            'oppositions' => DB::table('opt_out')
                ->where('email_hash', hash('sha256', $email))
                ->get(['scope', 'source', 'created_at'])->toArray(),

            // 2. SUPPRESSION TECHNIQUE : un fait constate (rebond, plainte).
            // Le motif compte pour elle : « plainte » et « rebond dur » ne
            // disent pas la meme chose de ce qui s'est passe.
            'suppressions_techniques' => DB::table('email_suppressions')
                ->where('email_hash', hash('sha256', $email))
                ->get(['scope', 'reason', 'source', 'occurrences', 'first_seen_at', 'last_seen_at'])->toArray(),

            // 3. DESABONNEMENT : son propre geste, sur un lien d'envoi.
            'desabonnements' => DB::table('unsubscribes')
                ->whereRaw('lower(email::text) = ?', [$email])
                ->get(['source', 'reason', 'unsubscribed_at'])->toArray(),

            // 4. « NE PAS APPELER » : liste souvent importee de l'exterieur -
            // raison de plus pour qu'elle puisse la consulter.
            'listes_ne_pas_appeler' => DB::table('dnc_entries')
                ->whereRaw('lower(email::text) = ?', [$email])
                ->get(['email', 'phone', 'created_at'])->toArray(),

            // ── SON COMPTE DU CRM, ET CE QUI Y MENE (B10-004) ────────────────
            //
            // Trois tables restaient hors des DEUX services : `users`,
            // `invitations`, `password_reset_tokens`. Elles portent des
            // personnes IDENTIFIEES — les utilisateurs du CRM et les gens
            // qu'on a invites. L'effacement les traite desormais (par
            // ANONYMISATION pour `users` : le catalogue interdit la
            // suppression, cf. GdprErasureService) ; par l'invariant que ce
            // service se donne, il faut donc savoir les MONTRER.
            //
            // 🔴 AUCUN SECRET D'AUTHENTIFICATION NE SORT D'ICI :
            // ni `password_hash`, ni `totp_secret`, ni `totp_recovery_codes`,
            // ni `remember_token`, ni le `token_hash` de l'invitation, ni le
            // `token` de reinitialisation. Ils n'apprendraient rien a la
            // personne, et les deposer dans un fichier remis a distance
            // fabriquerait la fuite que cet export pretend prevenir. Un test
            // cherche chacune de ces valeurs dans tout le JSON.
            'comptes_crm' => DB::table('users')
                ->where('email', $email)
                ->get([
                    'id', 'email', 'name', 'locale', 'timezone', 'avatar_url',
                    'email_verified_at', 'first_login_completed_at',
                    'last_login_at', 'last_login_ip', 'last_login_user_agent',
                    'totp_enabled_at', 'created_at', 'updated_at', 'deleted_at',
                ])->toArray(),

            // On ne rend PAS `invited_by` ni `accepted_by` : identifiants de
            // TIERS (celui qui a invite), meme raison que `notifications.user_id`.
            'invitations_recues' => DB::table('invitations')
                ->where('email', $email)
                ->get(['workspace_id', 'role_slug', 'expires_at', 'accepted_at', 'revoked_at', 'created_at'])
                ->toArray(),

            // Du jeton de reinitialisation on ne rend que le FAIT et la date :
            // le jeton lui-meme ouvre un compte.
            'reinitialisations_mot_de_passe' => DB::table('password_reset_tokens')
                ->where('email', $email)
                ->get(['created_at'])->toArray(),

            // Les SESSIONS ouvertes de son compte gardent son IP et son
            // navigateur. L'effacement les supprime — l'invariant impose donc
            // de savoir les montrer. On ne rend ni `id` (qui EST le jeton de
            // session du pilote `database`) ni `payload` (etat serialise, sans
            // valeur pour elle et porteur du meme jeton).
            'sessions_ouvertes' => DB::table('sessions')
                ->whereIn('user_id', DB::table('users')->where('email', $email)->select('id'))
                ->get(['workspace_id', 'ip_address', 'user_agent', 'last_activity'])->toArray(),

            // Les SIGNAUX transmis au site a son sujet (opposition, effacement).
            // La table les conserve comme preuve de transmission ; la personne
            // a le droit de savoir que le signal est parti, et quand.
            'signaux_envoyes_au_site' => DB::table('crm_outbound_events')
                ->where('email_hash', hash('sha256', $email))
                ->get(['event_type', 'scope', 'status', 'sent_at', 'created_at'])->toArray(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $encrypted = Crypt::encryptString($json);

        $token = Str::random(48);
        $path = "gdpr-exports/{$token}.enc";
        Storage::disk('local')->put($path, $encrypted);

        $expiresAt = now()->addDays(7);
        // 🔴 B14-002 — `->where('type', 'portability')` RENDAIT LE JETON MORT-NE
        // POUR L'ARTICLE 15.
        //
        // `retrieve()` retrouve l'archive par `rgpd_requests.export_token` : un
        // jeton qui n'a ete ecrit sur AUCUNE ligne n'ouvre rien. Tant que
        // `access` tombait dans le `default => ['noop' => true]` du controleur,
        // le defaut ne se voyait pas ; des que l'acces est cable sur cet export
        // — c'est le meme inventaire, et l'en-tete de ce service porte les deux
        // articles — il produirait un jeton que personne ne pourrait echanger.
        // Un lien de telechargement qui ne telecharge rien est la meme famille
        // de defaut que celle qu'on repare : une reponse qui promet plus qu'elle
        // ne fait.
        DB::table('rgpd_requests')->where('subject_email', $email)
            ->whereIn('type', self::TYPES_AVEC_EXPORT)
            ->whereNull('processed_at')
            ->update([
                'processed_at' => now(),
                'status' => 'done',
                'export_token' => hash('sha256', $token),
                'export_expires_at' => $expiresAt,
            ]);

        return ['token' => $token, 'expires_at' => $expiresAt->toIso8601String(), 'size' => strlen($encrypted)];
    }

    public function retrieve(string $token): ?string
    {
        $hash = hash('sha256', $token);
        $row = DB::table('rgpd_requests')
            ->where('export_token', $hash)
            ->where('export_expires_at', '>', now())
            ->first();
        if (! $row) {
            return null;
        }
        $path = "gdpr-exports/{$token}.enc";
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }
        $encrypted = Storage::disk('local')->get($path);

        return Crypt::decryptString((string) $encrypted);
    }
}
