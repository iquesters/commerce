<?php

namespace Iquesters\Commerce\Database\Seeders;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Iquesters\Commerce\Models\Product;
use Iquesters\Foundation\Database\Seeders\BaseSeeder;
use Iquesters\Foundation\Models\Module;
use Spatie\Permission\Models\Role;

class CommerceSeeder extends BaseSeeder
{
    /** Sees Products and Discounts (data limited to the organisations they manage). */
    public const MANAGE_PERMISSION = 'manage-products';

    /** Super-admins and organisation admins ("admin"). */
    public const MODULE_ROLES = ['super-admin', 'admin'];

    public const PRODUCT_FORM = 'product-details';

    public const DISCOUNT_FORM = 'product-discount-details';

    protected string $moduleName = 'commerce';

    protected string $description = 'commerce module: products, prices, discounts, coupon codes (orders next)';

    protected array $metas = [
        'module_icon' => 'fas fa-box',
        'module_sidebar_menu' => [
            [
                'key' => 'products',
                'icon' => 'fas fa-box',
                'label' => 'Products',
                'permission' => self::MANAGE_PERMISSION,
                'table_schema' => [
                    'slug' => 'product-table',
                    'name' => 'Products',
                    'description' => 'Products of the organisations you manage',
                    'schema' => [
                        'entity' => 'products',
                        // Rows from the product module (limited to the organisations the user manages).
                        'data_url' => '/products/data',
                        'create' => ['form' => self::PRODUCT_FORM, 'label' => 'Add product'],
                        'dt-options' => [
                            'columns' => [
                                ['data' => 'name', 'title' => 'Name', 'visible' => true, 'link' => true, 'form-schema-uid' => self::PRODUCT_FORM],
                                ['data' => 'type', 'title' => 'Type', 'visible' => true],
                                ['data' => 'price_label', 'title' => 'Price', 'visible' => true],
                                ['data' => 'organisation', 'title' => 'Organisation', 'visible' => true],
                                ['data' => 'status', 'title' => 'Status', 'visible' => true],
                            ],
                            'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
                        ],
                        'default_view_mode' => 'inbox',
                    ],
                ],
            ],
            [
                'key' => 'discounts',
                'icon' => 'fas fa-tags',
                'label' => 'Discounts & coupons',
                'permission' => self::MANAGE_PERMISSION,
                'table_schema' => [
                    'slug' => 'product-discount-table',
                    'name' => 'Discounts',
                    'description' => 'Automatic discounts and coupon codes',
                    'schema' => [
                        'entity' => 'product_discounts',
                        'data_url' => '/products/discounts/data',
                        'create' => ['form' => self::DISCOUNT_FORM, 'label' => 'Add discount'],
                        'dt-options' => [
                            'columns' => [
                                ['data' => 'name', 'title' => 'Name', 'visible' => true, 'link' => true, 'form-schema-uid' => self::DISCOUNT_FORM],
                                ['data' => 'how', 'title' => 'How', 'visible' => true],
                                ['data' => 'amount_label', 'title' => 'Discount', 'visible' => true],
                                ['data' => 'applies_to', 'title' => 'Applies to', 'visible' => true],
                                ['data' => 'used', 'title' => 'Used', 'visible' => true],
                                ['data' => 'status', 'title' => 'Status', 'visible' => true],
                            ],
                            'options' => ['pageLength' => 25, 'order' => [], 'responsive' => true],
                        ],
                        'default_view_mode' => 'inbox',
                    ],
                ],
            ],
        ],
    ];

    protected array $permissions = [
        self::MANAGE_PERMISSION,
    ];

    protected function seedCustom(): void
    {
        $roles = collect(self::MODULE_ROLES)->mapWithKeys(fn (string $name) => [$name => Role::findOrCreate($name, 'web')]);
        $roles['admin']->givePermissionTo(self::MANAGE_PERMISSION);
        Module::where('name', $this->moduleName)->first()?->assignRoles($roles->pluck('id')->all());

        // The module's forms are rebuilt on every seed (the module owns them).
        $this->upsertForm(self::PRODUCT_FORM, 'Product', self::productForm());
        $this->upsertForm(self::DISCOUNT_FORM, 'Discount', self::discountForm());
    }

