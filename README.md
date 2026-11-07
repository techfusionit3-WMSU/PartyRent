# PartyRent — Party Supplies Rental Marketplace (Laravel)

A full-stack Laravel 12 + MySQL build of the PartyRent prototype: customers rent party supplies from local
suppliers, suppliers manage stock and rentals, cashiers verify payments, and admins oversee the platform.

The business rules from the prototype are ported to the server, rule for rule, in `app/Domain`:

| Class | What it owns |
|---|---|
| `Inventory` | Date-by-date availability: `Available = Total − Reserved − Damaged − Missing − Maintenance − SetAside`. Only accepted-or-later rentals reserve stock; pending requests are shown as conflicts. Packages expand into their items. |
| `Pricing` | Tiered (quantity-based) prices per day, package value, delivery fee, supplier payment-policy amounts. |
| `Finance` | Payment schedules (full / down / installment / reservation), balances, deposit and compensation tracking. Only **verified** payments count. |
| `Rentals` | Every workflow: cart, submit, accept (stock re-checked inside a lock so two people can't double-book), decline, cancel per policy, fulfillment steps, return inspection, deposit settlement, completion, payments, overdue scan, supplier onboarding, catalog edits. Notifications and the audit trail are written here. |
| `Ai` | PartyRent Assist: a rule-based English/Filipino interpreter, event planner, alternatives finder and chatbot. It only suggests from real listings and live stock, and never books, prices, accepts or verifies anything. |

## Run it locally (XAMPP)

```bash
# 1. start MySQL from the XAMPP control panel, then create the database
mysql -uroot -e "CREATE DATABASE partyrent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. install and configure
composer install
cp .env.example .env        # DB_* already point at XAMPP's MySQL
php artisan key:generate
php artisan migrate --seed  # demo data, dated relative to today
php artisan storage:link

# 3. serve
php artisan serve           # http://127.0.0.1:8000
```

Reset the demo data at any time with `php artisan migrate:fresh --seed`.

### Demo accounts (password `demo123`)

| Account | Role |
|---|---|
| maria@demo.ph | Customer |
| juan@demo.ph | Customer + approved Supplier (Happy Moments) — switch modes from the profile menu |
| bea@demo.ph | Customer with a pending supplier application |
| cashier@partyrent.ph | Cashier |
| admin@partyrent.ph | Admin (all permissions) |
| paolo.admin@partyrent.ph | Admin with restricted permissions |
| super@partyrent.ph | Superadmin |

The demo-account buttons on the login page are hidden when `APP_ENV=production`.

## Scheduled tasks

`php artisan partyrent:scan-overdue` flags overdue payments and notifies the customer, supplier,
cashier and admins (rentals are never cancelled automatically). It is scheduled hourly — run
`php artisan schedule:work` in development, or add the standard `schedule:run` cron in production.

## Tests

```bash
php artisan test
```

The tests run against an in-memory SQLite database, so your MySQL data is never touched. They render
every page for every role, check access rules, and walk one rental through its full lifecycle
(cart → request → accept → pay → verify → release → inspection → deposit settlement → completion → review).

## Security notes

- Passwords are hashed (bcrypt). Login, registration, password reset and the chat endpoint are rate-limited.
- Every role-restricted page goes through the `role:` middleware; suppliers and customers can only open their own records.
- Payment proofs are stored on the private disk and only served to the customer, the supplier, cashiers and admins.
- Password-reset emails use the `log` mailer by default — set `MAIL_*` in `.env` to send real email.

## Build status

**Phase 1 (done):** marketplace, browse/filters/compare, product & package pages with the per-day scheduler and live
availability calendar, AI planner and chat, rental list, checkout, rental tracking, payments with proof upload,
cancellations, reviews, Start Selling wizard; supplier dashboard, requests and the full fulfillment workflow,
products, packages, inventory holds and condition counts, payments, shop profile; cashier dashboard, verification,
offline recording, balances, history and receipts; admin dashboard, supplier applications, users, transactions,
activity log; notifications and account settings for all roles.

**Phase 2 (done):** supplier inventory calendar (day/week/month), damage & compensation page and business reports
with CSV export; cashier financial reports; admin listings moderation, categories, complaints (incl. "Log an issue")
and platform reports with growth charts; superadmin platform overview, monitoring, platform users, administrators,
roles & permissions editor and system settings. Sidebar links are hidden when a staff member lacks the permission.
Progress log: `docs/PHASE2.md`.

