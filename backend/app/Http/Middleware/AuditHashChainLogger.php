<?php

namespace App\Http\Middleware;

use App\Services\Audit\AuditHashChain;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Response;

/**
 * Post-handle middleware : journalise dans la table `audit_logs` (chaîne
 * cryptographique append-only) deux familles de requêtes — celles qui
 * MODIFIENT (POST/PUT/PATCH/DELETE) et celles qui FONT SORTIR DES PERSONNES du
 * système (les exports, qui sont des GET).
 * Le hash de chaque ligne = sha256(prev_hash || canonical_row).
 */
class AuditHashChainLogger
{
    /**
     * 🔴 LES EXPORTS SONT DES GET, ET AUCUN NE LAISSAIT DE TRACE.
     *
     * Constats B16-008 / B12-010 (S1, audit 360), mesurés le 2026-08-20 sur ce
     * dépôt. Ce middleware ne retenait que POST/PUT/PATCH/DELETE — le
     * raisonnement habituel « on journalise ce qui écrit ». Or les QUATRE
     * points de sortie de données nominatives du CRM sont tous des lectures :
     *
     *   GET /api/v1/companies/export    → 4 295 349 fiches, avec dirigeants,
     *                                      adresses et téléphones
     *   GET /api/v1/media/export        → rédactions et adresses de rédaction
     *   GET /api/v1/journalists/export  → personnes nommées, email, téléphone
     *   GET /api/v1/rgpd/export/{token} → le dossier COMPLET d'une personne
     *
     * Un export de données personnelles sans trace, c'est l'impossibilité de
     * répondre à « qui a emporté quoi, et quand » — la première question posée
     * après une fuite. C'est un problème de conformité, pas de confort.
     *
     * ── Pourquoi un motif de CHEMIN plutôt qu'une liste de quatre routes ────
     * Une liste nominative serait juste aujourd'hui et fausse au cinquième
     * export. Le défaut caractéristique de ce dépôt (patron A-011, 20+ cas) est
     * précisément le correctif posé à un endroit et jamais porté à son jumeau.
     * Un motif sur le segment `export` attrape d'office toute route future qui
     * suit la même convention, y compris celles qu'on n'a pas écrites.
     *
     * ── Pourquoi PAS tous les GET ──────────────────────────────────────────
     * Chaque écran du CRM émet plusieurs lectures par affichage. Tout
     * journaliser noierait le signal dans son propre bruit et ferait grossir
     * une table partitionnée append-only sans rien apprendre à personne. On
     * trace la SORTIE de données, pas la consultation. Gardé en négatif par
     * `tests/Feature/ExportsNominatifsTest.php`.
     */
    private const MOTIF_EXPORT = '#(^|/)export(/|$)#';

    /**
     * Noms de paramètres de route dont la VALEUR est un secret et ne doit pas
     * entrer dans la colonne `path`. Sous-chaîne, insensible à la casse — même
     * arbitrage que `CHAMPS_SENSIBLES` : masquer un paramètre anodin de trop
     * coûte moins cher que d'en laisser passer un seul.
     */
    private const MOTIF_PARAMETRE_SECRET = '#token|jeton|secret|signature#i';

