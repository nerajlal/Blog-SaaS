<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoReview extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];
    protected $casts = ['project_id' => 'integer', 'created_at' => 'datetime'];
}
