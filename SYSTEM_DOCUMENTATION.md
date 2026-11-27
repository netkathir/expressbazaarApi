# Express Bazaar — System Documentation

**Document status:** implementation-based reference  
**Application:** Laravel 12 marketplace/e-commerce platform  
**Primary source:** current repository code, especially `routes/web.php`, `app/`, and `database/migrations/`

This document describes behavior present in the codebase. Where a business rule is inferred from implementation rather than explicitly documented, it is marked as an implementation note.

## 1. System overview

Express Bazaar supports a single-vendor-per-cart marketplace model. Customers browse a location-aware catalog, maintain a session cart, authenticate with email/OTP flows, place orders, pay through Stripe Checkout, and track orders. Administrators manage master data, catalog, vendors, customers, orders, inventory, delivery, notifications, permissions, and reports. Vendors manage their own products, coupons, orders, notifications, and payment views.

### Technology and runtime

- PHP 8.2+, Laravel 12, Blade views, Eloquent ORM, MySQL 8/MariaDB.
- Vite, Tailwind CSS, Axios for browser assets.
- Laravel session, cache, database queue, mail, notifications, and filesystem abstractions.
- Stripe Checkout is optional but required for live card payment.
- POS inventory is represented by queued synchronization jobs; the current job implementation is an integration boundary rather than a complete external POS client.
- Public uploads are served through `/storage/uploads/{path}` and the legacy `/uploads/{path}` route after path validation.

### Major application areas

| Area | Audience | Responsibilities |
|---|---|---|
| Storefront | Public/customer | Catalog discovery, location selection, cart, coupon application, checkout, account, orders, wishlist, addresses, contact form |
| Admin panel | Admin users | All master data and operational management, order/payment administration, reports, roles and permissions, system configuration |
| Vendor panel | Vendors | Vendor onboarding/authentication, own catalog and bulk import, own coupons, order lifecycle, payment read view, notifications |
| Background work | System | POS stock sync/reversal and queued notification/email processing depending on queue/mail configuration |

## 2. Actors and access control

### Customer

Stored in `users` with `role = customer`. Customers can register, verify email with OTP, log in, reset passwords, manage profile/addresses/wishlist, place orders, pay/retry payment, cancel eligible orders, reorder, and receive database bell notifications.

### Administrator

Stored in `users` with an active role matching `roles.role_name`. `AdminAccess` requires an active user with an active role. `PermissionMiddleware` maps routes to module permissions. The role named `admin` is effectively full-access in `User::hasRolePermission`; other roles use `role_permissions` CRUD flags.

### Vendor

Stored separately in `vendors`, authenticated with the vendor guard. Vendors require active status and are protected by `VendorAccess`. Vendor setup can be completed from a tokenized setup URL; vendor accounts have their own password, role, remember token, and last-login timestamp.

### Permission model

`roles` defines named roles. `role_permissions` stores one row per role/module with `can_view`, `can_create`, `can_edit`, and `can_delete`. Route actions are translated into module and ability checks. Vendor permissions use the vendor's role record; vendor routes are also scoped to the authenticated vendor in controllers.

## 3. Business workflows

### 3.1 Customer registration and authentication

1. Customer submits registration with name, email, password, and related fields.
2. A `users` row is created with active customer status.
3. An OTP record is created in `otp_verifications` with a purpose, expiry, and attempt counter; email delivery uses the mail layer.
4. OTP verification sets `email_verified_at` and marks the OTP used. Resend creates/replaces the applicable verification flow.
5. Login only succeeds for matching credentials and `status = active`; `last_login_at` is updated.
6. Logout invalidates the session.

Password reset follows request → OTP email → OTP verification → password reset. OTP rows are keyed operationally by email and purpose, not by a unique database constraint, so expiry/used checks are application logic.

### 3.2 Admin and vendor password reset

Admin reset uses `password_otps`; vendor reset uses `vendor_password_otps`. Each table has a unique email, OTP, expiry, and timestamps. The flow is request → send OTP → verify OTP → set new password.

### 3.3 Vendor onboarding

1. Admin creates or updates a vendor record.
2. A setup token may be issued and sent through vendor setup mail.
3. Vendor opens `/vendor/setup/{token}`, completes credentials/setup, and the system sets `is_setup_complete`.
4. Vendor logs into the separate vendor guard.
5. Existing vendors with a password are migrated as setup-complete by migration `2026_04_30_000003_add_vendor_workflow_fields`.

