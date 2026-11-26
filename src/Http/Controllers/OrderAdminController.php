<?php

namespace Iquesters\Commerce\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Iquesters\Commerce\Models\Order;
use Iquesters\Commerce\Services\OrderService;
use Iquesters\Commerce\Support\OrderException;
use Iquesters\Commerce\Support\ProductAccess;
use Iquesters\Foundation\System\Http\ApiResponse;
use Iquesters\Organisation\Support\OrganisationContext;

/**
 * Orders for the generic table (order-table, rows from data()): super-admins
 * and organisation admins, each for their organisations. Paying is the payment
 * module's screen; here: the order, its lines and history, and cancelling it.
 */
class OrderAdminController extends Controller
{
    public function __construct(private OrderService $orders) {}

    public function data(Request $request): JsonResponse
    {
        $query = $this->scoped($request)->with('organisation:id,name')->withCount('lines');

        if (in_array($request->query('status'), Order::STATUSES, true)) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('ref_type')) {
            $query->where('ref_type', (string) $request->query('ref_type'))
                ->when($request->filled('ref_id'), fn ($q) => $q->where('ref_id', (int) $request->query('ref_id')));
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('reference', 'like', $like)
                ->orWhere('buyer_name', 'like', $like)->orWhere('buyer_email', 'like', $like)->orWhere('buyer_phone', 'like', $like));
        }

        $offset = max(0, (int) $request->query('offset', 0));
        $length = min(100, max(1, (int) $request->query('length', 25)));
        $total = (clone $query)->count();
        $sort = (string) $request->query('sort', '');
        $column = ltrim($sort, '-');
        in_array($column, ['reference', 'total', 'status', 'created_at'], true)
            ? $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc')
            : $query->latest('id');

        $rows = $query->skip($offset)->take($length)->get()->map(fn (Order $o) => $this->row($o))->all();

        return ApiResponse::success($rows, 'OK', 200, ['pagination' => ['total' => $total, 'offset' => $offset, 'length' => $length]]);
    }

    public function record(Request $request, string $uid): JsonResponse
    {
        return ApiResponse::success($this->row($this->find($request, $uid)));
    }

    /** Record section: the lines (own price, discount, paid) and the history. */
    public function summary(Request $request, string $uid): View
    {
        $order = $this->find($request, $uid)->load(['lines', 'histories']);

        return view('commerce::orders.summary', ['order' => $order]);
    }

    /** The cancel form (order-cancel) posts here. */
    public function cancel(Request $request, string $uid): JsonResponse
    {
        $order = $this->find($request, $uid);
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);

        try {
            $this->orders->cancel($order, $data['reason'], (int) $request->user()->id);
        } catch (OrderException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors ?: ['reason' => [$e->getMessage()]]], 422);
        }

        return response()->json(['success' => true, 'message' => 'Order cancelled; its stock is free again.']);
    }

    private function scoped(Request $request): Builder
    {
        $allowed = ProductAccess::organisationIds($request->user());
        $context = app()->bound(OrganisationContext::class) ? app(OrganisationContext::class)->id($request->user()) : null;
        if ($context && in_array($context, $allowed, true)) {
            $allowed = [$context];
        }

        return Order::query()->whereIn('organisation_id', $allowed);
    }

    private function find(Request $request, string $uid): Order
    {
        $order = Order::query()->with('organisation:id,name')->where('uid', $uid)->first();
        abort_unless($order && ProductAccess::canManageOrganisation($request->user(), (int) $order->organisation_id), 404);

        return $order;
    }

    private function row(Order $order): array
    {
        return [
            'uid' => $order->uid,
            'reference' => $order->reference,
            'organisation' => $order->organisation?->name,
            'buyer_name' => $order->buyer_name,
            'buyer_email' => $order->buyer_email,
            'buyer_phone' => $order->buyer_phone,
            'buyer' => trim(($order->buyer_name ?? '').($order->buyer_phone ? ' · '.$order->buyer_phone : '')),
            'total' => $order->total,
            'total_label' => $order->currency.' '.number_format((float) $order->total, 2),
            'status' => $order->status,
            'status_label' => $order->statusLabel(),
            'source' => $order->source,
            'lines' => $order->lines_count ?? $order->lines()->count(),
            'created_at' => $order->created_at?->format('Y-m-d H:i'),
            'paid_at' => $order->paid_at?->format('Y-m-d H:i'),
            'can_cancel' => $order->canMoveTo(Order::STATUS_CANCELLED) ? '1' : '0',
        ];
    }
}
