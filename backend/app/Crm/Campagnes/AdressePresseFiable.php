<?php

namespace App\Crm\Campagnes;

use App\Crm\Emails\QualificationEmail;
use Illuminate\Support\Facades\DB;

/**
 * DANS LE SEGMENT PRESSE, UNE ADRESSE NE PART QUE SI SA PROVENANCE EST
 * FIABLE — une seule définition (ouverture de la presse, 01/10/2026).
 *
 * Constat en production : beaucoup de fiches portent un site DEVINÉ
 * (`companies.website_method` `guess` / `guess2` : un nom de domaine essayé
 * à partir de la dénomination), souvent FAUX (« PARIS LIVE » → paris.fr), et
 * leur `email_generic` en a été extrait (l'adresse d'une bijouterie sur la
 * fiche d'un média). Environ 1 400 fiches de presse ont une adresse tirée
 * d'un site deviné. Ces adresses ne partent JAMAIS.
 *
 * ── La règle, par OCCURRENCE (une adresse vue sur une fiche) ────────────
 * Une occurrence est FIABLE si l'une de ces provenances est établie :
 *
 *  1. `journaliste` — une personne de la presse (`GardePresse::
 *     estContactPresseSql`) dont la porte d'accès vaut `email_redaction`
 *     (`contacts.metadata.acces`). Sans cette porte : jamais
 *     (`journaliste_sans_acces`), même si une adresse est présente ;
 *  2. `liste_presse` — l'adresse de rédaction importée d'une liste presse
 *     (`crm:presse:importer`, colonne `email_redaction`), gardée dans
 *     `companies.metadata.emails_liste_presse` ;
 *  3. `source_presse` — l'adresse d'une ligne `media` vivante de la fiche
 *     venue d'une SOURCE PRESSE (`SOURCES_PRESSE` : CPPAP, SPEL, kit presse,
 *     ARCOM, agences, Wikidata, liste presse) dont le site n'a PAS été
 *     deviné lui-même (`media:generate-redaction-emails` fabrique
 *     `redaction@<domaine du site>` : sur un site deviné, c'est la même
 *     erreur) ;
 *  4. `site_verifie` — la fiche porte le marqueur de vérification
 *     `companies.metadata.site_verifie = true` (posé par la lecture du site,
 *     autre chantier) ;
 *  5. `site_fiable` — aucun site deviné sur la fiche : ni
 *     `companies.website_method` `guess%`, ni une ligne `media` vivante de la
 *     fiche au site deviné (`media.website_method` `guess%` — ses adresses
 *     ont pu être recopiées sur la fiche par l'harmonisation).
 *
 * Sinon : `site_devine` — l'adresse vient (ou peut venir) d'un site deviné non
 * vérifié. Une adresse est DESTINATAIRE si au moins une de ses occurrences est
 * fiable ; les autres règles (`EligibiliteAdresse` : invalide, non vérifiée,
 * personnelle, opposition ; adresses partagées) s'appliquent ensuite à TOUTES
 * ses occurrences, comme partout.
 *
 * Aucune ligne ici n'écrit ni n'envoie quoi que ce soit, sauf
 * `retenirEmailListe`, appelée par l'import d'une liste presse.
 */
final class AdressePresseFiable
{
    /** Le marqueur de vérification du site, dans `companies.metadata` (booléen `true`). */
    public const MARQUEUR_SITE_VERIFIE = 'site_verifie';

    /** Les adresses de rédaction importées d'une liste presse, dans `companies.metadata`. */
    public const CLE_EMAILS_LISTE = 'emails_liste_presse';

    /** Préfixe des méthodes de découverte de site par DEVINETTE (`guess`, `guess2`). */
    public const PREFIXE_SITE_DEVINE = 'guess';

    /** Les sources de `media.source` qui sont de vraies sources presse (jamais `naf-extract`). */
    public const SOURCES_PRESSE = ['cppap', 'spel', 'press-kit', 'arcom', 'agence', 'wikidata', 'liste-presse'];

    /** La porte d'accès qui seule rend l'adresse d'un journaliste diffusable. */
    public const ACCES_DIFFUSABLE = 'email_redaction';

    // Provenances fiables.
    public const JOURNALISTE = 'journaliste';

    public const LISTE_PRESSE = 'liste_presse';

    public const SOURCE_PRESSE = 'source_presse';

    public const SITE_VERIFIE = 'site_verifie';

    public const SITE_FIABLE = 'site_fiable';

    // Motifs de refus.
    public const SITE_DEVINE = 'site_devine';

