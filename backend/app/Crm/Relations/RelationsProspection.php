<?php

namespace App\Crm\Relations;

/**
 * CE QU'UNE AUDIENCE DE PROSPECTION EXCLUT PAR DÉFAUT — une seule définition.
 *
 * On n'écrit pas un courriel de prospection à un client, à un partenaire, à un
 * journaliste, à un fournisseur ni à un investisseur : ils ont déjà une
 * relation avec Axion-IA, et un message « découvrez nos services » la dégrade.
 *
 * OÙ VIT CE DÉFAUT (et nulle part ailleurs) :
 *
 *  1. ici — la liste ;
 *  2. le constructeur d'audiences de la console : une audience NOUVELLE naît
 *     avec un bloc `not` [`relation_type` in cette liste] déjà coché, visible et
 *     décochable (`frontend/src/features/audiences/criteres-relation.ts`, qui
 *     lit la liste GÉNÉRÉE depuis celle-ci : `RELATIONS_HORS_PROSPECTION` dans
 *     `referentiels.generated.ts`) ;
 *  3. les trois audiences par défaut (`DefaultAudiencesSeeder`, et la migration
 *     `2026_10_01_000021` pour celles qui existent déjà en base) portent ce
 *     même bloc `not`.
 *
 * Ce n'est PAS un filtre caché dans `AudienceBuilderService` : une exclusion
 * que l'écran ne montre pas finirait par surprendre (« pourquoi mon client
 * n'est-il pas dans l'audience ? »). Le défaut est un CRITÈRE ordinaire, écrit
 * dans l'audience, que l'aperçu compte et que l'on peut retirer.
 *
 * `prospect`, `newsletter` et `conference` n'y figurent pas : ce sont des
 * publics que l'on peut démarcher.
 */
final class RelationsProspection
{
    /** @var list<string> */
    public const HORS_PROSPECTION = [
        'client',
        'partenaire',
        'presse_media',
        'fournisseur',
        'investisseur',
    ];

    /**
     * La condition d'exclusion, telle qu'elle s'écrit dans un bloc `not`.
     *
     * @return array{field: string, op: string, value: list<string>}
     */
    public static function conditionExclusion(): array
    {
        return ['field' => 'relation_type', 'op' => 'in', 'value' => self::HORS_PROSPECTION];
    }
}
