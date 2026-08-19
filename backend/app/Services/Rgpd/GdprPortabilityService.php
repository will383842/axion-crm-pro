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
            'subject'  => $email,
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

            // L'etat d'opposition CONCERNE la personne : elle a le droit de
            // savoir qu'elle y figure, et depuis quand. On n'exporte que le
            // marqueur, jamais le hachage - qui ne lui apprendrait rien.
            'oppositions' => DB::table('opt_out')
                ->where('email_hash', hash('sha256', $email))
                ->get(['scope', 'source', 'created_at'])->toArray(),
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $encrypted = Crypt::encryptString($json);

        $token = Str::random(48);
        $path = "gdpr-exports/{$token}.enc";
        Storage::disk('local')->put($path, $encrypted);

        $expiresAt = now()->addDays(7);
        DB::table('rgpd_requests')->where('subject_email', $email)
            ->where('type', 'portability')
            ->whereNull('processed_at')
            ->update([
                'processed_at'      => now(),
                'status'            => 'done',
                'export_token'      => hash('sha256', $token),
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
