<?php

/**
 * GARDE — aucun code ne REMPLACE, ne VIDE ni ne SUPPRIME en bloc les
 * étiquettes ou leurs liens, hors des endroits connus et relus (chantier 2,
 * 2026-09-29).
 *
 * `ClassifierService::autoTag()` faisait `$company->tags()->sync($tagIds)` :
 * `sync` DÉTACHE toutes les étiquettes qui ne sont pas dans la liste — les
 * manuelles, les `src:` de provenance, les verrouillées, dont celle qui
 * PROTÈGE les organisateurs d'événements et les fédérations. La classe n'était
 * appelée nulle part (audit du 2026-09-28) ; elle a été SUPPRIMÉE.
 *
 * ⚠️ C'EST UNE GARDE TEXTUELLE. Elle lit le code source de `app/` avec le
 * tokeniseur PHP ; elle ne l'exécute pas. Elle voit :
 *   1. tout appel de méthode `->sync(`, `->syncWithPivotValues(`, `->detach(`
 *      — quel que soit l'objet : `$company->tags()->sync()`, une variable
 *      intermédiaire `$rel->sync()`, `detach()`, `detach(null)`,
 *      `detach($x->pluck('id'))`. AUCUN n'est permis dans `app/` ;
 *   2. toute instruction qui part de `table('company_tag'|'candidate_tag'|'tags')`
 *      et contient `->delete(`, `->forceDelete(` ou `->truncate(`, quelles que
 *      soient les fermetures (closures) qu'elle traverse ;
 *   3. tout texte `DELETE FROM company_tag|candidate_tag|tags` (SQL brut) ;
 *   4. `Tag::…->delete(`, `Tag::destroy(`, `$tag->delete(`.
 * Les cas 2 à 4 ne sont permis QUE dans les fichiers et au nombre exact de
 * la liste `SD_PERMIS` : un ajout, ou un retrait non reporté, la fait rougir.
 *
 * Elle NE voit PAS : une requête rangée dans une variable puis supprimée plus
 * loin (`$q = DB::table('tags'); … $q->delete();`), un nom de table construit
 * dynamiquement, un `DB::statement()` dont le SQL est assemblé ailleurs. Ces
 * formes restent à la relecture humaine.
 */
const SD_TABLES = ['company_tag', 'candidate_tag', 'tags'];

/**
 * Les suppressions CONNUES, fichier par fichier : chemin relatif à `app/` =>
 * [famille de geste => nombre exact].
 */
const SD_PERMIS = [
    // Retrait des liens automatiques devenus faux (classement), et suppression
    // des étiquettes inemployées SUR OPTION seulement (--supprimer-etiquettes-orphelines).
    'Console/Commands/CrmReferentielsReclasser.php' => ['sql' => 2],
    // Texte d'aide affiché à l'opérateur (procédure de retour arrière), pas une requête.
    'Console/Commands/ScrapingBackfillSrcTags.php' => ['sql' => 1],
    // Retrait en masse d'UNE étiquette choisie par l'utilisateur, sur des fiches choisies.
    'Http/Controllers/Api/CompanyTagsBulkController.php' => ['requete' => 1],
    // Suppression d'une étiquette par l'utilisateur, depuis l'écran « Tags ».
    'Http/Controllers/Api/TagsController.php' => ['modele' => 1],
    // La synchro retire UN lien automatique devenu faux, jamais src:/verrouillée/manuelle.
    'Services/Tags/AutoTaggerService.php' => ['requete' => 1],
];

/**
 * Les gestes trouvés dans un source PHP, une entrée par occurrence :
 * `relation`, `requete`, `sql` ou `modele`.
 *
 * @return list<string>
 */
