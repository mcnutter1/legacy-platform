<?php

namespace App\Models;

use App\Models\Concerns\HasCanonicalUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic workspace record backed by a registry-defined table (see App\Resources\Registry).
 * Table and casts travel with every instance so query results keep their definition.
 */
class Record extends Model
{
    use HasCanonicalUlids;

    protected $guarded = ['id', 'workspace_id'];

    public static function for(string $table, array $casts = []): static
    {
        $m = new static;
        $m->setTable($table);
        $m->mergeCasts($casts);

        return $m;
    }

    public function newInstance($attributes = [], $exists = false)
    {
        $model = parent::newInstance($attributes, $exists);
        $model->mergeCasts($this->getCasts());

        return $model;
    }
}
