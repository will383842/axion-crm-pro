<?php

namespace App\Crm\Doublons;

/**
 * DOUBLONS — le vocabulaire et les règles PURES du rapprochement (chantier 5,
 * 2026-09-30). Aucune requête ici : les commandes et le service de fusion
 * lisent la base, cette classe décide.
 *
 * ── Ce qui fait une paire ───────────────────────────────────────────────────
 *
 * Deux fiches au même SIREN sont une seule entité juridique ; deux fiches à
 * SIREN DIFFÉRENTS sont deux entités, quels que soient leur nom, leur code
 * postal ou leur site : elles ne forment JAMAIS une paire (`pairePossible`).
 * Une adresse e-mail partagée ne fait jamais une paire non plus : c'est très
 * souvent celle d'un expert-comptable, d'une domiciliation ou d'un siège qui
 * sert plusieurs entreprises différentes (ordre de Will, 29/09).
 *
 * ── Ce qui autorise la fusion AUTOMATIQUE ───────────────────────────────────
 *
 * Seulement une preuve certaine (`MOTIFS_CERTAINS`) :
 *  - même SIREN, ou même (pays, identifiant) — impossibles aujourd'hui (clés
 *    uniques de la base), gardés pour le jour où une clé tomberait ;
 *  - une fiche SANS SIREN créée par une source de collecte (jamais l'INSEE) et
 *    une fiche INSEE, au même nom normalisé EXACT, au même code postal ET au
 *    même site (`nom_cp_site`).
 * Tout le reste va dans la file « Doublons à vérifier ».
 */
final class Rapprochement
{
    public const MEME_SIREN = 'meme_siren';

    public const MEME_IDENTIFIANT = 'meme_identifiant';

    public const NOM_CP_SITE = 'nom_cp_site';

    public const NOM_SITE = 'nom_site';

    public const NOM_CP = 'nom_cp';

    public const SANS_SIREN_NOM = 'sans_siren_nom';

    /**
     * Motif => [score, libellé]. Le score va dans `duplicate_flags.similarity`
     * (NUMERIC(4,3)) : il ordonne la file, il ne décide rien.
     *
     * @var array<string, array{0: float, 1: string}>
     */
    public const MOTIFS = [
        self::MEME_SIREN => [1.0, 'Même SIREN'],
        self::MEME_IDENTIFIANT => [1.0, 'Même identifiant (pays et identifiant de source)'],
        self::NOM_CP_SITE => [0.99, 'Même nom, même code postal et même site (fiche sans SIREN et fiche INSEE)'],
        self::NOM_SITE => [0.9, 'Même nom et même site, codes postaux différents (fiche sans SIREN et fiche avec SIREN)'],
        self::NOM_CP => [0.85, 'Même nom et même code postal, sites différents ou absents (fiche sans SIREN et fiche avec SIREN)'],
        self::SANS_SIREN_NOM => [0.8, 'Deux fiches sans SIREN au même nom, même code postal ou même site'],
    ];

    /** Les seuls motifs qui peuvent autoriser une fusion sans relecture. */
    public const MOTIFS_CERTAINS = [self::MEME_SIREN, self::MEME_IDENTIFIANT, self::NOM_CP_SITE];

    // ── Adresses partagées ──────────────────────────────────────────────────

    public const CABINET_COMPTABLE = 'cabinet_comptable';

    public const DOMICILIATION = 'domiciliation';

    public const GROUPE = 'groupe';

    public const INCONNUE = 'inconnue';

    /** @var array<string, string> nature => libellé */
    public const NATURES_ADRESSE = [
        self::CABINET_COMPTABLE => 'Cabinet comptable',
        self::DOMICILIATION => 'Domiciliation',
        self::GROUPE => 'Groupe ou siège',
        self::INCONNUE => 'Inconnue',
    ];

    /** NAF rév. 2 (sans point) : activités comptables. */
    public const NAF_COMPTABLE = '6920Z';

    /** NAF rév. 2 (sans point) : services administratifs combinés de bureau — la domiciliation. */
    public const NAF_DOMICILIATION = '8211Z';

