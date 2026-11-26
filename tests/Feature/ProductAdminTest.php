<?php

namespace Iquesters\Commerce\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Iquesters\Commerce\Database\Seeders\CommerceSeeder;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Models\ProductDiscount;
use Iquesters\Commerce\Tests\TestCase;
use Iquesters\Foundation\Models\Module;

class ProductAdminTest extends TestCase
{
    public function test_an_organisation_admin_manages_their_products(): void
    {
        $mine = $this->makeOrganisation('Alpha');
        $other = $this->makeOrganisation('Beta');
        $this->makeProduct($other, ['name' => 'Beta plan']);
        $this->actingAs($this->memberOf($mine, 'admin'));

        // "Is it a physical item?" must be answered.
        $this->postJson('/products', ['organisation_id' => $mine->id, 'name' => 'Annual', 'type' => 'membership', 'price' => 1200])
            ->assertStatus(422)->assertJsonValidationErrors('kind');
        $this->postJson('/products', ['organisation_id' => $mine->id, 'name' => 'Annual', 'type' => 'membership', 'kind' => 'virtual', 'price' => 1200])
            ->assertCreated()->assertJsonPath('data.currency', 'INR');
        $product = Product::where('name', 'Annual')->firstOrFail();

        $this->getJson('/products/data')->assertOk()
            ->assertJsonCount(1, 'response_schema.data')
            ->assertJsonPath('response_schema.data.0.name', 'Annual')
            ->assertJsonPath('response_schema.meta.pagination.total', 1);

        $this->putJson("/products/{$product->uid}", ['organisation_id' => $mine->id, 'name' => 'Annual', 'type' => 'membership', 'kind' => 'virtual', 'price' => 1500, 'status' => 'inactive'])
            ->assertOk();
        $this->assertSame('1500.00', $product->fresh()->price);

        // Another organisation: not theirs.
        $this->postJson('/products', ['organisation_id' => $other->id, 'name' => 'X', 'type' => 'other', 'kind' => 'virtual', 'price' => 1])
            ->assertStatus(422)->assertJsonValidationErrors('organisation_id');
        $beta = Product::where('name', 'Beta plan')->firstOrFail();
        $this->getJson("/products/data/{$beta->uid}")->assertNotFound();
    }

    public function test_coupon_codes_are_unique_per_organisation_and_valid(): void
    {
        $organisation = $this->makeOrganisation();
        $this->actingAs($this->makeUser('super-admin'));
        $base = ['organisation_id' => $organisation->id, 'name' => 'Alumni', 'kind' => 'percent', 'value' => 20, 'code' => 'alumni-20'];

        $this->postJson('/products/discounts', $base)->assertCreated()->assertJsonPath('data.code', 'ALUMNI-20');
        $this->postJson('/products/discounts', $base)->assertStatus(422)->assertJsonValidationErrors('code');
        $this->postJson('/products/discounts', ['code' => 'bad code!', 'value' => 120] + $base)
            ->assertStatus(422)->assertJsonValidationErrors(['code', 'value']);

        $this->getJson('/products/discounts/data?kind=code')->assertOk()->assertJsonPath('response_schema.data.0.how', 'Code ALUMNI-20');
    }

    public function test_others_get_no_management_and_anyone_signed_in_gets_a_quote(): void
    {
        $organisation = $this->makeOrganisation();
        $product = $this->makeProduct($organisation);
        ProductDiscount::create(['organisation_id' => $organisation->id, 'name' => 'Alumni', 'kind' => 'percent', 'value' => 20, 'code' => 'ALUMNI']);
        $user = $this->makeUser('user');

        $this->actingAs($user)->getJson('/products/data')->assertForbidden();
        $quote = $this->getJson("/products/{$product->uid}/quote?code=alumni")->assertOk()
            ->assertJsonPath('response_schema.data.discount.code', 'ALUMNI');
        // JSON has no float type: 800.0 arrives as 800, so compare the amount, not the type.
        $this->assertEquals(800, $quote->json('response_schema.data.final_price'));
    }