    public function __construct(private readonly AuditHashChain $chain) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! self::doitJournaliser($request)) {
            return $response;
        }

        // Évite les boucles : pas d'audit sur l'audit lui-même.
        if (str_starts_with($request->path(), 'api/v1/audit-logs')) {
            return $response;
        }

        try {
            $userId = optional($request->user())->id;
            $wsId = app()->bound('workspace.id') ? app('workspace.id') : null;

            $this->chain->record([
                // audit_logs.workspace_id et user_id sont typés UUID en Postgres.
                // Si la valeur reçue n'est pas un UUID valide (legacy users en INT,
                // workspace mocké en INT, etc.), on stocke null pour ne pas planter
                // l'insert. La requête principale reste auditée par path/method/ip.
                'workspace_id' => self::asUuidOrNull($wsId),
                'user_id' => self::asUuidOrNull($userId),
                'method' => $request->method(),
                // 🔴 Le chemin, PRIVÉ DE SES SECRETS. Cf. `cheminSansSecret()` :
                // `/api/v1/rgpd/export/{token}` porte le jeton DANS l'URL.
                'path' => self::cheminSansSecret($request),
                'status' => $response->getStatusCode(),
                'ip' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                // 🔴 B16-005 (S1) — voir `empreinteDuCorps()` juste dessous.
                // Cette ligne calculait `hash('sha256', json_encode($request->all()))`
                // SANS MASQUER QUOI QUE CE SOIT. Sur `POST api/v1/auth/login`,
                // `$request->all()` vaut `{"email":"...","password":"..."}` : le
                // condense stocke etait donc un ORACLE DE MOT DE PASSE.
                'payload_hash' => self::empreinteDuCorps($request->all()),
            ]);
        } catch (\Throwable $e) {
            // Ne pas casser la requête sur erreur d'audit ; logger sentry/glitchtip.
            // report() peut lui-même lever si Sentry mal configuré : on swallow tout.
            try {
                report($e);
            } catch (\Throwable) { /* swallow */
            }
        }

        return $response;
    }

    /**
     * Cette requête doit-elle laisser une trace ?
     *
     * Deux familles, et deux seulement :
     *   1. elle MODIFIE l'état (POST/PUT/PATCH/DELETE) — le critère historique ;
     *   2. elle FAIT SORTIR des données nominatives (un export, donc un GET) —
     *      la famille entière qui manquait (B16-008 / B12-010).
     *
     * `HEAD` est délibérément exclu : il ne transporte aucun corps, donc aucune
     * donnée ne sort. L'inclure remplirait le journal des sondes de santé et
     * des pré-vols de navigateur.
     */
    private static function doitJournaliser(Request $request): bool
    {
        if (in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        return $request->isMethod('GET')
            && preg_match(self::MOTIF_EXPORT, $request->path()) === 1;
    }

    /**
     * Le chemin tel qu'il sera STOCKÉ, avec les secrets d'URL remplacés par
     * leur nom de paramètre.
     *
     * 🔴 POURQUOI CE FILTRE EXISTE. Journaliser `GET /api/v1/rgpd/export/{token}`
     * était nécessaire (c'est le quatrième export de données nominatives), mais
     * le chemin de cette route CONTIENT le secret : 48 caractères aléatoires,
     * valables 7 jours, qui suffisent À EUX SEULS à télécharger le dossier
     * complet d'une personne — la route est volontairement non authentifiée,
     * son modèle de sécurité EST la possession du jeton.
     *
     * Écrire ce jeton en clair dans `audit_logs` l'aurait recopié dans une table
     * que toute la chaîne d'exploitation peut lire : `GET /api/v1/audit-logs`
     * pour qui porte `audit.view`, mais aussi les sauvegardes, les répliques de
     * lecture et les exports de partition. La trace serait devenue la fuite —
     * exactement le contraire de ce qu'on ajoute ici.
     *
     * On remplace donc la VALEUR par `{token}`, ce qui conserve tout ce qui
     * sert : la route empruntée, l'auteur, le résultat, l'horodatage.
     */
    private static function cheminSansSecret(Request $request): string
    {
        $chemin = $request->path();
        $route = $request->route();

        if (! $route instanceof Route) {
            return $chemin;
        }

        foreach ($route->parameters() as $nom => $valeur) {
            if (! is_string($valeur) || $valeur === '') {
                continue;
            }

            if (preg_match(self::MOTIF_PARAMETRE_SECRET, (string) $nom) !== 1) {
                continue;
            }

            $chemin = str_replace($valeur, '{' . $nom . '}', $chemin);
        }

        return $chemin;
    }

    /**
     * Champs dont la VALEUR ne doit jamais entrer dans l'empreinte du corps.
     *
     * La comparaison se fait en minuscules ET en sous-chaine : `password`
     * attrape `password_confirmation`, `current_password`, `new_password` ;
     * `token` attrape `_token`, `reset_token`, `refresh_token`. Mieux vaut
     * masquer un champ anodin de trop que d'en laisser passer un seul.
     *
     * @var list<string>
     */
    public const CHAMPS_SENSIBLES = [
        'password', 'passwd', 'mot_de_passe', 'motdepasse',
        'token', 'secret', 'api_key', 'apikey', 'authorization',
        'otp', 'code_2fa', 'two_factor_code', 'recovery_code',
        'private_key', 'credential', 'session',
    ];

    /**
     * Ce qui remplace la valeur masquee. Une CONSTANTE, identique pour tous :
     * c'est precisement parce qu'elle ne varie pas que l'empreinte cesse de
     * distinguer deux mots de passe.
     */
    public const VALEUR_MASQUEE = '[masque]';

    /**
     * Empreinte du corps de requete, valeurs sensibles masquees.
     *
     * 🔴 CONSTAT B16-005 (S1), mesure le 2026-08-20 sur ce depot.
     *
     * Ce n'est PAS un mot de passe en clair qui etait stocke — c'est pire que
     * ce que le mot « empreinte » laisse croire, et moins que ce que « fuite de
     * mot de passe » laisse croire. Il faut le dire exactement :
     *
     *   `payload_hash` valait `sha256(json_encode($request->all()))`. Sur la
     *   route de connexion, ce corps est `{"email":"x@y.z","password":"P"}`.
     *   Le condense est donc une fonction DETERMINISTE et SANS SEL du mot de
     *   passe, dont l'autre entree (l'email) est ecrite EN CLAIR dans la meme
     *   ligne du journal (`path`, et l'email lui-meme via l'espace/user_id).
     *
     *   Quiconque lit cette ligne peut donc VERIFIER une hypothese de mot de
     *   passe hors ligne : il forme `{"email":"x@y.z","password":"hypothese"}`,
     *   hache, compare. Un SHA-256 nu se calcule par milliards par seconde sur
     *   une carte graphique, la ou un `password_hash()` bcrypt cout 4..12 en
     *   compte quelques milliers. Le journal d'audit — la piece meme qui est
     *   censee prouver ce qui s'est passe — devenait un banc d'essai de mots
     *   de passe, servi par une route HTTP a tout compte portant `audit.view`.
     *
     * Le masquage se fait A LA SOURCE, avant le hachage, et non a l'affichage :
     *   - une sauvegarde, une replique de lecture, un export de partition ou un
     *     `psql` suffisent a lire la colonne ; cacher le champ dans la reponse
     *     JSON n'aurait deplace le probleme que d'un metre ;
     *   - `payload_hash` reste servi TEL QUEL, volontairement : un auditeur
     *     externe doit pouvoir recalculer la chaine, ce qui exige toutes les
     *     colonnes hachees. On ne peut donc pas compter sur le fait de le cacher.
     *
     * Ce que l'empreinte perd : plus rien ne distingue deux tentatives de
     * connexion sur le meme compte avec deux mots de passe differents. C'est
     * exactement la propriete recherchee — et c'est ce que verifie la garde
     * `tests/Feature/Rgpd/EmpreinteCorpsConnexionTest.php`. Ce que l'empreinte
     * garde : tout le reste du corps, donc la detection de rejeu/alteration sur
     * les requetes metier, qui est l'usage reel de cette colonne.
     *
     * @param  array<array-key,mixed>  $corps
     */
    public static function empreinteDuCorps(array $corps): string
    {
        return hash('sha256', json_encode(self::masquer($corps), JSON_THROW_ON_ERROR));
    }

    /**
     * Masque recursivement les valeurs des champs sensibles.
     *
     * Recursif parce qu'un corps imbrique (`{"user":{"password":"..."}}`) est
     * courant des qu'un formulaire est structure : un masquage a plat aurait
     * laisse passer le cas, et une garde ecrite a plat ne l'aurait pas vu.
     *
     * @param  array<array-key,mixed>  $corps
     * @return array<array-key,mixed>
     */
    private static function masquer(array $corps): array
    {
        $sortie = [];

        foreach ($corps as $cle => $valeur) {
            if (self::cleEstSensible((string) $cle)) {
                // On garde la CLE (savoir qu'un mot de passe a ete soumis est
                // une information d'audit legitime) et on jette la VALEUR.
                $sortie[$cle] = self::VALEUR_MASQUEE;

                continue;
            }

            $sortie[$cle] = is_array($valeur) ? self::masquer($valeur) : $valeur;
        }

        return $sortie;
    }

    private static function cleEstSensible(string $cle): bool
    {
        $cle = strtolower($cle);

        foreach (self::CHAMPS_SENSIBLES as $motif) {
            if (str_contains($cle, $motif)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Retourne la valeur si elle ressemble à un UUID (v1-v8) ou un ULID (26 chars Crockford base32).
     * Sinon null — utile quand l'ID user/workspace est legacy INT en attente de migration.
     */
    private static function asUuidOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        // UUID classique : 8-4-4-4-12 hex
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s)) {
            return $s;
        }
        // ULID Crockford base32 : 26 caractères [0-9A-HJKMNP-TV-Z]
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $s)) {
            return $s;
        }

        return null;
    }
}
