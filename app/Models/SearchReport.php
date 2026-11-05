<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchReport extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['rows' => 'array', 'fetched_at' => 'datetime'];
}
