<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $t) {
            $t->string('normalized_address')->nullable();
            $t->string('county', 32)->nullable();
            $t->string('municipality', 64)->nullable();
            $t->string('mun_code', 8)->nullable();
            $t->string('block', 16)->nullable();
            $t->string('lot', 16)->nullable();
            $t->string('qualifier', 16)->nullable();
            $t->string('parcel_pin', 40)->nullable();
            $t->decimal('latitude', 12, 8)->nullable();
            $t->decimal('longitude', 12, 8)->nullable();
            $t->string('property_class', 8)->nullable();
            $t->unsignedSmallInteger('year_built')->nullable();
            $t->unsignedInteger('living_area_sqft')->nullable();
            $t->decimal('lot_acres', 12, 4)->nullable();
            $t->money('assessed_land');
            $t->money('assessed_improvement');
            $t->money('assessed_total');
            $t->money('annual_property_tax');
            $t->string('deed_book', 16)->nullable();
            $t->string('deed_page', 16)->nullable();
            $t->date('deed_date')->nullable();
            $t->money('last_sale_price');
            $t->string('records_source', 64)->nullable();
            $t->timestamp('records_refreshed_at')->nullable();
            $t->timestamp('address_verified_at')->nullable();
            $t->index(['workspace_id', 'mun_code', 'block', 'lot'], 'properties_block_lot_idx');
        });

        Schema::table('assets', function (Blueprint $t) {
            $t->string('make', 64)->nullable();
            $t->string('model', 64)->nullable();
            $t->unsignedSmallInteger('model_year')->nullable();
            $t->string('registration_state', 8)->nullable();
            $t->date('registration_expires_on')->nullable();
        });

        Schema::create('property_lookups', function (Blueprint $t) {
            $t->root();
            $t->wsRef('property_id', 'properties');
            $t->string('query');
            $t->json('sources');
            $t->json('result');
            $t->string('selected_pin', 40)->nullable();
            $t->tracking();
            $t->index(['workspace_id', 'property_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_lookups');
        Schema::table('assets', fn (Blueprint $t) => $t->dropColumn(['make', 'model', 'model_year', 'registration_state', 'registration_expires_on']));
        Schema::table('properties', function (Blueprint $t) {
            $t->dropIndex('properties_block_lot_idx');
            $t->dropColumn(['normalized_address', 'county', 'municipality', 'mun_code', 'block', 'lot', 'qualifier', 'parcel_pin', 'latitude', 'longitude',
                'property_class', 'year_built', 'living_area_sqft', 'lot_acres', 'assessed_land', 'assessed_improvement', 'assessed_total', 'annual_property_tax',
                'deed_book', 'deed_page', 'deed_date', 'last_sale_price', 'records_source', 'records_refreshed_at', 'address_verified_at']);
        });
    }
};
