<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['workspace_id', 'slug', 'name', 'color', 'description', 'rules'];

    protected function casts(): array
    {
        return ['rules' => 'array'];
    }

    public function companies()
    {
        return $this->belongsToMany(Company::class);
    }
}
