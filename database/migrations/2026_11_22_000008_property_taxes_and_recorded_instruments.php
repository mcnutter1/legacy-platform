<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_tax_years', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->unsignedSmallInteger('tax_year');
            $t->money('assessed_land');
            $t->money('assessed_improvement');
            $t->money('assessed_total');
            $t->money('exemptions');
            $t->money('tax_amount');
            $t->string('property_class', 8)->nullable();
            $t->string('source', 24)->default('manual');
            $t->date('as_of')->nullable();
            $t->text('notes')->nullable();
            $t->tracking();
            $t->unique(['property_id', 'tax_year']);
        });

        Schema::create('property_tax_installments', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->unsignedSmallInteger('tax_year');
            $t->unsignedTinyInteger('quarter');
            $t->date('due_on');
            $t->money('amount');
            $t->string('status', 16)->default('projected');
            $t->date('paid_on')->nullable();
            $t->money('paid_amount');
            $t->wsRef('expense_id', 'property_expenses');
            $t->string('source', 24)->default('manual');
            $t->text('notes')->nullable();
            $t->tracking();
            $t->unique(['property_id', 'tax_year', 'quarter']);
            $t->index(['workspace_id', 'status', 'due_on']);
        });

        Schema::create('recorded_instruments', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties', false);
            $t->string('kind', 24);
            $t->date('recorded_on')->nullable();
            $t->string('book', 16)->nullable();
            $t->string('page', 16)->nullable();
            $t->string('instrument_number', 40)->nullable();
            $t->text('grantor')->nullable();
            $t->text('grantee')->nullable();
            $t->money('consideration');
            $t->string('county', 32)->nullable();
            $t->string('source', 24)->default('manual');
            $t->wsRef('document_id', 'documents');
            $t->wsRef('liability_id', 'liabilities');
            $t->text('notes')->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recorded_instruments');
        Schema::dropIfExists('property_tax_installments');
        Schema::dropIfExists('property_tax_years');
    }
};
