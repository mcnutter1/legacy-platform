<?php

namespace App\Support;

use Illuminate\Database\Schema\Blueprint;

/** Shared column conventions so every workspace root gets the same identity, scope and tracking columns. */
final class SchemaMacros
{
    public static function register(): void
    {
        // ULID primary key + workspace ownership + composite (workspace_id, id) key for cross-table consistency.
        Blueprint::macro('root', function (bool $scoped = true) {
            /** @var Blueprint $this */
            $this->ulid('id')->primary();
            $this->ulid('workspace_id');
            $this->foreign('workspace_id')->references('id')->on('workspaces')->cascadeOnDelete();
            $this->unique(['workspace_id', 'id']);
            if ($scoped) {
                $this->ulid('scope_id');
                $this->foreign(['workspace_id', 'scope_id'])->references(['workspace_id', 'id'])->on('scopes');
                $this->index(['workspace_id', 'scope_id']);
            }
        });

        // Reference to another workspace root; the composite FK makes cross-workspace links impossible.
        Blueprint::macro('wsRef', function (string $column, string $table, bool $nullable = true) {
            /** @var Blueprint $this */
            $col = $this->ulid($column);
            if ($nullable) {
                $col->nullable();
            }
            $name = $this->getTable().'_workspace_id_'.$column.'_foreign';
            if (strlen($name) > 64) {
                // MySQL limits identifiers to 64 characters.
                $name = substr($this->getTable().'_'.$column, 0, 50).'_'.substr(md5($name), 0, 8).'_fk';
            }
            $this->foreign(['workspace_id', $column], $name)->references(['workspace_id', 'id'])->on($table);

            return $col;
        });

        Blueprint::macro('tracking', function () {
            /** @var Blueprint $this */
            $this->unsignedInteger('edit_version')->default(1);
            $this->ulid('created_by')->nullable();
            $this->ulid('updated_by')->nullable();
            $this->timestamps();
            $this->timestamp('archived_at')->nullable();
        });

        Blueprint::macro('money', function (string $column) {
            /** @var Blueprint $this */
            return $this->decimal($column, 20, 4)->nullable();
        });

        Blueprint::macro('effective', function () {
            /** @var Blueprint $this */
            $this->date('effective_from')->nullable();
            $this->date('effective_to')->nullable();
        });

        // Observation provenance: where the value came from and whether it is actual/provisional/projected/override.
        Blueprint::macro('provenance', function () {
            /** @var Blueprint $this */
            $this->string('source', 32)->default('manual');
            $this->ulid('source_ref')->nullable();
            $this->string('value_state', 16)->default('actual');
            $this->text('override_reason')->nullable();
            $this->date('override_expires_on')->nullable();
        });
    }
}
