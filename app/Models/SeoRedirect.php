<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoRedirect extends Model
{
    protected $table = 'seo_redirects';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'status_code' => 'integer'];
}
