<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class BlogSection extends Model
{
    use HasFactory;

    protected $fillable = ['blog_id', 'text_content', 'image_path', 'order'];

    public function blog()
    {
        return $this->belongsTo(Blog::class);
    }
}
