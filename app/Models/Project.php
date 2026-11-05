<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'workspace_id',
        'name',
        'subdomain',
        'custom_domain',
        'description',
        'api_key',
        'seo_settings',
    ];

    protected $hidden = ['seo_domain_token', 'seo_content_version', 'telemetry_key_hash'];

    protected $casts = ['seo_content_version' => 'integer', 'seo_settings' => 'array', 'seo_verified_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function blogs()
    {
        return $this->hasMany(Blog::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }
}
