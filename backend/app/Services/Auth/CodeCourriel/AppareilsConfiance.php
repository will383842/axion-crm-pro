<?php

namespace App\Services\Auth\CodeCourriel;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * G3-C — appareils de confiance (cookie `axion_appareil`, 30 jours).
 *
 * En base : seulement l'empreinte SHA-256 du jeton. Tout est filtré sur `user_id` (le jeton d'un autre compte n'ouvre rien).
 * Rien ne s'efface : « retiré » est une date.
 */
final class AppareilsConfiance
{
    public const COOKIE = 'axion_appareil';

    /** Crée l'appareil et rend le jeton en clair (à poser dans le cookie, jamais conservé). */
    public function ajouter(User $u, ?string $ip, ?string $agent): string
    {
        $jeton = Str::random(48);
        $maintenant = CarbonImmutable::now();

        DB::table('appareils_confiance')->insert([
            'user_id' => $u->id,
            'jeton_hash' => $this->empreinte($jeton),
            'nom' => $this->nom($agent),
            'ip' => $ip,
            'ajoute_le' => $maintenant->toIso8601String(),
            'expire_le' => $maintenant->addDays((int) config('double_auth.duree_appareil_jours', 30))->toIso8601String(),
        ]);

        return $jeton;
    }

    /** Vrai si le jeton est celui d'un appareil de CE compte, non retiré, non expiré (et note la visite). */
    public function reconnaitre(User $u, ?string $jeton): bool
    {
        if (! is_string($jeton) || $jeton === '') {
            return false;
        }

        return DB::table('appareils_confiance')
            ->where('user_id', $u->id)
            ->where('jeton_hash', $this->empreinte($jeton))
            ->whereNull('retire_le')
            ->where('expire_le', '>', CarbonImmutable::now()->toIso8601String())
            ->update(['vu_le' => CarbonImmutable::now()->toIso8601String()]) > 0;
    }

    /** @return list<array{id: string, nom: string, lieu: string, ip: ?string, ajoute_le: string, expire_le: string, actuel: bool}> */
    public function lister(User $u, ?string $jetonCourant): array
    {
        $hashCourant = is_string($jetonCourant) && $jetonCourant !== '' ? $this->empreinte($jetonCourant) : null;

        $lignes = DB::table('appareils_confiance')
            ->where('user_id', $u->id)
            ->whereNull('retire_le')
            ->where('expire_le', '>', CarbonImmutable::now()->toIso8601String())
            ->orderByDesc('ajoute_le')
            ->get()
            ->map(fn ($l): array => [
                'id' => (string) $l->id,
                'nom' => (string) $l->nom,
                'lieu' => '—', // aucune géolocalisation (pas de service tiers)
                'ip' => $this->masquer($l->ip),
                'ajoute_le' => CarbonImmutable::parse($l->ajoute_le)->toIso8601String(),
                'expire_le' => CarbonImmutable::parse($l->expire_le)->toIso8601String(),
                'actuel' => $hashCourant !== null && hash_equals((string) $l->jeton_hash, $hashCourant),
            ])
            ->all();

        return array_values($lignes);
    }

    /** Pose `retire_le` (jamais DELETE) sur un appareil de CE compte ; faux si inconnu, autre compte ou déjà retiré. */
    public function retirer(User $u, string $id): bool
    {
        if (! Str::isUuid($id)) {
            return false;
        }

        return DB::table('appareils_confiance')
            ->where('id', $id)->where('user_id', $u->id)->whereNull('retire_le')
            ->update(['retire_le' => CarbonImmutable::now()->toIso8601String()]) > 0;
    }

    /**
     * SEC-APP — retire (jamais DELETE) tous les appareils actifs de CE compte, sauf celui dont le jeton est donné.
     * Un seul UPDATE. Rend le nombre de lignes retirées.
     */
    public function retirerTous(User $u, ?string $jetonAGarder = null): int
    {
        $requete = DB::table('appareils_confiance')->where('user_id', $u->id)->whereNull('retire_le');
        if (is_string($jetonAGarder) && $jetonAGarder !== '') {
            $requete->where('jeton_hash', '<>', $this->empreinte($jetonAGarder));
        }

        return $requete->update(['retire_le' => CarbonImmutable::now()->toIso8601String()]);
    }

    private function empreinte(string $jeton): string
    {
        return hash('sha256', $jeton);
    }

    private function nom(?string $agent): string
    {
        $nom = trim((string) $agent);

        return $nom === '' ? 'Appareil' : mb_substr($nom, 0, 80);
    }

    /** IPv4 : dernier octet masqué (192.0.2.x) ; IPv6 : trois premiers groupes. */
    private function masquer(mixed $ip): ?string
    {
        if (! is_string($ip) || $ip === '') {
            return null;
        }
        $ip = explode('/', $ip)[0];
        if (str_contains($ip, ':')) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)) . ':x';
        }
        $o = explode('.', $ip);
        $o[count($o) - 1] = 'x';

        return implode('.', $o);
    }
}
