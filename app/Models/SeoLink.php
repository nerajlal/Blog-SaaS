<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoLink extends Model
{
    protected $table = 'seo_links';
    protected $guarded = ['id'];
    protected $casts = ['occurrences' => 'integer'];
    public $timestamps = false;
    public function blog() { return $this->belongsTo(Blog::class); }
    public function targetBlog() { return $this->belongsTo(Blog::class, 'target_blog_id'); }
}
