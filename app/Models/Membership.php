<?php

namespace App\Models;

use App\Models\Concerns\HasCanonicalUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Membership extends Model
{
    use HasCanonicalUlids;

    public const ROLES = ['administrator', 'principal', 'member', 'advisor', 'dependent'];

    protected $guarded = ['id'];

    protected $hidden = ['invite_token_hash'];

    protected function casts(): array
    {
        return [
            'invite_expires_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_recovery_admin' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(AccessGrant::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    public function isAdministrator(): bool
    {
        return $this->isActive() && $this->role === 'administrator';
    }
}
