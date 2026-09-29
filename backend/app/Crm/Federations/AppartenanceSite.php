<?php

namespace App\Crm\Federations;

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Support\Str;
use stdClass;

/**
 * CE SITE EST-IL CELUI DE CET ORGANISME ? — la règle de Will (29/09).
 *
 * Un moteur de recherche rend, pour « FFB Rhône », le site national de la
 * FFB bien avant celui de la section. Poser le site national sur la fiche
 * d'une section départementale, c'est écrire une coordonnée FAUSSE sur une
 * fiche protégée. D'où la règle, appliquée à la page d'accueil du site :
 *
 *  1. la vérification commune d'abord (`DomainFinderService::verifyBody()`,
 *     sur la page translittérée) : page non vide, et SIREN, ou deux mots du
 *     nom, ou un mot et la ville ;
 *  2. puis l'IDENTITÉ : le SIREN de l'organisme, OU son nom (ses mots
 *     DISTINCTIFS — « fédération », « nationale », « syndicat »… ne
 *     distinguent personne) ET son sigle. Sans sigle connu, TOUS les mots
 *     distinctifs, et au moins deux ;
 *  3. pour une section DÉPARTEMENTALE, la page cite AUSSI son département :
 *     son nom (cinq lettres au moins — « Ain », « Var », « Nord » se lisent
 *     partout) ou un code postal du département. Sinon, c'est le site
 *     national, et on n'en veut pas.
 */
class AppartenanceSite
{
    /**
     * Mots qui ne distinguent AUCUN organisme professionnel : les retenir
     * ferait passer le site de n'importe quelle fédération pour celui d'une
     * autre.
     *
     * @var list<string>
     */
    public const MOTS_GENERIQUES = [
        'federation', 'federations', 'federal', 'federale', 'confederation', 'confederale',
        'syndicat', 'syndicats', 'syndical', 'syndicale', 'syndicales', 'intersyndicale',
        'national', 'nationale', 'nationaux', 'nationales', 'regional', 'regionale', 'regionaux', 'regionales',
        'departemental', 'departementale', 'departementaux', 'departementales', 'interdepartemental', 'interdepartementale',
        'union', 'unions', 'chambre', 'chambres', 'association', 'associations', 'ordre', 'conseil',
        'comite', 'groupement', 'collectif', 'reseau', 'maison', 'office', 'institut', 'centre',
        'france', 'francais', 'francaise', 'francaises', 'french',
        'professionnel', 'professionnelle', 'professionnels', 'professionnelles', 'profession', 'professions',
        'interprofessionnel', 'interprofessionnelle', 'interprofession', 'metier', 'metiers', 'branche',
        'entreprise', 'entreprises', 'employeur', 'employeurs', 'patronat', 'patronale', 'salaries',
        'section', 'antenne', 'delegation', 'territoriale', 'territorial', 'locale', 'local',
        'des', 'de', 'du', 'la', 'le', 'les', 'et', 'en', 'pour', 'sur', 'aux', 'au', 'par', 'dans', 'avec',
    ];

    /** Longueur minimale d'un nom de département reconnu seul (« Ain », « Var »… se lisent partout). */
    private const NOM_DEPARTEMENT_MIN = 5;

    public function __construct(private readonly DomainFinderService $finder) {}

    /**
     * Les mots distinctifs d'un nom (sans accents, en minuscules, 3 lettres au
     * moins, hors mots génériques), six au plus.
     *
     * @return list<string>
     */
    public function motsDistinctifs(string $nom): array
    {
        $mots = [];
        foreach (explode(' ', self::normaliser($nom)) as $mot) {
            if (mb_strlen($mot) >= 3 && ! ctype_digit($mot) && ! in_array($mot, self::MOTS_GENERIQUES, true)) {
                $mots[$mot] = true;
            }
        }

        return array_slice(array_keys($mots), 0, 6);
    }

