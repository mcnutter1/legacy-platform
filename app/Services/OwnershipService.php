<?php

namespace App\Services;

use App\Support\AccessContext;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;

/** Effective-dated ownership resolution with entity look-through, total validation and cycle detection. */
final class OwnershipService
{
    private array $cache = [];

    public function __construct(private readonly string $workspaceId, private readonly string $date, private readonly string $basis = 'legal_title') {}

    private function interests(string $subjectType, string $subjectId): array
    {
        $k = $subjectType.'|'.$subjectId;

        return $this->cache[$k] ??= DB::table('ownership_interests')->where('workspace_id', $this->workspaceId)
            ->where('subject_type', $subjectType)->where('subject_id', $subjectId)->where('basis', $this->basis)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $this->date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $this->date))
            ->get(['owner_type', 'owner_id', 'fraction'])->all();
    }

    /** Fraction of a subject attributable to a person; null when ownership is unresolved. */
    public function fraction(string $personId, string $subjectType, string $subjectId, array $visited = [], array &$issues = []): ?string
    {
        $key = $subjectType.':'.$subjectId;
        if (isset($visited[$key])) {
            $issues[] = ['code' => 'ownership_cycle', 'message' => 'Circular ownership detected involving '.$key];

            return '0';
        }
        $visited[$key] = true;
        $rows = $this->interests($subjectType, $subjectId);
        if (! $rows) {
            if ($subjectType === 'account') {
                $a = DB::table('accounts')->where('id', $subjectId)->first(['owner_person_id', 'owner_entity_id']);
                if ($a?->owner_person_id) {
                    return $a->owner_person_id === $personId ? '1' : '0';
                }
                if ($a?->owner_entity_id) {
                    return $this->fraction($personId, 'entity', $a->owner_entity_id, $visited, $issues);
                }
            }

            return null;
        }
        $sum = '0';
        $total = '0';
        foreach ($rows as $r) {
            $f = Dec::norm($r->fraction, 12);
            $total = bcadd($total, $f, 12);
            if ($r->owner_type === 'person') {
                if ($r->owner_id === $personId) {
                    $sum = bcadd($sum, $f, 12);
                }
            } else {
                $inner = $this->fraction($personId, 'entity', $r->owner_id, $visited, $issues);
                if ($inner !== null) {
                    $sum = bcadd($sum, bcmul($f, $inner, 12), 12);
                }
            }
        }
        if (bccomp($total, '1.000000001', 12) > 0) {
            $issues[] = ['code' => 'ownership_exceeds_100', 'message' => "Ownership of {$key} totals ".bcmul($total, '100', 4).'% on '.$this->date];
        }

        return $sum;
    }

    public static function validateAll(string $workspaceId, string $date): array
    {
        $rows = DB::table('ownership_interests')->where('workspace_id', $workspaceId)->whereNull('archived_at')
            ->where(fn ($q) => $q->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $date))
            ->selectRaw('subject_type, subject_id, basis, sum(fraction) as total')->groupBy('subject_type', 'subject_id', 'basis')->get();
        $out = [];
        foreach ($rows as $r) {
            $t = Dec::norm($r->total, 12);
            if (bccomp($t, '1.000000001', 12) > 0) {
                $out[] = ['subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'basis' => $r->basis, 'total' => $t, 'problem' => 'exceeds_100'];
            } elseif (bccomp($t, '0.999999999', 12) < 0) {
                $out[] = ['subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'basis' => $r->basis, 'total' => $t, 'problem' => 'unallocated'];
            }
        }

        return $out;
    }
}