    /**
     * Hébergeurs et plateformes : deux fiches dont le « site » est une page
     * de ces domaines n'ont PAS le même site (deux pages Facebook de deux
     * clubs différents partagent `facebook.com`). Ni fusion automatique, ni
     * motif « même site » sur ces domaines.
     *
     * @var list<string>
     */
    public const DOMAINES_PLATEFORMES = [
        'facebook.com', 'fb.com', 'instagram.com', 'linkedin.com', 'twitter.com', 'x.com', 'youtube.com',
        'tiktok.com', 'linktr.ee', 'sites.google.com', 'google.com', 'business.site', 'goo.gl',
        'pagesjaunes.fr', 'societe.com', 'helloasso.com', 'wordpress.com', 'blogspot.com', 'over-blog.com',
        'wixsite.com', 'jimdo.com', 'e-monsite.com', 'free.fr', 'orange.fr', 'wanadoo.fr',
    ];

    /**
     * Messageries grand public : le domaine d'une adresse `@gmail.com` ne dit
     * rien de l'organisation qui la porte.
     *
     * @var list<string>
     */
    public const DOMAINES_MESSAGERIE = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'live.fr', 'live.com',
        'yahoo.fr', 'yahoo.com', 'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'laposte.net', 'icloud.com',
        'me.com', 'aol.com', 'gmx.fr', 'gmx.com', 'neuf.fr', 'bbox.fr', 'numericable.fr', 'protonmail.com', 'proton.me',
    ];

    public static function score(string $motif): float
    {
        return self::MOTIFS[$motif][0] ?? 0.5;
    }

    public static function libelle(string $motif): string
    {
        return self::MOTIFS[$motif][1] ?? $motif;
    }

    public static function estCertain(string $motif): bool
    {
        return in_array($motif, self::MOTIFS_CERTAINS, true);
    }

    /**
     * Deux fiches peuvent-elles former une paire ? JAMAIS quand elles portent
     * chacune un SIREN, et que ces SIREN diffèrent : deux entités juridiques.
     */
    public static function pairePossible(?string $sirenA, ?string $sirenB): bool
    {
        $a = self::siren($sirenA);
        $b = self::siren($sirenB);

        return $a === null || $b === null || $a === $b;
    }

    public static function siren(?string $siren): ?string
    {
        $siren = trim((string) $siren);

        return $siren === '' ? null : $siren;
    }

    /**
     * Le domaine d'un site (`https://www.Exemple.fr/contact` → `exemple.fr`),
     * ou null s'il n'y en a pas ou si c'est une plateforme partagée.
     */
    public static function domaineSite(?string $site): ?string
    {
        $site = trim((string) $site);
        if ($site === '') {
            return null;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $site) !== 1) {
            $site = 'http://' . $site;
        }
        $hote = parse_url($site, PHP_URL_HOST);
        if (! is_string($hote) || $hote === '') {
            return null;
        }
        $hote = rtrim(mb_strtolower($hote), '.');
        if (str_starts_with($hote, 'www.')) {
            $hote = substr($hote, 4);
        }
        if ($hote === '' || ! str_contains($hote, '.')) {
            return null;
        }
        foreach (self::DOMAINES_PLATEFORMES as $plateforme) {
            if ($hote === $plateforme || str_ends_with($hote, '.' . $plateforme)) {
                return null;
            }
        }

        return $hote;
    }

    /** Le domaine d'une adresse e-mail, en minuscules, ou null. */
    public static function domaineEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));
        $position = strrpos($email, '@');
        if ($position === false) {
            return null;
        }
        $domaine = rtrim(substr($email, $position + 1), '.');

        return $domaine === '' ? null : $domaine;
    }

    public static function codePostal(?string $cp): ?string
    {
        $cp = preg_replace('/\s+/', '', (string) $cp) ?? '';

        return $cp === '' ? null : mb_strtoupper($cp);
    }

    /** Un code NAF sans point ni espace, en majuscules (`69.20Z` → `6920Z`). */
    public static function naf(?string $naf): ?string
    {
        $naf = mb_strtoupper(preg_replace('/[\s.]/', '', (string) $naf) ?? '');

        return $naf === '' ? null : $naf;
    }

    /**
     * Le motif d'une paire, ou null si les deux fiches ne se rapprochent pas.
     *
     * @param  array{siren: ?string, cp: ?string, domaine: ?string}  $sansSiren  la fiche sans SIREN
     * @param  array{siren: ?string, cp: ?string, domaine: ?string}  $autre
     */
    public static function motifNomIdentique(array $sansSiren, array $autre): ?string
    {
        if (! self::pairePossible($sansSiren['siren'], $autre['siren'])) {
            return null;
        }
        $memeCp = $sansSiren['cp'] !== null && $sansSiren['cp'] === $autre['cp'];
        $memeSite = $sansSiren['domaine'] !== null && $sansSiren['domaine'] === $autre['domaine'];

        if (self::siren($autre['siren']) === null) {
            return ($memeCp || $memeSite) ? self::SANS_SIREN_NOM : null;
        }
        if ($memeCp && $memeSite) {
            return self::NOM_CP_SITE;
        }
        if ($memeSite) {
            return self::NOM_SITE;
        }

        return $memeCp ? self::NOM_CP : null;
    }

    /**
     * La preuve est-elle CERTAINE, sur les données du moment ? C'est la seule
     * question que pose la fusion automatique : elle ne se fie jamais au
     * drapeau écrit par la détection, les fiches ont pu changer depuis.
     *
     * @param  array{siren: ?string, country_code: ?string, foreign_id: ?string, nom: ?string, cp: ?string, domaine: ?string, source: ?string}  $garde
     * @param  array{siren: ?string, country_code: ?string, foreign_id: ?string, nom: ?string, cp: ?string, domaine: ?string, source: ?string}  $absorbee
     * @param  bool  $absorbeeVientDUneCollecte  la source de la fiche absorbée est une source de collecte du registre, autre que l'INSEE
     */
    public static function preuveCertaine(string $motif, array $garde, array $absorbee, bool $absorbeeVientDUneCollecte): bool
    {
        $sirenGarde = self::siren($garde['siren']);
        $sirenAbsorbee = self::siren($absorbee['siren']);

        return match ($motif) {
            self::MEME_SIREN => $sirenGarde !== null && $sirenGarde === $sirenAbsorbee,
            self::MEME_IDENTIFIANT => ($garde['foreign_id'] ?? '') !== ''
                && $garde['foreign_id'] === $absorbee['foreign_id']
                && $garde['country_code'] === $absorbee['country_code']
                && self::pairePossible($sirenGarde, $sirenAbsorbee),
            self::NOM_CP_SITE => $sirenAbsorbee === null
                && $absorbeeVientDUneCollecte
                && $sirenGarde !== null
                && $garde['source'] === 'insee'
                && ($garde['nom'] ?? '') !== ''
                && $garde['nom'] === $absorbee['nom']
                && $garde['cp'] !== null && $garde['cp'] === $absorbee['cp']
                && $garde['domaine'] !== null && $garde['domaine'] === $absorbee['domaine'],
            default => false,
        };
    }

    /**
     * La nature probable d'une adresse portée par plusieurs fiches.
     *
     *  1. Une fiche dont le SITE a le même domaine que l'adresse en est la
     *     propriétaire : sa NAF dit la nature (69.20Z → cabinet comptable,
     *     82.11Z → domiciliation), sinon c'est un groupe ou un siège qui écrit
     *     pour ses filiales.
     *  2. Sans propriétaire : une fiche en 69.20Z → cabinet comptable, en
     *     82.11Z → domiciliation ; sinon inconnue.
     *
     * Une adresse de messagerie grand public n'a pas de propriétaire par le
     * domaine (tout le monde a `gmail.com`).
     *
     * @param  list<array{naf: ?string, domaine: ?string}>  $fiches
     */
    public static function natureAdresse(string $email, array $fiches): string
    {
        $domaine = self::domaineEmail($email);
        $messagerie = $domaine === null || in_array($domaine, self::DOMAINES_MESSAGERIE, true);

        if (! $messagerie) {
            $proprietaires = array_values(array_filter($fiches, static fn (array $f): bool => $f['domaine'] !== null
                && ($f['domaine'] === $domaine || str_ends_with($domaine, '.' . $f['domaine']))));
            if ($proprietaires !== []) {
                $nafs = array_map(static fn (array $f): ?string => self::naf($f['naf']), $proprietaires);
                if (in_array(self::NAF_COMPTABLE, $nafs, true)) {
                    return self::CABINET_COMPTABLE;
                }
                if (in_array(self::NAF_DOMICILIATION, $nafs, true)) {
                    return self::DOMICILIATION;
                }

                return self::GROUPE;
            }
        }

        $nafs = array_map(static fn (array $f): ?string => self::naf($f['naf']), $fiches);
        if (in_array(self::NAF_COMPTABLE, $nafs, true)) {
            return self::CABINET_COMPTABLE;
        }
        if (in_array(self::NAF_DOMICILIATION, $nafs, true)) {
            return self::DOMICILIATION;
        }

        return self::INCONNUE;
    }
}
