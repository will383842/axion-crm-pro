<?php

/*
 * Ouvrier de test de concurrence de la CHAUFFE (processus séparé, connexion séparée).
 * Usage : php ouvrier_chauffe.php <workspace> <jour Paris Y-m-d> <cle> <départ microtime> <pause secondes dans l'injection> <envois déjà notés>
 * Le « serveur » dort pendant l'injection : la réservation est alors validée mais PAS encore notée (en vol).
 * Écrit sur la sortie standard une ligne JSON {"accepte":bool}. Aucun réseau, aucun envoi réel.
 */

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Chauffe\GardeChauffe;
use App\Envoi\Chauffe\ResolveurEnMemoire;
use App\Envoi\Contrat\EtatMessage;
use App\Envoi\Contrat\EtatServeur;
use App\Envoi\Contrat\LotEvenements;
use App\Envoi\Contrat\MessageAEnvoyer;
use App\Envoi\Contrat\RenvoiParMemeCle;
use App\Envoi\Contrat\ResultatInjection;
use App\Envoi\Contrat\SanteServeur;
use App\Envoi\Contrat\ServeurEnvoi;
use App\Envoi\Depot\DepotSocleDb;
use App\Envoi\PasserelleLocale;
use App\Envoi\Simulateur\FauxServeurEnvoi;
use App\Support\WorkspaceContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ws, $jour, $cle, $depart, $pause, $notes] = $argv;
while (microtime(true) < (float) $depart) {
    usleep(500);
}

$faux = new FauxServeurEnvoi;
$serveur = new class($faux, (float) $pause) implements RenvoiParMemeCle, ServeurEnvoi
{
    public function __construct(private readonly FauxServeurEnvoi $faux, private readonly float $pause) {}

    public function injecter(MessageAEnvoyer $message): ResultatInjection
    {
        usleep((int) ($this->pause * 1_000_000)); // l'envoi « dure » : l'autre worker passe ses portes pendant ce temps

        return $this->faux->injecter($message);
    }

    public function etatEnvoi(string $idInjection): EtatMessage
    {
        return $this->faux->etatEnvoi($idInjection);
    }

    public function pause(): void
    {
        $this->faux->pause();
    }

    public function reprise(): void
    {
        $this->faux->reprise();
    }

    public function modeTemoins(): bool
    {
        return $this->faux->modeTemoins();
    }

    public function etat(): EtatServeur
    {
        return $this->faux->etat();
    }

    public function sante(): SanteServeur
    {
        return $this->faux->sante();
    }

    public function lireEvenements(?string $curseur, int $limite = 100): LotEvenements
    {
        return $this->faux->lireEvenements($curseur, $limite);
    }
};
$maintenant = new DateTimeImmutable($jour . ' 10:00:00', new DateTimeZone('Europe/Paris'));
$garde = new GardeChauffe(new DepotChauffe, new DepotSocleDb, new ResolveurEnMemoire(['example.test' => 'gmail']), null);
$passerelle = new PasserelleLocale($serveur, new DepotSocleDb, true, 300, fn () => $maintenant, $ws, 300, fn () => WorkspaceContext::current(), $garde);
// Tables TEMPORAIRES de même nom (pg_temp passe avant public) : `envoi_sante_battement`, `envoi_boites` et
// `envoi_chauffe_jours` sont en AJOUT SEUL ; une ligne validée ici resterait pour toute la base et fausserait d'autres
// tests (battement unique, comptes de boîtes). Les compteurs « déjà notés » sont donc posés dans la session de chaque
// ouvrier ; ce qui est PARTAGÉ (journal des réservations, compteur global, verrou du jour) reste dans les vraies tables.
DB::statement('CREATE TEMP TABLE envoi_sante_battement (id smallint PRIMARY KEY, dernier_le timestamptz NOT NULL)');
DB::statement('CREATE TEMP TABLE envoi_boites (LIKE public.envoi_boites INCLUDING ALL)');
DB::statement('CREATE TEMP TABLE envoi_chauffe_jours (LIKE public.envoi_chauffe_jours INCLUDING ALL)');
$boite = Uuid::uuid5(Uuid::NAMESPACE_DNS, 'boite1.' . $ws)->toString(); // même identifiant chez les deux ouvriers : leurs réservations se voient
DB::statement("INSERT INTO envoi_boites (id, workspace_id, adresse, hote, port, identifiants_chiffres) VALUES (?, ?, 'boite1@example.test', 'smtp.example.test', 587, 'chiffre-de-test')", [$boite, $ws]);
DB::statement("INSERT INTO envoi_chauffe_jours (workspace_id, boite, jour, fournisseur, envoyes) VALUES (?, ?, ?, 'gmail', ?)", [$ws, $boite, $jour, (int) $notes]);
$passerelle->sante(); // un battement à l'heure courante

$resultat = WorkspaceContext::run($ws, fn () => $passerelle->injecter(
    new MessageAEnvoyer($ws, 'temoin@example.test', 'boite1@example.test', 'S', 'C', $cle),
));
echo json_encode(['accepte' => $resultat->accepte]), "\n";
