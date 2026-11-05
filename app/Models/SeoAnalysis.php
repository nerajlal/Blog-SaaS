<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoAnalysis extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['project_id' => 'integer', 'missing_metadata' => 'boolean', 'result' => 'array', 'valid_until' => 'datetime', 'created_at' => 'datetime'];
}
