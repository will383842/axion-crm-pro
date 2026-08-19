<?php
// Agent 29 — comment Laravel serialise une date vers le SPA (aucune base requise).
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Carbon;

class Sonde extends Illuminate\Database\Eloquent\Model {
    protected $table = 'sonde';
    protected $guarded = [];
    protected $casts = ['quand' => 'datetime'];
}

echo "date_default_timezone = " . date_default_timezone_get() . "\n\n";

foreach ([
    'ete   2026-03-29 14:00 Paris' => '2026-03-29 14:00:00',
    'hiver 2026-10-25 14:00 Paris' => '2026-10-25 14:00:00',
    'bascule ete 02:30 (inexistante a Paris)' => '2026-03-29 02:30:00',
    'bascule hiver 02:30 (ambigue a Paris)'   => '2026-10-25 02:30:00',
] as $label => $s) {
    $c = Carbon::parse($s); // fuseau par defaut = app.timezone
    $m = new Sonde(['quand' => $c]);
    // ce que Laravel ENVOIE en base (chaine liee au PDO)
    $grammar = Illuminate\Support\Facades\DB::getQueryGrammar();
    $chaine_sql = $c->format($grammar->getDateFormat());
    // ce que l API RENVOIE au SPA
    $json = json_decode($m->toJson(), true)['quand'];
    printf("%-42s Carbon=%s | vers SQL=%-21s | JSON API=%s\n",
        $label, $c->format('Y-m-d H:i:s P'), $chaine_sql, $json);
}
