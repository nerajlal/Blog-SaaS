<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiProfile extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['data' => 'encrypted:array', 'revision' => 'integer'];
}
