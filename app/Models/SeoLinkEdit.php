<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoLinkEdit extends Model
{
    protected $table = 'seo_link_edits';
    protected $guarded = ['id'];
    protected $casts = ['reverted_at' => 'datetime'];
}
