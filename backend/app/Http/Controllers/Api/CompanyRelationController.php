<?php

namespace App\Http\Controllers\Api;

use App\Crm\Console\CompteursHub;
use App\Crm\Taxonomy;
use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\Company;
use App\Support\AuditLogger;
use App\Support\MasquageCoordonnees;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * LE STATUT DE RELATION D'UNE FICHE, POSÉ À LA MAIN (chantier B, 2026-10-01).
 *
 * `PUT /companies/{company}/relation` — `relation_type` et/ou
 * `lifecycle_stage`. Permission `companies.update` (opérateur, admin,
 * propriétaire ; jamais `viewer`).
 *
 * C'est une DÉCISION HUMAINE : elle peut aller dans les deux sens (un client
 * perdu redevient `perdu`, une erreur d'import se corrige) — la règle « on ne
 * recule jamais » est celle des AUTOMATISMES, pas de l'opérateur. En échange :
 *
 *  - la fiche est marquée `relation_saisie_manuelle_at` : `crm:relations:importer`
 *    ne la touchera plus ;
 *  - chaque saisie est tracée DEUX fois : une activité `stage_changed` dans la
 *    timeline de la fiche (qui, quand, de quoi à quoi) et un événement
 *    `company.relation.saisie` dans `business_events` ;
 *  - verrou optimiste OPTIONNEL (`If-Match`), comme `PUT /companies/{id}` :
 *    deux saisies concurrentes ne s'écrasent pas en silence.
 */
class CompanyRelationController extends ApiController
{
    use VerrouOptimiste;

    public function update(Request $r, Company $company): JsonResponse
    {
        $this->refuserHorsEspace($company);
        $this->refuserSiVersionPerimee($r, $company);

        $valide = $r->validate([
            'relation_type' => ['sometimes', 'required', 'string', Rule::in(Taxonomy::BUSINESS_RELATION_TYPES)],
            'lifecycle_stage' => ['sometimes', 'required', 'string', Rule::in(Taxonomy::BUSINESS_LIFECYCLE_STAGES)],
        ]);
        if ($valide === []) {
            return response()->json([
                'error' => 'rien_a_poser',
                'message' => 'Indiquer relation_type et/ou lifecycle_stage.',
            ], 422);
        }

        $workspaceId = (string) $company->getAttribute('workspace_id');
        $userId = Auth::id();

        /** @var array{0: array<string, string>, 1: array<string, string>} $changement */
        $changement = DB::transaction(function () use ($company, $valide, $workspaceId, $userId): array {
            $lue = DB::table('companies')
                ->where('id', $company->id)
                ->where('workspace_id', $workspaceId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['relation_type', 'lifecycle_stage']);
            if ($lue === null) {
                abort(404);
            }
            $avant = ['relation_type' => (string) $lue->relation_type, 'lifecycle_stage' => (string) $lue->lifecycle_stage];
            $apres = array_merge($avant, $valide);

            DB::table('companies')->where('id', $company->id)->where('workspace_id', $workspaceId)->update([
                'relation_type' => $apres['relation_type'],
                'lifecycle_stage' => $apres['lifecycle_stage'],
                'relation_saisie_manuelle_at' => now(),
            ]);

            DB::table('activities')->insert([
                'workspace_id' => $workspaceId,
                'type' => 'stage_changed',
                'kind' => 'stage_changed',
                'occurred_at' => now(),
                'subject_type' => 'company',
                'subject_id' => $company->id,
                'user_id' => $userId,
                'title' => 'Relation : ' . $avant['relation_type'] . ' → ' . $apres['relation_type']
                    . ' ; étape : ' . $avant['lifecycle_stage'] . ' → ' . $apres['lifecycle_stage'] . ' (saisie manuelle)',
                'payload' => json_encode([
                    'relation' => ['from' => $avant['relation_type'], 'to' => $apres['relation_type']],
                    'etape' => ['from' => $avant['lifecycle_stage'], 'to' => $apres['lifecycle_stage']],
                    'source' => 'console:fiche',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            DB::afterCommit(static function () use ($workspaceId): void {
                CompteursHub::oublier($workspaceId);
            });

            return [$avant, $apres];
        });

        // APRÈS le COMMIT : `AuditLogger` avale ses échecs, et un échec SQL
        // dans la transaction l'aurait laissée avortée.
        AuditLogger::log('company.relation.saisie', [
            'workspace_id' => $workspaceId,
            'resource_type' => 'company',
            'resource_id' => (string) $company->id,
            'avant' => $changement[0],
            'apres' => $changement[1],
        ]);

        $fraiche = $company->fresh() ?? $company;

        return $this->avecJetonDeVersion(
            $this->ok(MasquageCoordonnees::masquerSiRequis($fraiche)),
            $fraiche,
        );
    }
}
