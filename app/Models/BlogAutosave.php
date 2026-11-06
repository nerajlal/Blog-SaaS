<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogAutosave extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['snapshot'];
    protected $casts = ['snapshot' => 'encrypted:array', 'version' => 'integer'];
}
