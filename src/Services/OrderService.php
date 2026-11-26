<?php

namespace Iquesters\Commerce\Services;

use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Events\OrderPaid;
use Iquesters\Commerce\Events\OrderStatusChanged;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\OrderStatusHistory;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Support\OrderException;
use Iquesters\Commerce\Enums\Module;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\System\Traits\Loggable;
use Iquesters\Organisation\Models\Organisation;

/**
 * Makes orders and moves them between statuses. It checks what can be bought
 * (active, on sale, public unless an admin adds it, stock, buyer-chosen amount
 * limits), turns a bundle into one line per item (own price, discount, price in
 * the bundle), copies the prices onto the lines, gives the order its reference
 * (e.g. TTV-MEET-0042) and holds the stock. Paying is the payment module's job;
 * it calls markUnderReview / markPaid / reject here.
 */
class OrderService
{
    use Loggable;

    /**
     * @param  array{name?: ?string, email?: ?string, phone?: ?string, user_id?: ?int}  $buyer
     * @param  array<int, array{product: Product|string|int, quantity?: int, amount?: float|string|null, meta?: array}>  $items
     * @param  array{prefix?: ?string, source?: ?string, ref_type?: ?string, ref_id?: ?int, admin?: bool, by?: ?int, notes?: ?string}  $options
     *
     * @throws OrderException
     */
    public function create(Organisation $organisation, array $buyer, array $items, array $options = []): Order
    {
        if ($items === []) {
            throw OrderException::for('items', 'Choose at least one item.');
        }
        $admin = (bool) ($options['admin'] ?? false);
        $by = $options['by'] ?? null;

        $order = DB::transaction(function () use ($organisation, $buyer, $items, $options, $admin, $by) {
            $lines = [];
            $reservations = [];
            $sort = 0;

            foreach (array_values($items) as $i => $item) {
                $product = $this->product($organisation, $item['product'] ?? null, "items.{$i}");
                $quantity = max(1, (int) ($item['quantity'] ?? 1));
                $meta = (array) ($item['meta'] ?? []);
                $this->assertBuyable($product, $admin, "items.{$i}");

                if ($product->isBundle()) {
                    $bundleItems = $product->bundleItems()->with('product')->get();
                    if ($bundleItems->isEmpty()) {
                        throw OrderException::for("items.{$i}", "{$product->name} has no items.");
                    }
                    $reservations[$product->id] = ($reservations[$product->id] ?? 0) + $quantity;
                    foreach ($bundleItems as $bundleItem) {
                        $inner = $bundleItem->product;
                        if (! $inner) {
                            throw OrderException::for("items.{$i}", "An item of {$product->name} no longer exists.");
                        }
                        $count = $quantity * max(1, (int) $bundleItem->quantity);
                        $reservations[$inner->id] = ($reservations[$inner->id] ?? 0) + $count;
                        $own = (float) $inner->price;
                        $paid = (float) $bundleItem->price_in_bundle;
                        $lines[] = $this->line($inner, $count, $own, max(0, $own - $paid), $paid, $meta + ['bundle' => $product->name], $sort++, $product->id);
                    }

                    continue;
                }

                if ($product->custom_amount) {
                    $amount = $this->customAmount($product, $item['amount'] ?? null, "items.{$i}");
                    $lines[] = $this->line($product, $quantity, $amount, 0, $amount, $meta, $sort++);
                } else {
                    $price = (float) $product->price;
                    $lines[] = $this->line($product, $quantity, $price, 0, $price, $meta, $sort++);
                }
                $reservations[$product->id] = ($reservations[$product->id] ?? 0) + $quantity;
            }

            $this->reserve($reservations);

            $subtotal = array_sum(array_map(fn ($l) => $l['unit_price'] * $l['quantity'], $lines));
            $discount = array_sum(array_map(fn ($l) => $l['discount'] * $l['quantity'], $lines));
            $total = array_sum(array_map(fn ($l) => $l['line_total'], $lines));

            $order = Order::create([
                'organisation_id' => $organisation->id,
                'reference' => $this->nextReference($organisation, $options['prefix'] ?? null),
                'user_id' => $buyer['user_id'] ?? null,
                'buyer_name' => $buyer['name'] ?? null,
                'buyer_email' => $buyer['email'] ?? null,
                'buyer_phone' => $buyer['phone'] ?? null,
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'subtotal' => round($subtotal, 2),
                'discount_total' => round($discount, 2),
                'total' => round($total, 2),
                'currency' => $lines[0]['currency'] ?? 'INR',
                'source' => $options['source'] ?? null,
                'ref_type' => $options['ref_type'] ?? null,
                'ref_id' => $options['ref_id'] ?? null,
                'notes' => $options['notes'] ?? null,
                'created_by' => $by,
                'updated_by' => $by,
            ]);

            foreach ($lines as $index => $line) {
                unset($line['currency']);
                // The first line keeps what the order holds, given back if it's cancelled.
                if ($index === 0) {
                    $line['meta'] = array_merge((array) $line['meta'], ['_reserved' => $reservations]);
                }
                $order->lines()->create($line);
            }

            $this->history($order, null, Order::STATUS_AWAITING_PAYMENT, null, $by);

            return $order;
        });

        static::logInfo('Order created', ['order' => $order->reference, 'total' => (string) $order->total, 'by' => $by]);

        // Nothing to pay (e.g. crew, children only): paid straight away.
        if ((float) $order->total <= 0) {
            return $this->markPaid($order, 'Nothing to pay', $by);
        }

        return $order->fresh('lines');
    }

