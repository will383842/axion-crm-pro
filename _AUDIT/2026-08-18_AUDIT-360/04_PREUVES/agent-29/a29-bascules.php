<?php
/**
 * AGENT 29 — critère 16 du §29, joué sur les DEUX bascules horaires de 2026.
 *
 * « toute date est stockée en temps universel et affichée dans le fuseau de
 *   l'utilisateur ; un événement émis par la console à 14:00 apparaît à 14:00
 *   sur la fiche, y compris aux changements d'heure. »
 *
 * Chemin réellement emprunté : SiteSyncEvent::fromArray() (le SEUL point
 * d'entrée des dates extérieures) puis DB::table('activities')->insert(),
 * exactement comme SiteSyncIngestService::587-594. Table réelle, base dédiée
 * `axion_crm_a29` (58 migrations).
 */
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Crm\Ingest\SiteSyncEvent;
use App\Support\WorkspaceContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$tz = new DateTimeZone('Europe/Paris');

echo "base                = " . DB::selectOne('select current_database() as d')->d . "\n";
echo "migrations          = " . DB::table('migrations')->count() . "\n";
echo "app.timezone        = " . config('app.timezone') . "\n";
echo "DB_TIMEZONE (env)   = " . var_export(env('DB_TIMEZONE'), true) . "\n";
echo "PG SHOW TimeZone    = " . DB::selectOne('SHOW TimeZone')->TimeZone . "\n";
echo str_repeat('=', 118) . "\n";

// --- un workspace de travail -------------------------------------------------
$ws = DB::table('workspaces')->where('slug', 'a29')->value('id');
if (! $ws) {
    $ws = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $ws, 'slug' => 'a29', 'name' => 'Agent 29']);
}

/**
 * Les deux bascules de 2026, plus un témoin en plein été et un en plein hiver.
 * On veut « 14:00 à Paris ». La console émet en temps universel
 * (`Date.toISOString()`), donc 12:00Z en été (UTC+2) et 13:00Z en hiver (UTC+1).
 */
$cas = [
    'temoin ete    2026-06-15 14:00 Paris' => '2026-06-15 14:00:00',
    'BASCULE ETE   2026-03-29 14:00 Paris' => '2026-03-29 14:00:00',
    'BASCULE HIVER 2026-10-25 14:00 Paris' => '2026-10-25 14:00:00',
    'temoin hiver  2026-01-15 14:00 Paris' => '2026-01-15 14:00:00',
    // Cas limites de la bascule elle-même :
    'bascule ete   2026-03-29 02:30 (heure INEXISTANTE a Paris)' => '2026-03-29 02:30:00',
    'bascule hiver 2026-10-25 02:30 (heure AMBIGUE a Paris)'     => '2026-10-25 02:30:00',
];

printf("%-58s | %-25s | %-26s | %-22s | %s\n",
    'cas', 'instant voulu (Paris)', 'relu -> heure de Paris', 'ecart', 'ce que la fiche 360 AFFICHE');
echo str_repeat('-', 170) . "\n";

WorkspaceContext::run($ws, function () use ($cas, $tz, $ws) {
    foreach ($cas as $libelle => $local) {
        $voulu = new DateTimeImmutable($local, $tz);       // l'instant que l'utilisateur vise
        $iso   = $voulu->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'); // ce que la console emet

        $ref = 'a29-' . Str::random(10);
        $evt = SiteSyncEvent::fromArray([
            'schema_version' => 1,
            'event_id' => $ref,
            'event_type' => 'form_submission',
            'occurred_at' => $iso,
            'form_type' => 'autre',
            'subject_ref' => 'site:submission:' . $ref,
            'person' => [
                'person_key' => hash('sha256', $ref),
                'email' => $ref . '@a29.local',
                'first_name' => 'A', 'last_name' => '29',
            ],
            'consent' => ['at' => $iso, 'version' => 'contact-v1'],
        ]);

        $id = DB::table('activities')->insertGetId([
            'workspace_id' => $ws,
            'type' => 'form_submission',
            'kind' => 'form_submission',
            'occurred_at' => $evt->occurredAt,     // <- exactement SiteSyncIngestService:594
            'person_key' => hash('sha256', $ref),
            'external_ref' => $ref,
            'title' => 'A29',
        ]);

        // 1) ce que Postgres a REELLEMENT stocke, ramene a l'heure de Paris
        $relu = DB::selectOne(
            "select occurred_at, (occurred_at at time zone 'Europe/Paris')::text as paris,
                    extract(epoch from occurred_at) as epoch
             from activities where id = ?", [$id]);

        // 2) ce que la fiche 360 affiche : PersonTimelineController rend la
        //    valeur brute du query builder, PersonTimelinePage l'imprime telle quelle.
        $affiche = (string) DB::table('activities')->where('id', $id)->value('occurred_at');

        $ecart = (int) round(((float) $relu->epoch) - $voulu->getTimestamp());
        printf("%-58s | %-25s | %-26s | %+6d s %-12s | %s\n",
            $libelle,
            $voulu->format('Y-m-d H:i:s P'),
            $relu->paris,
            $ecart,
            $ecart === 0 ? 'OK' : 'DECALE',
            $affiche);
    }
});
