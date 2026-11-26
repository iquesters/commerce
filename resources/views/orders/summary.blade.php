{{-- Record section (Orders table): the order's lines and its history. --}}
@php($money = fn ($v) => $order->currency.' '.number_format((float) $v, 2))
<div class="small">
    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
        <div>
            <div class="text-muted">Reference</div>
            <div class="fw-semibold font-monospace">{{ $order->reference }}</div>
        </div>
        <div class="text-end">
            <div class="text-muted">{{ $order->statusLabel() }}</div>
            <div class="fs-5 fw-semibold">{{ $money($order->total) }}</div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-2">
            <thead>
                <tr class="text-muted">
                    <th>Item</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Price</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($order->lines as $line)
                    @php($meta = collect((array) $line->meta)->except(['_reserved']))
                    <tr>
                        <td>
                            {{ $line->description }}
                            @if ($meta->has('bundle'))<span class="badge text-bg-light">{{ $meta['bundle'] }}</span>@endif
                            @if ($meta->except('bundle')->isNotEmpty())
                                <div class="text-muted">{{ $meta->except('bundle')->map(fn ($v, $k) => \Illuminate\Support\Str::headline($k).': '.(is_bool($v) ? ($v ? 'Yes' : 'No') : (is_scalar($v) ? $v : json_encode($v))))->implode(' · ') }}</div>
                            @endif
                            @if ($line->valid_until)
                                <div class="text-muted">Valid {{ $line->valid_from?->format('j M Y') }} – {{ $line->valid_until->format('j M Y') }}</div>
                            @endif
                        </td>
                        <td class="text-end">{{ $line->quantity }}</td>
                        <td class="text-end">{{ $money($line->unit_price) }}</td>
                        <td class="text-end">{{ (float) $line->discount > 0 ? '−'.$money($line->discount) : '' }}</td>
                        <td class="text-end">{{ $money($line->line_total) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                @if ((float) $order->discount_total > 0)
                    <tr><td colspan="4" class="text-end text-muted">Subtotal</td><td class="text-end">{{ $money($order->subtotal) }}</td></tr>
                    <tr><td colspan="4" class="text-end text-muted">Discounts</td><td class="text-end">−{{ $money($order->discount_total) }}</td></tr>
                @endif
                <tr><td colspan="4" class="text-end fw-semibold">Total</td><td class="text-end fw-semibold">{{ $money($order->total) }}</td></tr>
            </tfoot>
        </table>
    </div>

    <div class="text-muted mb-1">History</div>
    <ul class="list-unstyled mb-0">
        @foreach ($order->histories as $history)
            <li>
                {{ $history->created_at?->format('j M Y, g:i A') }}:
                <span class="fw-semibold">{{ \Iquesters\Commerce\Models\Order::LABELS[$history->to_status] ?? $history->to_status }}</span>
                @if ($history->note) · {{ $history->note }} @endif
            </li>
        @endforeach
    </ul>
</div>