    /** Proof of payment sent (the payment module). */
    public function markUnderReview(Order $order, ?string $note = null, ?int $by = null): Order
    {
        return $this->move($order, Order::STATUS_UNDER_REVIEW, $note, $by);
    }

    /** Paid: the lines get their validity, and OrderPaid is fired once the change is saved. */
    public function markPaid(Order $order, ?string $note = null, ?int $by = null): Order
    {
        $from = null;
        $order = DB::transaction(function () use ($order, $note, $by, &$from) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertMove($order, Order::STATUS_PAID);
            $from = $order->status;
            $paidAt = now();
            foreach ($order->lines()->with('product')->get() as $line) {
                [$validFrom, $validUntil] = $line->product?->validityFor($paidAt) ?? [null, null];
                $line->update(['valid_from' => $validFrom, 'valid_until' => $validUntil]);
            }
            $order->update(['status' => Order::STATUS_PAID, 'paid_at' => $paidAt, 'updated_by' => $by]);
            $this->history($order, $from, Order::STATUS_PAID, $note, $by);

            return $order;
        });

        static::logInfo('Order paid', ['order' => $order->reference, 'by' => $by]);
        OrderStatusChanged::dispatch($order, $from, Order::STATUS_PAID, $note);
        OrderPaid::dispatch($order->fresh('lines'));

