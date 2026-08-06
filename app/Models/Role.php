<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    protected $fillable = ['code', 'label_fr', 'label_ar', 'is_staff'];

    protected function casts(): array
    {
        return ['is_staff' => 'boolean'];
    }
}
