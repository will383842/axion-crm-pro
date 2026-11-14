<?php

namespace App\Envoi\Imap;

use App\Support\ListeSuppression;
use Illuminate\Support\Facades\DB;

/**
 * Retrouve l'adresse d'un destinataire à partir de son EMPREINTE (SHA-256 de l'adresse en minuscules), dans les
 * contacts de l'espace. Lecture seule ; l'adresse reste en mémoire (jamais journalisée). Introuvable => null :
 * le retour reste « à rattacher » (revue humaine), jamais d'effet deviné.
 *
 * D1 — UNE SEULE empreinte : `ListeSuppression::empreinte()` (PHP) fait foi. La base ne sert qu'à PRÉ-SÉLECTIONNER
 * des candidats par un index d'expression (`idx_contacts_empreinte_email`, migration 2026_12_02_000200) ; chaque
 * candidat est ensuite RECALCULÉ en PHP et n'est renvoyé que si l'empreinte est identique. Le calcul SQL retire les
 * mêmes blancs que `trim()` (espace, tabulation, saut de ligne, retour chariot, tabulation verticale) : avant, un
 * `btrim()` sans liste n'ôtait que l'espace et une adresse saisie avec une tabulation restait introuvable.
 * Limite connue : les majuscules NON ASCII ne sont pliées par `lower()` que selon la locale de la base ; en cas
 * d'écart, l'adresse n'est pas trouvée (retour « à rattacher »), jamais une mauvaise adresse.
 */
final class AdresseParEmpreinteCrm
{
    private const MAX_CANDIDATS = 20;

    /**
     * Expression SQL de l'empreinte, IDENTIQUE à celle de l'index (même texte, sinon l'index ne sert plus).
     * La fonction `empreinte_adresse_sql` (migration 2026_12_02_000200, IMMUTABLE : `convert_to` ne l'est pas, il
     * faut l'envelopper pour indexer) retire les mêmes blancs que `trim()` de PHP (espace, \t, \n, \r, \x0B ;
     * pas l'octet nul que PostgreSQL refuse), passe en minuscules et prend le SHA-256 de l'UTF-8.
     */
    public const EXPRESSION_SQL = 'public.empreinte_adresse_sql(email::text)';

    /** Requête à deux paramètres : (espace, empreinte). */
    public static function requeteSql(): string
    {
        return 'SELECT email FROM contacts WHERE workspace_id = ? AND deleted_at IS NULL AND email IS NOT NULL AND '
            . self::EXPRESSION_SQL . ' = ? ORDER BY id LIMIT ' . self::MAX_CANDIDATS;
    }

    public static function trouver(string $workspaceId, string $empreinte): ?string
    {
        foreach (DB::select(self::requeteSql(), [$workspaceId, $empreinte]) as $ligne) {
            $adresse = (string) $ligne->email;
            if (ListeSuppression::empreinte($adresse) === $empreinte) { // la fonction PHP fait foi
                return $adresse;
            }
        }

        return null;
    }
}
