# PartyRent — AI-Assisted Party Supplies Rental Management System

A complete, interconnected frontend for party supply discovery, rental coordination and supplier management.
Built with plain **HTML, CSS and JavaScript** (no build step). Data is persisted in the browser (`localStorage`) so every workflow can be demonstrated end-to-end.

## Run it

Open `index.html` directly in Chrome/Edge/Firefox, **or** (recommended) serve the folder:

```bash
cd partyrent
python3 -m http.server 8080      # then open http://localhost:8080
```

Use **Login → Demo accounts** (password `demo123` for all):

| Account | Role |
|---|---|
| maria@demo.ph | Customer |
| juan@demo.ph | Customer **+ Supplier** (Happy Moments Party Rentals) — use the profile menu to switch modes |
| bea@demo.ph | Customer with a **pending individual** supplier application |
| liza@demo.ph | **Organization Admin** of Elegant Occasion Rentals (organization supplier) |
| cashier@elegantoccasion.ph | Cashier of Elegant Occasion Rentals (sees only that shop's payments) |
| inventory@elegantoccasion.ph | Inventory Staff of Elegant Occasion Rentals |
| kristine@demo.ph | Customer with a **pending organization** application |
| admin@partyrent.ph | Admin (full admin permissions) |
| paolo.admin@partyrent.ph | Admin with restricted permissions |
| super@partyrent.ph | Superadmin |

"Reset demo data" (footer / sidebar / Superadmin → System Settings) restores the sample data.

## Folder structure

```
index.html, login.html, register.html, forgot-password.html, reset-password.html
notifications.html, account.html          shared pages (layout adapts to the active role)
customer/    browse, ai, suppliers, supplier, product, packages, package, cart, checkout,
             confirmation, rentals, rental, payments, profile, start-selling
supplier/    dashboard, requests, request, schedule, products, product-form, packages,
             inventory, calendar, payments, damages, reports, shop
cashier/     dashboard, verification, record, balances, history, receipts, reports
admin/       dashboard, users, applications, listings, categories, transactions, complaints, activity, reports
superadmin/  dashboard, monitoring, users, reports, admins, roles, audit, settings
css/base.css        design tokens + components (buttons, forms, cards, badges, tables, modals)
css/layout.css      shells, marketplace, dashboards, floating bottom nav, responsive breakpoints
js/core/            utils, seed (sample data), store (data layer), inventory, pricing, finance,
                    auth (roles, mode switch, permissions), services (all workflows), ai
js/ui/              icons, ui components, charts, scheduler (multi-day editor), layout + bootstrap
js/pages/           page modules per role
```

## Key business logic (single source of truth)

- **Inventory** (`js/core/inventory.js`): `Available(date) = Total − Reserved(date) − Damaged − Missing − Maintenance(date) − Set-aside(date)`.
  Reserved comes only from accepted-or-later rentals; package lines expand into their component items. Pending requests are reported as conflicts but never consume stock. Every page (marketplace, cart, checkout, request review, calendar, AI) calls the same functions.
- **Multi-day rentals**: each line stores `{ date: qty }`, so quantities can differ per day and dates can be non-consecutive. Tiered pricing is applied per date.
- **Protection**: acceptance re-checks every date; stock reductions, holds and damaged/missing adjustments are blocked if they would break accepted reservations.
- **Payments** (`js/core/finance.js`): only *verified* payments count. Reservation fee is either credited to the down payment or added once — never twice. Security deposits and damage compensation are tracked separately from rental income. Overdue items notify the customer, supplier, cashier and admins and are logged; rentals are never auto-cancelled.
- **Maximum rental duration** (optional, per product): suppliers set it in *Products → Edit → Maximum rental duration* (or tick "No maximum"). Within one booking, the period from the first to the last day a product is rented (directly or inside a package) may not exceed the limit. It is enforced in `Services.addToCart`, `Services.submitRequest` and flagged in `Services.acceptReview`, so the product page, package page, Rental List, checkout and AI planner all follow the same rule. Separate bookings are checked independently. A real backend must apply the same check in its "create request" endpoint (`Services.durationIssues` is the reference rule).
- **Price snapshot**: a request stores its quote at submission, so later price changes don't alter accepted rentals.
- **AI** (`js/core/ai.js`): a local, rule-based demo engine (English + Filipino). It interprets descriptions into editable filters, plans checklists and suggests alternatives **only from existing listings with real availability**, and its chatbot refuses to accept, cancel, reserve, price or verify anything.

## Rental dates & browsing

- After a customer logs in, a one-time prompt offers **Select Rental Dates** (start/end with live duration) or **I'm Just Browsing**. It is not shown again until the next login, and never to staff.
- The selected period (`js/core/rental-period.js`) follows the customer across browsing, product and package pages, the Rental List and checkout, and is cleared at logout.
- On the browse page the dates sit in a bar above the products, e.g. `12/12/2026 - 12/15/2026 | 4 Days`. Filters open in a side panel from the **Filters** button.

## Listing status & admin moderation

Products and rental packages publish **immediately** (no approval step) and share three statuses, defined once in `Services.LISTING`:

| Status | Meaning | In marketplace |
|---|---|---|
| Active | Live for customers | Yes |
| Hidden by admin | Removed by an admin; supplier keeps it but cannot republish | No |
| Inactive | Turned off (or archived) by the supplier | No |

`admin/listings.html` (Products and Rental packages tabs) → **View** opens the customer page (admins may preview non-active listings), **Manage** opens `admin/edit-listing.html?id=…` (packages: `?type=package&id=…`).
- **Hide** requires a reason. It removes the listing from the marketplace without touching accepted, upcoming or ongoing rentals, notifies the supplier and writes one Activity Log entry. **Unhide** restores the listing to Active, also with a notification and log entry.
- **Delete Listing** asks for confirmation. It is refused while any pending, accepted, upcoming or ongoing rental depends on the listing. A deleted listing disappears from every list; a minimal record is kept so past rentals still show the item name.
- All three actions go through `Services.adminHideListing / adminUnhideListing / adminDeleteListing`. Those functions check the `listings.manage` permission and re-check reservations. Supplier saves and on/off toggles cannot clear an admin hide.

## Date-based inventory (how availability is calculated)

- **Total inventory** (`product.totalStock`) is never reduced by bookings.
- **Not rentable:** damaged + missing units (until the supplier resolves them) and maintenance / set-aside holds (for the dates they cover).
- **Booked:** units in *accepted or later* rentals (accepted → preparing → released → return pending), counted **only on the dates each booking covers**. Package bookings count their component items. Pending requests never consume stock; cancelled/declined ones stop counting immediately; returned items count again after inspection (damaged/missing ones are moved out of rentable stock).
- **Available for a day** = Total − Not rentable − Booked that day. **Available for a period** = the lowest daily figure in the period (the busiest day), not total minus every booking in the range.
- Example: 120 owned, 20 in maintenance, 20 booked Monday → Monday 80, Tuesday 100.
- Customers only see stock once they pick rental dates (browse cards, product and package pages). Supplier and admin screens show the date they refer to.
- One engine (`js/core/inventory.js`) feeds browse cards, product/package pages, the Rental List, checkout, request acceptance, supplier inventory/calendar/dashboard and reports.
- Stock is re-checked at add-to-list, at submission and again when the supplier accepts. Those writes run inside `Store.transaction()`, a short lock shared by all open tabs that reloads the latest saved data before checking, so two tabs cannot confirm the same units.

**With a real database**, do the same check-and-write inside one transaction with row locks, e.g.:

```sql
BEGIN;
SELECT id FROM products WHERE id = $product FOR UPDATE;            -- serialize bookings per product
SELECT d.day, COALESCE(SUM(bd.qty),0) AS booked
  FROM generate_series($start::date, $end::date, '1 day') d(day)
  LEFT JOIN booking_days bd ON bd.product_id = $product AND bd.day = d.day
  LEFT JOIN bookings b ON b.id = bd.booking_id AND b.status IN ('accepted','in_progress','ready_pickup','out_delivery','ongoing','return_pending')
 GROUP BY d.day;                                                     -- usable − booked must be ≥ requested for EVERY day
-- (also check the product's max_rental_days against $end − $start + 1)
UPDATE bookings SET status = 'accepted' WHERE id = $booking;        -- or INSERT booking_days rows
COMMIT;
```

## Connecting a backend

All reads/writes go through `Store` (data access) and `Services` (workflows). Replace their bodies with `fetch()` calls, e.g. `Services.submitRequest → POST /requests`, `acceptRequest → POST /requests/:id/accept`, `verifyPayment → POST /payments/:id/verify`, `saveProduct → PUT /products/:id`. Replace `AI.interpret / AI.chat` with calls to an AI service and keep the same guardrails.

> Demo notice: authentication, payments, notifications and AI are simulated in the browser. Passwords stored in localStorage are not secure.


## Version 4 — payment plans, time slots, locations, damage policy, organizations

The demo data is stored under a new key (`partyrent_db_v4`), so the sample data starts fresh once.

### Payment plans (per supplier, Shop Settings → Payment plans)
* **Standard Payment Plan** – Reservation Fee % + Down Payment % + Final Balance (due on the event date).
* **Full Payment Plan** – 100 % in one payment.
* **Installment Payment Plan** – Reservation Fee + Down Payment + installments the customer proposes at checkout; the supplier approves or rejects them. Only available when the event is at least the configured number of months away; every installment must be due on or before the event date.
* Reservation fee and down payment are always **credited** (Final Balance = Total − Reservation Fee − Down Payment). Deadlines count from acceptance and never pass the event date. Percentages are validated (≤ 100 % combined) and always use the individual supplier order total.
* Duplicate reference numbers and payments above the outstanding balance are rejected. Statuses: Pending, Partially Paid, Fully Paid, Overdue.

### Rental List & checkout
* Items from several shops in one list, grouped by shop, with per-item, per-shop and **Select all** checkboxes. Only selected items go to checkout; the rest stay in the list.
* Checkout creates **one request per supplier**, each with its own event date, delivery locations, rental times, services, payment plan and schedule. Everything is re-validated in `Services.submitOrders` (one transaction).

### Rental time & time-based stock
* Customers pick start/end times (12-hour format) within the supplier's rental hours; the duration is calculated automatically. Products/packages may set minimum/maximum hours.
* Availability = total − the **peak** quantity of bookings overlapping the requested time (plus supplier preparation and recovery buffers) − unusable units. Example: 50 chairs, 25 booked 6–11 AM → 25 left in the morning, 50 in the afternoon; after 20 more 1–6 PM → morning 25, afternoon 30.

### Rental Charges
Collapsible section (product page estimate, cart, checkout, order details) with Rental Fee, Delivery Fee (per delivery location / zone) and itemised Service Fees. The subtotal is the basis of the payment plan.

### Multi-location inventory & delivery
* Suppliers manage inventory locations, stock per location (total = sum), transfers and a movement log (Inventory Locations page).
* Customers can add several delivery/event locations to one order and split quantities between them, each with its own address, delivery fee and rental time. Splitting never changes the price tier.
* Each booking line is allocated to one source location (proposed automatically, reviewed/changed by the supplier, approved on acceptance) and checked against that location's stock. Damaged/missing/written-off units are tracked per product (not per location).

### Damage & loss policy
Shop Settings → Damage policy: Repairable %, Non-Repairable %, Lost % by condition (Brand New / Good as New / Used), optional per-category rules. Compensation = product value × % × quantity. The supplier reviews and confirms the assessment before anything is charged; repairable units stay unavailable until marked repaired, non-repairable and lost units leave usable stock; one assessment per rental.

### Supplier organizations & applications
* Two application routes (Individual / Organization–Company) with different information and document checklists; admin can approve, reject or request revision.
* The applicant becomes the **Organization Admin**; Team & Roles adds Cashier, Inventory Staff and Staff accounts tied to the same supplier ID. Cashiers are no longer platform-wide — they can only see and act on their own shop's payments. Individual suppliers can verify and record their own payments without a cashier.
