<?php

namespace App\Support;

/**
 * Masquage des coordonnées pour les comptes en LECTURE SEULE (plan §2.10).
 *
 * Un `viewer` doit pouvoir consulter et se repérer, pas repartir avec 665 771
 * adresses lisibles à l'écran. L'export lui est déjà fermé (`data.export`) ;
 * sans masquage, il lui suffirait de faire défiler et de recopier.
 *
 * ── Ce que le masquage N'EST PAS ─────────────────────────────────────────
 * Ce n'est pas une mesure de sécurité au sens fort : la donnée transite
 * toujours par le serveur, et qui a le droit de la lire l'a vraiment. C'est
 * une réduction d'exposition — le principe de minimisation du RGPD appliqué à
 * l'affichage. On le dit ici pour que personne ne s'en croie protégé contre un
 * accès direct à la base.
 *
 * ── Pourquoi laisser le domaine visible ──────────────────────────────────
 *
 * `p***@axion-ia.com` reste utile : un opérateur reconnaît l'entreprise et
 * peut travailler. Masquer le domaine aussi rendrait la liste illisible sans
 * rien protéger de plus — le domaine se déduit de la fiche entreprise.
 */
final class MasquageCoordonnees
{
    public const PERMISSION = 'contacts.view_pii';

    /**
     * Colonnes qui portent une adresse e-mail sur les fiches rendues par l'API.
     *
     * `email` : table `contacts`. `email_generic` : table `companies`. Relevé
     * colonne par colonne le 2026-08-20 dans `information_schema.columns` de
     * `axion_crm_test_lot6` — ce sont les DEUX seules colonnes e-mail des deux
     * tables. On ne devine pas un nom de colonne, on le lit.
     */
    private const CHAMPS_EMAIL = ['email', 'email_generic'];

    /** Téléphone : la colonne `phone` existe sur `companies` ET sur `contacts`. */
    private const CHAMPS_TELEPHONE = ['phone'];

    /**
     * L'utilisateur courant doit-il voir des coordonnées masquées ?
     *
     * Ferme par DÉFAUT : sans utilisateur ou sans droit, on masque. Une garde
     * dont l'état inconnu vaut « montre tout » n'est pas une garde.
     */
    public static function requis(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return true;
        }