### 3.4 Location selection and vendor availability

1. Customer selects country, city, and optionally zone through location endpoints and browser/session state.
2. `countries`, `cities`, and `regions_zones` provide location master data.
3. Vendor records contain country, city, region-zone, and pincode; location indexes support filtering.
4. `delivery_config` determines whether delivery is available and the delivery charge for a country/city/zone combination.
5. Storefront catalog queries use active products and active vendors, with location context used to restrict the visible/eligible vendor catalog.

Implementation note: `vendors` contains both `region_zone_id` and a later `zone_id` column. The Eloquent relationship uses `region_zone_id`; `zone_id` is a legacy/compatibility field without a declared foreign key in the migration.

### 3.5 Catalog browsing

Customers can browse home banners and product sections, category pages, subcategory pages, product details, search results, and search suggestions. Products are related to one category, optional subcategory, one vendor, and optional tax. Product images are ordered by `sort_order`, then `id`.

Only active catalog records are intended for storefront display. Product pricing uses `price`, optional discount type/value/date range, and persisted `final_price`. Supported discount types are fixed and percentage; percentage discounts cannot exceed 100%, and fixed discounts cannot exceed the base price.

### 3.6 Cart and coupon workflow

1. Guest and authenticated carts are held in the Laravel session; there is no cart database table.
2. Add/update/remove/clear operations validate product status, vendor status, inventory mode, and requested quantity.
3. Cart merge is available after authentication to combine the guest session cart with the customer session.
4. The implementation enforces one vendor per cart. Adding a product from another vendor is rejected or requires cart replacement behavior according to storefront logic.
5. Authenticated customers can apply/remove a coupon. Coupons may be global (`vendor_id` null) or vendor-specific, and support fixed or percentage values, minimum order, expiry, and active flag.
6. Cart totals include product subtotals, coupon/offer effects, and delivery charge where eligible.

Implementation note: coupons are not persisted against an order by a dedicated order-coupon table. The applied coupon is session/cart state and the resulting order total is persisted.

### 3.7 Checkout, stock reservation, and order creation

Checkout requires authentication and `EnsureEmailVerifiedForCheckout`. The order placement flow validates the cart, delivery location/configuration, customer, product availability, and totals, then creates an `orders` row and `order_items` rows.

The implementation reserves/reduces internal inventory during order placement through `InventoryService::deductForOrder`. Each reduction writes an `inventory_logs` row whose reason includes the order number. POS items are marked pending and dispatched to `SyncPosStockJob` instead of being manually adjusted.

An order is initialized with pending payment and pending order status. `placed_at` records placement. An `OrderPlaced` event triggers customer bell notification, vendor notification, and template-based notifications.

### 3.8 Payment workflow

1. The payment controller creates a Stripe Checkout Session for an order.
2. Stripe receives order ID/order number metadata and line items. Delivery is a separate line item when non-zero.
3. The customer is redirected to Stripe and then to success/cancel URLs.
4. The Stripe webhook route is CSRF-exempt and verifies/handles gateway events through the payment controller.
5. Payment records are stored in `payments`; orders retain `stripe_session_id`, `stripe_payment_intent`, payment status, and a separate legacy/compatibility `status` column.
6. Customers can view success, cancellation, and retry-payment flows. Administrators can manage payment records; vendors have a read-oriented payment module.

Implementation note: Stripe line-item construction reconciles item subtotal + delivery against `orders.total_amount`. If they differ, it creates one order-total line item and includes offer savings in the description.

### 3.9 Order lifecycle

The authoritative order status is `orders.order_status`; the later `orders.status` column is kept synchronized when present.

Allowed transitions:

```text
pending    -> accepted | cancelled
accepted   -> processing | cancelled
processing -> dispatched | cancelled
dispatched -> delivered
delivered  -> completed
completed  -> (terminal)
cancelled  -> (terminal)
```

Each valid change updates the order, writes `order_logs`, dispatches customer status notifications, and sends configured template notifications. Vendor actions are accept, reject/cancel, processing, dispatched, and delivered. Pending orders can be auto-cancelled after a configurable/service default of 30 minutes.