        return $order->fresh('lines');
    }

    /** The payment wasn't accepted; the buyer may send proof again. */
    public function reject(Order $order, string $reason, ?int $by = null): Order
    {
        return $this->move($order, Order::STATUS_REJECTED, $reason, $by);
    }

    /** Cancelled: the stock it held is given back. */
    public function cancel(Order $order, ?string $reason = null, ?int $by = null): Order
    {
        $order = DB::transaction(function () use ($order, $reason, $by) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertMove($order, Order::STATUS_CANCELLED);
            $from = $order->status;
            $reserved = (array) ($order->lines()->orderBy('id')->first()?->meta['_reserved'] ?? []);
            foreach ($reserved as $productId => $quantity) {
                $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
                if ($product && $product->track_stock) {
                    $product->forceFill(['stock_reserved' => max(0, (int) $product->stock_reserved - (int) $quantity)])->save();
                }
            }
            $order->update(['status' => Order::STATUS_CANCELLED, 'updated_by' => $by]);
            $this->history($order, $from, Order::STATUS_CANCELLED, $reason, $by);

            return $order;
        });
        OrderStatusChanged::dispatch($order, null, Order::STATUS_CANCELLED, $reason);

        return $order->fresh('lines');
    }

    private function move(Order $order, string $status, ?string $note, ?int $by): Order
    {
        $from = null;
        $order = DB::transaction(function () use ($order, $status, $note, $by, &$from) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $this->assertMove($order, $status);
            $from = $order->status;
            $order->update(['status' => $status, 'updated_by' => $by]);
            $this->history($order, $from, $status, $note, $by);

            return $order;
        });
        OrderStatusChanged::dispatch($order, $from, $status, $note);

        return $order->fresh('lines');
    }

    private function assertMove(Order $order, string $status): void
    {
        if (! $order->canMoveTo($status)) {
            throw OrderException::for('status', sprintf("This order is %s: it can't be %s.", strtolower($order->statusLabel()), strtolower(Order::LABELS[$status] ?? $status)));
        }
    }

    private function product(Organisation $organisation, Product|string|int|null $product, string $field): Product
    {
        $found = match (true) {
            $product instanceof Product => $product,
            is_int($product) => Product::query()->find($product),
            is_string($product) && $product !== '' => Product::query()->where('uid', $product)->first(),
            default => null,
        };
        if (! $found || (int) $found->organisation_id !== (int) $organisation->id) {
            throw OrderException::for($field, 'This item is not sold here.');
        }

        return $found;
    }

    private function assertBuyable(Product $product, bool $admin, string $field): void
    {
        if (! $product->isActive()) {
            throw OrderException::for($field, "{$product->name} is not available.");
        }
        // Admins may still add after the sale ends (e.g. a late attendee) and add private items (crew).
        if (! $admin && ! $product->isOnSale()) {
            throw OrderException::for($field, "{$product->name} is not on sale now.");
        }
        if (! $admin && $product->isPrivate()) {
            throw OrderException::for($field, 'This item is not sold here.');
        }
    }

    private function customAmount(Product $product, mixed $amount, string $field): float
    {
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw OrderException::for($field, "Enter the amount for {$product->name}.");
        }
        $amount = round((float) $amount, 2);
        if ($product->min_amount !== null && $amount < (float) $product->min_amount) {
            throw OrderException::for($field, "{$product->name}: at least ".number_format((float) $product->min_amount, 2).'.');
        }
        if ($product->max_amount !== null && $amount > (float) $product->max_amount) {
            throw OrderException::for($field, "{$product->name}: at most ".number_format((float) $product->max_amount, 2).'.');
        }

        return $amount;
    }

    /** Hold the stock (locked rows); sold out → OrderException. */
    private function reserve(array $reservations): void
    {
        foreach ($reservations as $productId => $quantity) {
            $product = Product::query()->whereKey($productId)->lockForUpdate()->first();
            if (! $product || ! $product->track_stock) {
                continue;
            }
            $left = $product->stockLeft();
            if ($left < $quantity) {
                throw OrderException::for('items', $left > 0 ? "Only {$left} left of {$product->name}." : "{$product->name} is sold out.");
            }
            $product->forceFill(['stock_reserved' => (int) $product->stock_reserved + $quantity])->save();
        }
    }

    private function line(Product $product, int $quantity, float $unitPrice, float $discount, float $price, array $meta, int $sort, ?int $bundleId = null): array
    {
        return [
            'product_id' => $product->id,
            'bundle_id' => $bundleId,
            'product_type' => $product->type,
            'description' => $product->name,
            'quantity' => $quantity,
            'unit_price' => round($unitPrice, 2),
            'discount' => round($discount, 2),
            'price' => round($price, 2),
            'line_total' => round($price * $quantity, 2),
            'meta' => $meta ?: null,
            'sort' => $sort,
            'currency' => $product->currency ?: 'INR',
        ];
    }

    /** "TTV-MEET-0042": the organisation's next number for that prefix (locked, so two orders never share one). */
    public function nextReference(Organisation $organisation, ?string $prefix): string
    {
        // No prefix given: commerce's setting (order_reference_prefix, default ORD).
        $prefix = ($prefix === null || trim($prefix) === '') ? (string) ConfProvider::from(Module::COMMERCE)->order_reference_prefix : $prefix;
        $prefix = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9-]+/', '-', $prefix), '-')) ?: 'ORD';
        $prefix = mb_substr($prefix, 0, 32);

        DB::table('order_sequences')->insertOrIgnore([
            'organisation_id' => $organisation->id, 'prefix' => $prefix, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $row = DB::table('order_sequences')->where('organisation_id', $organisation->id)->where('prefix', $prefix)->lockForUpdate()->first();
        $next = (int) $row->last_number + 1;
        DB::table('order_sequences')->where('id', $row->id)->update(['last_number' => $next, 'updated_at' => now()]);

        return sprintf('%s-%04d', $prefix, $next);
    }

    private function history(Order $order, ?string $from, string $to, ?string $note, ?int $by): void
    {
        OrderStatusHistory::create(['order_id' => $order->id, 'from_status' => $from, 'to_status' => $to, 'note' => $note, 'changed_by' => $by]);
    }
}
