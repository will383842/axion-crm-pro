<?php

namespace App\Crm\Presse;

use App\Crm\Campagnes\Segments;
use App\Crm\Relations\PromotionRelation;
use App\Crm\Taxonomy;
use App\Models\Company;
use App\Services\Tags\AutoTaggerService;
use Illuminate\Support\Facades\DB;

/**
 * CE QUE « HARMONISER » VEUT DIRE POUR UNE FICHE DE PRESSE — une seule
 * définition, partagée par `crm:presse:harmoniser` et `crm:presse:importer`
 * (2026-09-30).
 *
 * Une fiche de média porte le même modèle que toutes les autres fiches du
 * CRM : une NATURE (`entity_nature = media`), une RELATION
 * (`relation_type = presse_media`), des étiquettes (`EtiquettesMedia`), des
 * contacts. La règle qui décide si l'on peut écrire nature et relation :
 *
 * ── Nature ───────────────────────────────────────────────────────────────
 * `entreprise` (la valeur que porte toute fiche Sirene) ou vide devient
 * `media`. Toute AUTRE nature (`association` — une radio associative —,
 * `institution`, `cci`…) est une qualification déjà faite : jamais remplacée
 * (même règle que les fédérations). La fiche reste visable comme média par
 * ses étiquettes `media-type:` et sa relation.
 *
 * ── Relation ─────────────────────────────────────────────────────────────
 * L'ordre de promotion UNIQUE du CRM (`PromotionRelation`, chantier B) :
 * client > investisseur > partenaire > presse_media > fournisseur > conference
 * > prospect > newsletter. `presse_media` ne remplace que ce qui est en
 * dessous ; on ne rétrograde jamais (un média client reste client). Et une
 * relation POSÉE À LA MAIN (`relation_saisie_manuelle_at`) n'est jamais
 * touchée, quelle qu'elle soit — ni `fournisseur`, relation qu'on ne pose
 * qu'à la main (B13-008), même sans la marque (antérieure au chantier B). L'étape (`lifecycle_stage`) ne bouge JAMAIS :
 * aucune source de la presse ne demande d'étape (a fortiori pas `client`).
 *
 * ── Sources DÉCLARATIVES (veto de la relecture sécurité de #265) ────────
 * Une source déclarative (une liste de diffusion importée : ses lignes ne sont
 * confirmées par personne) ne pose JAMAIS un type hors prospection sur une
 * fiche EXISTANTE : `PromotionRelation::relationDeclarative`
 * (`$declaratif = true`). L'harmonisation (`crm:presse:harmoniser`) n'est pas
 * déclarative : elle lit la table `media` du CRM, constituée par ses propres
 * importeurs depuis des registres publics (CPPAP, services de presse en ligne
 * et agences agréées, catégories ARCOM, Sirene, Wikidata) — aucun tiers ne
 * peut y écrire une ligne.
 *
 * ── Réversible ───────────────────────────────────────────────────────────
 * La première fois qu'une fiche change, sa nature et sa relation d'AVANT sont
 * gardées dans `companies.metadata.harmonisation_presse` (jamais réécrites
 * ensuite) : revenir en arrière est un UPDATE qui les relit.
 *
 * `updated_at` n'est pas touché quand l'appelant a posé
 * `app.conserver_updated_at` (qualifier une fiche n'est pas la modifier : le
 * tri « récent » du hub et la reprise des archives n'en sont pas brouillés).
 */
final class QualificationPresse
{
    public const SOURCE = 'presse-2026';

    public const NATURE = 'media';

    public const RELATION = 'presse_media';

    /**
     * Ancre (`foreign_id`) d'une fiche créée par l'harmonisation pour un média
     * qui n'en avait pas (`media:<id>`) : une fiche PROVISOIRE. Quand le SIREN
     * officiel d'un de ses titres désigne la fiche d'un éditeur, la paire est
     * déposée dans « Doublons à vérifier » (`media:link-to-companies`) : un
     * humain décide, rien n'est fusionné automatiquement.
     */
    public const PREFIXE_ANCRE_MEDIA = 'media:';

