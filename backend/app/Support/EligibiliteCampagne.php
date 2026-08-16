<?php

namespace App\Support;

use App\Models\Company;
use App\Models\Contact;
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

        return self::appliquerPortes($query, 'companies.email_generic');
    }

    /**
     * Les PERSONNES joignables — `contacts.email`.
     *
     * 🔴 Ce n'est pas un raffinement : 410 481 contacts portent une adresse,
     * contre 255 290 génériques d'entreprise. Les personnes sont donc 1,6 fois
     * plus nombreuses que les entreprises côté adresses. Une garde qui ne
     * couvrait que `companies.email_generic` laissait la MAJORITÉ des envois
     * possibles hors de toute protection.
     *
     * @param  Builder<Contact>  $query
     * @return Builder<Contact>
     */
    public static function appliquerContacts(Builder $query): Builder
    {
        $query->whereNotNull('email')->whereNull('deleted_at');

        return self::appliquerPortes($query, 'contacts.email');
    }

    /**
     * LES DEUX PORTES SEULES, sans les conditions d'éligibilité à une campagne.
     *
     * 🔴 Pourquoi cette méthode existe : les EXPORTS CSV ne passaient par
     * aucune garde. Constaté le 2026-08-16 — `CompaniesController::export()`
     * embarquait `nom prénom (rôle) email téléphone` de chaque contact, y
     * compris ceux inscrits en `opt_out` ou en `email_suppressions`. Sur
     * 4,29 M de fiches, permission `data.export`. C'était la fuite la plus
     * large du système.
     *
     * `appliquerContacts()` ne convenait pas ici : elle impose
     * `email IS NOT NULL`, ce qui aurait retiré de l'export des contacts sans
     * adresse — qui n'ont commis aucune opposition. Une garde ne doit pas
     * emporter plus que ce qu'elle protège.
     *
     * ⚠️ Un contact SANS email traverse la garde, et c'est correct : les deux
     * comparaisons (adresse en clair et empreinte) sont fausses sur `NULL`,
     * donc `whereNotExists` est vrai. Personne n'est exclu pour une adresse
     * qu'il n'a pas.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $colonneEmail  Colonne QUALIFIÉE (`table.colonne`)
     * @return Builder<TModel>
     */
    public static function exclureOpposes(Builder $query, string $colonneEmail, string $scope = 'business'): Builder
    {
        return self::appliquerPortes($query, $colonneEmail, $scope);
    }

    /**
     * LA question, posée sur une adresse seule : « a-t-on le droit d'écrire
     * ici ? »
     *
     * C'est le point de passage OBLIGÉ du futur moteur d'envoi. Une audience
     * est une PHOTO : entre sa constitution et l'envoi, une opposition peut
     * arriver. Filtrer la liste ne suffit donc pas — il faut re-poser la
     * question juste avant d'écrire, adresse par adresse. C'est cette méthode
     * qui doit être appelée là, et elle vaut pour TOUTE source : entreprise,
     * personne, journaliste, import ponctuel.
     */
    public static function peutRecevoir(string $email, string $scope = 'business'): bool
    {
        $normalise = mb_strtolower(trim($email));
        if ($normalise === '') {
            return false;
        }

        $empreinte = hash('sha256', $normalise);

        $oppose = DB::table('opt_out')
            ->where('scope', $scope)
            ->where(function ($q) use ($normalise, $empreinte): void {
                $q->where('email', $normalise)->orWhere('email_hash', $empreinte);
            })
            ->exists();

        if ($oppose) {
            return false;
        }

        return ! ListeSuppression::estSupprimee($normalise, $scope);
    }

    /**
     * Les DEUX portes, écrites UNE SEULE FOIS et appliquées à la colonne
     * d'adresse qu'on lui donne.
     *
     * Recopier ce bloc par table aurait garanti la divergence : le jour où une
     * troisième liste de suppression apparaît, une des copies l'oublierait, et
     * personne ne s'en apercevrait — une garde qui ne garde plus ne fait aucun
     * bruit.
     *
     *  1. OPPOSITION (`opt_out`) — la personne a dit non. C'est une VOLONTÉ :
     *     valeur juridique, elle ne s'efface jamais.
     *  2. SUPPRESSION (`email_suppressions`) — rebond dur, plainte, rebonds
     *     temporaires répétés. C'est un FAIT technique.
     *
     * Les deux restent séparées à dessein : les confondre rendrait impossible
     * de répondre à « cette personne s'est-elle opposée ? », la seule question
     * que pose la CNIL. Pour l'ENVOI en revanche, l'une comme l'autre interdit.
     *
     * Comparaison sur l'adresse (colonnes `citext`, insensibles à la casse) ET
     * sur son empreinte : les signaux venus du site arrivent hachés, ceux d'un
     * fournisseur d'envoi arrivent en clair. Une garde qui ne reconnaîtrait
     * qu'une seule forme serait aveugle une fois sur deux.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $colonneEmail  Colonne QUALIFIÉE (`table.colonne`)
     * @return Builder<TModel>
     */
    private static function appliquerPortes(Builder $query, string $colonneEmail, string $scope = 'business'): Builder
    {
        $empreinte = "encode(digest(btrim(lower({$colonneEmail})), 'sha256'), 'hex')";

        foreach (['opt_out', 'email_suppressions'] as $table) {
            $query->whereNotExists(function (\Illuminate\Database\Query\Builder $sub) use ($table, $colonneEmail, $empreinte, $scope): void {
                $sub->select(DB::raw('1'))
                    ->from($table)
                    ->where($table . '.scope', $scope)
                    ->where(function (\Illuminate\Database\Query\Builder $inner) use ($table, $colonneEmail, $empreinte): void {
                        $inner->whereColumn($table . '.email', $colonneEmail)
                            ->orWhereRaw($table . '.email_hash = ' . $empreinte);
                    });
            });
        }

        return $query;
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
