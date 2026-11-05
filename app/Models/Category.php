<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'name', 'slug'];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function blogs()
    {
        return $this->hasMany(Blog::class);
    }
}
