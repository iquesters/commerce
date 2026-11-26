<?php

namespace Iquesters\Commerce\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Iquesters\Commerce\Enums\Module;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Models\ProductDiscount;
use Iquesters\Commerce\Support\ProductAccess;
use Iquesters\Foundation\Support\ConfProvider;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Organisation\Models\Organisation;
use Iquesters\Organisation\Support\OrganisationContext;

/**
 * Products and discounts for the generic tables (data_url) and their form
 * schemas (product-details, product-discount-details). Super-admins and
 * organisation admins, each limited to their organisations.
 */
class ProductAdminController extends Controller
{
    // ---- Products ----------------------------------------------------------

    public function products(Request $request): JsonResponse
    {
        $query = $this->scoped($request, Product::query())->with('organisation:id,name');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('sku', 'like', $like));
        }
        if (in_array($request->query('type'), Product::TYPES, true)) {
            $query->where('type', $request->query('type'));
        }

        return $this->page($request, $query, ['name', 'type', 'price', 'status', 'created_at'], fn (Product $p) => $this->productRow($p));
    }

    public function product(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->productRow($this->findProduct($request, $uid)));
    }

    public function storeProduct(Request $request): JsonResponse
    {
        [$data, $items] = $this->validatedProduct($request);
        $product = $this->saveProduct($request, $data, $items);

        return $this->saved('Product created.', $this->productRow($product), 201);
    }

    public function updateProduct(Request $request, string $uid): JsonResponse
    {
        $product = $this->findProduct($request, $uid);
        [$data, $items] = $this->validatedProduct($request, $product);
        $product = $this->saveProduct($request, $data, $items, $product);

        return $this->saved('Product saved.', $this->productRow($product));
    }

    /** Options of a bundle's item select: the organisation's products that aren't bundles. */
    public function itemOptions(Request $request): JsonResponse
    {
        $products = $this->scoped($request, Product::query())->where('type', '!=', 'bundle')->orderBy('name')->get(['id', 'name', 'price', 'currency']);

        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => 'Choose…'],
            ...$products->map(fn ($p) => ['value' => (int) $p->id, 'label' => "{$p->name} ({$p->currency} ".number_format((float) $p->price, 2).')'])->all(),
        ]]);
    }

    // ---- Discounts ---------------------------------------------------------

    public function discounts(Request $request): JsonResponse
    {
        $query = $this->scoped($request, ProductDiscount::query())->with('product:id,name')->withCount('redemptions');

        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like));
        }
        if ($request->query('kind') === 'code') {
            $query->whereNotNull('code');
        } elseif ($request->query('kind') === 'automatic') {
            $query->whereNull('code');
        }

        return $this->page($request, $query, ['name', 'code', 'value', 'valid_until', 'status', 'created_at'], fn (ProductDiscount $d) => $this->discountRow($d));
    }

    public function discount(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->discountRow($this->findDiscount($request, $uid)->loadCount('redemptions')));
    }

    public function storeDiscount(Request $request): JsonResponse
    {
        $discount = ProductDiscount::create($this->validatedDiscount($request) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return $this->saved('Discount created.', $this->discountRow($discount->loadCount('redemptions')), 201);
    }

    public function updateDiscount(Request $request, string $uid): JsonResponse
    {
        $discount = $this->findDiscount($request, $uid);
        $discount->update($this->validatedDiscount($request, $discount) + ['updated_by' => $request->user()->id]);

        return $this->saved('Discount saved.', $this->discountRow($discount->fresh()->loadCount('redemptions')));
    }

    // ---- Options for the forms' selects -----------------------------------

    public function organisationOptions(Request $request): JsonResponse
    {
        $organisations = Organisation::query()->whereIn('id', ProductAccess::organisationIds($request->user()))->orderBy('name')->get(['id', 'name']);

        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => $organisations->isEmpty() ? 'No organisation you manage' : 'Choose…'],
            ...$organisations->map(fn ($o) => ['value' => (int) $o->id, 'label' => $o->name])->all(),
        ]]);
    }

    public function productOptions(Request $request): JsonResponse
    {
        $products = $this->scoped($request, Product::query())->where('status', Product::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name', 'type']);

        return response()->json(['success' => true, 'data' => [
            ['value' => '', 'label' => 'Any product (or every product of the type below)'],
            ...$products->map(fn ($p) => ['value' => (int) $p->id, 'label' => "{$p->name} ({$p->type})"])->all(),
        ]]);
    }

    // ---- Helpers -----------------------------------------------------------

    /** @return array{0: array, 1: ?array} the product's values and, for a bundle, its items */
    private function validatedProduct(Request $request, ?Product $product = null): array
    {
        $organisationId = (int) $request->input('organisation_id');
        $data = $request->validate([
            'organisation_id' => ['required', 'integer', Rule::in(ProductAccess::organisationIds($request->user()))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Product::TYPES)],
            'kind' => ['nullable', Rule::in([Product::KIND_VIRTUAL, Product::KIND_PHYSICAL])],
            'visibility' => ['nullable', Rule::in([Product::VISIBILITY_PUBLIC, Product::VISIBILITY_PRIVATE])],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sku' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
            // Validity of what is bought.
            'validity' => ['nullable', Rule::in([Product::VALIDITY_NONE, Product::VALIDITY_FIXED, Product::VALIDITY_DURATION])],
            'valid_from' => ['nullable', 'date', 'required_if:validity,fixed'],
            'valid_until' => ['nullable', 'date', 'required_if:validity,fixed', 'after_or_equal:valid_from'],
            'valid_for' => ['nullable', 'integer', 'min:1', 'max:1200', 'required_if:validity,duration'],
            'valid_unit' => ['nullable', Rule::in(Product::VALID_UNITS), 'required_if:validity,duration'],
            // When it can be bought.
            'sale_from' => ['nullable', 'date'],
            'sale_until' => ['nullable', 'date', 'after_or_equal:sale_from'],
            // Stock.
            'track_stock' => ['nullable', 'boolean'],
            'stock_quantity' => ['nullable', 'integer', 'min:'.(int) ($product?->stock_reserved ?? 0), 'required_if:track_stock,1,true'],
            // Buyer-chosen amount (donations).
            'custom_amount' => ['nullable', 'boolean'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_amount' => ['nullable', 'numeric', 'min:0', 'gte:min_amount'],
            // Bundle items.
            'bundle_items' => ['nullable', 'array', 'max:20'],
            'bundle_items.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organisation_id', $organisationId)->whereNot('type', 'bundle')],
            'bundle_items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'bundle_items.*.price_in_bundle' => ['required', 'numeric', 'min:0'],
        ], [
            'organisation_id.in' => "You don't manage that organisation.",
            'stock_quantity.min' => 'Orders already hold :min; the stock can\'t be lower.',
            'bundle_items.*.product_id.exists' => 'Choose a product (not a bundle) of the same organisation.',
            'max_amount.gte' => 'The maximum must be at least the minimum.',
        ]);

        $data['currency'] = strtoupper($data['currency'] ?? '') ?: (string) ConfProvider::from(Module::COMMERCE)->default_currency;
        $data['status'] ??= Product::STATUS_ACTIVE;
        $data['kind'] ??= Product::KIND_VIRTUAL;
        $data['visibility'] ??= Product::VISIBILITY_PUBLIC;
        $data['validity'] ??= Product::VALIDITY_NONE;
        $data['track_stock'] = (bool) ($data['track_stock'] ?? false);
        $data['custom_amount'] = (bool) ($data['custom_amount'] ?? false);
        if ($data['validity'] !== Product::VALIDITY_FIXED) {
            $data['valid_from'] = $data['valid_until'] = null;
        }
        if ($data['validity'] !== Product::VALIDITY_DURATION) {
            $data['valid_for'] = $data['valid_unit'] = null;
        }
        if (! $data['track_stock']) {
            $data['stock_quantity'] = null;
        }
        if (! $data['custom_amount']) {
            $data['min_amount'] = $data['max_amount'] = null;
        }

        $items = null;
        if ($data['type'] === 'bundle') {
            $items = collect($request->input('bundle_items', []))->values()->map(fn ($item, $i) => [
                'product_id' => (int) $item['product_id'],
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'price_in_bundle' => round((float) $item['price_in_bundle'], 2),
                'sort' => $i,
            ]);
            $sum = round($items->sum(fn ($i) => $i['price_in_bundle'] * $i['quantity']), 2);
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['bundle_items' => 'A bundle needs at least one product.']);
            }
            if ($items->pluck('product_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['bundle_items' => 'Each product can be in the bundle once (use its quantity).']);
            }
            if (abs($sum - round((float) $data['price'], 2)) > 0.001) {
                throw ValidationException::withMessages(['bundle_items' => 'The items\' prices in the bundle add up to '.number_format($sum, 2).'; they must add up to the bundle\'s price ('.number_format((float) $data['price'], 2).').']);
            }
            $items = $items->all();
            $data['custom_amount'] = false;
        }
        unset($data['bundle_items']);

        return [$data, $items];
    }

    /** Save a product and, for a bundle, its items (replaced). */
    private function saveProduct(Request $request, array $data, ?array $items, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($request, $data, $items, $product) {
            $userId = (int) $request->user()->id;
            if ($product) {
                $product->update($data + ['updated_by' => $userId]);
            } else {
                $product = Product::create($data + ['created_by' => $userId, 'updated_by' => $userId]);
            }
            if ($items !== null) {
                $product->bundleItems()->delete();
                foreach ($items as $item) {
                    $product->bundleItems()->create($item);
                }
            } elseif ($product->type !== 'bundle') {
                $product->bundleItems()->delete();
            }

            return $product->fresh(['organisation', 'bundleItems.product']);
        });
    }

    private function validatedDiscount(Request $request, ?ProductDiscount $discount = null): array
    {
        $organisationId = (int) $request->input('organisation_id');

        $data = $request->validate([
            'organisation_id' => ['required', 'integer', Rule::in(ProductAccess::organisationIds($request->user()))],
            'name' => ['required', 'string', 'max:255'],
            'product_id' => ['nullable', 'integer', Rule::exists('products', 'id')->where('organisation_id', $organisationId)],
            'product_type' => ['nullable', Rule::in(Product::TYPES)],
            'kind' => ['required', Rule::in([ProductDiscount::KIND_PERCENT, ProductDiscount::KIND_FIXED])],
            'value' => ['required', 'numeric', 'gt:0', $request->input('kind') === ProductDiscount::KIND_PERCENT ? 'max:100' : 'max:99999999'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique('product_discounts', 'code')->where('organisation_id', $organisationId)->ignore($discount?->id)],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'max_uses_per_user' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'organisation_id.in' => "You don't manage that organisation.",
            'product_id.exists' => 'Choose a product of the same organisation.',
            'code.regex' => 'Use letters, numbers, - and _ only.',
            'code.unique' => 'Another discount already uses this code.',
        ]);

        $data['code'] = ProductDiscount::normaliseCode($data['code'] ?? null);
        $data['status'] ??= 'active';

        return $data;
    }

    private function productRow(Product $product): array
    {
        return [
            'uid' => $product->uid,
            'organisation_id' => $product->organisation_id,
            'organisation' => $product->organisation?->name,
            'name' => $product->name,
            'type' => $product->type,
            'description' => $product->description,
            'price' => $product->price,
            'currency' => $product->currency,
            'price_label' => $product->custom_amount ? 'Any amount' : $product->currency.' '.number_format((float) $product->price, 2),
            'sku' => $product->sku,
            'status' => $product->status,
            'kind' => $product->kind,
            'visibility' => $product->visibility,
            'validity' => $product->validity,
            'valid_from' => $product->valid_from?->format('Y-m-d'),
            'valid_until' => $product->valid_until?->format('Y-m-d'),
            'valid_for' => $product->valid_for,
            'valid_unit' => $product->valid_unit,
            'sale_from' => $product->sale_from?->format('Y-m-d\TH:i'),
            'sale_until' => $product->sale_until?->format('Y-m-d\TH:i'),
            'on_sale' => $product->isOnSale() ? 'On sale' : 'Not on sale',
            'track_stock' => (bool) $product->track_stock,
            'stock_quantity' => $product->stock_quantity,
            'stock_label' => $product->track_stock ? $product->stockLeft().' of '.$product->stock_quantity.' left' : 'No limit',
            'custom_amount' => (bool) $product->custom_amount,
            'min_amount' => $product->min_amount,
            'max_amount' => $product->max_amount,
            'bundle_items' => $product->type === 'bundle'
                ? $product->bundleItems()->with('product:id,name,price')->get()->map(fn ($item) => [
                    'product_id' => (int) $item->product_id,
                    'quantity' => (int) $item->quantity,
                    'price_in_bundle' => $item->price_in_bundle,
                ])->all()
                : [],
            'created_at' => $product->created_at?->format('Y-m-d H:i'),
        ];
    }

    private function discountRow(ProductDiscount $discount): array
    {
        return [
            'uid' => $discount->uid,
            'organisation_id' => $discount->organisation_id,
            'name' => $discount->name,
            'product_id' => $discount->product_id,
            'applies_to' => $discount->product?->name ?? ($discount->product_type ? 'All '.$discount->product_type : 'All products'),
            'product_type' => $discount->product_type,
            'kind' => $discount->kind,
            'value' => $discount->value,
            'amount_label' => $discount->describe(),
            'code' => $discount->code,
            'how' => $discount->code ? 'Code '.$discount->code : 'Automatic',
            'valid_from' => $discount->valid_from?->format('Y-m-d\TH:i'),
            'valid_until' => $discount->valid_until?->format('Y-m-d\TH:i'),
            'max_uses' => $discount->max_uses,
            'max_uses_per_user' => $discount->max_uses_per_user,
            'used' => (int) ($discount->redemptions_count ?? 0),
            'status' => $discount->status,
            'created_at' => $discount->created_at?->format('Y-m-d H:i'),
        ];
    }

    /** One page of rows, in the entity API's format (offset, length, search, sort → data + total). */
    private function page(Request $request, Builder $query, array $sortable, callable $row): JsonResponse
    {
        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();

        $sorted = false;
        foreach (array_filter(explode(',', (string) $request->query('sort', ''))) as $sort) {
            if (in_array(ltrim($sort, '-'), $sortable, true)) {
                $query->orderBy(ltrim($sort, '-'), str_starts_with($sort, '-') ? 'desc' : 'asc');
                $sorted = true;
            }
        }
        if (! $sorted) {
            $query->latest('id');
        }

        $rows = $query->skip($offset)->take($length)->get()->map($row)->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    /** The organisations the user manages (only the one worked in, if chosen). */
    private function scoped(Request $request, Builder $query): Builder
    {
        $allowed = ProductAccess::organisationIds($request->user());
        $context = app()->bound(OrganisationContext::class) ? app(OrganisationContext::class)->id($request->user()) : null;
        if ($context && in_array($context, $allowed, true)) {
            $allowed = [$context];
        }

        return $query->whereIn($query->getModel()->getTable().'.organisation_id', $allowed);
    }

    private function findProduct(Request $request, string $uid): Product
    {
        return $this->guard($request, Product::query()->with('organisation:id,name')->where('uid', $uid)->firstOrFail());
    }

    private function findDiscount(Request $request, string $uid): ProductDiscount
    {
        return $this->guard($request, ProductDiscount::query()->with('product:id,name')->where('uid', $uid)->firstOrFail());
    }

    /** Another organisation's record looks like a missing one. */
    private function guard(Request $request, Model $model): Model
    {
        abort_unless(ProductAccess::canManageOrganisation($request->user(), (int) $model->organisation_id), 404);

        return $model;
    }

    private function saved(string $message, array $data, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }
}
