<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Renamed from an unprefixed file (so it runs before the dated upgrades);
        // existing installs already have these tables.
        if (Schema::hasTable('products')) {
            return;
        }

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->string('sku');
            $table->unsignedBigInteger('organisation_id')->nullable();
            $table->string('status')->default('unknown');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
        });

        Schema::create('product_metas', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('ref_parent')->unsigned()->nullable();
            $table->string('meta_key');
            $table->string('meta_value');
            $table->string('status')->default('unknown');
            $table->bigInteger('created_by')->default(0);
            $table->bigInteger('updated_by')->default(0);
            $table->timestamps();
            $table->foreign('ref_parent')->references('id')->on('products')->onDelete('cascade')->onUpdate('cascade');
        });
    }

    public function down(): void
    {
        // Intentionally empty: renamed from an unprefixed migration that existing
        // installs already ran; rolling it back must not drop live data.
    }
};
