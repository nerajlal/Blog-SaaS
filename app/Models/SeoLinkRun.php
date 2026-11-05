<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoLinkRun extends Model
{
    protected $table = 'seo_link_runs';
    protected $guarded = ['id'];
    protected $casts = ['cursor' => 'integer', 'max_blog_id' => 'integer', 'processed' => 'integer', 'omitted' => 'integer'];
}
