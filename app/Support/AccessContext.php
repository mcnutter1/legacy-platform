<?php

namespace App\Support;

use App\Models\AccessGrant;
use App\Models\Membership;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Per-request authorization context. Deny by default: data access comes only from active, explicit
 * grants on scopes. Administrators manage membership/governance but receive no implicit data access.
 */
final class AccessContext
{
    /** @var Collection<int, AccessGrant> */
    private Collection $grants;

    /** @param list<string>|null $tokenDomains MCP/OAuth token restriction (null = interactive session) */
    public function __construct(
        public readonly User $user,
        public readonly Workspace $workspace,
        public readonly Membership $membership,
        public readonly string $actorType = 'user',
        public readonly ?array $tokenDomains = null,
        public readonly ?array $tokenActions = null,
        public readonly ?string $clientId = null,
    ) {
        $this->grants = $membership->isActive()
            ? AccessGrant::query()->where('membership_id', $membership->id)->where('workspace_id', $workspace->id)
                ->active()->get()->filter(fn (AccessGrant $g) => $g->isActive())->values()
            : collect();
    }

    public static function current(): ?self
    {
        return app()->bound(self::class) ? app(self::class) : null;
    }

    public function isAdministrator(): bool
    {
        return $this->membership->isAdministrator() && $this->tokenDomains === null;
    }

    public function grants(): Collection
    {
        return $this->grants;
    }

    /** Token-level restriction applied on top of grants (OAuth/MCP clients never exceed the user). */
    private function tokenAllows(string $domain, string $action): bool
    {
        if ($this->tokenDomains !== null && ! in_array($domain, $this->tokenDomains, true)) {
            return false;
        }

        return $this->tokenActions === null || in_array($action, $this->tokenActions, true);
    }

    /** @return list<string> */
    public function scopeIds(string $domain, string $action = 'read', ?int $year = null): array
    {
        if (! $this->tokenAllows($domain, $action)) {
            return [];
        }
        $ids = [];
        foreach ($this->grants as $g) {
            if (! in_array($domain, $g->domains ?? [], true) || ! in_array($action, $g->actions ?? [], true)) {
                continue;
            }
            if ($year !== null && ! empty($g->years) && ! in_array($year, array_map('intval', $g->years), true)) {
                continue;
            }
            $ids[] = $g->scope_id;
        }

        return array_values(array_unique($ids));
    }

    public function can(string $domain, string $action, ?string $scopeId, ?int $year = null): bool
    {
        return $scopeId !== null && in_array($scopeId, $this->scopeIds($domain, $action, $year), true);
    }

    public function canAny(string $domain, string $action = 'read'): bool
    {
        return $this->scopeIds($domain, $action) !== [];
    }

    /**
     * Restrict a query to rows this principal may see. Grants with year limits become
     * (scope_id = ? AND year IN (...)) clauses so counts and aggregates never leak.
     */
    public function constrain(EloquentBuilder|QueryBuilder $q, string $domain, string $action = 'read', ?string $yearColumn = null, string $table = ''): EloquentBuilder|QueryBuilder
    {
        $prefix = $table !== '' ? $table.'.' : '';
        $q->where($prefix.'workspace_id', $this->workspace->id);
        if (! $this->tokenAllows($domain, $action)) {
            return $q->whereRaw('1 = 0');
        }
        $clauses = [];
        foreach ($this->grants as $g) {
            if (in_array($domain, $g->domains ?? [], true) && in_array($action, $g->actions ?? [], true)) {
                $clauses[] = [$g->scope_id, $yearColumn && ! empty($g->years) ? array_map('intval', $g->years) : null];
            }
        }
        if ($clauses === []) {
            return $q->whereRaw('1 = 0');
        }

        return $q->where(function ($w) use ($clauses, $prefix, $yearColumn) {
            foreach ($clauses as [$scope, $years]) {
                $w->orWhere(function ($c) use ($scope, $years, $prefix, $yearColumn) {
                    $c->where($prefix.'scope_id', $scope);
                    if ($years !== null) {
                        $c->whereIn($prefix.$yearColumn, $years);
                    }
                });
            }
        });
    }

    public function isSteward(string $scopeId): bool
    {
        return $this->tokenDomains === null && DB::table('scope_stewards')
            ->where('scope_id', $scopeId)->where('membership_id', $this->membership->id)->exists();
    }

    /** @return list<string> */
    public function stewardScopeIds(): array
    {
        if ($this->tokenDomains !== null) {
            return [];
        }

        return DB::table('scope_stewards')->where('workspace_id', $this->workspace->id)
            ->where('membership_id', $this->membership->id)->pluck('scope_id')->all();
    }

    public function cacheKey(): string
    {
        return hash('sha256', implode('|', [
            $this->workspace->id, $this->membership->id, $this->membership->access_version,
            $this->grants->map(fn ($g) => $g->id.':'.$g->updated_at)->implode(','),
            json_encode($this->tokenDomains), json_encode($this->tokenActions),
        ]));
    }
}