function sdGestes(string $source): array
{
    $jetons = token_get_all($source);
    $n = count($jetons);
    $texte = static fn (int $i): string => is_array($jetons[$i]) ? $jetons[$i][1] : $jetons[$i];
    $significatif = static function (int $i, int $pas) use ($jetons, $n): int {
        for ($j = $i + $pas; $j >= 0 && $j < $n; $j += $pas) {
            if (! is_array($jetons[$j]) || ! in_array($jetons[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $j;
            }
        }

        return -1;
    };
    // Le texte de l'instruction qui commence en $i, jusqu'au `;` de même profondeur.
    $instruction = static function (int $i) use ($jetons, $n, $texte): string {
        $profondeur = 0;
        $sortie = '';
        for ($j = $i; $j < $n; $j++) {
            $t = $texte($j);
            if (in_array($t, ['(', '[', '{'], true) || (is_array($jetons[$j]) && in_array($jetons[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $profondeur++;
            } elseif (in_array($t, [')', ']', '}'], true)) {
                $profondeur--;
            }
            $sortie .= $t;
            if (($t === ';' && $profondeur <= 0) || $profondeur < 0) {
                break;
            }
        }

        return $sortie;
    };

    $gestes = [];
    for ($i = 0; $i < $n; $i++) {
        if (! is_array($jetons[$i])) {
            continue;
        }
        [$type, $valeur] = $jetons[$i];

        // 1. Toute méthode sync / syncWithPivotValues / detach.
        if ($type === T_STRING && in_array(strtolower($valeur), ['sync', 'syncwithpivotvalues', 'detach'], true)) {
            $avant = $significatif($i, -1);
            $apres = $significatif($i, 1);
            if ($avant >= 0 && is_array($jetons[$avant]) && in_array($jetons[$avant][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                && $apres >= 0 && $texte($apres) === '(') {
                $gestes[] = 'relation';
            }
        }

        // 2. table('company_tag'|'candidate_tag'|'tags') … ->delete( dans la même instruction.
        if ($type === T_STRING && strtolower($valeur) === 'table') {
            $parenthese = $significatif($i, 1);
            $nom = $parenthese >= 0 ? $significatif($parenthese, 1) : -1;
            if ($parenthese >= 0 && $texte($parenthese) === '(' && $nom >= 0 && is_array($jetons[$nom])
                && $jetons[$nom][0] === T_CONSTANT_ENCAPSED_STRING
                && in_array(trim($jetons[$nom][1], '\'"'), SD_TABLES, true)
                && preg_match('/->\s*(delete|forceDelete|truncate)\s*\(/i', $instruction($i)) === 1) {
                $gestes[] = 'requete';
            }
        }

        // 3. SQL brut.
        if (in_array($type, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
            $trouves = preg_match_all('/delete\s+from\s+(?:public\.)?"?(?:company_tag|candidate_tag|tags)\b/i', $valeur);
            for ($k = 0; $k < (int) $trouves; $k++) {
                $gestes[] = 'sql';
            }
        }

        // 4. Le modèle Tag.
        if ($type === T_STRING && $valeur === 'Tag') {
            $suivant = $significatif($i, 1);
            if ($suivant >= 0 && is_array($jetons[$suivant]) && $jetons[$suivant][0] === T_DOUBLE_COLON
                && preg_match('/^Tag\s*::\s*(destroy\s*\(|.*->\s*(delete|forceDelete)\s*\()/is', $instruction($i)) === 1) {
                $gestes[] = 'modele';
            }
        }
        if ($type === T_VARIABLE && strtolower($valeur) === '$tag'
            && preg_match('/^\$tag\s*->\s*(delete|forceDelete)\s*\(/i', $instruction($i)) === 1) {
            $gestes[] = 'modele';
        }
    }

    return $gestes;
}

/** @return array<string, array<string, int>> chemin relatif à app/ => [geste => nombre] */
function sdInventaire(): array
{
    $racine = dirname(__DIR__, 3) . '/app';
    $trouves = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (! $f instanceof SplFileInfo || $f->getExtension() !== 'php') {
            continue;
        }
        $relatif = str_replace('\\', '/', substr($f->getPathname(), strlen($racine) + 1));
        foreach (sdGestes((string) file_get_contents($f->getPathname())) as $geste) {
            $trouves[$relatif][$geste] = ($trouves[$relatif][$geste] ?? 0) + 1;
        }
    }
    ksort($trouves);

    return $trouves;
}

test('la sonde reconnaît chaque contournement (témoins)', function (string $code, array $attendu) {
    expect(sdGestes("<?php\n" . $code))->toBe($attendu);
})->with([
    'sync direct' => ['$company->tags()->sync($ids);', ['relation']],
    'variable intermédiaire' => ['$rel = $company->tags(); $rel->sync($ids);', ['relation']],
    'syncWithPivotValues' => ['$c->tags()->syncWithPivotValues($ids, []);', ['relation']],
    'detach()' => ['$c->tags()->detach();', ['relation']],
    'detach(null)' => ['$c->tags()->detach(null);', ['relation']],
    'detach(pluck)' => ['$c->tags()->detach($c->tags->pluck("id"));', ['relation']],
    'requête avec fermeture' => ["DB::table('company_tag')->where(function (\$q) { \$q->where('a', 1); })->delete();", ['requete']],
    'requête candidate_tag' => ["DB::table('candidate_tag')->whereIn('tag_id', \$ids)->delete();", ['requete']],
    'requête tags truncate' => ["DB::table('tags')->truncate();", ['requete']],
    'SQL brut' => ["DB::statement('DELETE FROM tags WHERE id = 1');", ['sql']],
    'SQL brut public' => ['DB::delete("delete from public.company_tag where x");', ['sql']],
    'Tag:: delete' => ["Tag::where('slug', 'x')->delete();", ['modele']],
    'Tag::destroy' => ['Tag::destroy($ids);', ['modele']],
    '$tag->delete' => ['$tag->delete();', ['modele']],
    // Permis : ajouter sans retirer, lire, supprimer dans une autre table.
    'syncWithoutDetaching' => ['$c->tags()->syncWithoutDetaching($ids);', []],
    'lecture' => ["DB::table('tags')->where('slug', 'x')->first();", []],
    'autre table' => ["DB::table('scraper_runs')->whereIn('id', \$ids)->delete();", []],
]);

test('les suppressions d étiquettes et de liens de app/ sont EXACTEMENT celles de la liste permise', function () {
    $trouves = sdInventaire();
    $permis = SD_PERMIS;
    ksort($permis);

    // Témoin : la sonde a bien trouvé les suppressions connues (sinon « rien de
    // nouveau » ne prouverait rien).
    expect(array_sum(array_map('array_sum', $trouves)))->toBeGreaterThanOrEqual(5)
        ->and($trouves)->toBe($permis);
});

test('ClassifierService, qui le faisait, n existe plus', function () {
    expect(class_exists('App\\Services\\Classification\\ClassifierService'))->toBeFalse()
        ->and(is_file(dirname(__DIR__, 3) . '/app/Services/Classification/ClassifierService.php'))->toBeFalse();
});