        return ! $user->can(self::PERMISSION);
    }

    /**
     * `pierre.durand@acme.fr` → `p***@acme.fr`
     *
     * On garde la première lettre : elle suffit à reconnaître un interlocuteur
     * déjà connu sans livrer l'adresse à qui ne la connaît pas.
     */
    public static function email(?string $email): ?string
    {
        if ($email === null || trim($email) === '') {
            return $email;
        }

        $position = mb_strrpos($email, '@');

        // Pas d'arobase : la valeur n'est pas une adresse (donnée abîmée). On
        // masque TOUT plutôt que de la laisser passer par défaut.
        if ($position === false || $position === 0) {
            return '***';
        }

        $locale = mb_substr($email, 0, $position);
        $domaine = mb_substr($email, $position);

        return mb_substr($locale, 0, 1) . '***' . $domaine;
    }

    /**
     * `+33612345678` → `+336******78`
     *
     * Les deux derniers chiffres suffisent à confirmer un numéro qu'on a déjà
     * sous les yeux — c'est la vérification qu'un lecteur seul peut avoir à
     * faire — sans permettre de le reconstituer.
     */
    public static function telephone(?string $telephone): ?string
    {
        if ($telephone === null || trim($telephone) === '') {
            return $telephone;
        }

        $valeur = trim($telephone);
        $longueur = mb_strlen($valeur);

        // Trop court pour qu'un masque partiel garde quoi que ce soit.
        if ($longueur <= 6) {
            return '***';
        }

        return mb_substr($valeur, 0, 4) . str_repeat('*', max(1, $longueur - 6)) . mb_substr($valeur, -2);
    }

    /**
     * Masque, SI le compte courant l'exige, une fiche déjà chargée — et TOUT ce
     * qui pend dessous.
     *
     * ── Pourquoi cette méthode existe (constat B12-002 / F36-006, S1) ────────
     *
     * Le masquage couvrait TROIS listes (`companies.index`, `contacts.index`,
     * `crm/contacts-hub`) et zéro fiche détaillée. Or les identifiants de
     * `companies` sont des entiers consécutifs et `GET /companies/{id}` ne
     * porte aucune permission supplémentaire (routes/api.php:138) : un `viewer`
     * lisait la liste masquée, relevait les identifiants, puis rappelait chaque
     * fiche EN CLAIR. Le masquage des listes ne coûtait donc rien.
     *
     * La leçon du constat n'est pas « il manquait un quatrième appel » mais
     * « la règle vivait dans les appelants ». Elle vit désormais ICI : un
     * appelant écrit une ligne, et n'a plus à savoir quelles colonnes portent
     * une coordonnée ni quelles relations sont chargées.
     *
     * ── Pourquoi c'est RÉCURSIF ──────────────────────────────────────────────
     *
     * `GET /companies/{id}` fait `->load(['contacts', 'tags'])`. Masquer la
     * société sans masquer `contacts` laisserait passer les coordonnées
     * NOMINATIVES — les plus sensibles des deux. On descend donc dans les
     * relations DÉJÀ chargées (jamais on n'en charge : ce serait transformer un
     * masquage en requêtes N+1).
     *
     * `$vus` coupe les cycles : `contact → company → contacts` boucle sinon
     * indéfiniment dès qu'une relation inverse est chargée.
     *
     * ⚠️ On pose l'attribut sur le modèle en mémoire : il devient « sale ». Rien
     * ne doit le SAUVEGARDER ensuite, sinon le masque remplacerait la donnée.
     * Une garde du dépôt relit la base après la requête pour l'interdire
     * (`MasquageFicheDetailleeTest`, « ne PERSISTE pas »).
     *
     * @template T
     *
     * @param  T  $fiche  modèle Eloquent, collection, tableau — ou null
     * @return T la même instance, mutée sur place
     */
    public static function masquerSiRequis(mixed $fiche): mixed
    {
        if ($fiche === null || ! self::requis()) {
            return $fiche;
        }

        $vus = [];
        self::masquer($fiche, $vus);

        return $fiche;
    }

    /**
     * @param  array<int, true>  $vus  identités d'objets déjà traitées
     */
    private static function masquer(mixed $noeud, array &$vus): void
    {
        // Collection Eloquent, tableau de modèles, paginateur déjà déballé…
        if (is_iterable($noeud)) {
            foreach ($noeud as $element) {
                self::masquer($element, $vus);
            }

            return;
        }

        if (! $noeud instanceof \Illuminate\Database\Eloquent\Model) {
            return;
        }

        $identite = spl_object_id($noeud);
        if (isset($vus[$identite])) {
            return; // déjà masqué : relation inverse, on ne boucle pas
        }
        $vus[$identite] = true;

        // `getAttributes()` et non `getAttribute()` : on ne veut toucher que
        // les colonnes RÉELLEMENT présentes. Poser `email` sur une société
        // ajouterait une clé inventée au JSON de la réponse.
        $attributs = $noeud->getAttributes();

        foreach (self::CHAMPS_EMAIL as $champ) {
            if (array_key_exists($champ, $attributs)) {
                $noeud->setAttribute($champ, self::email(self::enTexte($noeud->getAttribute($champ))));
            }
        }

        foreach (self::CHAMPS_TELEPHONE as $champ) {
            if (array_key_exists($champ, $attributs)) {
                $noeud->setAttribute($champ, self::telephone(self::enTexte($noeud->getAttribute($champ))));
            }
        }

        // Relations DÉJÀ chargées uniquement — `getRelations()` ne déclenche
        // aucune requête. `tags` n'a aucune colonne de coordonnée : la descente
        // y est un passage à vide, pas une erreur.
        foreach ($noeud->getRelations() as $relation) {
            self::masquer($relation, $vus);
        }
    }

    /**
     * Une colonne de coordonnée peut remonter autre chose qu'une chaîne (cast,
     * donnée abîmée). `email()` et `telephone()` sont typés `?string` : sans ce
     * passage, un entier lèverait un TypeError EN PLEINE RÉPONSE et le 500
     * masquerait… le masquage.
     */
    private static function enTexte(mixed $valeur): ?string
    {
        if ($valeur === null) {
            return null;
        }

        return is_scalar($valeur) ? (string) $valeur : '***';
    }
}
