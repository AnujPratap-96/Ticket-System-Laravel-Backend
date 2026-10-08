<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Article extends Model
{
    protected $fillable = ['title', 'slug', 'summary', 'body', 'is_published', 'department_id', 'author_id', 'category'];

    protected $casts = ['is_published' => 'boolean', 'views' => 'integer', 'helpful_count' => 'integer', 'unhelpful_count' => 'integer'];
}
