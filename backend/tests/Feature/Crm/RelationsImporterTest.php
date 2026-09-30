<?php

/**
 * `crm:relations:importer` — le statut de relation venu du site (chantier B).
 *
 * Chaque garde face à un TÉMOIN, prouvée par son EFFET en base. Fixtures
 * FICTIVES (dépôt public) : SIREN 941xxxxxx, noms « ZZ », domaines
 * `.example` / `.invalid` ; `tests/fixtures/relations/exemple-anonyme.jsonl`
 * documente le format.
 */

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const RI_FIXTURE = __DIR__ . '/../../fixtures/relations/exemple-anonyme.jsonl';

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $this->slug = 'zz-relations-' . Str::random(6);
    Workspace::create(['id' => $this->espace, 'slug' => $this->slug, 'name' => 'ZZ relations']);
});

/** @param  array<string, mixed>  $attrs */
function riFiche(string $espace, array $attrs = []): int
{
    static $seq = 0;
    $seq++;

    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $espace,
        'siren' => (string) (941500000 + $seq),
        'denomination' => 'ZZ fiche ' . $seq,
        'discovery_source' => 'insee',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

function riContact(string $espace, int $fiche, string $email): void
{
    DB::table('contacts')->insert([
        'workspace_id' => $espace, 'company_id' => $fiche, 'first_name' => 'ZZ', 'last_name' => 'Personne ' . Str::random(6),
        'email' => $email, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * Le jeu de la fixture : une fiche par cas.
 *
 * @return array<string, int>
 */
function riJeu(string $espace): array
{
    $f = [
        'siren' => riFiche($espace, ['siren' => '941000001']),
        'personne' => riFiche($espace),
        'generique' => riFiche($espace, ['email_generic' => 'accueil@zz-generique.example']),
        'domaine' => riFiche($espace, ['website' => 'https://www.ZZ-Domaine.example/contact']),
        'doublon1' => riFiche($espace, ['website' => 'http://zz-doublon.example']),
        'doublon2' => riFiche($espace, ['website' => 'zz-doublon.example/']),
        'corbeille' => riFiche($espace, ['siren' => '941000099', 'deleted_at' => now()]),
        'newsletter' => riFiche($espace, ['siren' => '941000002']),
        // Une fiche dont le site est une messagerie grand public (famille « yahoo », extension .zz jamais déléguée) : un webmail ne rapproche JAMAIS par domaine.
        'webmail' => riFiche($espace, ['website' => 'https://yahoo.zz']),
    ];
    riContact($espace, $f['personne'], 'direction@zz-personne.example');

    return $f;
}

/** @param  array<string, mixed>  $options */
function riImporter(string $slug, string $fichier, array $options = []): array
{
    $code = Artisan::call('crm:relations:importer', array_merge(['fichier' => $fichier, '--workspace' => $slug], $options));

    return ['code' => $code, 'sortie' => Artisan::output()];
}

function riCompteur(string $sortie, string $compteur): ?int
{
    return preg_match('/\|\s*' . preg_quote($compteur, '/') . '\s*\|\s*(\d+)\s*\|/', $sortie, $m) === 1 ? (int) $m[1] : null;
}

/** @return array{0: string, 1: string} */
function riEtat(int $id): array
{
    $l = DB::table('companies')->where('id', $id)->first(['relation_type', 'lifecycle_stage']);

    return [(string) $l->relation_type, (string) $l->lifecycle_stage];
}

/** @param  list<array<string, mixed>>  $lignes */
function riFichier(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'zz-relations-');
    file_put_contents($chemin, implode("\n", array_map(static fn (array $l): string => (string) json_encode($l), $lignes)) . "\n");

    return $chemin;
}

// ── Le rapprochement ────────────────────────────────────────────────────────

test('SIREN, puis e-mail de personne ou generique, puis domaine unique — et RIEN n est cree', function () {
    $f = riJeu($this->espace);
    $fiches = DB::table('companies')->count();
    $personnes = DB::table('contacts')->count();

    $r = riImporter($this->slug, RI_FIXTURE);

    expect($r['code'])->toBe(0)
        ->and(riEtat($f['siren']))->toBe(['client', 'client'])
        ->and(riEtat($f['personne']))->toBe(['prospect', 'qualifie'])
        ->and(riEtat($f['generique']))->toBe(['prospect', 'opportunite'])
        // Rapprochée par DOMAINE : un type hors prospection n'y est JAMAIS posé.
        ->and(riEtat($f['domaine']))->toBe(['prospect', 'nouveau'])
        // Ambiguë : deux fiches pour le même domaine — aucune n'est touchée.
        ->and(riEtat($f['doublon1']))->toBe(['prospect', 'nouveau'])
        ->and(riEtat($f['doublon2']))->toBe(['prospect', 'nouveau'])
        // Webmail : jamais rapproché par domaine, même quand une fiche l'a pour site.
        ->and(riEtat($f['webmail']))->toBe(['prospect', 'nouveau'])
        // À la corbeille : jamais rapprochée.
        ->and(riEtat($f['corbeille']))->toBe(['prospect', 'nouveau'])
        // Une source déclarative (`site-contact`) ne pose aucun type.
        ->and(riEtat($f['newsletter']))->toBe(['prospect', 'nouveau'])
        // Rien de créé, rien de supprimé.
        ->and(DB::table('companies')->count())->toBe($fiches)
        ->and(DB::table('contacts')->count())->toBe($personnes)
        ->and(riCompteur($r['sortie'], 'lignes_lues'))->toBe(10)
        ->and(riCompteur($r['sortie'], 'lignes_rejetees'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'rapprochees_par_siren'))->toBe(2)
        ->and(riCompteur($r['sortie'], 'rapprochees_par_email'))->toBe(2)
        ->and(riCompteur($r['sortie'], 'rapprochees_par_domaine'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'ambigues'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'non_rapprochees'))->toBe(3)
        ->and(riCompteur($r['sortie'], 'domaines_webmail_ecartes'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'demandes_refusees_sans_recul'))->toBe(0)
        ->and(riCompteur($r['sortie'], 'demandes_refusees_confiance'))->toBe(2)
        ->and(riCompteur($r['sortie'], 'types_hors_prospection_refuses'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'fiches_modifiees'))->toBe(3)
        ->and(riCompteur($r['sortie'], 'cle_inconnue'))->toBe(1);
});

test('un e-mail porte par DEUX fiches est ambigu : rien n est pose', function () {
    $a = riFiche($this->espace, ['email_generic' => 'commun@zz-partage.example']);
    $b = riFiche($this->espace);
    riContact($this->espace, $b, 'commun@zz-partage.example');
    // TÉMOIN : une adresse portée par une seule fiche est rapprochée.
    $c = riFiche($this->espace, ['email_generic' => 'seul@zz-partage.example']);

    $r = riImporter($this->slug, riFichier([
        ['source' => 'site-client', 'email' => 'commun@zz-partage.example', 'relation_type' => 'client'],
        ['source' => 'site-client', 'email' => 'SEUL@zz-partage.example', 'relation_type' => 'client'],
    ]));

    expect(riEtat($a))->toBe(['prospect', 'nouveau'])
        ->and(riEtat($b))->toBe(['prospect', 'nouveau'])
        ->and(riEtat($c))->toBe(['client', 'client'])
        ->and(riCompteur($r['sortie'], 'ambigues'))->toBe(1);
});

// ── La promotion ────────────────────────────────────────────────────────────

test('on ne retrograde JAMAIS, et une relation posee a la main n est jamais ecrasee', function () {
    $client = riFiche($this->espace, ['relation_type' => 'client', 'lifecycle_stage' => 'client']);
    $opportunite = riFiche($this->espace, ['lifecycle_stage' => 'opportunite']);
    $main = riFiche($this->espace, ['relation_type' => 'partenaire', 'lifecycle_stage' => 'qualifie', 'relation_saisie_manuelle_at' => now()]);
    // TÉMOIN : la même fiche, sans la marque manuelle, est promue.
    $temoin = riFiche($this->espace, ['relation_type' => 'partenaire', 'lifecycle_stage' => 'qualifie']);
    $sirens = DB::table('companies')->whereIn('id', [$client, $opportunite, $main, $temoin])->pluck('siren', 'id');

    $r = riImporter($this->slug, riFichier([
        ['source' => 'site-contact', 'siren' => $sirens[$client], 'relation_type' => 'partenaire', 'lifecycle_stage' => 'qualifie'],
        ['source' => 'site-contact', 'siren' => $sirens[$opportunite], 'lifecycle_stage' => 'qualifie'],
        ['source' => 'site-client', 'siren' => $sirens[$main], 'relation_type' => 'client'],
        ['source' => 'site-client', 'siren' => $sirens[$temoin], 'relation_type' => 'client'],
    ]));

    expect(riEtat($client))->toBe(['client', 'client'])
        ->and(riEtat($opportunite))->toBe(['prospect', 'opportunite'])
        ->and(riEtat($main))->toBe(['partenaire', 'qualifie'])
        ->and(riEtat($temoin))->toBe(['client', 'client'])
        ->and(riCompteur($r['sortie'], 'verrouillees_a_la_main'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'demandes_refusees_sans_recul'))->toBe(2)
        ->and(riCompteur($r['sortie'], 'fiches_modifiees'))->toBe(1);
});

// ── L'essai à blanc ne ment pas ─────────────────────────────────────────────

test('l essai a blanc n ecrit RIEN et annonce ce que l execution realise, meme sur plusieurs paquets', function () {
    $f = riJeu($this->espace);
    $ligne = riFiche($this->espace);
    $siren = (string) DB::table('companies')->where('id', $ligne)->value('siren');
    // La MÊME fiche dans deux paquets (paquets d'une ligne) : qualifie puis opportunite puis client.
    $fichier = riFichier([
        ['source' => 'site-contact', 'siren' => $siren, 'lifecycle_stage' => 'qualifie'],
        ['source' => 'site-rdv', 'siren' => $siren, 'lifecycle_stage' => 'opportunite'],
        ['source' => 'site-client', 'siren' => $siren, 'relation_type' => 'client'],
    ]);
    $photo = static fn (): array => DB::table('companies')->orderBy('id')->get(['id', 'relation_type', 'lifecycle_stage', 'updated_at'])->map(fn ($l) => (array) $l)->all();
    $avant = $photo();
    $activites = DB::table('activities')->count();
    $audits = DB::table('audit_logs')->count();

    $blanc = riImporter($this->slug, $fichier, ['--dry-run' => true, '--paquet' => 1]);
    $blancFixture = riImporter($this->slug, RI_FIXTURE, ['--dry-run' => true]);

    expect($photo())->toBe($avant)
        ->and(DB::table('activities')->count())->toBe($activites)
        ->and(DB::table('audit_logs')->count())->toBe($audits)
        ->and($blanc['sortie'])->toContain('[À BLANC]')
        ->and(riCompteur($blanc['sortie'], 'fiches_modifiees'))->toBeNull();

    $reel = riImporter($this->slug, $fichier, ['--paquet' => 1]);
    $reelFixture = riImporter($this->slug, RI_FIXTURE);

    expect(riCompteur($blanc['sortie'], 'fiches_a_modifier'))->toBe(3)
        ->and(riCompteur($reel['sortie'], 'fiches_modifiees'))->toBe(3)
        ->and(riCompteur($blancFixture['sortie'], 'fiches_a_modifier'))->toBe(riCompteur($reelFixture['sortie'], 'fiches_modifiees'))
        ->and(riEtat($ligne))->toBe(['client', 'client'])
        ->and(riEtat($f['siren']))->toBe(['client', 'client']);
});

test('relance sur le meme fichier : idempotente, plus rien a ecrire', function () {
    riJeu($this->espace);
    riImporter($this->slug, RI_FIXTURE);
    $activites = DB::table('activities')->count();

    $r = riImporter($this->slug, RI_FIXTURE);

    expect(riCompteur($r['sortie'], 'fiches_a_modifier'))->toBe(0)
        ->and(riCompteur($r['sortie'], 'fiches_modifiees'))->toBe(0)
        ->and(riCompteur($r['sortie'], 'deja_a_jour'))->toBeGreaterThan(0)
        ->and(DB::table('activities')->count())->toBe($activites);
});

// ── Traces ──────────────────────────────────────────────────────────────────

test('chaque fiche promue a son activite dans la timeline, chaque paquet ecrit son entree d audit', function () {
    $f = riJeu($this->espace);

    riImporter($this->slug, RI_FIXTURE, ['--paquet' => 3]);

    $activite = DB::table('activities')->where('subject_type', 'company')->where('subject_id', $f['siren'])->where('kind', 'stage_changed')->first();
    $payload = json_decode((string) $activite?->payload, true);
    expect($activite)->not->toBeNull()
        ->and($payload['source'])->toBe('crm:relations:importer')
        ->and($payload['origines'])->toBe(['site-client'])
        ->and($payload['relation'])->toEqual(['from' => 'prospect', 'to' => 'client'])
        ->and(DB::table('activities')->where('kind', 'stage_changed')->count())->toBe(3)
        ->and(DB::table('audit_logs')->where('event_type', 'RELATIONS_IMPORT_PAQUET')->count())->toBeGreaterThanOrEqual(1)
        ->and(DB::table('audit_logs')->where('event_type', 'RELATIONS_IMPORT_FIN')->count())->toBe(1);
});

test('aucune adresse ni denomination a l ecran ; --compteurs-seulement ne montre meme pas les numeros de ligne', function () {
    riJeu($this->espace);

    $normal = riImporter($this->slug, RI_FIXTURE, ['--dry-run' => true]);
    $discret = riImporter($this->slug, RI_FIXTURE, ['--dry-run' => true, '--compteurs-seulement' => true]);

    foreach ([$normal['sortie'], $discret['sortie']] as $sortie) {
        expect($sortie)->not->toContain('@')
            ->and($sortie)->not->toContain('ZZ Societe')
            ->and($sortie)->not->toContain('Particulier');
    }
    expect($normal['sortie'])->toContain('non_rapprochee : 6, 7, 8')
        ->and($discret['sortie'])->not->toContain('non_rapprochee :')
        ->and($discret['sortie'])->not->toContain($this->espace);
});

test('un fichier introuvable ou un espace inconnu echouent sans rien ecrire', function () {
    expect(riImporter($this->slug, '/nexiste/pas.jsonl')['code'])->toBe(1)
        ->and(riImporter('zz-espace-inconnu', RI_FIXTURE)['code'])->toBe(1);
});

// ── Relecture de #265 : confiance, fournisseur, corbeille, comptage ─────────

test('un type hors prospection n est pose QUE par site-client, par SIREN ou e-mail exact', function () {
    $parSiren = riFiche($this->espace);
    $parEmail = riFiche($this->espace, ['email_generic' => 'achat@zz-confiance.example']);
    $parDomaine = riFiche($this->espace, ['website' => 'https://zz-domaine-confiance.example']);
    $formulaire = riFiche($this->espace);
    // TÉMOIN : la même ligne de formulaire promeut l'ÉTAPE.
    $etape = riFiche($this->espace);
    $sirens = DB::table('companies')->whereIn('id', [$parSiren, $formulaire, $etape])->pluck('siren', 'id');

    $r = riImporter($this->slug, riFichier([
        ['source' => 'site-client', 'siren' => $sirens[$parSiren], 'relation_type' => 'partenaire'],
        ['source' => 'site-client', 'email' => 'achat@zz-confiance.example', 'relation_type' => 'client'],
        ['source' => 'site-client', 'email' => 'x@zz-domaine-confiance.example', 'relation_type' => 'client', 'lifecycle_stage' => 'client'],
        ['source' => 'site-contact', 'siren' => $sirens[$formulaire], 'relation_type' => 'presse_media', 'lifecycle_stage' => 'client'],
        ['source' => 'site-contact', 'siren' => $sirens[$etape], 'relation_type' => 'partenaire', 'lifecycle_stage' => 'opportunite'],
    ]));

    expect(riEtat($parSiren))->toBe(['partenaire', 'nouveau'])
        ->and(riEtat($parEmail))->toBe(['client', 'client'])
        ->and(riEtat($parDomaine))->toBe(['prospect', 'nouveau'])
        ->and(riEtat($formulaire))->toBe(['prospect', 'nouveau'])
        ->and(riEtat($etape))->toBe(['prospect', 'opportunite'])
        ->and(riCompteur($r['sortie'], 'types_hors_prospection_refuses'))->toBe(3)
        ->and(riCompteur($r['sortie'], 'demandes_refusees_confiance'))->toBe(2);
});

test('fournisseur (saisie manuelle seulement) n est jamais importe ; une fiche hors prospection n y revient jamais', function () {
    $fournisseur = riFiche($this->espace, ['relation_type' => 'fournisseur']);
    $cible = riFiche($this->espace);
    $sirens = DB::table('companies')->whereIn('id', [$fournisseur, $cible])->pluck('siren', 'id');

    $r = riImporter($this->slug, riFichier([
        ['source' => 'site-client', 'siren' => $sirens[$cible], 'relation_type' => 'fournisseur'],
        ['source' => 'site-client', 'siren' => $sirens[$fournisseur], 'relation_type' => 'conference'],
    ]));

    expect(riEtat($cible))->toBe(['prospect', 'nouveau'])
        ->and(riEtat($fournisseur))->toBe(['fournisseur', 'nouveau'])
        ->and(riCompteur($r['sortie'], 'relation_saisie_manuelle'))->toBe(1)
        ->and(riCompteur($r['sortie'], 'demandes_refusees_sans_recul'))->toBe(1);
});

test('une personne vivante d une fiche a la corbeille ne rapproche rien', function () {
    $supprimee = riFiche($this->espace, ['deleted_at' => now()]);
    riContact($this->espace, $supprimee, 'vivant@zz-corbeille.example');

    $r = riImporter($this->slug, riFichier([
        ['source' => 'site-client', 'email' => 'vivant@zz-corbeille.example', 'relation_type' => 'client'],
    ]));

    expect(riEtat($supprimee))->toBe(['prospect', 'nouveau'])
        ->and(riCompteur($r['sortie'], 'rapprochees_par_email'))->toBe(0)
        ->and(riCompteur($r['sortie'], 'non_rapprochees'))->toBe(1);
});

test('chaque ligne lue tombe dans UN compteur, et un seul', function () {
    riJeu($this->espace);
    $main = riFiche($this->espace, ['relation_saisie_manuelle_at' => now()]);
    $siren = (string) DB::table('companies')->where('id', $main)->value('siren');
    $fichier = riFichier(array_merge(
        array_map(static fn (string $l): array => (array) json_decode($l, true), array_values(array_filter(file(RI_FIXTURE, FILE_IGNORE_NEW_LINES) ?: [], static fn (string $l): bool => trim($l) !== '' && str_contains($l, '"relation_type"')))),
        [['source' => 'site-client', 'siren' => $siren, 'relation_type' => 'client'], ['source' => 'site-client', 'siren' => '941000001', 'relation_type' => 'client']],
    ));

    $r = riImporter($this->slug, $fichier);
    $n = static fn (string $c): int => (int) riCompteur($r['sortie'], $c);

    $ventilees = $n('lignes_rejetees') + $n('ambigues') + $n('non_rapprochees') + $n('lignes_appliquees') + $n('deja_a_jour')
        + $n('demandes_refusees_sans_recul') + $n('demandes_refusees_confiance') + $n('verrouillees_a_la_main') + $n('fiches_disparues');
    expect($n('lignes_lues'))->toBeGreaterThan(5)
        ->and($ventilees)->toBe($n('lignes_lues'))
        ->and($n('verrouillees_a_la_main'))->toBe(1)
        ->and($n('deja_a_jour'))->toBe(1);
});