    /**
     * `$fiche` : une ligne de la sélection de `crm:federations:trouver-sites`
     * (siren, denomination, nom_developpe, sigle, niveau, department_code,
     * departement_nom, city, city_name).
     */
    public function verifier(string $corps, stdClass $fiche): bool
    {
        $nom = self::nom($fiche);
        $entreprise = (new Company)->forceFill([
            'siren' => $fiche->siren,
            'city' => $fiche->city,
            'city_name' => $fiche->city_name,
        ]);

        // `verifyBody()` compare des mots SANS accents (`nameTokens()`) à une
        // page lue telle quelle : « fédération » n'y rencontre jamais
        // « federation ». La page lui est donc passée translittérée.
        $translitteree = Str::ascii(html_entity_decode($corps, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (! $this->finder->verifyBody($translitteree, $entreprise, $this->finder->nameTokens($nom))) {
            return false;
        }

        $texte = ' ' . self::normaliser(self::texteDeLaPage($corps)) . ' ';
        if (! $this->identite($texte, $fiche, $nom)) {
            return false;
        }

        return $fiche->niveau !== 'departemental' || $this->citeLeDepartement($texte, $fiche);
    }

    /**
     * Le nom qui décrit l'organisme : le nom développé s'il est connu, sinon
     * la dénomination.
     */
    public static function nom(stdClass $fiche): string
    {
        $developpe = trim((string) ($fiche->nom_developpe ?? ''));

        return $developpe !== '' ? $developpe : trim((string) ($fiche->denomination ?? ''));
    }

    /**
     * Le texte VISIBLE : sans scripts ni styles (un sigle cité dans du
     * JavaScript ne dit rien de la page), et chaque balise remplacée par une
     * espace — `strip_tags()` seul colle « agricoles</h1><p>Actualités » en
     * « agricolesActualités », et le mot n'est plus trouvé.
     */
    public static function texteDeLaPage(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', $html);

        return strip_tags(str_replace('<', ' <', $html));
    }

    /** Minuscules, sans accents, tout ce qui n'est ni lettre ni chiffre devient une espace. */
    public static function normaliser(string $texte): string
    {
        $texte = mb_strtolower(Str::ascii(html_entity_decode($texte, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $texte));
    }

    private function identite(string $texte, stdClass $fiche, string $nom): bool
    {
        $siren = preg_replace('/\D/', '', (string) ($fiche->siren ?? ''));
        if (is_string($siren) && strlen($siren) === 9 && str_contains(str_replace(' ', '', $texte), $siren)) {
            return true;
        }

        $mots = $this->motsDistinctifs($nom);
        if ($mots === []) {
            return false;
        }
        $trouves = count(array_filter($mots, static fn (string $m): bool => str_contains($texte, ' ' . $m . ' ')));

        $sigle = self::normaliser((string) ($fiche->sigle ?? ''));
        if ($sigle === '') {
            return count($mots) >= 2 && $trouves === count($mots);
        }

        $compact = str_replace(' ', '', $sigle);
        // « F.F.B. » normalisé devient « f f b » : les deux graphies comptent.
        $sigleCite = mb_strlen($compact) >= 2 && (
            str_contains($texte, ' ' . $compact . ' ')
            || str_contains($texte, ' ' . implode(' ', mb_str_split($compact)) . ' ')
        );

        return $sigleCite && $trouves >= min(2, count($mots));
    }

    private function citeLeDepartement(string $texte, stdClass $fiche): bool
    {
        $nom = self::normaliser((string) ($fiche->departement_nom ?? ''));
        if (mb_strlen(str_replace(' ', '', $nom)) >= self::NOM_DEPARTEMENT_MIN && str_contains($texte, ' ' . $nom . ' ')) {
            return true;
        }

        $code = strtoupper(trim((string) ($fiche->department_code ?? '')));
        $motif = match (true) {
            in_array($code, ['2A', '2B'], true) => '20\d{3}',
            preg_match('/^\d{3}$/', $code) === 1 => $code . '\d{2}',
            preg_match('/^\d{2}$/', $code) === 1 => $code . '\d{3}',
            default => null,
        };

        return $motif !== null && preg_match('/ ' . $motif . ' /', $texte) === 1;
    }
}
