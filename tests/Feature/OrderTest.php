<?php

namespace Iquesters\Commerce\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Iquesters\Commerce\Events\OrderPaid;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Commerce\Support\OrderException;
use Iquesters\Commerce\Tests\TestCase;
use Iquesters\Organisation\Models\Organisation;

/** Orders: bundles, references, sale windows, visibility, amounts, stock and statuses. */
class OrderTest extends TestCase
{
    private function orders(): OrderService
    {
        return app(OrderService::class);
    }

    /** The meet: pass ₹600, membership ₹300 (₹0 in the bundle), the bundle ₹600. */
    private function meetProducts($organisation): array
    {
        $pass = $this->makeProduct($organisation, ['name' => 'Meet pass', 'type' => 'ticket', 'price' => 600]);
        $membership = $this->makeProduct($organisation, ['name' => 'Membership 2027', 'type' => 'membership', 'price' => 300,
            'validity' => Product::VALIDITY_FIXED, 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31']);
        $bundle = $this->makeProduct($organisation, ['name' => 'Pass with membership', 'type' => 'bundle', 'price' => 600]);
        $bundle->bundleItems()->create(['product_id' => $pass->id, 'quantity' => 1, 'price_in_bundle' => 600, 'sort' => 0]);
        $bundle->bundleItems()->create(['product_id' => $membership->id, 'quantity' => 1, 'price_in_bundle' => 0, 'sort' => 1]);

        return [$pass, $membership, $bundle];
    }

    public function test_a_bundle_becomes_one_line_per_item_with_its_discount_and_the_reference_counts_up(): void
    {
        $organisation = $this->makeOrganisation();
        [$pass, $membership, $bundle] = $this->meetProducts($organisation);

        $order = $this->orders()->create($organisation, ['name' => 'Rahul Sen', 'phone' => '9800000001'], [
            ['product' => $bundle, 'meta' => ['attendee' => 'Rahul Sen', 'meal' => 'veg']],
            ['product' => $pass->uid, 'meta' => ['attendee' => 'Spouse', 'meal' => 'non-veg']],
        ], ['prefix' => 'ttv meet', 'source' => 'event_registration']);

        $this->assertSame('TTV-MEET-0001', $order->reference);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertSame([['Meet pass', '600.00', '0.00', '600.00'], ['Membership 2027', '300.00', '300.00', '0.00'], ['Meet pass', '600.00', '0.00', '600.00']],
            $order->lines->map(fn ($l) => [$l->description, $l->unit_price, $l->discount, $l->price])->all());
        $this->assertSame(['1500.00', '300.00', '1200.00'], [$order->subtotal, $order->discount_total, $order->total]);
        $this->assertSame('Pass with membership', $order->lines[1]->meta['bundle']);

        $this->assertSame('TTV-MEET-0002', $this->orders()->create($organisation, [], [['product' => $pass]], ['prefix' => 'TTV-MEET'])->reference);
        $this->assertSame('ORD-0001', $this->orders()->create($organisation, [], [['product' => $pass]])->reference);
    }

    public function test_paying_fills_the_validity_and_fires_order_paid(): void
    {
        Event::fake([OrderPaid::class]);
        $organisation = $this->makeOrganisation();
        [, , $bundle] = $this->meetProducts($organisation);
        $order = $this->orders()->create($organisation, [], [['product' => $bundle]]);

        $order = $this->orders()->markUnderReview($order, 'UTR 123456789012');
        $order = $this->orders()->reject($order, 'Amount does not match');
        $this->assertSame(Order::STATUS_REJECTED, $order->status);
        $order = $this->orders()->markUnderReview($order, 'Sent again');
        $order = $this->orders()->markPaid($order, 'Verified', 1);

        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->paid_at);
        $membershipLine = $order->lines->firstWhere('product_type', 'membership');
        $this->assertSame(['2027-01-01', '2027-12-31'], [$membershipLine->valid_from->format('Y-m-d'), $membershipLine->valid_until->format('Y-m-d')]);
        Event::assertDispatched(OrderPaid::class, fn ($e) => $e->order->is($order));
        $this->assertSame(['awaiting_payment', 'under_review', 'rejected', 'under_review', 'paid'], $order->histories->pluck('to_status')->all());

        // Paid: nothing moves it any more.
        $this->expectException(OrderException::class);
        $this->orders()->cancel($order);
    }

    public function test_a_free_order_is_paid_at_once(): void
    {
        Event::fake([OrderPaid::class]);
        $organisation = $this->makeOrganisation();
        $crew = $this->makeProduct($organisation, ['name' => 'Crew', 'type' => 'ticket', 'price' => 0, 'visibility' => Product::VISIBILITY_PRIVATE]);

        $order = $this->orders()->create($organisation, ['name' => 'Sound man'], [['product' => $crew]], ['admin' => true]);

        $this->assertSame(Order::STATUS_PAID, $order->status);
        Event::assertDispatched(OrderPaid::class);
    }

    public function test_private_and_off_sale_products_only_for_admins(): void
    {
        $organisation = $this->makeOrganisation();
        $crew = $this->makeProduct($organisation, ['name' => 'Crew', 'type' => 'ticket', 'price' => 10, 'visibility' => Product::VISIBILITY_PRIVATE]);
        $closed = $this->makeProduct($organisation, ['name' => 'Early bird', 'type' => 'ticket', 'price' => 500, 'sale_until' => now()->subDay()]);

        foreach ([$crew, $closed] as $product) {
            try {
                $this->orders()->create($organisation, [], [['product' => $product]]);
                $this->fail("{$product->name} was sold to the public.");
            } catch (OrderException $e) {
                $this->assertArrayHasKey('items.0', $e->errors);
            }
            $this->assertNotNull($this->orders()->create($organisation, [], [['product' => $product]], ['admin' => true]));
        }

        // Another organisation's product is never sold here.
        $this->expectException(OrderException::class);
        $this->orders()->create($this->makeOrganisation('Other'), [], [['product' => $closed]], ['admin' => true]);
    }

    public function test_a_donation_respects_its_limits(): void
    {
        $organisation = $this->makeOrganisation();
        $donation = $this->makeProduct($organisation, ['name' => 'Donation', 'type' => 'donation', 'price' => 0, 'custom_amount' => true, 'min_amount' => 50, 'max_amount' => 100000]);

        foreach ([null, 10, 200000] as $amount) {
            try {
                $this->orders()->create($organisation, [], [['product' => $donation, 'amount' => $amount]]);
                $this->fail("Amount {$amount} was accepted.");
            } catch (OrderException) {
            }
        }
        $this->assertSame('500.00', $this->orders()->create($organisation, [], [['product' => $donation, 'amount' => 500]])->total);
    }

    public function test_stock_is_held_and_given_back_on_cancel(): void
    {
        $organisation = $this->makeOrganisation();
        $seat = $this->makeProduct($organisation, ['name' => 'Auditorium seat', 'type' => 'ticket', 'price' => 100, 'track_stock' => true, 'stock_quantity' => 2]);

        $first = $this->orders()->create($organisation, [], [['product' => $seat, 'quantity' => 2]]);
        $this->assertSame(0, $seat->fresh()->stockLeft());

        try {
            $this->orders()->create($organisation, [], [['product' => $seat]]);
            $this->fail('Sold beyond the stock.');
        } catch (OrderException $e) {
            $this->assertStringContainsString('sold out', $e->getMessage());
        }

        $this->orders()->cancel($first, 'Changed plans');
        $this->assertSame(2, $seat->fresh()->stockLeft());
    }

    public function test_admins_manage_bundles_and_see_their_organisations_orders(): void
    {
        $organisation = $this->makeOrganisation();
        [$pass, $membership] = $this->meetProducts($organisation);
        $admin = $this->memberOf($organisation, 'admin');
        $this->actingAs($admin);

        $bundle = ['organisation_id' => $organisation->id, 'name' => 'Pass + membership', 'type' => 'bundle', 'price' => 600];
        // The items' prices in the bundle must add up to its price.
        $this->postJson('/products', $bundle + ['bundle_items' => [
            ['product_id' => $pass->id, 'price_in_bundle' => 400], ['product_id' => $membership->id, 'price_in_bundle' => 100],
        ]])->assertStatus(422)->assertJsonValidationErrors('bundle_items');
        $uid = $this->postJson('/products', $bundle + ['bundle_items' => [
            ['product_id' => $pass->id, 'price_in_bundle' => 400], ['product_id' => $membership->id, 'price_in_bundle' => 200],
        ]])->assertCreated()->json('data.uid');
        $this->getJson("/products/data/{$uid}")->assertJsonCount(2, 'response_schema.data.bundle_items');

        $order = $this->orders()->create($organisation, ['name' => 'Ann'], [['product' => $uid]]);
        $other = $this->orders()->create($this->makeOrganisation('Other'), [], [['product' => $this->makeProduct(Organisation::where('name', 'Other')->first(), ['price' => 5])]]);
        $this->getJson('/orders/data')->assertOk()->assertJsonCount(1, 'response_schema.data')
            ->assertJsonPath('response_schema.data.0.reference', $order->reference);
        $this->get("/orders/{$order->uid}/summary")->assertOk()->assertSee('Membership 2027')->assertSee('−INR 100.00');
        $this->getJson("/orders/data/{$other->uid}")->assertNotFound();

        $this->postJson("/orders/{$order->uid}/cancel", ['reason' => 'Duplicate'])->assertOk();
        $this->assertSame(Order::STATUS_CANCELLED, $order->fresh()->status);
    }
}
