<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Blog extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'user_id', 'category_id', 'title', 'slug', 'excerpt', 'content', 'cover_image', 'status', 'image_credits', 'author_name', 'author_title', 'author_avatar', 'published_at', 'seo_settings', 'cover_image_alt', 'cover_image_caption', 'cover_image_decorative', 'is_cornerstone', 'content_reviewed_at'];

    protected $hidden = ['seo_settings', 'is_cornerstone', 'content_reviewed_at', 'seo_title_key', 'seo_focus_key', 'seo_analyses', 'latest_seo_analysis'];

    protected $casts = [
        'seo_settings' => 'array',
        'is_cornerstone' => 'boolean',
        'content_reviewed_at' => 'datetime',
        'cover_image_decorative' => 'boolean',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Blog $blog) {
            $blog->seo_title_key = \App\Services\Seo\AnalysisText::key($blog->title);
            $blog->seo_focus_key = \App\Services\Seo\AnalysisText::key($blog->seo_settings['focus_keyphrase'] ?? null);
        });
        $invalidate = function (Blog $blog) {
            $ids = array_filter(array_unique([$blog->project_id, $blog->getOriginal('project_id')]));
            Project::withoutTimestamps(fn () => Project::whereIn('id', $ids)->increment('seo_content_version'));
            $blog->unsetRelation('project');
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    public function seoAnalyses()
    {
        return $this->hasMany(SeoAnalysis::class);
    }

    public function latestSeoAnalysis()
    {
        return $this->hasOne(SeoAnalysis::class)->latestOfMany();
    }

    public function seoReviews()
    {
        return $this->hasMany(SeoReview::class);
    }

    public function scopeLive($query)
    {
        return $query->where('status', 'published')->where(fn ($q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()));
    }

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
