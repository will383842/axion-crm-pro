<?php

namespace App\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Lot N2 — COMPTEUR DES REFUS DE SIGNATURE sur les routes signées par le site.
 *
 * ── POURQUOI ────────────────────────────────────────────────────────────────
 * Un secret partagé qui diverge (rotation faite d'un seul côté), une horloge
 * qui dérive, un magasin anti-rejeu tombé : dans les trois cas, le site émet,
 * le CRM répond 401/503, l'outbox du site garde ses lignes… et rien ne se voit
 * côté CRM, sinon une ligne `Log::warning` dans un journal que personne ne lit.
 * La surveillance externe (`.github/workflows/surveillance-canaux.yml`) lit ce
 * compteur via `crm:canaux:etat`.
 *
 * ── CE QUI EST STOCKÉ ───────────────────────────────────────────────────────
 * Un ENTIER par (canal, motif, tranche de 5 minutes). Ni adresse IP, ni corps,
 * ni en-tête : le compteur ne contient aucune donnée personnelle. Les clés
 * expirent seules au bout de deux heures — pas de ménage à faire.
 *
 * ── MAGASIN ─────────────────────────────────────────────────────────────────
 * Le même que la mémoire anti-rejeu (`crm.ingest.replay_store`, `redis` en
 * production, `array` dans les tests) : le compteur vit là où vit déjà l'état
 * du canal signé, sans nouvelle dépendance.
 *
 * ── INCASSABLE ──────────────────────────────────────────────────────────────
 * `incrementer()` n'échoue JAMAIS : compter un refus ne doit pas changer la
 * réponse de refus. Si le magasin est tombé (c'est précisément le cas du motif
 * `replay_guard_unavailable`), l'incrément est perdu ; `lire()` le dira alors
 * par `disponible = false`, et la surveillance le traite comme une alerte —
 * un compteur illisible n'est pas un compteur à zéro.
 */
final class CompteurRefusCanal
{
    /** Les réponses de refus de {@see CanalSigneSite::controler()}. */
    public const MOTIFS = ['bad_signature', 'stale_signature', 'replay_guard_unavailable'];

    /** Largeur d'une tranche, en secondes. */
    public const TRANCHE_SECONDES = 300;

    /** Durée de vie d'une tranche : deux heures couvrent toute fenêtre de lecture utile. */
    public const TTL_SECONDES = 7200;

    private const PREFIXE = 'canal-signe:refus:';

    public static function incrementer(string $canal, string $motif): void
    {
        if (! in_array($motif, self::MOTIFS, true)) {
            return;
        }

        try {
            $cle = self::cle($canal, $motif, self::tranche(time()));
            $magasin = self::magasin();
            $magasin->add($cle, 0, self::TTL_SECONDES);
            $magasin->increment($cle);
        } catch (Throwable $e) {
            // Jamais d'IP ici : seul le type de l'exception est journalisé.
            Log::warning('compteur des refus de signature indisponible', ['exception' => $e::class]);
        }
    }

    /**
     * Agrège les refus des `$minutes` dernières minutes (arrondies à la tranche).
     *
     * @param  list<string>  $canaux
     * @return array{disponible: bool, fenetre_min: int, total: int, par_motif: array<string, int>, par_canal: array<string, int>}
     */
    public static function lire(array $canaux, int $minutes = 60): array
    {
        $minutes = max(5, $minutes);
        $nbTranches = (int) ceil($minutes * 60 / self::TRANCHE_SECONDES);
        $courante = self::tranche(time());

        $parMotif = array_fill_keys(self::MOTIFS, 0);
        $parCanal = array_fill_keys($canaux, 0);

        $cles = [];
        foreach ($canaux as $canal) {
            foreach (self::MOTIFS as $motif) {
                for ($i = 0; $i < $nbTranches; $i++) {
                    $cles[self::cle($canal, $motif, $courante - $i)] = [$canal, $motif];
                }
            }
        }

        try {
            /** @var array<string, mixed> $valeurs */
            $valeurs = $cles === [] ? [] : self::magasin()->many(array_keys($cles));
        } catch (Throwable $e) {
            Log::warning('compteur des refus de signature illisible', ['exception' => $e::class]);

            return [
                'disponible' => false,
                'fenetre_min' => $minutes,
                'total' => 0,
                'par_motif' => $parMotif,
                'par_canal' => $parCanal,
            ];
        }

        $total = 0;
        foreach ($cles as $cle => [$canal, $motif]) {
            $n = $valeurs[$cle] ?? null;
            $n = is_numeric($n) ? (int) $n : 0;
            $parMotif[$motif] += $n;
            $parCanal[$canal] += $n;
            $total += $n;
        }

        return [
            'disponible' => true,
            'fenetre_min' => $minutes,
            'total' => $total,
            'par_motif' => $parMotif,
            'par_canal' => $parCanal,
        ];
    }

    private static function tranche(int $horodatage): int
    {
        return intdiv($horodatage, self::TRANCHE_SECONDES);
    }

    private static function cle(string $canal, string $motif, int $tranche): string
    {
        return self::PREFIXE . $canal . ':' . $motif . ':' . $tranche;
    }

    private static function magasin(): Repository
    {
        return Cache::store((string) config('crm.ingest.replay_store', 'redis'));
    }
}
