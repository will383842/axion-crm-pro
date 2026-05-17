<?php

namespace App\Http\Requests;

use App\Models\ScrapingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateScrapingCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Update n'autorise les modifs qu'en status=draft (vérifié dans le controller).
     * Les règles ici sont les mêmes que pour la création, mais en `sometimes`.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'                    => ['sometimes', 'required', 'string', 'max:120'],
            'description'             => ['sometimes', 'nullable', 'string', 'max:500'],

            'sources'                 => ['sometimes', 'required', 'array', 'min:1'],
            'sources.*'               => ['required', 'string', Rule::in(ScrapingCampaign::ALLOWED_SOURCES)],

            'zones'                   => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'zones.*.type'            => ['required', 'string', Rule::in(ScrapingCampaign::ALLOWED_ZONE_TYPES)],
            'zones.*.code'            => ['required', 'string', 'max:10'],

            'max_companies'           => ['sometimes', 'integer', 'min:1', 'max:50000'],
            'max_duration_minutes'    => ['sometimes', 'integer', 'min:5', 'max:1440'],
            'max_requests_per_minute' => ['sometimes', 'integer', 'min:1', 'max:100'],

            'per_source_limits'       => ['sometimes', 'nullable', 'array'],
            'per_source_limits.*.rpm'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_source_limits.*.daily' => ['nullable', 'integer', 'min:1', 'max:50000'],

            'scheduled_at'            => ['sometimes', 'nullable', 'date', 'after:now'],
            'expires_at'              => ['sometimes', 'nullable', 'date'],
        ];
    }
}
