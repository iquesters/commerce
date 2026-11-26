# Orders

Commerce sells; the **payment** module takes the money; other modules react when an order is paid.

## Making an order

```php
$order = app(\Iquesters\Commerce\Services\OrderService::class)->create(
    $organisation,
    ['name' => 'Rahul Sen', 'email' => 'rahul@ttv.test', 'phone' => '9800000001', 'user_id' => $user->id],
    [
        ['product' => $bundle, 'meta' => ['attendee' => 'Rahul Sen', 'meal' => 'veg']],   // Product, uid or id
        ['product' => $pass->uid, 'quantity' => 1, 'meta' => ['attendee' => 'Guest']],
        ['product' => $donation, 'amount' => 500],                                       // buyer-chosen amount
    ],
    ['prefix' => 'TTV-MEET', 'source' => 'event_registration', 'ref_type' => 'event_registration', 'ref_id' => 42, 'admin' => false, 'by' => $user->id],
);
// $order->reference: TTV-MEET-0001
```

`OrderException` (with `$errors` keyed `items.{i}`) when an item isn't sold here, isn't on sale, is private (only `admin => true` may add it), is sold out, or its amount is outside the limits.

- A **bundle** becomes one line per item: `unit_price` (the item's own price), `discount` (own price − price in the bundle), `price` (paid), with `meta.bundle` = the bundle's name.
- A total of 0 is **paid at once**.

## Moving it

| Call | From → to |
|---|---|
| `markUnderReview($order, $note, $by)` | awaiting payment / rejected → under review (proof sent) |
| `markPaid($order, $note, $by)` | awaiting / under review / rejected → **paid**: lines get `valid_from` / `valid_until` from their product; fires `OrderPaid` |
| `reject($order, $reason, $by)` | under review → rejected (proof can be sent again) |
| `cancel($order, $reason, $by)` | any open status → cancelled; the stock it held is given back |

Every move is in `order_status_histories` and fires `OrderStatusChanged($order, $from, $to, $note)`.

## Reacting to a paid order

```php
Event::listen(\Iquesters\Commerce\Events\OrderPaid::class, function ($event) {
    foreach ($event->order->lines as $line) {
        if ($line->product_type === 'membership') { /* activate until $line->valid_until */ }
    }
});
```
