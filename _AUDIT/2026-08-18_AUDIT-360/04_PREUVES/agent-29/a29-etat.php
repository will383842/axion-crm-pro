<?php
// Agent 29 — etat des fuseaux, tel que Laravel le voit.
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "APP_TIMEZONE (env)      : " . var_export(env('APP_TIMEZONE'), true) . "\n";
echo "DB_TIMEZONE  (env)      : " . var_export(env('DB_TIMEZONE'), true) . "\n";
echo "config(app.timezone)    : " . config('app.timezone') . "\n";
echo "config(db.pgsql.tz)     : " . var_export(config('database.connections.pgsql.timezone'), true) . "\n";
echo "date_default_timezone   : " . date_default_timezone_get() . "\n";
echo "PHP now()               : " . now()->format('Y-m-d H:i:s P e') . "\n";
echo "grammar getDateFormat   : " . DB::getQueryGrammar()->getDateFormat() . "\n";
echo "PG SHOW TimeZone        : " . DB::selectOne('SHOW TimeZone')->TimeZone . "\n";
echo "PG now()                : " . DB::selectOne('select now() as n')->n . "\n";
echo "PG current_database     : " . DB::selectOne('select current_database() as d')->d . "\n";
