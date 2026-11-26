<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Selling: product options (kind, visibility, validity, on-sale window, stock,
 * buyer-chosen amounts, the record that made the product), bundles, and orders
 * with their lines, status history and reference numbers. Only additions:
 * existing products keep their values (defaults: virtual, public, no validity,
 * always on sale, no stock limit, fixed price).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'kind')) {
                $table->string('kind', 16)->default('virtual')->after('type');
                $table->string('visibility', 16)->default('public')->after('kind');
                // none | fixed (valid_from / valid_until) | duration (valid_for valid_unit from the purchase)
                $table->string('validity', 16)->default('none')->after('currency');
                $table->date('valid_from')->nullable()->after('validity');
                $table->date('valid_until')->nullable()->after('valid_from');
                $table->unsignedInteger('valid_for')->nullable()->after('valid_until');
                $table->string('valid_unit', 8)->nullable()->after('valid_for');
                $table->dateTime('sale_from')->nullable()->after('valid_unit');
                $table->dateTime('sale_until')->nullable()->after('sale_from');
                $table->boolean('track_stock')->default(false)->after('sale_until');
                $table->unsignedInteger('stock_quantity')->nullable()->after('track_stock');
                // Held by orders not cancelled (awaiting payment, under review, rejected for now, paid).
                $table->unsignedInteger('stock_reserved')->default(0)->after('stock_quantity');
                $table->boolean('custom_amount')->default(false)->after('stock_reserved');
                $table->decimal('min_amount', 12, 2)->nullable()->after('custom_amount');
                $table->decimal('max_amount', 12, 2)->nullable()->after('min_amount');
                // The record that made it (e.g. an event), so it finds its products again.
                $table->string('ref_type', 64)->nullable()->after('max_amount');
                $table->unsignedBigInteger('ref_id')->nullable()->after('ref_type');
                $table->index(['ref_type', 'ref_id']);
            }
        });

        if (! Schema::hasTable('product_bundle_items')) {
            Schema::create('product_bundle_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('bundle_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedInteger('quantity')->default(1);
                // The item's price inside the bundle; its own price is on the product.
                $table->decimal('price_in_bundle', 12, 2)->default(0);
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->unique(['bundle_id', 'product_id']);
                $table->foreign('bundle_id')->references('id')->on('products')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('order_sequences')) {
            Schema::create('order_sequences', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organisation_id');
                $table->string('prefix', 32);
                $table->unsignedInteger('last_number')->default(0);
                $table->timestamps();

                $table->unique(['organisation_id', 'prefix']);
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                $table->unsignedBigInteger('organisation_id');
                // e.g. TTV-MEET-0042: in the UPI note and the bank statement.
                $table->string('reference', 48);
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('buyer_name')->nullable();
                $table->string('buyer_email')->nullable();
                $table->string('buyer_phone', 32)->nullable();
                // awaiting_payment | under_review | paid | rejected | cancelled
                $table->string('status', 24)->default('awaiting_payment');
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('discount_total', 12, 2)->default(0);
                $table->decimal('total', 12, 2)->default(0);
                $table->string('currency', 3)->default('INR');
                // event_registration | membership | admin …
                $table->string('source', 32)->nullable();
                $table->string('ref_type', 64)->nullable();
                $table->unsignedBigInteger('ref_id')->nullable();
                $table->text('notes')->nullable();
                $table->dateTime('paid_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['organisation_id', 'reference']);
                $table->index(['organisation_id', 'status']);
                $table->index(['ref_type', 'ref_id']);
                $table->index('user_id');
                $table->foreign('organisation_id')->references('id')->on('organisations')->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('order_lines')) {
            Schema::create('order_lines', function (Blueprint $table) {
                $table->id();
                $table->string('uid', 26)->unique();
                $table->unsignedBigInteger('order_id');
                $table->unsignedBigInteger('product_id')->nullable();
                // The bundle this line came from (its items become lines), if any.
                $table->unsignedBigInteger('bundle_id')->nullable();
                $table->string('product_type', 40)->nullable();
                $table->string('description');
                $table->unsignedInteger('quantity')->default(1);
                // Per unit: the product's own price, the discount, the price paid.
                $table->decimal('unit_price', 12, 2)->default(0);
                $table->decimal('discount', 12, 2)->default(0);
                $table->decimal('price', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                // Filled when the order is paid (from the product's validity).
                $table->date('valid_from')->nullable();
                $table->date('valid_until')->nullable();
                // e.g. the attendee's name, gender, meal, whether an alumnus.
                $table->json('meta')->nullable();
                $table->unsignedInteger('sort')->default(0);
                $table->timestamps();

                $table->index(['order_id', 'sort']);
                $table->index('product_id');
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('order_status_histories')) {
            Schema::create('order_status_histories', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('order_id');
                $table->string('from_status', 24)->nullable();
                $table->string('to_status', 24);
                $table->text('note')->nullable();
                $table->unsignedBigInteger('changed_by')->nullable();
                $table->timestamp('created_at')->nullable();

                $table->index('order_id');
                $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('order_sequences');
        Schema::dropIfExists('product_bundle_items');

        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'kind')) {
                $table->dropIndex(['ref_type', 'ref_id']);
                $table->dropColumn(['kind', 'visibility', 'validity', 'valid_from', 'valid_until', 'valid_for', 'valid_unit',
                    'sale_from', 'sale_until', 'track_stock', 'stock_quantity', 'stock_reserved',
                    'custom_amount', 'min_amount', 'max_amount', 'ref_type', 'ref_id']);
            }
        });
    }
};