Vendor acceptance checks inventory. If checkout already created a reduction log for the order, the reservation is treated as present; otherwise internal stock must cover the requested quantity. POS inventory is rejected only when its sync status is failed.

Customer cancellation is allowed only for statuses permitted by `CustomerAccountController`; cancellation restores internal stock and queues POS reversal for POS products. Admin order updates use the same lifecycle service to enforce transition validity.

### 3.10 Inventory workflow

Two inventory modes exist:

- **Internal:** quantity can be manually added/reduced, cannot go below zero, and every change is transactionally locked and logged.
- **POS:** manual adjustment is blocked; stock is synchronized through `SyncPosStockJob` and restored through `ReversePosStockJob`.

`product_inventory` has one row per product. Low-stock evaluation compares stock with `low_stock_threshold`. It raises database notifications for active non-customer admin users and the product's active vendor, sets `products.is_low_stock`, and resolves unread alerts when stock rises above the threshold.

### 3.11 Product bulk import

Admin and vendor panels provide CSV template download and CSV import. The importer normalizes headers/aliases, resolves vendor/category/subcategory/tax by ID or case-insensitive name, validates each row, calculates `final_price`, and processes rows independently in database transactions. The result reports created count and row-level errors. POS rows can optionally queue an initial sync.

### 3.12 Notifications

There are two notification systems:

1. Laravel database notifications in `notifications` for bell alerts such as order placed and low stock; read/read-all endpoints update `read_at`.
2. Configurable `notification_templates` rendered by trigger/channel and recorded in `notification_logs`; email/SMS delivery is handled by listeners/helpers.

Known order triggers include order placed, order payment, order status update, and order delivered. Failed template delivery is retained with status/error information in notification logs.

### 3.13 Contact inquiries

Public users submit contact inquiries. The system stores optional authenticated user ID, contact details, message, originating IP, status, and read timestamp. Admins list, view, update status/read state, and delete inquiries.

## 4. Database design

All tables use Laravel timestamps unless stated otherwise. Tables marked **soft delete** have `deleted_at`; ordinary deletes may be blocked by foreign-key restrictions or cascade according to the migration.

### 4.1 Identity, sessions, and authentication

| Table | Important columns | Relationships / behavior | Keys and indexes |
|---|---|---|---|
| `users` | `id`, `name`, `email`, `phone`, `username`, `password`, `role`, `status`, verification/login timestamps, `avatar_path` | Customer and admin identity; referenced by orders, addresses, OTPs, inquiries, order logs, notifications | PK `id`; unique `email`, unique nullable `username`; session FK index |
| `password_reset_tokens` | `email`, `token`, `created_at` | Laravel password broker storage | PK `email` |
| `sessions` | `id`, nullable `user_id`, IP, agent, payload, `last_activity` | Laravel database sessions | PK `id`; indexes `user_id`, `last_activity` |
| `otp_verifications` | optional `user_id`, `email`, `purpose`, `otp_code`, `expires_at`, `used_at`, `attempts` | Customer registration/reset OTPs | PK `id`; composite index `email,purpose`; FK `user_id` nullable/null-on-delete |
| `password_otps` | unique `email`, `otp`, `expires_at` | Admin password reset OTP | PK `id`; unique `email` |
| `vendor_password_otps` | unique `email`, `otp`, `expires_at` | Vendor password reset OTP | PK `id`; unique `email` |

### 4.2 Location, vendor, and delivery

| Table | Important columns | Relationships / behavior | Keys and indexes |
|---|---|---|---|
| `countries` | name, code, currency, timezone, status, audit users | Parent of cities, zones, taxes, vendors, addresses, delivery config; soft delete | PK; unique `country_name`, unique `country_code`; indexed audit FKs |
| `cities` | `country_id`, state, city name/code, status | Belongs to country; parent of zones/vendors/addresses/delivery config; soft delete | PK; unique `country_id,state,city_name`; country index ensured by migration |
| `regions_zones` | country/city IDs, zone name/code, delivery flag, status | Belongs to country/city; referenced by vendors, addresses, delivery config; soft delete | PK; unique `city_id,zone_name` |
| `vendors` | name/email/phone/address/pincode, country/city/region zone, inventory mode, API/credentials, status, setup/auth fields, logo | Owns products, orders, coupons; location-scoped | PK; unique email; FK indexes `country_id`, `city_id`, named indexes `idx_vendors_country`, `idx_vendors_city`, `idx_vendors_pincode`; soft delete |
| `delivery_config` | country/city/zone, availability, charge, status | One delivery rule per location tuple | PK; unique `country_id,city_id,zone_id`; audit FK indexes |

