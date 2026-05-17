<?php

namespace App\Http\Requests;

use App\Models\ScrapingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScrapingCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name'                    => ['required', 'string', 'max:120'],
            'description'             => ['nullable', 'string', 'max:500'],

            'sources'                 => ['required', 'array', 'min:1'],
            'sources.*'               => ['required', 'string', Rule::in(ScrapingCampaign::ALLOWED_SOURCES)],

            'zones'                   => ['required', 'array', 'min:1', 'max:100'],
            'zones.*.type'            => ['required', 'string', Rule::in(ScrapingCampaign::ALLOWED_ZONE_TYPES)],
            'zones.*.code'            => ['required', 'string', 'max:10'],

            'max_companies'           => ['nullable', 'integer', 'min:1', 'max:50000'],
            'max_duration_minutes'    => ['nullable', 'integer', 'min:5', 'max:1440'],
            'max_requests_per_minute' => ['nullable', 'integer', 'min:1', 'max:100'],

            'per_source_limits'       => ['nullable', 'array'],
            'per_source_limits.*.rpm'   => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_source_limits.*.daily' => ['nullable', 'integer', 'min:1', 'max:50000'],

            'scheduled_at'            => ['nullable', 'date', 'after:now'],
            'expires_at'              => ['nullable', 'date', 'after:scheduled_at'],
        ];
    }
}
