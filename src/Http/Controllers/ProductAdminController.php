<?php

namespace Iquesters\Commerce\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
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
        $product = Product::create($this->validatedProduct($request) + ['created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);

        return $this->saved('Product created.', $this->productRow($product), 201);
    }

    public function updateProduct(Request $request, string $uid): JsonResponse
    {
        $product = $this->findProduct($request, $uid);
        $product->update($this->validatedProduct($request) + ['updated_by' => $request->user()->id]);

        return $this->saved('Product saved.', $this->productRow($product->fresh('organisation')));
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

    private function validatedProduct(Request $request): array
    {
        $data = $request->validate([
            'organisation_id' => ['required', 'integer', Rule::in(ProductAccess::organisationIds($request->user()))],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(Product::TYPES)],
            'description' => ['nullable', 'string', 'max:5000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['nullable', 'string', 'size:3'],
            'sku' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([Product::STATUS_ACTIVE, Product::STATUS_INACTIVE])],
        ], ['organisation_id.in' => "You don't manage that organisation."]);

        $data['currency'] = strtoupper($data['currency'] ?? '') ?: (string) ConfProvider::from(Module::COMMERCE)->default_currency;
        $data['status'] ??= Product::STATUS_ACTIVE;

        return $data;
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
            'price_label' => $product->currency.' '.number_format((float) $product->price, 2),
            'sku' => $product->sku,
            'status' => $product->status,
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
