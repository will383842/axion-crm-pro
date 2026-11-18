<?php

use App\Crm\Emails\QualificationEmail;
use App\Envoi\Campagnes\ConstructionSequence;
use App\Envoi\Campagnes\DepotCampagnes;
use App\Envoi\Campagnes\MaterialisationCampagne;
use App\Envoi\Campagnes\MaterialisationRefusee;
use App\Envoi\Campagnes\SelectionDestinataires;
use App\Envoi\Creation\ServiceMessagesTypes;
use App\Envoi\Depot\DepotSocle;
use App\Envoi\Verdicts\EcrivainVerdicts;
use App\Envoi\Verdicts\PreparationVerdicts;
use App\Envoi\Verdicts\VerdictAdresse;
use App\Envoi\Verdicts\VerificateurAdresse;
use App\Envoi\Verdicts\VerificateurInterne;
use App\Support\ListeSuppression;
use App\Support\WorkspaceContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\DoublonsFixtures as F;
use Tests\TestCase;

/*
 * Lot L4 (PLAN ADRESSES GÉNÉRALES, #680) — matérialisation du repli sur l'adresse générale : verdict `risque/generique` admis pour le repli
 * seulement, une seule étape figée, plafond de repli séparé (compris dans le plafond général), débordement écarté et compté.
 * Sous le rôle applicatif ET sous le propriétaire. Données fictives (`*.example.test`), verdicts simulés, aucun réseau, aucun envoi.
 */
uses(TestCase::class, RefreshDatabase::class);

const MRG_MODES = ['app', 'proprio'];

function mrgLundi(): DateTimeImmutable
{
    return new DateTimeImmutable('2026-11-16 07:00', new DateTimeZone('Europe/Paris'));
}

function mrgEspace(?string $interrupteur = 'true', ?string $plafond = null): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert(['id' => $id, 'name' => 'Mrg', 'slug' => 'mrg-' . substr($id, 0, 8), 'created_at' => now(), 'updated_at' => now()]);
    if ($interrupteur !== null) {
        mrgReglage($id, 'envoi.repli_generique', $interrupteur);
    }
    if ($plafond !== null) {
        mrgReglage($id, 'envoi.repli_generique_plafond_jour', $plafond);
    }

    return $id;
}

/** Posé par le propriétaire (seul habilité), valeurs légales seulement. */
function mrgReglage(string $ws, string $cle, string $valeur): void
{
    WorkspaceContext::run($ws, fn () => DB::table('envoi_reglages')->updateOrInsert(['workspace_id' => $ws, 'cle' => $cle], ['valeur' => $valeur, 'auteur' => 'fondateur']));
}

/**
 * Campagne « programmee » (séquence de 2 étapes) sur une liste manuelle : une entreprise par élément, avec un contact par adresse.
 *
 * @param  list<list<string>>  $entreprises
 */
