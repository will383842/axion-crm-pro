<?php

/*
 * Ouvrier du test de reprise après panne (processus séparé, connexion séparée) : un cycle de la VRAIE chaîne
 * (ChefOrchestre -> Sequenceur -> PasserelleLocale -> FauxServeurPersistant). Aucun réseau, aucun vrai message.
 * Usage : php ouvrier_cycle.php <workspace> <run> <point d'arrêt|aucun> <instant ISO> <plafond du jour>
 * Points d'arrêt (SIGKILL sur soi-même) : apres_temps_A | pendant_appel | apres_acceptation | pendant_temps_C.
 * La porte « envoi réel » est ouverte ICI, dans ce seul processus : drapeau posé en mémoire (jamais écrit), retrait / arrêt par
 * entreprise injectés. APP_ENV=testing : `ResolveurMx` lèverait sans faux, d'où `ResolveurEnMemoire`.
 * Écrit {"bilan":{...},"critiques":[...]} (les alertes critiques journalisées pendant ce cycle).
 */

use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Chauffe\ResolveurFournisseur;
use App\Envoi\Contrat\ConsultationAcceptation;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\Desinscription\ArretParEntreprise;
use App\Envoi\Desinscription\RetraitEnvoisEnFile;
use App\Envoi\FeuVert\ListeFeuVert;
use App\Envoi\FeuVert\PointFeuVert;
use App\Envoi\Orchestration\ChefOrchestre;
use App\Envoi\Orchestration\PorteEnvoiReelle;
use App\Envoi\PasserelleLocale;
use App\Envoi\Sequence\LecteurFicheDb;
use App\Envoi\Sequence\Sequenceur;
use App\Envoi\Sequence\SourceExpeditionDb;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FauxServeurPersistant;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ws, $run, $point, $instant, $plafond] = $argv;
$maintenant = new DateTimeImmutable($instant);

$tuer = static function (): never {
    posix_kill(posix_getpid(), 9); // SIGKILL : aucun finally, aucun shutdown, aucune transaction validée
    sleep(30);
    exit(99);
};

// Porte ouverte dans CE processus seulement (valeur jamais écrite en base ni en fichier).
config([
    'envoi.reel_actif' => true,
    'envoi.cle_desinscription' => str_repeat('k', 40),
    'envoi.desinscription_hote' => 'app',
    'envoi.desinscription_hotes' => ['app' => 'https://app.example.test', 'api' => 'https://api.example.test'],
]);
$app->instance(RetraitEnvoisEnFile::class, new class implements RetraitEnvoisEnFile
{
    public function retirerPour(string $workspaceId, string $empreinteAdresse): int
    {
        return 0;
    }
});
$app->instance(ArretParEntreprise::class, new class implements ArretParEntreprise
{
    public function declencher(string $workspaceId, string $empreinteAdresse): void {}
});
$app->bind(ResolveurFournisseur::class, fn () => new ResolveurEnMemoire);

$critiques = [];
Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$critiques): void {
    if ($e->level === 'critical') {
        $critiques[] = explode(' : ', $e->message)[0];
    }
});

$faux = new FauxServeurPersistant($run);
if ($point === 'pendant_appel') {
    $faux->pendantAppel = $tuer;
}
if ($point === 'apres_acceptation') {
    $faux->apresAcceptation = $tuer;
}
$passerelle = new PasserelleLocale($faux, new DepotSocleDb, true, (int) $plafond, fn () => $maintenant, '', 300, fn () => WorkspaceContext::current());

// Enveloppe : tue le processus juste AVANT l'appel de la passerelle (le temps A est déjà validé).
$serveur = new class($passerelle, $point === 'apres_temps_A' ? $tuer : null) implements ConsultationAcceptation, ServeurEnvoi
{
    public function __construct(private readonly PasserelleLocale $p, private readonly ?Closure $avant) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        if ($this->avant !== null) {
            ($this->avant)();
        }

        return $this->p->injecter($message);
    }

    public function dejaAccepte(string $workspaceId, string $cleIdempotence): ?ResultatInjection
    {
        return $this->p->dejaAccepte($workspaceId, $cleIdempotence);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return $this->p->etatEnvoi($idInjection);
    }

    public function pause(): void
    {
        $this->p->pause();
    }

    public function reprise(): void
    {
        $this->p->reprise();
    }

    public function modeTemoins(): bool
    {
        return $this->p->modeTemoins();
    }

    public function etat(): EtatServeur
    {
        return $this->p->etat();
    }

    public function sante(): SanteServeur
    {
        return $this->p->sante();
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        return $this->p->lireEvenements($curseur, $limite);
    }
};

// Temps C : tué EN PLEIN COMMIT du séquenceur, au moment où le message passe à « envoye » (transaction non validée).
if ($point === 'pendant_temps_C') {
    DB::listen(function (QueryExecuted $q) use ($tuer): void {
        if (str_starts_with($q->sql, 'update "envoi_messages"') && ($q->bindings[0] ?? null) === 'envoye' && DB::transactionLevel() > 0) {
            $tuer();
        }
    });
}

$liste = new class($ws) extends ListeFeuVert
{
    public function __construct(private readonly string $cible) {}

    public function derniereDecision(string $ws): ?array
    {
        if ($ws !== $this->cible) {
            return null; // aucun autre espace n'a de feu vert : le chef le saute
        }

        return ['id' => 1, 'decision' => 'accorde', 'motif' => 'test', 'le' => '2100-01-01T00:00:00Z'];
    }

    public function points(string $ws): array
    {
        return [new PointFeuVert('auto.reel_actif_faux', 'x', true, false, '', 'x', 'g', 'a_faire')];
    }
};

// Seul l'espace de ce test a un feu vert : les autres espaces de la base sont sautés par le chef (aucun message pour eux).
$chef = new ChefOrchestre(
    $app->make(PorteEnvoiReelle::class),
    new DepotSocleDb,
    $liste,
    fn (string $espace): Sequenceur => $app->make(SourceExpeditionDb::class)->sequenceurPour($espace, $serveur, new LecteurFicheDb, 'prospection'),
    fn () => $maintenant,
);
$bilan = $chef->cycle();

echo json_encode(['bilan' => $bilan, 'critiques' => $critiques]), "\n";
