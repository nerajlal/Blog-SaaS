<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRun extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['input' => 'encrypted:array', 'result' => 'encrypted:array', 'edited' => 'encrypted:array', 'usage' => 'array', 'revision' => 'integer', 'reviewed_at' => 'datetime'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }

    public function profileVersion()
    {
        return $this->belongsTo(AiProfileVersion::class, 'profile_version_id');
    }
}
