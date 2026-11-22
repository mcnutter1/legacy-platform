<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\HasCanonicalUlids;
use Illuminate\Database\Eloquent\Model;

class AccessGrant extends Model
{
    use HasCanonicalUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'domains' => 'array',
            'actions' => 'array',
            'years' => 'array',
            'conditions' => 'array',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereNull('revoked_at')
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture())
            && (! ($this->conditions['requires_approval'] ?? false) || $this->approved_by !== null);
    }
}
