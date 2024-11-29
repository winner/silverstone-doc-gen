<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LineItemGroup extends Model
{
    protected $guarded = [];
    protected $casts = [
        'line_item_indexes' => 'array',
    ];
}
