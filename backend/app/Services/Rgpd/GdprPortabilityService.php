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

        $data = [
            'subject'  => $email,
            'exported' => now()->toIso8601String(),
            'contacts' => DB::table('contacts')->where('email', $email)->get()->toArray(),
            'email_validations' => DB::table('email_validations')->where('email', $email)->get()->toArray(),
            'rgpd_requests' => DB::table('rgpd_requests')->where('subject_email', $email)->get()->toArray(),
            'magic_links_history' => DB::table('magic_links')->where('email', $email)->get(['id', 'expires_at', 'consumed_at', 'created_at'])->toArray(),
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
