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

        $this->postJson('/products', ['organisation_id' => $mine->id, 'name' => 'Annual', 'type' => 'membership', 'price' => 1200])
            ->assertCreated()->assertJsonPath('data.currency', 'INR');
        $product = Product::where('name', 'Annual')->firstOrFail();

        $this->getJson('/products/data')->assertOk()
            ->assertJsonCount(1, 'response_schema.data')
            ->assertJsonPath('response_schema.data.0.name', 'Annual')
            ->assertJsonPath('response_schema.meta.pagination.total', 1);

        $this->putJson("/products/{$product->uid}", ['organisation_id' => $mine->id, 'name' => 'Annual', 'type' => 'membership', 'price' => 1500, 'status' => 'inactive'])
            ->assertOk();
        $this->assertSame('1500.00', $product->fresh()->price);

        // Another organisation: not theirs.
        $this->postJson('/products', ['organisation_id' => $other->id, 'name' => 'X', 'type' => 'other', 'price' => 1])
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
}
