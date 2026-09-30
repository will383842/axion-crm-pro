<?php

namespace App\Crm\Presse;

use App\Crm\Relations\PromotionRelation;
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
 * client > investisseur > partenaire > presse_media > conference > fournisseur
 * > prospect > newsletter. `presse_media` ne remplace que ce qui est en
 * dessous ; on ne rétrograde jamais (un média client reste client). Et une
 * relation POSÉE À LA MAIN (`relation_saisie_manuelle_at`) n'est jamais
 * touchée, quelle qu'elle soit. L'étape (`lifecycle_stage`) ne bouge pas.
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
    public static function qualifier(int $companyId): array
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
        if (self::relationRemplacable($relation, $fiche->relation_saisie_manuelle_at)) {
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
    public static function relationRemplacable(?string $relation, mixed $saisieManuelle): bool
    {
        if ($saisieManuelle !== null) {
            return false;
        }
        if ($relation === null) {
            return true;
        }

        return $relation !== self::RELATION && PromotionRelation::relation($relation, self::RELATION) === self::RELATION;
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

        $id = DB::table('contacts')->where('company_id', $companyId)->whereNull('deleted_at')
            ->whereRaw(
                "normalized_hash = encode(digest(normalize_name(coalesce(?, '') || '_' || ?) || '_' || ?::TEXT, 'sha256'), 'hex')",
                [$prenom, $nom, $companyId],
            )
            ->orderBy('id')->value('id');

        return $id === null ? null : (int) $id;
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