function mrgProgrammee(string $ws, array $entreprises): string
{
    $liste = (int) DB::table('listes_manuelles')->insertGetId(['workspace_id' => $ws, 'nom' => 'Liste ' . Str::random(6)]);
    foreach ($entreprises as $adresses) {
        $e = (int) DB::table('companies')->insertGetId(['workspace_id' => $ws, 'siren' => F::siren(), 'denomination' => 'Société fictive', 'discovery_source' => 'insee']);
        foreach ($adresses as $i => $email) {
            DB::table('contacts')->insert(['workspace_id' => $ws, 'company_id' => $e, 'first_name' => 'Prenom', 'last_name' => 'Nom' . $i, 'email' => $email]);
        }
        DB::table('listes_manuelles_membres')->insert(['workspace_id' => $ws, 'liste_id' => $liste, 'company_id' => $e, 'origine' => 'coche']);
    }
    $service = app(ServiceMessagesTypes::class);
    $etapes = [];
    foreach ([0, 3] as $i => $d) {
        $m = $service->creer($ws, 'Etape ' . ($i + 1), 'Objet ' . ($i + 1), 'Corps ' . ($i + 1), 'le fondateur');
        $service->valider($ws, $m['id'], 1);
        $etapes[] = ['etape' => $i + 1, 'message_id' => $m['id'], 'delai_jours' => $d];
    }
    $depot = app(DepotCampagnes::class);
    $id = $depot->creer($ws, 'Campagne fictive', ['type' => 'liste', 'reference' => (string) $liste, 'libelle' => 'Liste fictive'], [], 2, ['contenu' => ['sequence' => $etapes], 'etapes_faites' => []], 'le fondateur');
    $depot->transitionner($ws, $id, 'a_valider', 1, 'le fondateur', 'test', 'cle-mrg-' . Str::random(12));
    DB::table('envoi_campagnes')->where('id', $id)->update(['statut' => 'programmee', 'version' => 3]);
    DB::table('envoi_campagnes_journal')->insert(['workspace_id' => $ws, 'campagne_id' => $id, 'transition' => 'a_valider>programmee', 'auteur' => 'le fondateur',
        'motif' => 'test', 'cle_idempotence' => 'cle-j-' . Str::random(12), 'cree_le' => DB::raw("now() - interval '10 minutes'")]);

    return $id;
}

/** Une entreprise par adresse générale seule. */
function mrgRepli(int $n, string $domaine = 'g'): array
{
    return array_map(static fn (int $i): array => ["contact@{$domaine}{$i}.example.test"], range(1, $n));
}

/**
 * Vérificateur simulé : $imposes (adresse => [verdict, motif]) sinon `risque/generique` pour un générique et `valide` pour un nominatif.
 * $avant : appelé à chaque vérification (entre la sélection et l'écriture).
 */
function mrgVerificateur(array $imposes = [], ?Closure $avant = null): PreparationVerdicts
{
    $v = new class($imposes, $avant) implements VerificateurAdresse
    {
        public function __construct(private readonly array $imposes, private readonly ?Closure $avant) {}

        public function source(): string
        {
            return VerificateurInterne::SOURCE;
        }

        public function verifier(string $ws, array $adresses, DateTimeImmutable $maintenant): array
        {
            if ($this->avant !== null) {
                ($this->avant)();
            }
            $sortie = [];
            foreach ($adresses as $a) {
                [$verdict, $motif] = $this->imposes[$a] ?? (QualificationEmail::type($a) === 'generique' ? [VerdictAdresse::RISQUE, 'generique'] : [VerdictAdresse::VALIDE, 'a']);
                $sortie[ListeSuppression::empreinte($a)] = new VerdictAdresse($verdict, $motif, $maintenant);
            }

            return $sortie;
        }
    };

    return new PreparationVerdicts($v, app(EcrivainVerdicts::class));
}

function mrgService(?PreparationVerdicts $verdicts = null): MaterialisationCampagne
{
    return new MaterialisationCampagne(
        app(DepotCampagnes::class),
        new SelectionDestinataires,
        app(ConstructionSequence::class),
        app(DepotSocle::class),
        MaterialisationCampagne::TAILLE_LOT,
        MaterialisationCampagne::LOTS_PAR_APPEL,
        $verdicts ?? mrgVerificateur(),
    );
}

/** « app » = rôle applicatif non propriétaire sous RLS. */
function mrgSous(string $mode, Closure $f): mixed
{
    MaterialisationCampagne::$niveauTolere = DB::transactionLevel();
    if ($mode === 'proprio') {
        return $f();
    }
    DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
    try {
        return $f();
    } finally {
        DB::statement('RESET ROLE');
    }
}

/** @return array<string, int> inscriptions par jour de Paris, toutes ou seulement celles de repli */
function mrgParJour(string $ws, bool $repliSeul): array
{
    $lignes = DB::select(
        "SELECT to_char(prochaine_echeance AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD') AS jour, count(*) AS n FROM envoi_inscriptions WHERE workspace_id = ?"
        . ($repliSeul ? ' AND repli_generique' : '') . ' GROUP BY 1',
        [$ws],
    );

    return array_column(array_map(static fn ($l): array => [(string) $l->jour, (int) $l->n], $lignes), 1, 0);
}

