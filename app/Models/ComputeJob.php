<?php

namespace App\Models;

use App\Models\Concerns\HasCanonicalUlids;
use Illuminate\Database\Eloquent\Model;

class ComputeJob extends Model
{
    use HasCanonicalUlids;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'result' => 'array',
            'metrics' => 'array',
            'lease_expires_at' => 'datetime',
            'available_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'queue' => $this->queue,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'result' => $this->status === 'succeeded' ? $this->result : null,
            'error' => in_array($this->status, ['failed', 'timed_out', 'retryable'], true) ? $this->error : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