    /**
     * SQL : ce média est-il AUTONOME — sans fiche, ou porté par une fiche
     * provisoire `media:<id>` ? `$alias` n'est jamais une donnée utilisateur.
     */
    public static function conditionMediaAutonome(string $alias = 'media'): string
    {
        return "({$alias}.company_id IS NULL OR EXISTS (SELECT 1 FROM companies ma_c"
            . " WHERE ma_c.id = {$alias}.company_id AND ma_c.foreign_id LIKE '" . self::PREFIXE_ANCRE_MEDIA . "%'))";
    }

    /** Clé de `companies.metadata` qui garde l'état d'avant. */
    public const CLE_AVANT = 'harmonisation_presse';

    /**
     * L'ANNULATION de l'harmonisation, prête à jouer (dans cet ordre). Elle ne
     * rend l'état d'avant QUE si la fiche porte encore ce que l'harmonisation
     * y a mis : une relation ou une nature changée depuis (à la main, par un
     * import, par le site) n'est jamais écrasée, ni une relation marquée
     * « saisie à la main ».
     *
     * @var list<string>
     */
    public const SQL_ANNULATION = [
        "UPDATE companies SET relation_type = metadata->'harmonisation_presse'->>'relation_avant'
          WHERE (metadata->'harmonisation_presse') IS NOT NULL
            AND relation_type = 'presse_media'
            AND relation_saisie_manuelle_at IS NULL
            AND metadata->'harmonisation_presse'->>'relation_avant' IS NOT NULL
            AND metadata->'harmonisation_presse'->>'relation_avant' <> 'presse_media'",
        "UPDATE companies SET entity_nature = metadata->'harmonisation_presse'->>'nature_avant'
          WHERE (metadata->'harmonisation_presse') IS NOT NULL
            AND entity_nature = 'media'
            AND COALESCE(metadata->'harmonisation_presse'->>'nature_avant', '') <> 'media'",
    ];

    /**
     * Nature et relation de la fiche, selon la règle ci-dessus.
     *
     * Compteurs rendus : `natures_posees`, `natures_conservees`,
     * `relations_posees`, `relations_conservees`.
     *
     * @return array<string, int>
     */
    public static function qualifier(int $companyId, bool $declaratif = false): array
    {
        $fiche = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')
            ->first(['id', 'entity_nature', 'relation_type', 'relation_saisie_manuelle_at', 'metadata']);
        if ($fiche === null) {
            return [];
        }

        $delta = [];
        $maj = [];

        $nature = $fiche->entity_nature;
        if ($nature === null || $nature === 'entreprise') {
            $maj['entity_nature'] = self::NATURE;
            $delta['natures_posees'] = 1;
        } elseif ($nature !== self::NATURE) {
            $delta['natures_conservees'] = 1;
        }

        $relation = $fiche->relation_type;
        if (self::relationRemplacable($relation, $fiche->relation_saisie_manuelle_at, $declaratif)) {
            $maj['relation_type'] = self::RELATION;
            $delta['relations_posees'] = 1;
        } elseif ($relation !== self::RELATION) {
            $delta['relations_conservees'] = 1;
        }

        if ($maj === []) {
            return $delta;
        }

        $meta = json_decode(is_string($fiche->metadata) ? $fiche->metadata : '{}', true);
        $meta = is_array($meta) ? $meta : [];
        if (! array_key_exists(self::CLE_AVANT, $meta)) {
            $meta[self::CLE_AVANT] = [
                'nature_avant' => $nature,
                'relation_avant' => $relation,
                'le' => now()->toDateString(),
            ];
            $maj['metadata'] = json_encode($meta, JSON_THROW_ON_ERROR);
        }

        DB::table('companies')->where('id', $companyId)->update($maj + ['updated_at' => now()]);

        return $delta;
    }