### 4.3 Catalog and pricing

| Table | Important columns | Relationships / behavior | Keys and indexes |
|---|---|---|---|
| `categories` | name, image, status | Has many subcategories/products; soft delete | PK; unique name; audit FK indexes |
| `subcategories` | `category_id`, name/image, status | Belongs to category; has products; soft delete | PK; unique `category_id,subcategory_name`; category FK index |
| `taxes` | name, percentage, optional country, region name, status | Optional product tax; country null-on-delete | PK; unique name; country/audit FK indexes; soft delete |
| `products` | name/description, category/subcategory/vendor/tax, price, discount fields/dates, final price, inventory mode/unit/status, low-stock flag | Belongs to category, optional subcategory/tax, vendor; has inventory/images/wishlists; soft delete | PK; FK indexes generated for relational columns; audit FK indexes; no explicit product search index or vendor/name unique DB constraint |
| `product_images` | product, image path, sort order, audit fields | Product gallery; cascade on product delete | PK; product/audit FK indexes |
| `banners` | title/subtitle/image/link, status, sort order | Storefront promotional content | PK; no explicit secondary index |
| `coupons` | unique code, fixed/percentage type, value, minimum order, optional vendor, expiry, active | Vendor-specific or global discount | PK; unique code; vendor FK index |

### 4.4 Inventory, orders, and payments

| Table | Important columns | Relationships / behavior | Keys and indexes |
|---|---|---|---|
| `product_inventory` | unique product, mode, stock, unit, low-stock threshold, sync status/timestamp | One-to-one product inventory; cascade on product delete | PK; unique `product_id`; product FK/index |
| `inventory_logs` | product, inventory, optional vendor, change type, quantity, previous/new stock, source, reason | Immutable-style stock audit trail; cascades with product/inventory; vendor null-on-delete | PK; FK indexes |
| `orders` | unique order number, optional customer/vendor, total, delivery charge, payment/order statuses, placement, notes, Stripe IDs, audit fields | Has items/payments/logs; customer/vendor null-on-delete; soft delete | PK; unique order number; customer/vendor/audit FK indexes; no explicit indexes on status/date/Stripe IDs |
| `order_items` | order, optional product, snapshot item name, quantity, price, subtotal | Order line snapshot; order cascade, product null-on-delete | PK; order/product FK indexes |
| `payments` | optional order, unique transaction ID, method, amount, status, gateway response, paid time | Payment history; order null-on-delete | PK; unique transaction ID; order FK index |
| `order_logs` | order, from/to status, optional changed user, note | Order lifecycle audit; order cascade, user null-on-delete | PK; order/changed-user FK indexes |

### 4.5 Authorization, notifications, and support

| Table | Important columns | Relationships / behavior | Keys and indexes |
|---|---|---|---|
| `roles` | unique role name, description, status | Has permissions | PK; unique role name |
| `role_permissions` | role, module name, four CRUD flags | One row per role/module | PK; unique `role_id,module_name`; role FK index |
| `system_config` | unique key, nullable value | Key/value application settings | PK; unique config key |
| `notifications` | UUID, type, polymorphic notifiable, JSON/text data, read time | Laravel database notifications for users/vendors | PK UUID; polymorphic `notifiable_type,notifiable_id` index |
| `notification_templates` | name, trigger/type, channel, subject, body, status | Has notification logs | PK; no explicit unique or composite trigger/channel index |
| `notification_logs` | template, recipient type/id, channel, rendered message, status/error | Delivery audit; template null-on-delete | PK; template/recipient FK/index |
| `customer_addresses` | user, recipient/contact/address, country/city/zone, postcode, default/status | Customer-owned addresses; user cascade, location restrictions | PK; user/country/city/zone FK indexes |
| `customer_wishlists` | user, product | Many-to-many bridge; both cascade | PK; unique `user_id,product_id` |
| `contact_inquiries` | optional user, contact details, subject/message, IP, status/read time | Support inbox | PK; indexes `status`, `email`, `created_at`; user null-on-delete |

