<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoLinkCheck extends Model
{
    protected $table = 'seo_link_checks';
    protected $guarded = ['id'];
    protected $casts = ['checked_at' => 'datetime', 'http_status' => 'integer'];
}
