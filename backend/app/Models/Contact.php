<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'company_id', 'first_name', 'last_name', 'normalized_hash',
        'title', 'role', 'email', 'email_status', 'email_score', 'email_confidence',
        'phone', 'linkedin_url', 'twitter_url',
        'sources', 'discovery_source', 'metadata',
    ];

    protected function casts(): array
    {
        return ['sources' => 'array', 'metadata' => 'array'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}
