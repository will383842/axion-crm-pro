<?php

namespace App\Http\Requests;

use App\Services\Audiences\AudienceBuilderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEmailAudienceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:1000'],
            'criteria' => ['required', 'array'],
            'criteria.all' => ['sometimes', 'array'],
            'criteria.any' => ['sometimes', 'array'],
            'criteria.not' => ['sometimes', 'array'],
            'criteria.all.*.field' => ['required_with:criteria.all', 'string', Rule::in(AudienceBuilderService::WHITELIST_FIELDS)],
            'criteria.all.*.op' => ['required_with:criteria.all', 'string', Rule::in(AudienceBuilderService::WHITELIST_OPS)],
            'criteria.any.*.field' => ['required_with:criteria.any', 'string', Rule::in(AudienceBuilderService::WHITELIST_FIELDS)],
            'criteria.any.*.op' => ['required_with:criteria.any', 'string', Rule::in(AudienceBuilderService::WHITELIST_OPS)],
            'criteria.not.*.field' => ['required_with:criteria.not', 'string', Rule::in(AudienceBuilderService::WHITELIST_FIELDS)],
            'criteria.not.*.op' => ['required_with:criteria.not', 'string', Rule::in(AudienceBuilderService::WHITELIST_OPS)],

            // 🔴 CES TROIS LIGNES NE SONT PAS DÉCORATIVES — constat X39-024 (S0).
            //
            // `$request->validated()` ne rend QUE les clés couvertes par une
            // règle. Sans `value` déclarée ici, la valeur de CHAQUE condition
            // était retirée du tableau que `store()` persiste — silencieusement,
            // sans erreur, alors que le client l'avait bien envoyée.
            //
            // Mesuré le 2026-08-23 sur l'écran lui-même, pas au `curl` :
            //   l'écran envoie  {"field":"sector_main","op":"in","value":["it_saas"]}
            //   le serveur rend {"op":"in","field":"sector_main"}
            //
            // Et le mode de défaillance DÉPEND DE L'OPÉRATEUR, ce qui rend le
            // défaut invisible à l'œil :
            //   `in`  → l'audience ne vise PERSONNE      (aperçu : 2 entreprises)
            //   `neq` → elle vise TOUT L'ESPACE          (aperçu : 3 entreprises)
            //   `eq`  → 1 membre, MAIS LA MAUVAISE FICHE (aperçu : 1, la bonne)
            // Le troisième est le pire : le compte est juste, la fiche est
            // fausse. Rien à l'écran ne peut alerter.
            //
            // ⚠️ L'APERÇU, LUI, ÉTAIT JUSTE : `AudiencesController::preview()`
            // lit `$request->input('criteria')` — l'entrée BRUTE. C'est donc
            // exactement le chiffre que l'utilisateur regarde pour décider
            // d'envoyer qui n'était pas celui qu'il obtenait.
            //
            // Ce service décide À QUI PART UN COURRIEL.
            //
            // `sometimes` sans contrainte de type est VOULU : `value` est un
            // scalaire pour `eq`/`neq`/`gte`, un tableau pour `in`/`not_in`/
            // `contains_any`, et absente pour `is_null`/`is_not_null`. La forme
            // est déjà tranchée en aval par `validerCriteres()` (constat
            // D26-001) ; le rôle de ces trois lignes est de faire SURVIVRE la
            // clé à `validated()`, pas de re-valider ce qui l'est déjà.
            //
            // Correctif écrit par l'agent 26 le 2026-08-20
            // (`11_GRILLES/agent-26_formulaires.md`), resté non appliqué.
            'criteria.all.*.value' => ['sometimes'],
            'criteria.any.*.value' => ['sometimes'],
            'criteria.not.*.value' => ['sometimes'],

            'is_active' => ['sometimes', 'boolean'],
            'auto_refresh' => ['sometimes', 'boolean'],
        ];
    }
}
