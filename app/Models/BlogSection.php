<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BlogSection extends Model
{
    use HasFactory;

    protected $fillable = ['blog_id', 'text_content', 'image_path', 'order', 'image_alt', 'image_caption', 'image_decorative'];

    protected $casts = ['image_decorative' => 'boolean'];

    protected function textContent(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn ($value) => \App\Support\RichText::clean($value),
            set: fn ($value) => \App\Support\RichText::clean($value),
        );
    }

    protected static function booted(): void
    {
        $invalidate = function (BlogSection $section) {
            $ids = Blog::whereIn('id', array_filter([$section->blog_id, $section->getOriginal('blog_id')]))->pluck('project_id');
            Project::whereIn('id', $ids)->increment('seo_content_version');
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }
}