    public static function productForm(): array
    {
        $options = fn (array $values) => array_map(fn ($v) => ['value' => $v, 'label' => Str::headline($v)], $values);

        return [
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Save',
            'modes' => [
                'create' => ['endpoint' => '/products', 'method' => 'POST'],
                'edit' => ['endpoint' => '/products/{uid}', 'method' => 'PUT', 'allowDelete' => false],
                // Products are switched off (status), not deleted: sales may point at them.
                'view' => ['allowDelete' => false],
            ],
            'fields' => [
                ['id' => 'product', 'type' => 'section', 'label' => 'Product', 'description' => 'What it is and who sells it.'],
                ['id' => 'organisation_id', 'label' => 'Organisation', 'type' => 'select', 'required' => true, 'optionsFrom' => ['url' => '/products/options/organisations']],
                ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255],
                ['id' => 'type', 'label' => 'Type', 'type' => 'select', 'required' => true, 'options' => $options(Product::TYPES)],
                ['id' => 'pricing', 'type' => 'section', 'label' => 'Price', 'description' => 'Discounts and coupon codes are set under Discounts & coupons.'],
                ['id' => 'price', 'label' => 'Price', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01'],
                ['id' => 'currency', 'label' => 'Currency', 'type' => 'text', 'maxLength' => 3, 'helperText' => 'Three letters, e.g. INR. Leave empty for the default currency.'],
                ['id' => 'sku', 'label' => 'SKU', 'type' => 'text', 'maxLength' => 100],
                ['id' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => $options([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
                ['id' => 'description', 'label' => 'Description', 'type' => 'textarea', 'rows' => 3, 'maxLength' => 5000],
            ],
        ];
    }

    public static function discountForm(): array
    {
        return [
            'defaultFieldSize' => 'auto',
            'submitButtonLabel' => 'Save',
            'modes' => [
                'create' => ['endpoint' => '/products/discounts', 'method' => 'POST'],
                'edit' => ['endpoint' => '/products/discounts/{uid}', 'method' => 'PUT', 'allowDelete' => false],
                // Switched off (status), not deleted: redemptions point at them.
                'view' => ['allowDelete' => false],
            ],
            'fields' => [
                ['id' => 'organisation_id', 'label' => 'Organisation', 'type' => 'select', 'required' => true, 'optionsFrom' => ['url' => '/products/options/organisations']],
                ['id' => 'name', 'label' => 'Name', 'type' => 'text', 'required' => true, 'maxLength' => 255, 'helperText' => 'e.g. Early bird, Senior batch.'],
                ['id' => 'amount', 'type' => 'section', 'label' => 'Discount', 'description' => 'How much it takes off, and whether people enter a code.'],
                ['id' => 'kind', 'label' => 'Kind', 'type' => 'select', 'required' => true, 'options' => [
                    ['value' => 'percent', 'label' => 'Percent off'],
                    ['value' => 'fixed', 'label' => 'Fixed amount off'],
                ]],
                ['id' => 'value', 'label' => 'Value', 'type' => 'number', 'required' => true, 'min' => 0, 'step' => '0.01'],
                ['id' => 'code', 'label' => 'Coupon code', 'type' => 'text', 'maxLength' => 40, 'helperText' => 'Empty: applied automatically. Otherwise people enter this code.'],
                ['id' => 'applies', 'type' => 'section', 'label' => 'Applies to', 'description' => 'One product, every product of a type, or all your products.'],
                ['id' => 'product_id', 'label' => 'Product', 'type' => 'select', 'optionsFrom' => ['url' => '/products/options/products']],
                ['id' => 'product_type', 'label' => 'Or every product of type', 'type' => 'select', 'options' => [
                    ['value' => '', 'label' => '—'],
                    ...array_map(fn ($v) => ['value' => $v, 'label' => Str::headline($v)], Product::TYPES),
                ]],
                ['id' => 'limits', 'type' => 'section', 'label' => 'Dates and limits', 'description' => 'Leave empty for no limit.'],
                ['id' => 'valid_from', 'label' => 'Valid from', 'type' => 'datetime-local'],
                ['id' => 'valid_until', 'label' => 'Valid until', 'type' => 'datetime-local'],
                ['id' => 'max_uses', 'label' => 'Uses in total', 'type' => 'number', 'min' => 1, 'helperText' => 'How many times it can be used altogether. Leave empty for unlimited.'],
                ['id' => 'max_uses_per_user', 'label' => 'Uses per person', 'type' => 'number', 'min' => 1, 'helperText' => 'How many times one person can use it. Leave empty for unlimited.'],
                ['id' => 'status', 'label' => 'Status', 'type' => 'select', 'options' => [
                    ['value' => 'active', 'label' => 'Active'],
                    ['value' => 'inactive', 'label' => 'Inactive'],
                ]],
            ],
        ];
    }

    private function upsertForm(string $slug, string $name, array $schema): void
    {
        if (! Schema::hasTable('form_schemas')) {
            return;
        }

        $existing = DB::table('form_schemas')->where('slug', $slug)->first(['uid', 'created_at']);
        DB::table('form_schemas')->updateOrInsert(['slug' => $slug], [
            'uid' => $existing->uid ?? (string) Str::ulid(),
            'name' => $name,
            'description' => "Product module form ({$slug}); commerce:seed rebuilds it",
            'schema' => json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'active',
            'created_at' => $existing->created_at ?? now(),
            'updated_at' => now(),
        ]);
    }
}
