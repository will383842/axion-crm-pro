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
 * ── Ce qui est couvert ───────────────────────────────────────────────────
 * Opposition volontaire (`opt_out`) ET suppression technique
 * (`email_suppressions` : rebond dur, plainte, rebonds temporaires répétés).
 * Les tables `dnc_entries` / `unsubscribes` que le plan supposait présentes
 * n'ont jamais existé ; `email_suppressions` les remplace, avec un vocabulaire
 * fermé et les deux univers étanches.
 *
 * ── Ce qui reste à faire AVANT un envoi réel ─────────────────────────────
 * Cette définition dit qui l'on a le DROIT de contacter. Elle ne remplace pas
 * la mécanique d'envoi elle-même (lot L7, reporté) : domaine dédié, SPF/DKIM/
 * DMARC, réception des retours du fournisseur pour ALIMENTER cette liste, et
 * désinscription un clic (RFC 8058). Une liste de suppression que personne ne
 * remplit reste vide, donc muette — c'est le raccordement des webhooks qui la
 * rendra vivante.
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

        // DEUX portes, et il faut passer les deux :
        //
        //  1. OPPOSITION (`opt_out`) — la personne a dit non. C'est une
        //     VOLONTÉ : elle a une valeur juridique et ne s'efface jamais.
        //  2. SUPPRESSION (`email_suppressions`) — rebond dur, plainte,
        //     rebonds temporaires répétés. C'est un FAIT technique.
        //
        // Les deux sont séparées à dessein : les confondre rendrait impossible
        // de répondre à « cette personne s'est-elle opposée ? », la seule
        // question que pose la CNIL. Mais pour l'ENVOI, l'une comme l'autre
        // interdit d'écrire.
        //
        // Comparaison sur l'adresse (colonne `citext`, insensible à la casse)
        // ET sur son empreinte : les signaux venus du site arrivent hachés,
        // ceux d'un fournisseur d'envoi arrivent en clair. Une garde qui ne
        // reconnaîtrait qu'une seule forme serait aveugle une fois sur deux.
        $query->whereNotExists(function (\Illuminate\Database\Query\Builder $sub): void {
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

        return $query->whereNotExists(function (\Illuminate\Database\Query\Builder $sub): void {
            $sub->select(DB::raw('1'))
                ->from('email_suppressions')
                ->where('email_suppressions.scope', 'business')
                ->where(function (\Illuminate\Database\Query\Builder $su): void {
                    $su->whereColumn('email_suppressions.email', 'companies.email_generic')
                        ->orWhereRaw("email_suppressions.email_hash = encode(digest(btrim(lower(companies.email_generic)), 'sha256'), 'hex')");
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
