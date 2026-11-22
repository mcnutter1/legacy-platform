<?php

namespace App\Models;

use App\Models\Concerns\HasCanonicalUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use HasCanonicalUlids;

    protected $guarded = ['id'];

    protected $hidden = ['totp_secret'];

    protected function casts(): array
    {
        return [
            'central_roles' => 'array',
            'totp_secret' => 'encrypted',
            'totp_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    public function getRememberTokenName()
    {
        return '';
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function canCreateWorkspaces(): bool
    {
        $allowed = config('legacy.auth.workspace_creators', []);

        return in_array('*', $allowed, true) || in_array($this->central_principal_id, $allowed, true)
            || (bool) array_intersect((array) ($this->central_roles ?? []), (array) config('legacy.auth.creator_roles', []));
    }
}