### 4.6 Laravel infrastructure tables

`cache` and `cache_locks` support database cache; `jobs`, `job_batches`, and `failed_jobs` support queued work and failures. Their primary/queue/expiration/UUID indexes are defined by Laravel migrations.

### 4.7 Relationship summary

```text
Country 1--* City 1--* RegionZone
Country 1--* Vendor, Tax, CustomerAddress, DeliveryConfig
City    1--* Vendor, CustomerAddress, DeliveryConfig
RegionZone 1--* CustomerAddress, DeliveryConfig

Category 1--* Subcategory 1--* Product
Vendor   1--* Product, Order, Coupon
Product  1--1 ProductInventory
Product  1--* ProductImage, InventoryLog, CustomerWishlist, OrderItem

User 1--* Order, CustomerAddress, CustomerWishlist, ContactInquiry, OrderLog
Order 1--* OrderItem, Payment, OrderLog
NotificationTemplate 1--* NotificationLog
Role 1--* RolePermission
```

## 5. Module-wise functionality

### Storefront modules

- **Home/content:** home page, banners, product sections, about, terms, privacy, FAQs, shipping policy.
- **Catalog:** category/subcategory/product detail pages, search, autocomplete suggestions, active product/vendor filtering.
- **Location:** location autocomplete, country/city/zone lookup, session location selection, vendors-by-location.
- **Cart:** add, update quantity, remove, clear, guest-to-authenticated merge, vendor consistency, stock validation.
- **Promotions:** coupon apply/remove and offer-aware price presentation.
- **Checkout/payment:** verified-email checkout, order placement, Stripe Checkout redirect, webhook return, success/cancel/retry.
- **Customer account:** profile, avatar, addresses, default address, wishlist, order list/detail/status, cancel, reorder.
- **Customer notifications:** unread listing and read/read-all operations.
- **Contact:** public inquiry submission.

### Admin modules

- **Dashboard/module shell:** dashboard and module routing.
- **Location masters:** country, city, region/zone CRUD; cascading selection support.
- **Vendor management:** vendor CRUD, location filters, setup/auth fields, vendor status and inventory integration fields.
- **Catalog:** category, subcategory, product CRUD, image management, tax assignment, pricing/discounts, bulk CSV import/template.
- **Inventory:** inventory records, stock adjustments, low-stock threshold/flag, inventory history.
- **Orders:** list/filter, create/edit/detail, status transitions, order logs, cancellation/restoration behavior.
- **Payments:** payment list/create/edit/delete and gateway response visibility.
- **Delivery:** location-based delivery availability and charge configuration.
- **Coupons:** global/vendor coupon CRUD, type/value/minimum/expiry/active controls.
- **Notifications:** template CRUD, notification alerts/read/read-all, delivery logs.
- **Customers:** customer CRUD/detail/status toggle and account information.
- **Roles/users:** admin user CRUD, role CRUD, module CRUD permissions.
- **System configuration:** key/value settings editor.
- **Reports:** report index, report detail by slug, export endpoint; exact report set is controller-defined.
- **Contact inquiries:** inbox list/detail/status/read/delete.

### Vendor modules

- **Dashboard:** vendor summary page.
- **Authentication/onboarding:** login/logout, setup token, OTP password reset.
- **Products:** vendor-scoped CRUD, product images, pricing/discounts, inventory mode, CSV bulk import/template.
- **Coupons:** vendor-scoped CRUD for vendor discounts.
- **Orders:** vendor-scoped list/detail and accept/reject/processing/dispatched/delivered actions.
- **Payments:** vendor-visible payment listing/filtering.
- **Notifications:** vendor alerts, read/read-all, low-stock/order notifications.
- **Reference views:** read-only countries, cities, zones, categories, subcategories, customers, taxes, inventory, delivery, notifications, reports.

## 6. Route surface

The complete route declaration is in `routes/web.php`. The main route groups are:

- Public/storefront routes at `/`, `/categories`, `/subcategories`, `/products`, `/search`, `/cart`, `/location`, `/checkout`, `/contact-us`.
- Authenticated customer routes at `/account`, `/notifications`, `/payments/checkout`.
- Stripe webhook at `/stripe/webhook`, explicitly exempted from CSRF middleware.
- Admin routes under `/admin`, protected by `auth` and `admin`; resources cover master and operational modules.
- Vendor routes under `/vendor`, protected by `vendor`; vendor setup is token-based and outside the authenticated group.

