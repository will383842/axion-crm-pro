<?php

/**
 * L3c-0 (ADR contrat v3 § 6, « AUCUNE PURGE ») — la garde de fond.
 *
 * Aucune tâche planifiée, aucune commande et aucune route ne supprime une
 * personne, un contact ni un candidat ; aucun DELETE dur sur `companies` (les
 * contacts partent avec, en cascade). Une opposition est une mise à l'écart
 * (`opposition_at`), jamais une suppression.
 *
 * Ce fichier ne touche pas la base : il lit le planificateur, les commandes et
 * le code applicatif.
 */

use App\Models\Company;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

uses(TestCase::class);

const AUCUNE_SUPPRESSION_TABLES = 'contacts|candidates|personnes|abonnements|companies|journalists|activities|email_messages|email_verification_logs|notifications|users';
const AUCUNE_SUPPRESSION_MODELES = 'Company|Contact|Candidate|Journalist|Personne|Abonnement|Activity|EmailMessage|EmailVerificationLog|User';

/**
 * Les gestes de suppression dure d'une personne ou de son entreprise dans UN
 * source PHP : constructeur de requêtes, modèle Eloquent (`Modèle::where()->delete()`,
 * `forceDelete`), requête rangée dans une variable puis supprimée, SQL brut
 * (`DB::statement`, `DB::unprepared`, `DB::raw`, `DB::select`) en DELETE/TRUNCATE.
 *
 * @return list<string>
 */
function aucuneSuppressionDetecter(string $source): array
{
    $tables = AUCUNE_SUPPRESSION_TABLES;
    $modeles = AUCUNE_SUPPRESSION_MODELES;
    $hits = [];

    // 1. DB::table('x')->…->delete() / forceDelete() / truncate().
    if (preg_match("/table\\(['\"]({$tables})['\"]\\)[^;]*?->(delete|forceDelete|truncate)\\(/s", $source, $m) === 1) {
        $hits[] = "suppression dure de {$m[1]}";
    }
    // 2. Modèle::where(...)->delete(), Modèle::query()->forceDelete(), Modèle::destroy(), withTrashed()->forceDelete().
    if (preg_match("/\\b({$modeles})::(?!create|find|first|updateOrCreate|firstOrCreate)\\w+\\([^;]*?->(delete|forceDelete)\\(/s", $source, $m) === 1) {
        $hits[] = "suppression Eloquent de {$m[1]} par requête";
    }
    if (preg_match("/\\b({$modeles})::(destroy|truncate|forceDestroy)\\(/", $source, $m) === 1) {
        $hits[] = "{$m[1]}::{$m[2]}()";
    }
    // 3. Requête RANGÉE EN VARIABLE, puis supprimée plus loin ($q = DB::table('contacts')…; $q->delete()).
    if (preg_match_all("/\\$(\\w+)\\s*=\\s*(?:DB::table\\(['\"](?:{$tables})['\"]\\)|(?:{$modeles})::(?!create|find|first|updateOrCreate|sole)\\w+\\()/", $source, $vars) > 0) {
        foreach (array_unique($vars[1]) as $variable) {
            if (preg_match('/\\$' . preg_quote($variable, '/') . '(?!\\s*->\\s*tokens\\()\\s*(?:->\\s*\\w+\\([^;]*?)*->\\s*(delete|forceDelete|truncate)\\(/s', $source) === 1) {
                $hits[] = "requête rangée dans \${$variable} puis supprimée";
            }
        }
    }
    // Idem pour une requête construite par une fonction d'aide (`contactsAuNumero(...)`, `$contacts`).
    if (preg_match('/\\$contacts->delete\\(|contactsAuNumero\\([^;]*?->delete\\(/s', $source) === 1) {
        $hits[] = 'suppression de contacts par requête construite';
    }
    // 4. SQL brut : DELETE FROM / TRUNCATE, quel que soit le véhicule (statement, unprepared, raw, nowdoc).
    if (preg_match("/DELETE\\s+FROM\\s+(?:ONLY\\s+)?(?:public\\.)?(?:\"?)({$tables})\\b/i", $source, $m) === 1) {
        $hits[] = "DELETE FROM {$m[1]}";
    }
    if (preg_match("/TRUNCATE\\s+(?:TABLE\\s+)?(?:ONLY\\s+)?(?:public\\.)?(?:\"?)({$tables})\\b/i", $source, $m) === 1) {
        $hits[] = "TRUNCATE {$m[1]}";
    }
    if (preg_match("/DB::(?:statement|unprepared|affectingStatement|select|raw)\\([^;]*?\\bDELETE\\b[^;]*?\\b({$tables})\\b/is", $source, $m) === 1) {
        $hits[] = "DB::statement/raw DELETE sur {$m[1]}";
    }
    // 5. forceDelete, où que ce soit.
    if (preg_match('/->forceDelete\\(/', $source) === 1) {
        $hits[] = 'forceDelete';
    }

    return array_values(array_unique($hits));
}

