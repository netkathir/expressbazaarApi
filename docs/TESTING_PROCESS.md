# ExpressBazaar Testing Process: Current State & Improvement Plan

How ExpressBazaar is tested today, and how we make it structured, step by step. Rule of thumb: **once a module is implemented, test it, then test every module connected to it.**

## 1. Existing testing process

Work arrives as new features (inventory, reservation, Zoho sync, vendor workflow), bug reports, UI changes and requirement changes. Testing is phase-based and change-driven: build the phase, test it manually (one step at a time on `127.0.0.1:8000`, dev DB), fix, re-test, then move to the next phase.

- **Functional & bug confirmation:** the new feature works; the reported issue no longer occurs.
- **Validation & UI checks:** mandatory fields, formats, quantities, popups, buttons, navigation (vendor form, checkout, product bulk import).
- **Workflow & status checks:** cart → checkout → reservation → payment → order → vendor workflow; status transitions; DB values (stock, reserved qty, `expires_at`, `checkout_key`, order/payment status).
- **Role, integration & existing-function checks:** Admin / Vendor / Customer behaviour, role permissions, Stripe return, Zoho webhook / push / reconcile, notifications, and that nothing else on the module broke.
- **Automated tests today:** `InventoryManagementTest`, `InventoryReservationTest`, `ProductCreateTest`, `RolePermissionEnforcementTest`, `StripePaymentReturnTest` (need `APP_URL=http://localhost`).

## 2. Gaps as the platform grows

- Testing centres on the changed phase; connected modules are missed when nobody lists them.
- Manual scenarios live in memory/chat, not in a shared sheet, so they are re-tested by hand and not reused.
- No fixed regression set or execution history (what was tested, when, by whom, on which commit).
- Open findings are not tracked centrally (e.g. "requires_review" page wording, cancelled paid orders not refunded, `zoho_sync_logs` unused, expiry job only runs if the scheduler runs).
- Only part of the code has automated tests (no coverage for vendor workflow, notifications, coupons/tax/delivery, bulk import, Zoho webhook/reconcile).

## 3. How we improve

| Improvement | What to do | Benefit |
|---|---|---|
| Impact analysis | Before testing, list changed module, related modules, tables, roles, upstream/downstream flows (see section 5 map). | Related issues found early |
| Test-case sheet | One shared sheet per module/flow: ID, steps, expected, actual, status, tester, date, commit. | Reusable, becomes regression suite |
| Regression levels | L1 smoke after every deploy; L2 module regression after any fix; L3 end-to-end before release. | Right depth per change |
| Two-pass self-testing | Pass 1: confirm the change while developing. Pass 2: after a break, run the written checklist as a QA (as if not the author), then UAT with the client/vendor. | Fewer missed cases without extra staff |
| Defect tracking & evidence | Statuses New → Testing → UAT → Closed; keep screenshots, test data, commit hash, log lines. | Traceable, auditable |
| Automate stable checks | Convert settled scenarios into PHPUnit feature tests; keep changing screens manual. | Faster, repeatable |

**Improved flow:** Requirement → Impact analysis → Test scenarios → Development → Developer test → Module test → Integration test (connected modules) → Regression (L1/L2/L3) → UAT → Deployment → Production check → Documentation → Close.

## 4. Regression levels

- **L1 Smoke (after every deploy, ~10 min):** admin/vendor/customer login; storefront loads; product list/detail; add to cart; checkout opens; Stripe test payment succeeds; order appears in vendor panel.
- **L2 Module regression (after any fix):** all test cases of the changed module plus its connected modules from the map below.
- **L3 End-to-end (before release):** full journey for each role, Zoho both directions, failure and expiry paths, scheduler running (`schedule:work`), multi-vendor.

## 5. Module connection map (use for impact analysis)

