<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Blog extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'user_id', 'category_id', 'title', 'slug', 'excerpt', 'content', 'cover_image', 'status', 'image_credits', 'author_name', 'author_title', 'author_avatar', 'published_at'];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function sections()
    {
        return $this->hasMany(BlogSection::class)->orderBy('order');
    }
}
