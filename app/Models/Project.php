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
    ];

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
