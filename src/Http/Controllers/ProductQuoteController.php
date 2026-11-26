<?php

namespace Iquesters\Commerce\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Iquesters\Commerce\Models\Product;
use Iquesters\Commerce\Support\Pricing;
use Iquesters\Foundation\System\Http\ApiResponse;

/**
 * The price of an active product for the signed-in user, with an optional
 * coupon code (?code=): list price, discount, final price, or why the code was
 * refused. Used by other modules' pages (membership fees, tickets…).
 */
class ProductQuoteController extends Controller
{
    public function __construct(private Pricing $pricing) {}

    public function show(Request $request, string $uid): JsonResponse
    {
        $product = Product::query()->where('uid', $uid)->where('status', Product::STATUS_ACTIVE)->firstOrFail();
        $code = $request->query('code');

        return ApiResponse::success($this->pricing->quote($product, $request->user(), is_string($code) ? $code : null)->toArray());
    }
}
