<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SearchConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'refresh_token'];

    protected $casts = ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'checked_at' => 'datetime'];
}