| If you change… | Also test… |
|---|---|
| Product / ProductInventory | Storefront listing & stock display, cart, bulk import, Zoho catalog sync, inventory logs, vendor product pages |
| InventoryService / stock quantity | Reservation, order placement, cancellation restore, Zoho push, reconcile, low-stock display |
| InventoryReservationService | Checkout, payment return, reservation expiry job, "insufficient stock" message, concurrent buyers, order creation |
| Checkout / StorefrontController (`checkout_key`) | Coupons, tax, delivery config, address, Stripe session, duplicate-click / back-button, reservation reuse |
| Payment / StripeCheckoutService | Order status, expired-reservation payment, payment return, notifications, refund/cancel behaviour |
| Order / OrderLifecycleService | Vendor workflow, order logs, stock restore, notifications, customer order history, Zoho order push/cancel |
| VendorOrderWorkflowService | Order statuses, customer notifications, inventory, vendor dashboard, role permissions |
| Zoho webhook / reconcile | Inventory, reservations (reserved stock kept), inventory logs, multi-vendor secrets, failed-push retry |
| Coupon / Tax / DeliveryConfig / Region zones | Cart totals, checkout total, Stripe amount, order totals, invoices |
| Roles & permissions | Every Admin and Vendor menu/route, direct URL access, API-style endpoints |
| Notifications / SMS / templates | Order events, OTP/login, password reset, template variables |
| Auth (Admin/Vendor/Customer) & OTP | Password reset, setup flow, session/guard separation, role redirect |

## 6. Test-case sheet format

Columns: `ID | Module | Scenario | Pre-condition / test data | Steps | Expected | Actual | Status (Pass/Fail/Blocked) | Tester | Date | Commit | Defect ID | Evidence`

ID pattern: `INV-001`, `RSV-001`, `CHK-001`, `PAY-001`, `ORD-001`, `VND-001`, `ZOH-001`, `AUTH-001`, `ROL-001`.

### Starter scenarios (from the current reservation/inventory/Zoho phases)

| ID | Scenario | Expected |
|---|---|---|
| INV-001 | Adjust stock from vendor panel | Qty updates, `InventoryLog` row written |
| INV-002 | Order more than available | "Insufficient stock" message names the product |
| RSV-001 | Checkout creates reservation | Reserved qty up, `expires_at` set, available qty down |
| RSV-002 | Reservation expires unpaid (scheduler on) | Reserved qty released, `expires_at` not auto-changed on update |
| RSV-003 | Pay after reservation expired | Handled safely, no oversell, clear message |
| RSV-004 | Two buyers, last unit | Only one succeeds |
| CHK-001 | Double-click / back button on checkout | `checkout_key` reuses only recent pending unpaid order, no duplicates |
| PAY-001 | Stripe test payment success | Order paid, reservation converted, stock deducted |
| PAY-002 | Stripe cancel/return | Order not paid, reservation released |
| ORD-001 | Cancel order | Stock restored locally and in Zoho |
| ZOH-001 | Zoho webhook updates stock | EB qty matches Zoho, log written |
| ZOH-002 | Reconcile after missed webhook | Fixes qty, idempotent, keeps reserved stock |
| ZOH-003 | Zoho API down during push, then reconcile | No stock resurrected; failure recorded |
| ZOH-004 | Multi-vendor (vendor 1 and 2 secrets) | Each webhook updates only its own vendor's stock |
| ROL-001 | Vendor opens admin URL directly | Denied |

## 7. Defect and change record

Each issue: ID, module, severity, steps, expected vs actual, status (New → Testing → UAT → Closed), fix commit, re-test date, evidence.

Currently open (from phase testing): requires_review page wording; cancelled paid orders stay paid with no refund; `zoho_sync_logs` unused (no persisted failed pushes/retry); expiry job depends on the scheduler.

## 8. Roadmap

- **Immediate:** create the shared test-case sheet with the starter scenarios; log open findings; record results after each phase; finish remaining Phase 5 (failed push + reconcile, multi-vendor, API limits, 15-min scheduler).
- **Next:** impact analysis for every change using the map; L1/L2/L3 suites; defect status and re-test history; vendor UAT records; add `APP_URL=http://localhost` to `phpunit.xml`.
- **Future:** automate stable flows as PHPUnit tests (checkout → reservation → payment → order, Zoho webhook/reconcile, role permissions, vendor workflow); keep frequently changing UI manual; run tests in CI on every push.

**Expected outcome:** fewer regression defects, connected-module coverage on every change, repeatable testing independent of one person, and recorded evidence of every change.
