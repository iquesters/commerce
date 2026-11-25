<?php

namespace Iquesters\Commerce\Tests\Feature;

use Iquesters\Commerce\Models\ProductDiscount;
use Iquesters\Commerce\Support\Pricing;
use Iquesters\Commerce\Tests\TestCase;

class PricingTest extends TestCase
{
    private function pricing(): Pricing
    {
        return app(Pricing::class);
    }

    private function discount(array $attributes): ProductDiscount
    {
        return ProductDiscount::create($attributes + ['name' => 'Discount', 'kind' => 'percent', 'status' => 'active']);
    }

    public function test_no_discount_is_the_list_price(): void
    {
        $product = $this->makeProduct($this->makeOrganisation());

        $quote = $this->pricing()->quote($product);
        $this->assertSame(1000.0, $quote->finalPrice());
        $this->assertNull($quote->discount);
    }

    public function test_the_best_automatic_discount_applies(): void
    {
        $organisation = $this->makeOrganisation();
        $product = $this->makeProduct($organisation);
        $this->discount(['organisation_id' => $organisation->id, 'product_id' => $product->id, 'name' => 'Ten', 'value' => 10]);
        $this->discount(['organisation_id' => $organisation->id, 'product_type' => 'membership', 'name' => 'Flat 300', 'kind' => 'fixed', 'value' => 300]);
        // Not this product: another type, another organisation, expired, not started, inactive.
        $this->discount(['organisation_id' => $organisation->id, 'product_type' => 'ticket', 'name' => 'Ticket', 'value' => 90]);
        $this->discount(['organisation_id' => $this->makeOrganisation('Other')->id, 'name' => 'Other org', 'value' => 90]);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Expired', 'value' => 90, 'valid_until' => now()->subDay()]);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Later', 'value' => 90, 'valid_from' => now()->addDay()]);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Off', 'value' => 90, 'status' => 'inactive']);

        $quote = $this->pricing()->quote($product);
        $this->assertSame('Flat 300', $quote->discount->name);
        $this->assertSame(700.0, $quote->finalPrice());
    }

    public function test_a_coupon_code_applies_when_it_gives_more(): void
    {
        $organisation = $this->makeOrganisation();
        $product = $this->makeProduct($organisation);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Early bird', 'value' => 10]);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Alumni', 'value' => 25, 'code' => ' alumni25 ']);

        $this->assertSame('ALUMNI25', ProductDiscount::where('name', 'Alumni')->value('code'));

        $quote = $this->pricing()->quote($product, null, 'Alumni25');
        $this->assertSame('ALUMNI25', $quote->code());
        $this->assertSame(750.0, $quote->finalPrice());

        $refused = $this->pricing()->quote($product, null, 'NOPE');
        $this->assertSame('This code is not valid for Annual membership.', $refused->codeRefusedReason);
        $this->assertSame(900.0, $refused->finalPrice()); // the automatic discount still applies
    }

    public function test_use_limits_are_kept(): void
    {
        $organisation = $this->makeOrganisation();
        $product = $this->makeProduct($organisation);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Once each', 'value' => 50, 'code' => 'HALF', 'max_uses' => 2, 'max_uses_per_user' => 1]);
        [$ann, $bob, $cid] = [$this->makeUser('user'), $this->makeUser('user'), $this->makeUser('user')];

        $this->pricing()->redeem($this->pricing()->quote($product, $ann, 'HALF'), $product, $ann);
        $this->assertSame('This code has already been used up.', $this->pricing()->quote($product, $ann, 'HALF')->codeRefusedReason);

        $this->pricing()->redeem($this->pricing()->quote($product, $bob, 'HALF'), $product, $bob);
        $this->assertSame('This code has already been used up.', $this->pricing()->quote($product, $cid, 'HALF')->codeRefusedReason);
    }

    public function test_a_discount_never_makes_the_price_negative(): void
    {
        $organisation = $this->makeOrganisation();
        $product = $this->makeProduct($organisation, ['price' => 100]);
        $this->discount(['organisation_id' => $organisation->id, 'name' => 'Huge', 'kind' => 'fixed', 'value' => 500]);

        $this->assertSame(0.0, $this->pricing()->quote($product)->finalPrice());
    }
}
