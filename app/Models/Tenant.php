<?php

namespace App\Models;

use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasPublicUuid;

    protected $fillable = ['slug', 'name', 'kind', 'status'];

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function isPlatform(): bool
    {
        return $this->kind === 'platform';
    }
}
