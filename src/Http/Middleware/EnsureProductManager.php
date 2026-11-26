<?php

namespace Iquesters\Commerce\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Iquesters\Commerce\Support\ProductAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product and discount management: super-admins and organisation admins
 * (limited to their organisations in the controllers). Auto-registered as
 * `ensure.product.manager`.
 */
class EnsureProductManager
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(ProductAccess::canManage($request->user()), 403);

        return $next($request);
    }
}