    public function test_the_seeder_makes_the_tables_forms_and_access(): void
    {
        $this->seed(CommerceSeeder::class);

        $schema = json_decode(DB::table('table_schemas')->where('slug', 'product-table')->value('schema'), true);
        $this->assertSame('/products/data', $schema['data_url']);
        $this->assertNotNull(DB::table('form_schemas')->where('slug', CommerceSeeder::DISCOUNT_FORM)->value('uid'));
        $this->assertEqualsCanonicalizing(['super-admin', 'admin'], Module::where('name', 'commerce')->firstOrFail()->getAssignedRoles()->pluck('name')->all());
        $this->assertTrue($this->makeUser('admin')->can(CommerceSeeder::MANAGE_PERMISSION));
    }

    public function test_a_physical_item_a_donation_and_sale_dates(): void
    {
        $mine = $this->makeOrganisation('Alpha');
        $this->actingAs($this->memberOf($mine, 'admin'));
        $base = ['organisation_id' => $mine->id, 'type' => 'merchandise', 'kind' => 'physical'];

        // A saree: physical, so no validity even if sent; stock; sold only between dates.
        $this->postJson('/products', $base + ['name' => 'Silk saree', 'price' => 2500, 'sku' => 'SAREE-SILK-RED', 'validity' => 'fixed', 'valid_from' => '2027-01-01', 'valid_until' => '2027-12-31',
            'track_stock' => true, 'stock_quantity' => 12, 'limit_sale' => true, 'sale_until' => '2027-03-31T23:59'])->assertCreated();
        $saree = Product::where('name', 'Silk saree')->firstOrFail();
        $this->assertSame(['none', null, true, 12, 'SAREE-SILK-RED'], [$saree->validity, $saree->valid_from, $saree->track_stock, $saree->stock_quantity, $saree->sku]);
        $this->assertNotNull($saree->sale_until);

        // "Sell only between dates" unticked: the dates go.
        $this->putJson("/products/{$saree->uid}", $base + ['name' => 'Silk saree', 'price' => 2500, 'limit_sale' => false, 'sale_until' => '2027-03-31T23:59'])->assertOk();
        $this->assertNull($saree->fresh()->sale_until);

        // A donation: the buyer chooses the amount, so no price is asked.
        $this->postJson('/products', ['organisation_id' => $mine->id, 'type' => 'donation', 'kind' => 'virtual', 'name' => 'Donation', 'custom_amount' => true, 'min_amount' => 50])->assertCreated();
        $this->assertSame(['50.00', true], [Product::where('name', 'Donation')->firstOrFail()->price, Product::where('name', 'Donation')->firstOrFail()->custom_amount]);
        // "Offer with every paid order": kept on a donation only.
        $donation = Product::where('name', 'Donation')->firstOrFail();
        $this->putJson("/products/{$donation->uid}", ['organisation_id' => $mine->id, 'type' => 'donation', 'kind' => 'virtual', 'name' => 'Donation', 'custom_amount' => true, 'min_amount' => 50, 'offer_with_every_order' => true])->assertOk();
        $this->assertTrue($donation->fresh()->offeredWithEveryOrder());
        $this->assertTrue(Product::donationForEveryOrder($mine->id)->is($donation));
        $this->getJson("/products/data/{$donation->uid}")->assertJsonPath('response_schema.data.offer_with_every_order', true);
        $this->putJson("/products/{$donation->uid}", ['organisation_id' => $mine->id, 'type' => 'donation', 'kind' => 'virtual', 'name' => 'Donation', 'custom_amount' => true, 'min_amount' => 50, 'offer_with_every_order' => false])->assertOk();
        $this->assertNull(Product::donationForEveryOrder($mine->id));

        // A fixed price is needed otherwise.
        $this->postJson('/products', $base + ['name' => 'Kurta'])->assertStatus(422)->assertJsonValidationErrors('price');
    }
}
