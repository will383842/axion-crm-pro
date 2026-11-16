<?php

namespace App\Crm\Contacts;

use App\Crm\Identite\CleDePersonne;
use App\Crm\Opposition\StatutOpposition;
use App\Models\Contact;
use App\Support\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Gestes d'écriture sur un contact (lot G1-A : modifier). Aucune suppression, aucun envoi.
 * Le filtre `workspace_id` est EXPLICITE (il ne dépend pas de la RLS : le propriétaire l'ignore).
 */
final class GestesContact
{
    /** Colonne modifiable par champ reçu. */
    private const COLONNES = [
        'first_name' => 'first_name', 'last_name' => 'last_name', 'email' => 'email', 'fonction' => 'role', 'phone' => 'phone',
    ];

    /**
     * @param  array<string, mixed>  $champs  champs déjà validés (les clés inconnues sont ignorées)
     * @return array{etat: 'ok'|'introuvable'|'doublon'|'champ_non_modifiable', contact?: Contact, champs?: list<string>, opposition?: array{opposee: bool, motifs: list<string>}}
     */
    public function modifier(string $workspaceId, int $contactId, array $champs): array
    {
        return DB::transaction(function () use ($workspaceId, $contactId, $champs): array {
            $ligne = DB::table('contacts')
                ->where('workspace_id', $workspaceId)
                ->where('id', $contactId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();
            if ($ligne === null) {
                return ['etat' => 'introuvable'];
            }

            $entreprise = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $ligne->company_id)->first(['siren', 'lifecycle_stage']);

            // L'étape vit sur l'entreprise : acceptée seulement si elle ne change rien.
            if (array_key_exists('lifecycle_stage', $champs) && $champs['lifecycle_stage'] !== null
                && $champs['lifecycle_stage'] !== ($entreprise->lifecycle_stage ?? null)) {
                return ['etat' => 'champ_non_modifiable'];
            }

            $maj = [];
            $noms = [];
            foreach (self::COLONNES as $champ => $colonne) {
                if (array_key_exists($champ, $champs)) {
                    $maj[$colonne] = $champs[$champ];
                    $noms[] = $champ;
                }
            }

            $origines = json_decode(is_string($ligne->field_origins ?? null) ? $ligne->field_origins : '{}', true);
            $origines = is_array($origines) ? $origines : [];
            foreach ($noms as $champ) {
                $origines[self::COLONNES[$champ]] = 'declared';
            }
            if (array_key_exists('email', $maj)) {
                $cle = CleDePersonne::pour(is_string($maj['email']) ? $maj['email'] : null);
                if ($cle !== null) {
                    $maj['person_key'] = $cle;
                }
            }
            $maj['field_origins'] = json_encode($origines, JSON_THROW_ON_ERROR);
            $maj['updated_at'] = now();

            try {
                // Point de sauvegarde : un 23505 ne doit pas casser la transaction englobante.
                DB::transaction(fn () => DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $contactId)->update($maj));
            } catch (QueryException $e) {
                if ($e->getCode() === '23505') {
                    return ['etat' => 'doublon'];
                }
                throw $e;
            }

            $contact = Contact::query()->where('workspace_id', $workspaceId)->with('company:id,denomination,siren')->findOrFail($contactId);
            $opposition = ['opposee' => false, 'motifs' => []];
            if (array_key_exists('email', $maj)) {
                $opposition = (new StatutOpposition)->pourLignes($workspaceId, [
                    $contactId => ['email' => is_string($maj['email']) ? $maj['email'] : null, 'siren' => $entreprise->siren ?? null],
                ])[$contactId] ?? $opposition;
            }

            // Noms de champs seulement, jamais les valeurs.
            AuditLogger::log('contact.modifie', [
                'workspace_id' => $workspaceId, 'resource_type' => 'contact', 'resource_id' => $contactId, 'champs' => $noms,
            ]);

            return ['etat' => 'ok', 'contact' => $contact, 'champs' => $noms, 'opposition' => $opposition];
        });
    }
}
