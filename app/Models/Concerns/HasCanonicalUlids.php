<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Str;

/** Canonical upper-case ULIDs everywhere (raw inserts use Str::ulid() too), so case-sensitive engines join correctly. */
trait HasCanonicalUlids
{
    use HasUlids;

    public function newUniqueId(): string
    {
        return (string) Str::ulid();
    }
}
