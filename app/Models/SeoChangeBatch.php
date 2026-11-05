<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoChangeBatch extends Model
{
    protected $table = 'seo_change_batches';
    protected $guarded = ['id'];
    protected $casts = ['changes' => 'array', 'expires_at' => 'datetime', 'applied_at' => 'datetime', 'reverted_at' => 'datetime'];
}
