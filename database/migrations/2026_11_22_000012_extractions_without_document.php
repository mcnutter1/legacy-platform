<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Values provided by a connected assistant may arrive before (or without) a paper copy.
        Schema::table('document_extractions', function (Blueprint $t) {
            $t->ulid('document_id')->nullable()->change();
            $t->ulid('version_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Expand-only: rows without a document may exist.
    }
};
