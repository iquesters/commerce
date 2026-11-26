# Changelog

All notable changes to `iquesters/commerce` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased] – 2.0.0

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