## 7. Events, listeners, jobs, and services

| Component | Purpose |
|---|---|
| `OrderPlaced` | Carries a newly placed order to notification listeners |
| `TriggerNotificationEvent` | Generic trigger/data contract for template notifications |
| `SendCustomerBellOrderPlacedNotification` | Customer database bell notification |
| `SendVendorNotification` | Vendor order notification |
| `DispatchOrderPlacedTemplateNotification` | Starts configured order-placed template notification |
| `SendTemplateNotification` | Finds template, renders placeholders, sends/logs notification |
| `InventoryService` | Transactional adjustment, POS sync handling, order deduction/restoration, low-stock alerts |
| `OrderLifecycleService` | Validates transitions, updates status, writes order logs, dispatches status notifications |
| `VendorOrderWorkflowService` | Vendor transition facade, acceptance stock checks, auto-cancellation |
| `StripeCheckoutService` | Creates/retrieves Stripe Checkout sessions and builds line items |
| `ProductBulkImportService` | CSV parsing, normalization, validation, product/inventory creation |
| `ProductBulkTemplateService` | Generates admin/vendor CSV templates |
| `SyncPosStockJob` | Queued POS reduction/synchronization boundary |
| `ReversePosStockJob` | Queued POS stock restoration boundary |
| `PasswordService` / `SmsService` | Password and SMS abstractions; SMS currently logs in development |

## 8. Operational and security considerations

- Keep `.env`, `APP_KEY`, Stripe secrets, Google Maps keys, API keys, and vendor credentials out of source control.
- Run a queue worker for POS jobs and queued notifications.
- Configure Stripe webhook signing secret and validate webhook events in production.
- Use `storage:link` or the application's upload routes as required by deployment.
- Customer/admin/vendor sessions and guards are logically separate; verify guard configuration before deploying behind a shared domain.
- Uploaded image paths are validated before serving, but upload validation and storage permissions should still be reviewed for production hardening.
- `vendors.credentials` is a long-text field intended for sensitive integration data; encryption-at-rest and redaction from logs should be enforced operationally.
- Soft-deleted master records remain in the database and can affect uniqueness/index behavior depending on query scope.

## 9. Implementation gaps and design observations

These are not necessarily defects, but they are important for maintainers and future requirements:

1. **No persistent cart table:** cart state is session-based; cross-device carts and abandoned-cart reporting are not represented.
2. **No order address snapshot:** customer address rows can change after checkout. The order schema stores no immutable shipping-address snapshot.
3. **No order coupon relation:** the applied coupon/code and discount amount are not independently auditable at order level.
4. **Two order status columns:** `order_status` is the lifecycle field; `status` was added for Stripe compatibility and is synchronized by the lifecycle service.
5. **Vendor zone duplication:** `region_zone_id` is the active Eloquent relationship; `zone_id` is an additional nullable legacy column without a declared foreign key.
6. **Several high-volume query fields lack explicit indexes:** product vendor/category/status, order status/customer/vendor/date, payment status, inventory product FK beyond its unique key, and notification template trigger/channel may need composite indexes as data grows.
7. **Inventory reservation semantics are log-based:** acceptance detects an existing reduce log by exact reason text. A dedicated reservation table or reservation status would provide stronger idempotency and auditability.
8. **Tax is product-linked but not order-snapshotted:** historical tax rate used at checkout is not stored in `order_items`.
9. **Report catalog is application-defined:** report names and calculations must be read from `ReportsController` and its views when formalizing finance/BI requirements.
10. **POS integration boundary:** queued jobs establish the workflow, but production synchronization requires a concrete API contract, authentication, retries, idempotency, and reconciliation policy.

## 10. Source map for maintainers

- Routing: [routes/web.php](routes/web.php)
- Models and relationships: [app/Models](app/Models)
- Business services: [app/Services](app/Services)
- Controllers: [app/Http/Controllers](app/Http/Controllers)
- Schema history: [database/migrations](database/migrations)
- Seed data: [database/seeders](database/seeders)
- UI: [resources/views](resources/views)
- Browser assets: [resources/js](resources/js) and [resources/css](resources/css)
- Tests: [tests](tests)