beforeEach(function () {
    config(['envoi.plafond_jour' => 20]);
    Http::preventStrayRequests();
});

afterEach(function () {
    MaterialisationCampagne::$niveauTolere = 0;
});

test('L4 1 le repli est inscrit avec UNE seule étape figée et la colonne à true ; le nominatif garde ses deux étapes', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace();
        $id = mrgProgrammee($ws, [['contact@seul.example.test'], ['prenom@nomme.example.test']]);
        $r = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        expect($r->etat)->toBe('terminee', $mode)->and($r->inscrits)->toBe(2)->and($r->compteurs['retenus_repli_generique'] ?? null)->toBe(1);
        $repli = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('repli_generique', true)->get();
        $nomme = DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('repli_generique', false)->get();
        expect($repli)->toHaveCount(1, $mode)->and($nomme)->toHaveCount(1)
            ->and(count(json_decode((string) $repli[0]->version_figee, true)['etapes']))->toBe(1)
            ->and(json_decode((string) $repli[0]->version_figee, true)['etapes'][0]['rang'])->toBe(1)
            ->and(count(json_decode((string) $nomme[0]->version_figee, true)['etapes']))->toBe(2)
            ->and($repli[0]->population)->toBe('entreprises');
    }
});

test('L4 2 dix entreprises en repli, plafond 3 : au plus 3 repli par jour ouvré, tous écrits', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace();
        $id = mrgProgrammee($ws, mrgRepli(10));
        $r = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        $jours = mrgParJour($ws, true);
        expect($r->inscrits)->toBe(10, $mode)->and(array_sum($jours))->toBe(10)->and(max($jours))->toBeLessThanOrEqual(3)->and(count($jours))->toBeGreaterThanOrEqual(4);
        foreach (array_keys($jours) as $j) {
            expect(JoursOuvresTestHelperEstOuvre($j))->toBeTrue("$j est un jour ouvré");
        }
    }
});

test('L4 3 le repli est COMPRIS dans le plafond général : total du jour ≤ plafond, repli ≤ plafond de repli', function () {
    foreach (MRG_MODES as $mode) {
        config(['envoi.plafond_jour' => 4]);
        $ws = mrgEspace();
        $nommes = array_map(static fn (int $i): array => ["prenom@n{$i}.example.test"], range(1, 6));
        $id = mrgProgrammee($ws, array_merge(mrgRepli(6), $nommes));
        $r = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        $tous = mrgParJour($ws, false);
        $repli = mrgParJour($ws, true);
        expect($r->inscrits)->toBe(12, $mode)->and(max($tous))->toBeLessThanOrEqual(4)->and(max($repli))->toBeLessThanOrEqual(3);
    }
});

test('L4 4 plafond de repli à 0 : aucune ligne de repli (écartées et comptées), les nommés sont inscrits', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace('true', '0');
        $id = mrgProgrammee($ws, array_merge(mrgRepli(3), [['prenom@nomme.example.test']]));
        $r = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        expect($r->etat)->toBe('terminee', $mode)->and($r->inscrits)->toBe(1)->and($r->compteurs['repli_generique_plafond'])->toBe(3)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('repli_generique', true)->count())->toBe(0)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(1);
    }
});

test('L4 5 débordement du plafond (horizon de 20 jours ouvrés) : écarté et compté, sans file d\'attente', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace('true', '1');
        $id = mrgProgrammee($ws, mrgRepli(22));
        $r = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        $retenus = $r->compteurs['retenus'];
        expect($r->inscrits)->toBe(20, $mode)->and($r->compteurs['repli_generique_plafond'])->toBe(2)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(20)
            ->and($r->compteurs['membres'] - $retenus)->toBe(2);
    }
});

