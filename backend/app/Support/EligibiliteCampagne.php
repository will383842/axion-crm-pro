<?php

namespace App\Support;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LA définition de « éligible à une campagne », écrite UNE SEULE FOIS.
 *
 * Elle existe pour une raison précise, décidée avec Will le 2026-08-15 : il
 * voulait « bien séparer ce qui est complet pour les campagnes de ce qui ne
 * l'est pas », pour que le futur moteur d'envoi (lot L7, reporté) soit plus
 * simple.
 *
 * 🔴 On ne sépare PAS physiquement. Un « bac campagnes » où l'on déplacerait
 * les fiches complètes serait faux trois jours plus tard :
 *   - 3 493 436 fiches sont encore `pending` et deviendront contactables au
 *     fil de l'enrichissement — un bac figé les raterait toutes ;
 *   - la même vérité vivrait à deux endroits, et le dépôt combat déjà cette
 *     divergence partout (« deux listes jumelles finissent par diverger ») ;
 *   - surtout, l'éligibilité n'est PAS un état stable : une adresse devient
 *     inéligible le jour où elle rebondit ou se désinscrit. Un bac figé
 *     continuerait à l'arroser — c'est ainsi qu'on fait blacklister un
 *     domaine d'envoi.
 *
 * On sépare donc par une définition CALCULÉE, toujours fraîche, que l'écran
 * utilise aujourd'hui et que le moteur d'envoi reprendra telle quelle demain.
 *
 * ⚠️ CE QUE CETTE DÉFINITION NE COUVRE PAS ENCORE (à compléter avant le
 * premier envoi réel) : l'historique de rebonds et de plaintes. Les tables
 * `dnc_entries` et `unsubscribes` que le plan supposait présentes N'EXISTENT
 * PAS en base (vérifié le 2026-08-15). Tant qu'elles n'existent pas, un envoi
 * de masse ne doit pas se lancer sur cette seule base — c'est écrit ici plutôt
 * que découvert plus tard.
 */
final class EligibiliteCampagne
{
    /**
     * Paliers de confiance de l'adresse, du plus sûr au moins sûr.
     * Répartition mesurée en production le 2026-08-15 sur les 255 290 fiches
     * pourvues d'une adresse : A = 165 587, B = 48 554, C = 40 956.
     */
    public const PALIERS = ['A', 'B', 'C'];

    /**
     * Fiches à qui l'on PEUT écrire.
     *
     * Deux conditions, et deux seulement — chacune vérifiable :
     *   1. une adresse existe (`email_generic`) ;
     *   2. cette adresse ne s'est pas opposée (table `opt_out`, portée
     *      `business` ; l'univers vivier a sa propre porte).
     *
     * Le PALIER de confiance n'entre PAS dans l'éligibilité : c'est un choix
     * de ciblage, pas une règle de droit. On le passe en paramètre pour que
     * « écrire aux A seulement » reste une décision explicite et visible,
     * jamais un défaut caché.
     *
     * @param  Builder<Company>  $query
     * @param  list<string>|null  $paliers  Restreint au(x) palier(s) donné(s) ; null = tous
     * @return Builder<Company>
     */
    public static function appliquer(Builder $query, ?array $paliers = null): Builder
    {
        $query->whereNotNull('email_generic')
            ->whereNull('deleted_at');

        if ($paliers !== null && $paliers !== []) {
            $query->whereIn('best_email_confidence', array_values(array_intersect($paliers, self::PALIERS)));
        }

        // Opposition : comparaison sur l'adresse elle-même (colonne `citext`,
        // donc insensible à la casse) ET sur son empreinte, parce que les
        // oppositions venues du site arrivent hachées — une opposition qui ne
        // serait reconnue que sous une seule des deux formes ne garde rien.
        return $query->whereNotExists(function (\Illuminate\Database\Query\Builder $sub): void {
            $sub->select(DB::raw('1'))
                ->from('opt_out')
                ->where('opt_out.scope', 'business')
                ->where(function (\Illuminate\Database\Query\Builder $ou): void {
                    $ou->whereColumn('opt_out.email', 'companies.email_generic')
                        // Empreinte IDENTIQUE à celle que calcule l'ingestion
                        // (`SiteSyncEvent::emailHash()` : sha256 hex de
                        // l'adresse en minuscules, sans sel). Toute divergence
                        // ici — un `trim` oublié, un sel ajouté — produirait
                        // une garde silencieusement inopérante.
                        ->orWhereRaw("opt_out.email_hash = encode(digest(btrim(lower(companies.email_generic)), 'sha256'), 'hex')");
                });
        });
    }

    /**
     * L'inverse : ce qui n'est PAS prêt. Utile pour montrer le RESTE À FAIRE
     * plutôt que de le laisser deviner — c'est là que vivent les 3,49 M de
     * fiches collectées mais jamais enrichies.
     *
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public static function appliquerNonEligibles(Builder $query): Builder
    {
        return $query->whereNull('deleted_at')->whereNull('email_generic');
    }
}
