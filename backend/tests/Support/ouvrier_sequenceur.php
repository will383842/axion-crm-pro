<?php

/*
 * Ouvrier de test de concurrence du séquenceur (processus séparé, connexion séparée).
 * Usage : php ouvrier_sequenceur.php <workspace> <inscription> <départ microtime> <répétitions> <maintenant ISO> [traiter|reserver]
 * Sortie : une ligne JSON {"envoyes":int} (mode traiter) ou {"reservees":int} (mode reserver : réservations (inscription, étape)
 * SANS verrou de ligne — seule l'unicité en base, ON CONFLICT DO NOTHING, départage). Transport : simulateur seulement, AUCUN réseau.
 */

use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\ComposeurSequence;
use App\Envoi\Sequence\DemandeComposition;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\ParametresExpedition;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ws, $inscription, $depart, $repetitions, $maintenant] = $argv;
$mode = $argv[6] ?? 'traiter';
while (microtime(true) < (float) $depart) {
    usleep(500);
}

$composeur = new class implements ComposeurSequence
{
    public function composer(DemandeComposition $d): string
    {
        return "Message-ID: {$d->messageId}\r\n\r\n{$d->corps}";
    }
};
$passerelle = new PasserelleLocale(
    new FauxServeurEnvoi, new DepotSocleDb, true, 300,
    fn () => new DateTimeImmutable($maintenant), $ws, 300, fn () => WorkspaceContext::current(),
);
$seq = new Sequenceur($passerelle, new LecteurFicheDb, $composeur, new ParametresExpedition('zz-envoi.example.invalid', 'from@zz-envoi.example.invalid', 'ZZ Expediteur SAS'));

if ($mode === 'reserver') {
    $reserver = new ReflectionMethod(Sequenceur::class, 'reserver');
    $reservees = 0;
    for ($i = 0; $i < (int) $repetitions; $i++) {
        $ins = WorkspaceContext::run($ws, fn () => DB::table('envoi_inscriptions')->where('id', $inscription)->first());
        foreach ([1, 2, 3] as $rang) {
            $id = WorkspaceContext::run($ws, fn () => $reserver->invoke($seq, $ins, $rang));
            $reservees += $id === null ? 0 : 1;
        }
    }
    echo json_encode(['reservees' => $reservees]), "\n";
    exit(0);
}

$envoyes = 0;
for ($i = 0; $i < (int) $repetitions; $i++) {
    $r = WorkspaceContext::run($ws, fn () => $seq->traiter($ws, $inscription, new DateTimeImmutable($maintenant)));
    $envoyes += $r === Sequenceur::ENVOYE ? 1 : 0;
}
echo json_encode(['envoyes' => $envoyes]), "\n";
