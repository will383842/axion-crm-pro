<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * La liste de suppression : le seul endroit qui décide qu'une adresse ne doit
 * plus jamais recevoir d'envoi, et pourquoi.
 *
 * Complète `opt_out` sans le remplacer : l'opposition est une VOLONTÉ, un
 * rebond est un FAIT technique. `EligibiliteCampagne` interroge les deux.
 */
final class ListeSuppression
{
    /** Rebond DUR : l'adresse n'existe pas. Suppression immédiate. */
    public const REBOND_DUR = 'hard_bounce';

    /** PLAINTE : le destinataire a signalé l'envoi comme spam. */
    public const PLAINTE = 'complaint';

    /** Rebonds TEMPORAIRES répétés au-delà du seuil. */
    public const REBOND_TEMPORAIRE = 'soft_bounce_threshold';

    /** Décision humaine. */
    public const MANUEL = 'manual';

    /** Adresse syntaxiquement invalide. */
    public const SYNTAXE_INVALIDE = 'invalid_syntax';

    /**
     * Nombre de rebonds TEMPORAIRES avant suppression.
     *
     * Trois, pas un : une boîte pleine ou un serveur en maintenance renvoie un
     * rebond mou sans que l'adresse soit morte. Supprimer au premier jetterait
     * des contacts parfaitement valides — et personne ne s'en apercevrait,
     * puisqu'une suppression ne fait aucun bruit.
     */
    public const SEUIL_REBONDS_TEMPORAIRES = 3;

    /**
     * Empreinte de l'adresse — MÊME calcul que l'ingestion
     * (`SiteSyncEvent::emailHash()`) : sha256 de l'adresse normalisée, sans
     * sel. Toute divergence ici rendrait la liste aveugle aux signaux venus
     * du site, en silence.
     */
    public static function empreinte(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    /**
     * Inscrit une adresse. Idempotent : un second signal incrémente le
     * compteur au lieu de créer une seconde ligne, et la raison la plus GRAVE
     * l'emporte — un rebond mou ne doit jamais « rétrograder » une plainte.
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function inscrire(
        string $email,
        string $raison,
        string $source,
        string $scope = 'business',
        array $metadata = [],
    ): void {
        $normalise = mb_strtolower(trim($email));
        if ($normalise === '') {
            return;
        }

        $empreinte = self::empreinte($normalise);
        $maintenant = now();

        $existante = DB::table('email_suppressions')
            ->where('scope', $scope)
            ->where(function ($q) use ($normalise, $empreinte): void {
                $q->where('email', $normalise)->orWhere('email_hash', $empreinte);
            })
            ->first();

        if ($existante === null) {
            DB::table('email_suppressions')->insert([
                'scope' => $scope,
                'email' => $normalise,
                'email_hash' => $empreinte,
                'reason' => $raison,
                'source' => $source,
                'occurrences' => 1,
                'first_seen_at' => $maintenant,
                'last_seen_at' => $maintenant,
                'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            ]);

            return;
        }

        DB::table('email_suppressions')
            ->where('id', $existante->id)
            ->update([
                'occurrences' => $existante->occurrences + 1,
                'last_seen_at' => $maintenant,
                'reason' => self::raisonLaPlusGrave((string) $existante->reason, $raison),
                // L'adresse en clair peut arriver APRÈS le hash (signal site
                // puis signal fournisseur) : on complète, on n'écrase jamais.
                'email' => $existante->email ?? $normalise,
                'email_hash' => $existante->email_hash ?? $empreinte,
            ]);
    }

    /**
     * Enregistre un rebond TEMPORAIRE. Ne supprime qu'au-delà du seuil.
     *
     * Le comptage vit dans `metadata` tant que l'adresse n'est pas supprimée :
     * une ligne de suppression qui ne supprime pas serait un piège pour qui
     * lirait la table.
     */
    public static function rebondTemporaire(string $email, string $source, string $scope = 'business'): bool
    {
        $normalise = mb_strtolower(trim($email));
        if ($normalise === '') {
            return false;
        }

        $cle = 'soft_bounce:' . $scope . ':' . self::empreinte($normalise);
        $compte = (int) cache()->increment($cle);
        if ($compte === 1) {
            // Fenêtre glissante : deux rebonds mous à six mois d'intervalle ne
            // décrivent pas la même adresse en péril.
            cache()->put($cle, 1, now()->addDays(30));
        }

        if ($compte < self::SEUIL_REBONDS_TEMPORAIRES) {
            return false;
        }

        self::inscrire($normalise, self::REBOND_TEMPORAIRE, $source, $scope, ['occurrences_molles' => $compte]);

        return true;
    }

    /** Cette adresse est-elle supprimée dans cet univers ? */
    public static function estSupprimee(string $email, string $scope = 'business'): bool
    {
        $normalise = mb_strtolower(trim($email));
        if ($normalise === '') {
            return false;
        }

        $empreinte = self::empreinte($normalise);

        return DB::table('email_suppressions')
            ->where('scope', $scope)
            ->where(function ($q) use ($normalise, $empreinte): void {
                $q->where('email', $normalise)->orWhere('email_hash', $empreinte);
            })
            ->exists();
    }

    /**
     * Ordre de gravité. Une plainte ne se fait jamais rétrograder par un
     * rebond : elle engage la réputation du domaine, pas seulement la
     * délivrabilité d'une adresse.
     */
    private static function raisonLaPlusGrave(string $actuelle, string $nouvelle): string
    {
        $gravite = [
            self::PLAINTE => 5,
            self::REBOND_DUR => 4,
            self::MANUEL => 3,
            self::SYNTAXE_INVALIDE => 2,
            self::REBOND_TEMPORAIRE => 1,
        ];

        return ($gravite[$nouvelle] ?? 0) > ($gravite[$actuelle] ?? 0) ? $nouvelle : $actuelle;
    }
}
