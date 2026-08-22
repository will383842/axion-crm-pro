<?php

namespace App\Crm\Ingest;

/**
 * Résultat d'une ingestion, tel qu'il est renvoyé à l'outbox du site.
 *
 * `status` est un contrat : l'outbox s'en sert pour décider si la ligne est
 * soldée (`created`/`updated`/`noop_idempotent`/`pending_match`/`opted_out`)
 * ou à rejouer. Tous ces statuts SOLDENT la ligne — un refus temporaire n'est
 * jamais un statut, c'est une SiteSyncRejection 503.
 */
final class IngestOutcome
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    /** L'événement avait déjà été ingéré : rien n'a été touché. */
    public const IDEMPOTENT = 'noop_idempotent';

    /**
     * Lead sans SIREN : aucune fiche n'est créée (le rapprochement par
     * dénomination est soumis à arbitrage humain, jamais automatique — plan
     * §2.4.2). L'événement est conservé dans la timeline avec son `match_hint`.
     */
    public const PENDING_MATCH = 'pending_match';

    /** La personne s'est opposée dans cet univers : rien n'est réinséré. */
    public const OPTED_OUT = 'opted_out';

    /**
     * @param  list<string>  $tags
     * @param  list<string>  $tagsIgnores slugs demandés que le référentiel ne connaît pas
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        public readonly ?int $activityId = null,
        public readonly array $tags = [],
        // B13-005 — CE CHAMP EST LA CONTREPARTIE DU 200.
        //
        // Un tag de provenance hors référentiel (`source_slug` libre côté site)
        // était abandonné par un `continue` nu : l'événement repartait en 200
        // avec `tags` amputé, et RIEN ne disait lequel manquait. Le site ne
        // pouvait ni compter ses pertes ni les deviner — la seule façon de s'en
        // apercevoir était de constater, des semaines plus tard, un segment vide.
        //
        // On refuse toujours de créer le tag à la volée (une ingestion ne doit
        // pas pouvoir polluer le référentiel gouverné) : ce qui change, c'est
        // qu'on le DIT.
        public readonly array $tagsIgnores = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'activity_id' => $this->activityId,
            'tags' => $this->tags,
            // B13-005 — clé AJOUTÉE au contrat de sortie lu par l'outbox du
            // site : jamais de clé retirée ni renommée, l'outbox lit par clé et
            // un ajout lui est transparent. Elle est TOUJOURS présente (liste
            // vide quand rien n'a été écarté) : un champ qui n'apparaît que les
            // mauvais jours ne se surveille pas.
            'tags_ignores' => $this->tagsIgnores,
        ];
    }
}
