<?php

namespace App\Models;

use App\Models\Concerns\HasCanonicalUlids;
use Illuminate\Database\Eloquent\Model;

class Scope extends Model
{
    use HasCanonicalUlids;

    protected $guarded = ['id'];

    public function stewardIds(): array
    {
        return \DB::table('scope_stewards')->where('scope_id', $this->id)->pluck('membership_id')->all();
    }
}
