<?php

namespace App\Crm\Identite;

/**
 * LA CLE DE RAPPROCHEMENT DES PERSONNES — une seule definition, pour les trois
 * langages qui doivent tomber d'accord.
 *
 * 🔴 A05-001 (S1), mesure du 2026-08-18 en production :
 *
 *     SELECT count(*), count(person_key), count(email) FROM contacts;
 *       1 319 567 | 0 | 410 481
 *
 * `contacts.person_key` etait la colonne sur laquelle repose TOUTE la fiche
 * 360° (`/console/personnes/$personKey`), et le hub n'affiche le lien que
 * `si contact.person_key !== null`. Zero fiche sur 1,3 M : l'ecran existait et
 * n'etait offert a personne.
 *
 * ── CE QUE LA CLE EST VRAIMENT ────────────────────────────────────────────
 *
 * Le commentaire de `ContactUpserter` disait « sha256(email normalise + sel),
 * calcule COTE SITE » et celui de `ScrapedRecordIngestService` en concluait
 * « la collecte ne peut pas le calculer ». Lu dans le depot du site
 * (`axionia/src/server/crm-sync/index.ts` l. 24 et
 * `axionia/src/lib/security/email-hash.ts` l. 81), c'est plus precis que cela :
 *
 *     person_key = hashEmailForLookup(email)
 *                = HMAC-SHA256(cle   = PII_ENCRYPTION_KEY,
 *                              texte = "submission-email-index-v1:" + email.trim().toLowerCase())
 *                  rendu en hexadecimal minuscule (64 caracteres)
 *
 * La FORMULE est publique ; seul le SECRET vit cote site. Le CRM peut donc
 * reproduire la cle au bit pres des lors qu'on lui donne le meme secret.
 * Parite verifiee le 2026-08-20 sur le vecteur ` Jean.Dupont@Acme.FR ` avec le
 * secret `SECRET-DE-TEST` — Node, PHP et PostgreSQL rendent tous les trois
 * `99cb0a72b610ede71128411d2beea0af53b799aefdf07bb65b3917f65fa72388`
 * (cf. `tests/Feature/Crm/CleDePersonneTest.php`).
 *
 * ── POURQUOI LE CRM N'INVENTE JAMAIS DE SEL ───────────────────────────────
 *
 * Une cle calculee avec un AUTRE secret serait pire que pas de cle du tout :
 *
 *   · elle rendrait la fiche 360° cliquable tout en garantissant qu'aucun
 *     rapprochement avec le site n'aboutisse jamais ;
 *   · `POST /internal/site-sync/gdpr` interroge `contacts.person_key` pour
 *     l'export art. 15 et l'effacement art. 17. Des cles incompatibles
 *     rendraient ces deux obligations legales MUETTES — le defaut exact que le
 *     site a corrige le 2026-08-12 de son cote.
 *
 * Sans secret, `pour()` rend `null` et les appelants ne posent RIEN. Le
 * remplissage du stock, lui, REFUSE bruyamment.
 *
 * ── OU SE POSE LE SECRET ──────────────────────────────────────────────────
 *
 *     CRM_PERSON_KEY_SECRET=<la MEME valeur que PII_ENCRYPTION_KEY du site>
 *
 * dans `/opt/axion-crm-pro/.env`, puis recreation des conteneurs `api`,
 * `horizon` et `scheduler` (`docker compose restart` NE RELIT PAS `env_file`).
 */
final class CleDePersonne
{
    /**
     * Separation de domaine — recopiee de `DOMAIN` dans
     * `axionia/src/lib/security/email-hash.ts`. Toute divergence, meme d'un
     * caractere, produit des cles incompatibles avec le site.
     */
    public const DOMAINE = 'submission-email-index-v1';

    /** Longueur d'un HMAC-SHA256 rendu en hexadecimal. */
    public const LONGUEUR = 64;

    /**
     * Normalisation, identique a `normalizeEmail()` du site
     * (`email.trim().toLowerCase()`).
     *
     * ⚠️ `mb_strtolower` et le `toLowerCase()` de JavaScript divergent sur
     * quelques caracteres exotiques (turc dotless i, sigma final grec). Une
     * adresse electronique valide n'en contient pas dans sa partie ASCII, et le
     * domaine est en punycode ou en ASCII : le cas ne se presente pas. On
     * l'ecrit pour que personne n'ait a le redecouvrir.
     */
    public static function normaliser(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalise = mb_strtolower(trim($email));

        return $normalise === '' ? null : $normalise;
    }

    /** Le secret partage avec le site. Chaine vide = non configure. */
    public static function secret(): string
    {
        return trim((string) config('crm.person_key.secret', ''));
    }

    public static function estConfiguree(): bool
    {
        return self::secret() !== '';
    }

    /**
     * La cle d'une adresse, ou `null` si l'adresse est vide OU si le secret
     * n'est pas configure. Jamais de valeur inventee.
     */
    public static function pour(?string $email): ?string
    {
        $normalise = self::normaliser($email);
        if ($normalise === null) {
            return null;
        }

        $secret = self::secret();
        if ($secret === '') {
            return null;
        }

        return hash_hmac('sha256', self::DOMAINE . ':' . $normalise, $secret);
    }

    /**
     * La MEME derivation, en SQL, pour le remplissage ensembliste du stock.
     *
     * Ecrire deux fois la formule est un risque assume et BORNE : le test
     * « la derivation SQL rend la MEME cle que PHP et que le site » compare les
     * deux au vecteur de reference du site. Le remplissage de 1,3 M de lignes
     * ne peut pas passer par PHP ligne a ligne, et `pgcrypto` (deja installe,
     * cf. `infra/postgres/init/01-extensions.sql`) fournit `hmac()`.
     *
     * `lower(btrim(...))` reproduit `trim().toLowerCase()`. `::text` est
     * indispensable : `contacts.email` est de type `citext`, dont la
     * comparaison est insensible a la casse mais dont la valeur STOCKEE garde
     * sa casse d'origine — hacher la valeur brute donnerait des cles differentes
     * pour `Jean@acme.fr` et `jean@acme.fr`.
     *
     * @param  string  $expressionEmail  expression SQL rendant l'adresse (une
     *                                   colonne, ou `?` pour un parametre lie).
     *                                   Le secret est le parametre lie SUIVANT.
     */
    public static function expressionSql(string $expressionEmail): string
    {
        return sprintf(
            "encode(hmac(%s || ':' || lower(btrim(%s::text)), ?, 'sha256'), 'hex')",
            // Le domaine est une constante du code, pas une donnee : on
            // l'ecrit litteralement plutot que de melanger parametres lies et
            // ordre de binding pour rien.
            "'" . self::DOMAINE . "'",
            $expressionEmail,
        );
    }
}