const AUCUNE_SUPPRESSION_COMMANDES = [
    'rgpd:purge-vivier',
    'rgpd:purge-business-prospects',
    'rgpd:purge-personnes',
    'prospection:purge-non-diffusible',
    'prospection:purge-non-commercial',
];

test('aucune tâche planifiée ne purge de personne, de contact ni de candidat', function () {
    Artisan::call('list', ['--format' => 'txt']); // charge routes/console.php

    $noms = array_map(
        static fn (Event $e): string => (string) $e->command,
        array_values(app(Schedule::class)->events()),
    );

    // TÉMOIN : le planificateur n'est pas vide.
    expect(count($noms))->toBeGreaterThan(20);

    foreach ($noms as $nom) {
        foreach (AUCUNE_SUPPRESSION_COMMANDES as $commande) {
            expect(str_contains($nom, $commande))->toBeFalse("{$commande} est planifiée");
        }
    }
});

test('les 5 commandes de purge refusent sans condition, même avec --force', function () {
    foreach (AUCUNE_SUPPRESSION_COMMANDES as $commande) {
        expect(Artisan::call($commande, ['--force' => true]))->toBe(1, $commande);
        expect(Artisan::call($commande))->toBe(1, $commande);
    }
});

test('aucun code applicatif ne supprime durement une personne, un contact, un candidat ou une entreprise', function () {
    $racine = dirname(__DIR__, 3) . '/app';
    $it = new AppendIterator;
    foreach ([$racine, dirname(__DIR__, 3) . '/routes'] as $dossier) {
        $it->append(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS)));
    }

    $fichiers = 0;
    $hits = [];
    foreach ($it as $fichier) {
        if (! str_ends_with((string) $fichier, '.php')) {
            continue;
        }
        $fichiers++;
        $source = (string) file_get_contents((string) $fichier);
        $court = str_replace([$racine . '/', dirname(__DIR__, 3) . '/'], '', (string) $fichier);

        foreach (aucuneSuppressionDetecter($source) as $hit) {
            // Seule exception : la purge des notifications ANCIENNES, qui exclut
            // le signalement RGPD (jamais purgé).
            if ($court === 'Console/Commands/RetentionPurge.php' && $hit === 'suppression dure de notifications'
                && str_contains($source, "where('type', '<>', SignalementDemandeEffacement::TYPE)")) {
                continue;
            }
            $hits[] = "{$court} : {$hit}";
        }
    }

    // TÉMOIN DE COUVERTURE : le balayage a bien vu le code.
    expect($fichiers)->toBeGreaterThan(300);
    expect($hits)->toBe([]);
});

test('DELETE /companies/{id} reste une suppression douce (corbeille), jamais définitive', function () {
    expect(in_array(SoftDeletes::class, class_uses_recursive(Company::class), true))->toBeTrue();

    $source = (string) file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Api/CompaniesController.php');
    expect($source)->not->toContain('forceDelete');
});

test('le détecteur de suppressions sait reconnaître les gestes cachés (témoins positifs) et épargne les lectures (témoins négatifs)', function () {
    $fautifs = [
        "<?php \\App\\Models\\Contact::where('email', \$e)->delete();",
        "<?php Company::query()->whereIn('id', \$ids)->forceDelete();",
        "<?php Candidate::withTrashed()->where('id', 1)->delete();",
        '<?php Journalist::destroy($ids);',
        "<?php \$q = DB::table('contacts')->where('email', \$e);\n\$q->delete();",
        "<?php \$q = Contact::where('email', \$e);\n\$n = \$q->delete();",
        "<?php DB::statement('DELETE FROM contacts WHERE email = ?', [\$e]);",
        '<?php DB::unprepared("DELETE FROM public.candidates WHERE 1=1");',
        "<?php DB::raw('DELETE FROM journalists');",
        "<?php DB::statement('TRUNCATE TABLE companies');",
        "<?php DB::table('journalists')->where('id', 3)->delete();",
        "<?php DB::table('activities')->where('id', 1)->delete();",
        "<?php DB::table('email_messages')->where('id', 1)->delete();",
        "<?php DB::statement('DELETE FROM notifications WHERE 1=1');",
        "<?php User::where('id', 1)->delete();",
    ];
    foreach ($fautifs as $code) {
        expect(aucuneSuppressionDetecter($code))->not->toBe([], $code);
    }

    $innocents = [
        "<?php \$q = DB::table('contacts')->where('email', \$e);\n\$n = \$q->count();",
        "<?php Contact::where('email', \$e)->update(['deleted_at' => now()]);",
        "<?php DB::table('activities')->where('id', 1)->count();",
        "<?php DB::table('contacts')->where('id', 1)->update(['deleted_at' => now()]);",
        '<?php $jeton->delete();',
    ];
    foreach ($innocents as $code) {
        expect(aucuneSuppressionDetecter($code))->toBe([], $code);
    }
});
