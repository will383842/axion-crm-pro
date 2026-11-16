<?php

namespace App\Envoi\Message;

use App\Support\WorkspaceContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Lit UNIQUEMENT les colonnes des noms demandés (table de correspondance fermée : jamais de nom de colonne venu de
 * l'appelant). Mêmes jointures que `LecteurFicheDb`. Sous `WorkspaceContext::run` : RLS sous le rôle applicatif,
 * filtre explicite des deux côtés sous le propriétaire. Un gabarit sans variable ne déclenche AUCUNE requête.
 */
final class LecteurVariablesDb implements LecteurVariables
{
    /** @var array<string, string> expressions SQL FIXES */
    private const COLONNES = [
        'prenom' => 'c.first_name',
        'nom' => 'c.last_name',
        'fonction' => 'c.title',
        'organisation' => "COALESCE(NULLIF(btrim(o.enseigne), ''), o.denomination)",
        'ville' => 'o.city',
    ];

    public function pourContacts(string $ws, array $contactIds, array $noms): array
    {
        $noms = array_values(array_unique($noms));
        foreach ($noms as $nom) {
            if (! isset(self::COLONNES[$nom])) {
                throw new InvalidArgumentException('Variable hors liste fermée.');
            }
        }
        $ids = array_values(array_unique(array_map('intval', $contactIds)));
        if ($noms === [] || $ids === []) {
            return [];
        }

        $lignes = WorkspaceContext::run($ws, function () use ($ws, $ids, $noms): array {
            $q = DB::table('contacts as c')
                ->join('companies as o', 'o.id', '=', 'c.company_id')
                ->where('c.workspace_id', $ws)
                ->where('o.workspace_id', $ws)
                ->whereIn('c.id', $ids)
                ->whereNull('c.deleted_at')
                ->selectRaw('c.id AS _id');
            foreach ($noms as $nom) {
                $q->selectRaw(self::COLONNES[$nom] . ' AS ' . $nom);
            }

            return $q->get()->all();
        });

        $sortie = [];
        foreach ($lignes as $l) {
            $ligne = (array) $l;
            $id = (int) $ligne['_id'];
            $sortie[$id] = [];
            foreach ($noms as $nom) {
                $sortie[$id][$nom] = $ligne[$nom] === null ? null : (string) $ligne[$nom];
            }
        }

        return $sortie;
    }
}
