<?php

namespace Iquesters\Commerce\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Iquesters\Commerce\Models\Order;

/** Any change of an order's status (e.g. event shows "payment under review"). */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(public Order $order, public ?string $from, public string $to, public ?string $note = null) {}
}
