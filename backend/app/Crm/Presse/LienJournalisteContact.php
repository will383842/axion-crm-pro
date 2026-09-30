<?php

namespace App\Crm\Presse;

use App\Support\ListeSuppression;
use Illuminate\Support\Facades\DB;

/**
 * LE LIEN JOURNALISTE ↔ CONTACT PORTE LES DROITS DE LA PERSONNE, DANS LES
 * DEUX SENS (relecture sécurité de #264, veto RGPD, 2026-09-30).
 *
 * Depuis l'harmonisation, une même personne vit à deux endroits : la ligne
 * `journalists` (console « Médias & Presse ») et le contact qui la porte
 * (`journalists.contact_id`), que les campagnes visent. Un droit exercé d'un
 * côté doit valoir de l'autre :
 *
 *  - OPPOSITION posée dans la console presse → ses coordonnées ET celles du
 *    contact lié entrent dans `opt_out` (portée business), la table que lisent
 *    le funnel (anti-réinsertion) et `EligibiliteCampagne` (envoi) ; le contact
 *    garde la date de l'opposition dans `metadata.opposition_presse_le`, même
 *    sans adresse ni téléphone ;
 *  - EFFACEMENT d'un journaliste (console, `GdprErasureService`) → le contact
 *    lié est SUPPRIMÉ, même sans adresse, et la personne inscrite au registre
 *    `contacts_retires` (le déclencheur de la base l'y écrit : on s'assure que
 *    ses `sources` citent `presse-2026`) ;
 *  - dans l'autre sens, c'est la BASE qui le garantit, quel que soit le
 *    chemin : la suppression d'un contact atteint la ligne `journalists` liée
 *    (opposée, coordonnées vidées, à la corbeille), et toute opposition
 *    inscrite dans `opt_out` atteint les journalistes dont l'adresse ou le
 *    téléphone — ou ceux du contact lié — y correspondent (déclencheurs de la
 *    migration `2026_10_01_000010`).
 */
final class LienJournalisteContact
{
    public const SOURCE_OPPOSITION = 'console:journalists';

    /**
     * Opposition décidée dans la console presse : propage vers `opt_out` et
     * vers le contact lié.
     */
    public static function opposer(int $journalisteId): void
    {
        $j = DB::table('journalists')->where('id', $journalisteId)->whereNull('deleted_at')->first(['id', 'email', 'phone', 'contact_id']);
        if ($j === null) {
            return;
        }
        $contact = $j->contact_id === null ? null
            : DB::table('contacts')->where('id', $j->contact_id)->whereNull('deleted_at')->first(['id', 'email', 'phone', 'metadata']);

        foreach ([$j->email, $contact?->email] as $email) {
            if (is_string($email) && trim($email) !== '') {
                self::opposerEmail($email);
            }
        }
        foreach ([$j->phone, $contact?->phone] as $telephone) {
            if (is_string($telephone) && trim($telephone) !== '') {
                self::opposerTelephone($telephone);
            }
        }

        if ($contact !== null) {
            $meta = json_decode(is_string($contact->metadata) ? $contact->metadata : '{}', true);
            $meta = is_array($meta) ? $meta : [];
            if (! array_key_exists('opposition_presse_le', $meta)) {
                $meta['opposition_presse_le'] = now()->toDateString();
                DB::table('contacts')->where('id', $contact->id)->update(['metadata' => json_encode($meta, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    /**
     * Dit à la base que les suppressions de contacts de la transaction en
     * cours sont des EFFACEMENTS (art. 17, opposition) : le déclencheur
     * `contacts_retrait_atteint_journalistes` ne reporte que ceux-là sur la
     * ligne `journalists` liée. Une suppression technique (hors de ce
     * marqueur) ne détruit rien. `SET LOCAL` : la portée est la transaction.
     */
    public static function marquerEffacement(): void
    {
        DB::statement("SET LOCAL app.effacement_personne = 'on'");
    }

    /**
     * Effacement d'un ou plusieurs journalistes : leurs contacts liés sont
     * supprimés (et inscrits au registre des retraits).
     *
     * @param  list<int>  $journalisteIds
     * @return int contacts supprimés
     */
    public static function effacerContactsDe(array $journalisteIds): int
    {
        if ($journalisteIds === []) {
            return 0;
        }
        $contacts = array_values(array_unique(array_map('intval', DB::table('journalists')
            ->whereIn('id', $journalisteIds)->whereNull('deleted_at')->whereNotNull('contact_id')->pluck('contact_id')->all())));
        if ($contacts === []) {
            return 0;
        }

        // Le registre (`contacts_memoriser_retrait`) retient les personnes dont
        // `sources` cite `presse-2026` : on s'en assure avant la suppression.
        DB::table('contacts')->whereIn('id', $contacts)
            ->whereRaw("NOT (COALESCE(sources, '[]'::jsonb) @> '[\"presse-2026\"]'::jsonb)")
            ->update(['sources' => DB::raw("COALESCE(sources, '[]'::jsonb) || '[\"presse-2026\"]'::jsonb")]);

        self::marquerEffacement();

        return DB::table('contacts')->whereIn('id', $contacts)->delete();
    }

    private static function opposerEmail(string $email): void
    {
        $empreinte = ListeSuppression::empreinte($email);
        if (DB::table('opt_out')->where('scope', 'business')->where('email_hash', $empreinte)->exists()) {
            return;
        }
        DB::table('opt_out')->insert([
            // Le hash suffit à l'anti-réinsertion : l'adresse en clair n'est
            // pas gardée sur une ligne d'opposition.
            'email' => null,
            'email_hash' => $empreinte,
            'scope' => 'business',
            'source' => self::SOURCE_OPPOSITION,
            'created_at' => now(),
        ]);
    }

    private static function opposerTelephone(string $telephone): void
    {
        $telephone = (string) preg_replace('/[\s.-]/', '', $telephone);
        if ($telephone === '' || DB::table('opt_out')->where('scope', 'business')->where('phone', $telephone)->exists()) {
            return;
        }
        DB::table('opt_out')->insert([
            'email' => null,
            'email_hash' => null,
            'phone' => $telephone,
            'scope' => 'business',
            'source' => self::SOURCE_OPPOSITION,
            'created_at' => now(),
        ]);
    }
}
