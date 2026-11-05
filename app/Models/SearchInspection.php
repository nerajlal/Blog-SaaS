<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchInspection extends Model
{
    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }

    protected $guarded = ['id'];

    protected $casts = ['result' => 'array', 'checked_at' => 'datetime'];
}
