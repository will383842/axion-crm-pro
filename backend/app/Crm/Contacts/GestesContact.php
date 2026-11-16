<?php

namespace App\Crm\Contacts;

use App\Crm\Identite\CleDePersonne;
use App\Crm\Opposition\StatutOpposition;
use App\Models\Contact;
use App\Support\AuditLogger;
use App\Support\MasquageCoordonnees;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Gestes d'écriture sur un contact (G1-A : modifier ; G1-B : retirer, restaurer, corbeille). Corbeille DOUCE seulement :
 * aucune suppression physique, aucun envoi.
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
     * @return array{etat: 'introuvable'|'doublon'|'champ_non_modifiable'}|array{etat: 'ok', contact: Contact, champs: list<string>, opposition: array{opposee: bool, motifs: list<string>}}
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

    /**
     * Retirer = corbeille douce. UNE requête `UPDATE` (jamais de suppression physique) qui pose `deleted_at` ET la marque
     * « retiré par la console » : seule cette marque rend la ligne restaurable depuis la console.
     *
     * @return array{etat: 'introuvable'}|array{etat: 'ok', deja: bool}
     */
    public function retirer(string $workspaceId, int $contactId, string $userId): array
    {
        return DB::transaction(function () use ($workspaceId, $contactId, $userId): array {
            $ligne = DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $contactId)->lockForUpdate()->first(['id', 'deleted_at']);
            if ($ligne === null) {
                return ['etat' => 'introuvable'];
            }
            if ($ligne->deleted_at !== null) {
                return ['etat' => 'ok', 'deja' => true];
            }

            DB::update(
                "UPDATE contacts SET deleted_at = now(), updated_at = now(), metadata = COALESCE(metadata, '{}'::jsonb) || jsonb_build_object('retire_console_le', to_char(now() AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD\"T\"HH24:MI'), 'retire_console_par', ?::text) WHERE workspace_id = ? AND id = ? AND deleted_at IS NULL",
                [$userId, $workspaceId, $contactId],
            );
            AuditLogger::log('contact.retire', [
                'workspace_id' => $workspaceId, 'resource_type' => 'contact', 'resource_id' => $contactId,
            ]);

            return ['etat' => 'ok', 'deja' => false];
        });
    }

    /**
     * Restaurer ce que la console a retiré. Refus : mise à l'écart RGPD (jamais restaurée ici), ligne sans marque console
     * (introuvable), entreprise en corbeille.
     *
     * @return array{etat: 'introuvable'|'mis_a_l_ecart'|'entreprise_corbeille'|'ok'}
     */
    public function restaurer(string $workspaceId, int $contactId): array
    {
        return DB::transaction(function () use ($workspaceId, $contactId): array {
            $ligne = DB::table('contacts')->where('workspace_id', $workspaceId)->where('id', $contactId)
                ->whereNotNull('deleted_at')->lockForUpdate()->first(['id', 'company_id', 'metadata']);
            if ($ligne === null) {
                return ['etat' => 'introuvable'];
            }
            $meta = json_decode(is_string($ligne->metadata) ? $ligne->metadata : '{}', true);
            $meta = is_array($meta) ? $meta : [];
            if (array_key_exists('mis_a_l_ecart_le', $meta)) {
                return ['etat' => 'mis_a_l_ecart'];
            }
            if (! array_key_exists('retire_console_le', $meta)) {
                return ['etat' => 'introuvable'];
            }
            $entrepriseRetiree = DB::table('companies')->where('workspace_id', $workspaceId)->where('id', $ligne->company_id)->whereNotNull('deleted_at')->exists();
            if ($entrepriseRetiree) {
                return ['etat' => 'entreprise_corbeille'];
            }

            DB::update(
                "UPDATE contacts SET deleted_at = NULL, updated_at = now(), metadata = (COALESCE(metadata, '{}'::jsonb) - 'retire_console_le' - 'retire_console_par') || jsonb_build_object('restaure_console_le', to_char(now() AT TIME ZONE 'Europe/Paris', 'YYYY-MM-DD\"T\"HH24:MI')) WHERE workspace_id = ? AND id = ? AND deleted_at IS NOT NULL",
                [$workspaceId, $contactId],
            );
            AuditLogger::log('contact.restaure', [
                'workspace_id' => $workspaceId, 'resource_type' => 'contact', 'resource_id' => $contactId,
            ]);

            return ['etat' => 'ok'];
        });
    }

    /**
     * La corbeille de la console (200 lignes au plus). Le prédicat est celui de `idx_contacts_corbeille_console`
     * (G0), recopié mot pour mot ; la condition en plus écarte une mise à l'écart RGPD, jamais listée ici.
     *
     * @return list<array{id: int, person_key: ?string, first_name: ?string, last_name: string, email: ?string, organisation: ?string, retire_le: string}>
     */
    public function corbeille(string $workspaceId): array
    {
        $lignes = DB::select(
            "SELECT c.id, c.person_key, c.first_name, c.last_name, c.email::text AS email, c.deleted_at, e.denomination AS organisation
             FROM contacts c LEFT JOIN companies e ON e.id = c.company_id AND e.workspace_id = c.workspace_id
             WHERE c.workspace_id = ? AND c.deleted_at IS NOT NULL AND c.metadata ?? 'retire_console_le' AND NOT (c.metadata ?? 'mis_a_l_ecart_le')
             ORDER BY c.deleted_at DESC, c.id DESC LIMIT 200",
            [$workspaceId],
        );
        $masquer = MasquageCoordonnees::requis();

        $rendu = [];
        foreach ($lignes as $l) {
            $courriel = is_string($l->email) ? $l->email : null;
            $rendu[] = [
                'id' => (int) $l->id,
                'person_key' => is_string($l->person_key) ? $l->person_key : null,
                'first_name' => is_string($l->first_name) ? $l->first_name : null,
                'last_name' => (string) $l->last_name,
                'email' => $masquer ? MasquageCoordonnees::email($courriel) : $courriel,
                'organisation' => is_string($l->organisation) ? $l->organisation : null,
                'retire_le' => Carbon::parse((string) $l->deleted_at)->toIso8601String(),
            ];
        }

        return $rendu;
    }
}
