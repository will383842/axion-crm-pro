<?php

namespace App\Crm\Presse;

/**
 * UNE LIGNE DE `journalists` EST-ELLE UNE PERSONNE ? (lot 3, 2026-10-02)
 *
 * ── LE CONSTAT ───────────────────────────────────────────────────────────
 *
 * L'audit visuel du 2026-10-02 a trouvé, dans la liste « Journalistes », des
 * noms d'émissions et de chaînes présentés comme des personnes : « Divers
 * (feuilleton) », « France 3 », « Arte France », « Centre des monuments
 * nationaux », « Journaliste éco local », « Production externe »…
 *
 * Diagnostic des sources (production, 1 257 lignes) :
 *   - `wikidata` / rôle « producteur » : la propriété « producteur » d'une
 *     émission désigne souvent une ORGANISATION (France 3, Arte France, la
 *     RTBF, le CNC). L'import (`crm:import-media-emissions-wikidata`) en a
 *     fait un prénom + nom en coupant au dernier espace ;
 *   - `press-kit` / rôle « présentateur » : la colonne « présentateur » d'un
 *     dossier de presse est un texte libre — « Divers (feuilleton) »,
 *     « Journaliste éco local », « Prénom Nom (Ven : Autre Nom) »,
 *     ou vide (un titre d'émission, ou un nom de scène sans prénom).
 *
 * ── LA RÈGLE (rien n'est supprimé, rien n'est réécrit) ──────────────────
 *
 * Le NOM est d'abord lu COUPÉ avant le premier « ( », « + », « " » ou « « »,
 * parenthèse fermante retirée : « Nom (+ Autre Nom » → « Nom », « Nom) » →
 * « Nom », « Nom (et alternants) » → « Nom » (relecture A09 de #284 : ces
 * noms mal découpés sont de vraies personnes). « Divers (feuilleton) » donne
 * un nom VIDE, donc écarté.
 *
 * Une ligne est une PERSONNE IDENTIFIÉE si :
 *   1. prénom ET nom (coupé) sont renseignés ;
 *   2. le nom complet ne contient ni chiffre, ni parenthèse, ni `+ : " « » / & @`
 *      (« France 3 ») ;
 *   3. le premier mot du prénom n'est pas un mot de LIBELLÉ (`MOTS_LIBELLE` :
 *      divers, journaliste, production, arte, radio, le, la…) ; « France »
 *      n'est écarté que suivi d'une CHAÎNE connue (`CHAINES_FRANCE` : Inter,
 *      Bleu, Culture, Télévisions…) — « France » est aussi un prénom ;
 *   4. le nom complet ne contient aucun mot d'INSTITUTION (`MOTS_INSTITUTION` :
 *      centre, national, télévision, société, production, feuilleton…) ;
 *   5. le nom commence par une MAJUSCULE, ou par une particule (de, d', du, le,
 *      van…) : « éco local », « nationaux », « romande » sont écartés,
 *      « de Xxx », « d'Xxx » gardés.
 *
 * Mesure en production (2026-10-02) : 1 237 personnes identifiées sur 1 257,
 * 20 lignes écartées (émissions, chaînes, organisations, noms sans prénom).
 * Faux négatifs assumés : une ligne écartée reste en base,
 * consultable via le filtre « À vérifier » ; elle n'est simplement plus
 * COMPTÉE ni MONTRÉE comme journaliste par défaut.
 *
 * ⚠️ Majuscules accentuées : la base est en collation C, où `lower('É')`
 * rend `É` et `[[:upper:]]` ignore les accents. La règle 5 énumère donc la
 * plage Latin-1 `À-Ö Ø-Þ` explicitement.
 */
final class PersonneReelle
{
    /** @var list<string> */
    public const MOTS_LIBELLE = [
        'divers', 'diverses', 'journaliste', 'journalistes', 'production', 'productions', 'magazine',
        'le', 'la', 'les', 'l', 'centre', 'radio', 'télévision', 'television', 'arte',
        'équipe', 'Équipe', 'equipe', 'rédaction', 'redaction', 'service', 'collectif', 'invité',
        'invités', 'invites', 'chroniqueurs', 'plusieurs', 'présentateur', 'présentatrice',
        'animateur', 'animatrice', 'tf1', 'm6', 'bfm', 'bfmtv', 'rtl', 'europe', 'canal', 'tv',
        'info', 'direction', 'agence', 'société', 'societe', 'groupe', 'association', 'studio', 'studios',
    ];

    /** @var list<string> */
    public const MOTS_INSTITUTION = [
        'centre', 'national', 'nationale', 'nationaux', 'télévision', 'television', 'radio', 'société',
        'societe', 'communauté', 'production', 'productions', 'rédaction', 'redaction', 'journalistes',
        'feuilleton', 'émission', 'emission', 'magazine', 'chaîne', 'chaine', 'studio', 'studios',
        'films', 'groupe', 'agence', 'association', 'fondation', 'institut', 'ministère', 'université',
        'alternants',
    ];

    /** « France » + l'un de ces noms = une chaîne, pas une personne. */
    public const CHAINES_FRANCE = [
        'inter', 'bleu', 'culture', 'info', 'musique', 'ô', 'Ô', 'o', 'tv', '2', '3', '4', '5', '24',
        'télévisions', 'Télévisions', 'televisions', 'télévision', 'Télévision', 'television', 'médias', 'Médias',
    ];

    /** Particules admises en tête d'un nom en minuscule. */
    private const PARTICULES = 'de|du|des|d\'\'|d’|le|la|van|von|da|di|del|della|ben|el|al|af|zu|dos|das';

    /**
     * SQL : la ligne `$alias` de `journalists` est une personne identifiée.
     * Aucun argument n'est une donnée utilisateur.
     */
    public static function conditionSql(string $alias = 'journalists'): string
    {
        $libelles = implode(',', array_map(static fn (string $m): string => "'" . str_replace("'", "''", $m) . "'", self::MOTS_LIBELLE));
        $institutions = implode('|', self::MOTS_INSTITUTION);
        $prenom = "btrim(coalesce({$alias}.first_name, ''))";
        $chaines = implode(',', array_map(static fn (string $m): string => "'" . $m . "'", self::CHAINES_FRANCE));
        // Le nom COUPÉ avant « ( + " « », parenthèse/guillemet fermants retirés.
        $nom = "btrim(regexp_replace(regexp_replace(coalesce({$alias}.last_name, ''), '[[:space:]]*[(+\"«].*$', ''), '[)»]', '', 'g'))";

        return '(' . implode(' AND ', [
            "{$prenom} <> ''",
            "{$nom} <> ''",
            "({$prenom} || ' ' || {$nom}) !~ '[0-9()+:\"«»/&@]'",
            "lower(split_part({$prenom}, ' ', 1)) NOT IN ({$libelles})",
            "NOT (lower(split_part({$prenom}, ' ', 1)) = 'france' AND lower({$nom}) IN ({$chaines}))",
            "lower({$prenom} || ' ' || {$nom}) !~ '(^|[^[:alpha:]])({$institutions})([^[:alpha:]]|$)'",
            "{$nom} ~ '^([A-ZÀ-ÖØ-Þ]|(" . self::PARTICULES . ")([[:space:]]|[A-ZÀ-ÖØ-Þ]))'",
        ]) . ')';
    }
}