    public const JOURNALISTE_SANS_ACCES = 'journaliste_sans_acces';

    /**
     * La provenance d'une occurrence, ou son motif de refus.
     *
     * `presse` : personne de la presse ; `acces` : sa porte d'accès ;
     * `emails_surs` : adresse normalisée => provenance (`emailsSurs`).
     *
     * @param  array{presse: bool, acces: ?string, site_verifie: bool, site_devine: bool, emails_surs: array<string, string>}  $o
     * @return array{0: bool, 1: string} [fiable, provenance ou motif]
     */
    public static function juger(string $email, array $o): array
    {
        if ($o['presse']) {
            return $o['acces'] === self::ACCES_DIFFUSABLE ? [true, self::JOURNALISTE] : [false, self::JOURNALISTE_SANS_ACCES];
        }
        $cle = QualificationEmail::normaliser($email);
        if (isset($o['emails_surs'][$cle])) {
            return [true, $o['emails_surs'][$cle]];
        }
        if ($o['site_verifie']) {
            return [true, self::SITE_VERIFIE];
        }

        return $o['site_devine'] ? [false, self::SITE_DEVINE] : [true, self::SITE_FIABLE];
    }

    /**
     * SQL : la fiche `$aliasFiche` (identifiant `$colonneId`) porte un site
     * DEVINÉ — le sien, ou celui d'une de ses lignes `media` vivantes. Aucun
     * argument n'est une donnée utilisateur. Alias internes `apf_m` réservés.
     */
    public static function siteDevineSql(string $colonneId = 'companies.id', string $aliasFiche = 'companies'): string
    {
        $motif = self::PREFIXE_SITE_DEVINE . '%';

        return "(COALESCE({$aliasFiche}.website_method, '') LIKE '{$motif}'"
            . " OR EXISTS (SELECT 1 FROM media apf_m WHERE apf_m.company_id = {$colonneId} AND apf_m.deleted_at IS NULL"
            . " AND COALESCE(apf_m.website_method, '') LIKE '{$motif}'))";
    }

    /** SQL : la fiche porte le marqueur « site vérifié ». */
    public static function siteVerifieSql(string $aliasFiche = 'companies'): string
    {
        return "(COALESCE({$aliasFiche}.metadata->'" . self::MARQUEUR_SITE_VERIFIE . "', 'false'::jsonb) = 'true'::jsonb)";
    }

    /**
     * Les adresses SÛRES d'une fiche, par leur provenance : importées d'une
     * liste presse, ou portées par une ligne `media` d'une source presse au
     * site non deviné.
     *
     * @return array<string, string> adresse normalisée => provenance
     */
    public static function emailsSurs(int $companyId): array
    {
        $surs = [];
        $motif = self::PREFIXE_SITE_DEVINE . '%';
        $medias = DB::table('media')
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->whereIn('source', self::SOURCES_PRESSE)
            ->whereRaw("COALESCE(website_method, '') NOT LIKE ?", [$motif])
            ->pluck('email');
        foreach ($medias as $e) {
            $cle = QualificationEmail::normaliser((string) $e);
            if ($cle !== '') {
                $surs[$cle] = self::SOURCE_PRESSE;
            }
        }

        $meta = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')->value('metadata');
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;
        $liste = is_array($meta) && is_array($meta[self::CLE_EMAILS_LISTE] ?? null) ? $meta[self::CLE_EMAILS_LISTE] : [];
        foreach ($liste as $e) {
            $cle = is_string($e) ? QualificationEmail::normaliser($e) : '';
            if ($cle !== '') {
                $surs[$cle] = self::LISTE_PRESSE;
            }
        }

        return $surs;
    }

    /**
     * Garde la trace d'une adresse de rédaction importée d'une liste presse
     * (`companies.metadata.emails_liste_presse`, sans doublon). Additif : rien
     * n'est retiré.
     */
    public static function retenirEmailListe(int $companyId, string $email): void
    {
        $cle = QualificationEmail::normaliser($email);
        if ($cle === '') {
            return;
        }
        $champ = self::CLE_EMAILS_LISTE;
        DB::update(
            "UPDATE companies SET metadata = jsonb_set(COALESCE(metadata, '{}'::jsonb), '{{$champ}}',"
            . " COALESCE(metadata->'{$champ}', '[]'::jsonb) || to_jsonb(?::text))"
            . " WHERE id = ? AND NOT (COALESCE(metadata->'{$champ}', '[]'::jsonb) @> to_jsonb(ARRAY[?::text]))",
            [$cle, $companyId, $cle],
        );
    }
}