    /**
     * `presse_media` peut-elle remplacer cette relation ? Selon l'ORDRE DE
     * PROMOTION UNIQUE du CRM (`PromotionRelation`, chantier B) : on ne
     * rétrograde jamais — client, investisseur, partenaire restent ; conférence,
     * fournisseur, prospect, lettre deviennent presse. Et JAMAIS une relation
     * posée à la main (`relation_saisie_manuelle_at`).
     */
    public static function relationRemplacable(?string $relation, mixed $saisieManuelle, bool $declaratif = false): bool
    {
        if ($saisieManuelle !== null) {
            return false;
        }
        // Une relation qu'on ne pose QU'À LA MAIN (`fournisseur`, B13-008) est
        // une saisie manuelle par définition, même sans la marque — qui
        // n'existait pas avant le chantier B : jamais remplacée.
        if ($relation !== null && in_array($relation, Taxonomy::BUSINESS_RELATION_TYPES_SAISIE_MANUELLE, true)) {
            return false;
        }
        if ($relation === null) {
            return ! $declaratif;
        }
        $retenue = $declaratif
            ? PromotionRelation::relationDeclarative($relation, self::RELATION)
            : PromotionRelation::relation($relation, self::RELATION);

        return $relation !== self::RELATION && $retenue === self::RELATION;
    }

    /**
     * La fiche est-elle DÉJÀ une fiche de presse ? Tag de provenance presse,
     * ligne `media` vivante rattachée, nature `media` ou relation
     * `presse_media` — jamais un média incertain (`MediaIncertain`).
     */
    public static function estFichePresse(int $companyId): bool
    {
        $fiche = DB::table('companies')->where('id', $companyId)->whereNull('deleted_at')
            ->first(['entity_nature', 'relation_type', 'relation_saisie_manuelle_at']);
        if ($fiche === null) {
            return false;
        }
        // Une relation `presse_media` SAISIE À LA MAIN : Will a tranché, la fiche
        // est de la presse — avant toute autre règle.
        if ($fiche->relation_type === self::RELATION && $fiche->relation_saisie_manuelle_at !== null) {
            return true;
        }
        // Un MÉDIA INCERTAIN (NAF 63.12Z / 58.19Z, seule source `naf-extract`)
        // n'est pas de la presse, même s'il a été basculé à tort avant la
        // règle : une liste déclarative ne s'y rattache pas.
        if (MediaIncertain::fiche($companyId)) {
            return false;
        }
        if ($fiche->entity_nature === self::NATURE || $fiche->relation_type === self::RELATION) {
            return true;
        }

        return DB::table('media')->where('company_id', $companyId)->whereNull('deleted_at')->exists()
            || DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
                ->where('company_tag.company_id', $companyId)->where('tags.slug', 'src:scraping-' . self::SOURCE)->exists();
    }

    /** Resynchronise les étiquettes automatiques de la fiche (dont `media-*`). */
    public static function etiqueter(int $companyId): void
    {
        $company = Company::query()->find($companyId);
        if ($company !== null) {
            (new AutoTaggerService)->syncTags($company);
        }
    }

    /**
     * Le contact VIVANT d'une personne sur une fiche, retrouvé comme le funnel
     * la dédoublonne : adresse sur cette fiche, sinon empreinte nom + fiche
     * (`normalized_hash`, même expression SQL que la colonne générée).
     */
    public static function contactDe(int $companyId, ?string $prenom, string $nom, ?string $email): ?int
    {
        if ($email !== null) {
            $id = DB::table('contacts')->where('company_id', $companyId)->where('email', $email)
                ->whereNull('deleted_at')->orderBy('id')->value('id');
            if ($id !== null) {
                return (int) $id;
            }
        }

        $contact = DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereRaw(
                "normalized_hash = encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex')",
                [$prenom, $nom, $companyId],
            )
            ->orderBy('id')->first(['id', 'email']);
        if ($contact === null) {
            return null;
        }
        // Un homonyme qui porte une AUTRE adresse (venue d'ailleurs) n'est pas
        // réputé être cette personne : jamais de rattachement.
        if ($contact->email !== null && mb_strtolower((string) $contact->email) !== mb_strtolower((string) $email)) {
            return null;
        }

        return (int) $contact->id;
    }

    /**
     * Un contact HOMONYME de la fiche (hors journaliste harmonisé) porte-t-il
     * une adresse venue d'ailleurs — différente de celle de cette personne,
     * ou alors qu'elle n'en a pas ? Le funnel le prendrait pour elle (dédup
     * par nom + fiche) et lui verserait fonction, téléphone et source presse :
     * on ne lui envoie pas cette personne.
     */
    public static function homonymeAutreAdresse(int $companyId, ?string $prenom, string $nom, ?string $email): bool
    {
        return DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereNotNull('email')
            ->where(static fn ($q) => $q->whereNull('external_ref')->orWhere('external_ref', 'not like', 'journaliste:%'))
            ->whereRaw(
                "normalized_hash = encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex')",
                [$prenom, $nom, $companyId],
            )
            ->when($email !== null, static fn ($q) => $q->where('email', '<>', (string) $email))
            ->exists();
    }