test('L4 6 verdicts : risque/generique admis pour le repli seulement ; invalide et tout autre risque écartés', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace();
        $id = mrgProgrammee($ws, [
            ['contact@ok.example.test'], ['contact@muet.example.test'], ['contact@jetable.example.test'], ['prenom@risque.example.test'],
        ]);
        $verdicts = mrgVerificateur([
            'contact@muet.example.test' => [VerdictAdresse::INVALIDE, 'mx'],
            'contact@jetable.example.test' => [VerdictAdresse::RISQUE, 'jetable'],
            'prenom@risque.example.test' => [VerdictAdresse::RISQUE, 'generique'],
        ]);
        $r = mrgSous($mode, fn () => mrgService($verdicts)->materialiser($ws, $id, mrgLundi()));
        $c = $r->compteurs;
        expect($r->inscrits)->toBe(1, $mode)->and($c['adresse_invalide'])->toBe(1)->and($c['adresse_risquee'])->toBe(2)
            ->and($c['membres'] - $c['retenus'])->toBe(3)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('repli_generique', true)->count())->toBe(1);
    }
});

test('L4 7 interrupteur éteint entre la sélection et l\'écriture : MaterialisationRefusee, rien d\'écrit', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace();
        $id = mrgProgrammee($ws, array_merge(mrgRepli(2), [['prenom@nomme.example.test']]));
        $eteindre = function () use ($ws, $mode): void {
            if ($mode === 'app') {
                DB::statement('RESET ROLE'); // seul le propriétaire pose le réglage
            }
            mrgReglage($ws, 'envoi.repli_generique', 'false');
            if ($mode === 'app') {
                DB::statement('SET LOCAL ROLE "' . str_replace('"', '""', (string) config('database.connections.pgsql_app.username')) . '"');
            }
        };
        $refus = null;
        DB::statement('SAVEPOINT mrg_refus');
        try {
            mrgSous($mode, fn () => mrgService(mrgVerificateur([], $eteindre))->materialiser($ws, $id, mrgLundi()));
        } catch (MaterialisationRefusee $e) {
            $refus = $e;
            DB::statement('ROLLBACK TO SAVEPOINT mrg_refus');
            DB::statement('RESET ROLE');
        }
        expect($refus)->not->toBeNull($mode)
            ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->count())->toBe(0)
            ->and((int) DB::table('envoi_campagnes_materialisations')->where('campagne_id', $id)->value('curseur'))->toBe(0);
    }
});

test('L4 8 non-régression : interrupteur éteint ou absent ⇒ aucun repli, seuls les nommés sont inscrits', function () {
    foreach (MRG_MODES as $mode) {
        foreach (['false', null] as $reglage) {
            $ws = mrgEspace($reglage);
            $id = mrgProgrammee($ws, array_merge(mrgRepli(3), [['prenom@nomme.example.test']]));
            $r = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
            expect($r->inscrits)->toBe(1, "$mode " . var_export($reglage, true))->and($r->compteurs)->not->toHaveKey('retenus_repli_generique')
                ->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('repli_generique', true)->count())->toBe(0);
        }
    }
});

test('L4 9 reprise rejouable : un second passage ne crée aucun doublon (un repli par entreprise)', function () {
    foreach (MRG_MODES as $mode) {
        $ws = mrgEspace();
        $id = mrgProgrammee($ws, mrgRepli(4));
        $premier = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        expect($premier->inscrits)->toBe(4, $mode);
        $second = mrgSous($mode, fn () => mrgService()->materialiser($ws, $id, mrgLundi()));
        expect($second->inscrits)->toBe(0)->and(DB::table('envoi_inscriptions')->where('workspace_id', $ws)->where('repli_generique', true)->count())->toBe(4);
    }
});

function JoursOuvresTestHelperEstOuvre(string $jour): bool
{
    return ! in_array((int) (new DateTimeImmutable($jour))->format('N'), [6, 7], true);
}
