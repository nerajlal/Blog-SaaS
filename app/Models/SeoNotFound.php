<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoNotFound extends Model
{
    protected $table = 'seo_404_events';
    protected $guarded = ['id'];
    protected $casts = ['last_seen' => 'datetime', 'hits' => 'integer'];
    public $timestamps = false;
}
