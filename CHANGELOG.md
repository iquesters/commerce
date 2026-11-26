# Changelog

All notable changes to `iquesters/commerce` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] – 2.0.0

### Added (showing an order)
- **`Order::groupedLines()`**: the lines for people to read, the same item at the same price once with its count (*2 × Membership 2027*), its total and its price before discount. The order record still lists a line per person.

### Added (amounts the buyer chooses)
- **`Product::amountLimits()` / `amountLimitsText()`**: what a buyer may pay for a choose-the-amount product (e.g. a donation): its minimum (₹1 when none is set) and its maximum, and the same in words (*₹100 to ₹5,000*, *₹1 or more*), for other modules' forms.

### Added (donations everywhere)
- **"Offer with every paid order"** on a donation product (product meta `offer_with_every_order`): modules offer an optional donation at every paid checkout of the organisation (event registrations, paying a membership). `Product::donationForEveryOrder($organisationId)`.

### Changed (the product form)
- **Fields show only when they apply**: *Minimum / Maximum amount* only when *the buyer chooses the amount* (then no *Price* is asked; the price is the minimum); *Products in the bundle* only for type Bundle; *Validity* only for things that aren't physical (a saree has none; the server also clears it), with *Valid from / until* or *Valid for / unit* by the choice; *On sale from / until* only after *Sell only between dates* (unticked clears them); *How many can be sold* only after *Limit the number sold*.
- **"Is it a physical item?"** (the kind) is required, with plain choices; *Who can buy it* and *Status* say what they mean. Sections: Product, Price, Products in the bundle, Validity, When it is sold, Inventory (SKU and stock). No more "Empty: no minimum" hints: the boxes say *No minimum* / *No maximum*.

### Added (order numbers)
- **Setting `order_reference_prefix`** (default `ORD`): orders are numbered `{prefix}-0001`, `-0002`… per organisation. A caller may still pass its own prefix.

### Added (orders)
- **Product options:** kind (virtual / physical), visibility (public / **private**: only admins add it, e.g. crew), **validity** (none, between dates, or a time from the purchase), an **on-sale window**, **stock** (optional on any product: how many can be sold, held by orders, given back on cancel), a **buyer-chosen amount** with minimum / maximum (donations), and the record that made it (`ref_type` / `ref_id`, e.g. an event).
- **Bundles** (type `bundle`): products sold together at a combined price; each item keeps its own price and has a **price in the bundle** (the difference is the bundle's discount); the prices in the bundle must add up to the bundle's price. Edited in the product form (repeater, options from `/products/options/items`).
- **Orders** (`orders`, `order_lines`, `order_status_histories`, `order_sequences`): `OrderService::create()` checks what can be bought (active, on sale, public unless an admin adds it, stock, amount limits), turns a bundle into one line per item (own price, discount, price paid), copies the prices onto the lines and gives the order a **reference** per organisation and prefix (`TTV-MEET-0001`). Statuses awaiting payment → under review → paid / rejected (proof can be sent again) / cancelled, with a history; a free order is paid at once. **Paid** fills each line's validity and fires **`OrderPaid`** (and `OrderStatusChanged` on every move) so other modules react without commerce knowing them.
- **Orders** screen (generic table `order-table`, `/orders/data`): reference, buyer, total, status; the record shows the lines (price, discount, total, validity) and the history; **Cancel the order** (form `order-cancel`).

### Changed (renamed to commerce)
- **`iquesters/product` is now `iquesters/commerce`** (repo `iquesters/commerce`), because it will also hold sales orders and receipts. Namespace `Iquesters\Commerce`, provider `CommerceServiceProvider`, conf `CommerceConf` (`Iquesters\Commerce\Enums\Module::COMMERCE`), seeder `CommerceSeeder`, command **`commerce:seed`**. Tables, models (`Product`, `ProductDiscount`...), routes (`/products`), permissions (`manage-products`) and table/form schema slugs keep their names.
- **Existing installs carry over:** migration `rename_product_module_to_commerce` renames the `product` module row (keeping its roles and metas), its settings (`module_settings`) and its navigation keys. Switch with `composer remove iquesters/product`, `composer require iquesters/commerce`, `php artisan migrate`, `php artisan commerce:seed`.

### Added
- **Products with a price:** `name`, `type` (membership, ticket, merchandise, donation, other), `description`, `price`, `currency`; the SKU is optional.
- **Discounts** (`product_discounts`, model `ProductDiscount`): percent or fixed; on one product, every product of a type, or all of an organisation's products; valid from / until; uses in total and per person; **automatic** or with a **coupon code** (stored upper case, unique per organisation).
- **Redemptions** (`product_discount_redemptions`): who used a discount, for what (e.g. a membership period), how much.
- **`Pricing`:** `quote($product, $buyer, $code)` returns the list price, the best valid discount (a code applies when it gives more than the automatic one), the final price (never below 0), or why the code was refused; `redeem(...)` records a use, checking the limits again under a lock.
- **Admin screens** (`manage-products`): **Products** and **Discounts & coupons** on user-interface's generic table (rows from `/products/data` and `/products/discounts/data`, the table's `data_url`) with form schemas `product-details` and `product-discount-details`. Super-admins and organisation admins (organisation module's `OrganisationAccess`), each limited to their organisations and to the one they work in.
- **Quote endpoint** for signed-in users: `GET /products/{uid}/quote?code=`.
- Settings in the DB conf: `default_currency` (`INR`).

### Changed
- **2.0 conventions:** `BaseServiceProvider` (routes, views, migrations, `commerce:seed`, conf auto-detected); requires PHP 8.3, Laravel 12/13, `iquesters/foundation`, `iquesters/user-interface` and `iquesters/organisation` 2.x; tests (Testbench), Pint, Larastan, CI.
- `product_metas.meta_value` is text.
- The products migration has a date prefix (`2025_10_01_000001_…`) and skips tables that exist.

### Removed
- The old product pages and `ProductController` (they fell back to a made-up "Demo Organisation"), `config/product.php` and `PRODUCT_LAYOUT`, and the package layout.