    /**
     * La fiche porte-t-elle le tag d'un segment de campagne OUVERT autre que la
     * presse (un groupe de presse qui organise des salons) ? Ses journalistes
     * y restent exclus des envois (`GardePresse::conditionContactsSql`) : on
     * le compte pour le dire.
     *
     * @param  list<string>  $ouverts
     */
    public static function porteUnSegmentOuvert(int $companyId, array $ouverts = Segments::OUVERTS): bool
    {
        $tags = [];
        foreach ($ouverts as $segment) {
            if ($segment !== Segments::PRESSE) {
                $tags[] = Segments::tag($segment);
            }
        }
        if ($tags === []) {
            return false;
        }

        return DB::table('company_tag')->join('tags', 'tags.id', '=', 'company_tag.tag_id')
            ->where('company_tag.company_id', $companyId)->whereIn('tags.slug', $tags)->exists();
    }

    /**
     * Un AUTRE journaliste de même nom est-il déjà un contact vivant de cette
     * fiche ? Le funnel les dédoublonnerait par nom + fiche et fusionnerait
     * deux personnes : on ne lui envoie pas le second.
     */
    public static function homonymeJournaliste(int $companyId, ?string $prenom, string $nom, int $journalisteId): bool
    {
        return DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->where('external_ref', 'like', 'journaliste:%')
            ->where('external_ref', '<>', 'journaliste:' . $journalisteId)
            ->whereRaw(
                "normalized_hash = encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex')",
                [$prenom, $nom, $companyId],
            )
            ->exists();
    }

    /**
     * Une personne de même nom a-t-elle été mise à la CORBEILLE sur cette
     * fiche ? Le funnel la retrouverait par son empreinte et la « complèterait »
     * sans la faire revenir : on ne la lui envoie pas.
     */
    public static function personneALaCorbeille(int $companyId, ?string $prenom, string $nom): bool
    {
        return DB::table('contacts')->where('company_id', $companyId)->whereNotNull('deleted_at')
            ->whereRaw(
                "normalized_hash = encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex')",
                [$prenom, $nom, $companyId],
            )
            ->exists();
    }

    /**
     * Complète le contact d'un journaliste : sa référence de source (si elle
     * est libre) et, dans `metadata`, ce que le contact ne sait pas dire
     * (rubrique, porte d'accès, média, journaliste d'origine). BACKFILL-ONLY :
     * une clé déjà présente n'est jamais réécrite.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    public static function completerContact(int $contactId, ?string $externalRef, array $metadata): void
    {
        $contact = DB::table('contacts')->where('id', $contactId)->whereNull('deleted_at')
            ->first(['id', 'workspace_id', 'external_ref', 'metadata']);
        if ($contact === null) {
            return;
        }

        $maj = [];
        // La référence est UNIQUE par espace, corbeille comprise : une
        // référence portée par un contact à la corbeille n'est pas libre.
        $prise = $externalRef === null ? null : DB::table('contacts')->where('workspace_id', $contact->workspace_id)
            ->where('external_ref', $externalRef)->first(['id', 'deleted_at']);
        if ($externalRef !== null && $contact->external_ref === null && $prise === null) {
            $maj['external_ref'] = $externalRef;
        }

        $meta = json_decode(is_string($contact->metadata) ? $contact->metadata : '{}', true);
        $meta = is_array($meta) ? $meta : [];
        $nouveau = $meta;
        foreach ($metadata as $cle => $valeur) {
            if ($valeur !== null && $valeur !== '' && ! array_key_exists($cle, $nouveau)) {
                $nouveau[$cle] = $valeur;
            }
        }
        if ($nouveau !== $meta) {
            $maj['metadata'] = json_encode($nouveau, JSON_THROW_ON_ERROR);
        }

        if ($maj !== []) {
            DB::table('contacts')->where('id', $contactId)->update($maj);
        }
    }
}
