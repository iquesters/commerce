<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Products get a name, type and price; discounts (automatic or a coupon
     * code) and a record of every use.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'name')) {
                $table->string('name')->default('')->after('uid');
            }
            if (! Schema::hasColumn('products', 'type')) {
                // membership | ticket | merchandise | donation | other
                $table->string('type', 40)->default('other')->after('name');
            }
            if (! Schema::hasColumn('products', 'description')) {
                $table->text('description')->nullable()->after('type');
            }
            if (! Schema::hasColumn('products', 'price')) {
                $table->decimal('price', 12, 2)->default(0)->after('description');
            }
            if (! Schema::hasColumn('products', 'currency')) {
                $table->string('currency', 3)->default('INR')->after('price');
            }
        });

        // The SKU becomes optional (memberships and tickets rarely have one).
        Schema::table('products', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
            $table->index(['organisation_id', 'type', 'status']);
        });

        Schema::table('product_metas', function (Blueprint $table) {
            $table->text('meta_value')->nullable()->change();
        });

        Schema::create('product_discounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('uid')->unique();
            $table->unsignedBigInteger('organisation_id')->nullable();
            // One product, or every product of a type (null: all of the organisation's products).
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_type', 40)->nullable();
            $table->string('name');
            // percent | fixed
            $table->string('kind', 10)->default('percent');
            $table->decimal('value', 12, 2);
            // Null: applied automatically. Otherwise the buyer enters it (stored upper case).
            $table->string('code', 40)->nullable();
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_until')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('max_uses_per_user')->nullable();
            $table->string('status', 16)->default('active');
            $table->unsignedBigInteger('created_by')->default(0);
            $table->unsignedBigInteger('updated_by')->default(0);
            $table->timestamps();

            $table->unique(['organisation_id', 'code']);
            $table->index(['organisation_id', 'status']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('product_discount_redemptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_discount_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            // What it was used for, e.g. a membership period or an event registration.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->decimal('amount', 12, 2);
            $table->timestamp('created_at')->nullable();

            $table->index(['product_discount_id', 'user_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->foreign('product_discount_id')->references('id')->on('product_discounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_discount_redemptions');
        Schema::dropIfExists('product_discounts');
        // The product columns stay: dropping them could lose data.
    }
};
