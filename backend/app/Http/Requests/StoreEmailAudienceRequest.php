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
            'name'         => ['required', 'string', 'max:160'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'criteria'     => ['required', 'array'],
            'criteria.all' => ['sometimes', 'array'],
            'criteria.any' => ['sometimes', 'array'],
            'criteria.not' => ['sometimes', 'array'],
            'criteria.all.*.field' => ['required_with:criteria.all', 'string', Rule::in(AudienceBuilderService::WHITELIST_FIELDS)],
            'criteria.all.*.op'    => ['required_with:criteria.all', 'string', Rule::in(AudienceBuilderService::WHITELIST_OPS)],
            'criteria.any.*.field' => ['required_with:criteria.any', 'string', Rule::in(AudienceBuilderService::WHITELIST_FIELDS)],
            'criteria.any.*.op'    => ['required_with:criteria.any', 'string', Rule::in(AudienceBuilderService::WHITELIST_OPS)],
            'criteria.not.*.field' => ['required_with:criteria.not', 'string', Rule::in(AudienceBuilderService::WHITELIST_FIELDS)],
            'criteria.not.*.op'    => ['required_with:criteria.not', 'string', Rule::in(AudienceBuilderService::WHITELIST_OPS)],
            'is_active'    => ['sometimes', 'boolean'],
            'auto_refresh' => ['sometimes', 'boolean'],
        ];
    }
}
