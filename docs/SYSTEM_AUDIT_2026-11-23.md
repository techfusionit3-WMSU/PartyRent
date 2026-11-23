# PartyRent system update and audit (2026-11-23)

This covers the "SYSTEM UPDATE" document: what was implemented (automatic payment checking, the payment flow, working demo accounts) and the audit report it asks for. As the document says, the audit findings are reported here and **are not fixed until you approve them**.

How sure each finding is:

- **Confirmed**: seen in the code and, where possible, reproduced in a test or in the running app.
- **Potential**: the code allows it, but it was not reproduced.
- **Needs clarification**: a business decision, not a bug.

---

## 1. What was implemented

### 1.1 Payment checking is automatic on the PartyRent admin side

| Document step | Where it happens now |
|---|---|
| 1. Customer pays with GCash, Maya or a card | Rental page, Make a payment, "Pay online" (`OnlinePay::start`). This opens the gateway's checkout page: PayMongo, Xendit, or the practice gateway. |
| 2. Gateway processes the payment and tells PartyRent | The gateway calls `POST /webhooks/paymongo` or `POST /webhooks/xendit`. The practice gateway sends the same signed webhook (`PayMongo::practiceNotify`, `EventOnlinePay::practiceNotify`). |
| 3. PartyRent collects; the notification is verified before the order changes | `PayMongo::receiveWebhook` checks the `Paymongo-Signature` HMAC, and the Xendit callback checks `x-callback-token`. A bad signature is refused with HTTP 401 and recorded as **Rejected**. Only after that check does `OnlinePay::settle` verify the payment (`Rentals::confirmPayment`). |
| 4. Seller receives a payout after deductions | Unchanged: `Payouts` pays the shop after commission, on its 15-day or monthly schedule (Admin, Supplier Payouts). |
| Backend creates the order, calculates the total, requests payment | `Rentals::submitPayment` creates the payment row (`awaiting`) after `validatePayment`. `PayMongo::open` / `Xendit::open` create the checkout. |
| Webhook says paid or failed; backend verifies it | Paid: verified and a receipt issued. A wrong amount, or more than the balance: sent to the cashier as **Needs review**. Unpaid after 24 hours: the checkout is closed. |
| Database: order status becomes PAID and the transaction is recorded | `payments.status = verified` plus `receipt_no`. The order's payment status (`Finance::compute`) becomes **Partially Paid** or **Fully Paid**. A new **`payment_transactions`** table records every webhook, return and check. |

**Webhooks get lost, and can't reach a laptop on localhost.** So PartyRent now also asks the gateway about every open checkout:

- every minute through the scheduler (`php artisan partyrent:check-payments`);
- when an admin, superadmin or cashier page loads (at most once a minute);
- from **Admin → Online Payments → Check now**.

**New admin page: Admin → Online Payments** (admins with `transactions.view`, and the superadmin). It shows:

- the 4-step flow;
- open checkouts and when each was last checked;
- automatic verifications today, money collected online, and gateway fees;
- the gateway set-up (gateway, test/live/practice mode, webhook secret);
- the full gateway transaction log, with filters.

Nothing on the page verifies a payment by hand. Bank transfers and cash are unchanged: the cashier still checks transfers and the shop confirms cash, because no gateway confirms those.

**Event company installments paid online** go through the same verified-notification path and are recorded the same way.

### 1.2 Demo accounts work

The cause was confirmed: the local database was **6 migrations behind** and was missing **6 of the 22** login-page accounts:

- `desk@elegantoccasion.ph`
- `cashier@partyrent.ph`
- `events@demo.ph`
- `coordinator@`, `finance@` and `crew@grandfiesta.ph`

What was done:

- Ran the pending migrations.
- Added `php artisan partyrent:demo-accounts`. It adds only the missing demo people and the Grand Fiesta event company with its packages, bookings and team, and erases nothing.
- Set `SEED_DEMO=true` in `.env` and `.env.example`, so a fresh `migrate:fresh --seed` creates every account.
- The database was backed up first: `storage/backups/partyrent-before-system-update-2026-11-23.sql`.
- All 22 accounts were checked against the real database with password `demo123`.

### 1.3 Files

**New**

- `app/Domain/PaymentChecks.php`
- `app/Models/PaymentTransaction.php`
- `app/Http/Controllers/Admin/PaymentMonitorController.php`
- `resources/views/admin/payment-monitor.blade.php`
- `database/migrations/2026_11_23_000100_payment_transactions.php`
- `tests/Feature/AutomaticPaymentChecksTest.php`
- `tests/Feature/DemoAccountsTest.php`
- `.claude/launch.json`

**Changed**

- `app/Domain/PayMongo.php`, `OnlinePay.php`, `Xendit.php`, `EventOnlinePay.php`
- `PayMongoWebhookController`, `XenditWebhookController`
- `Customer/RentalController`, `Customer/EventController`, `Customer/PracticeCheckoutController`
- `AdminController`, `SuperController`, `CashierController` (run the check on page load)
- `app/Models/Payment.php` (`checked_at` cast)
- `routes/web.php`, `routes/console.php`
- `resources/views/layouts/dash.blade.php` (menu)
- `database/seeders/DemoSeeder.php` (`topUp`)
- `.env.example`, `README.md`

### 1.4 Tests

- `php artisan test`: **235 passed** (225 existing and 10 new). The baseline before the change was 225 passed.
- New tests cover:
  - a signed webhook verifying the order and recording the transaction;
  - a forged signature being refused and logged;
  - the automatic check settling a lost webhook;
  - a checkout expiring after 24 hours;
  - refunds being left alone;
  - a wrong amount going to the cashier;
  - the admin page, Check now and access rules;
  - an event installment paid through the signed notification;
  - every login-page account signing in;
  - the non-destructive demo top-up.
- **Manual run** (`php artisan serve`): logged in as Maria, paid PR-0017's ₱805 balance online with Maya on the practice gateway. It was verified automatically (receipt OR-10029), PR-0017 became **Fully Paid**, and the admin page showed the webhook transaction with its fee. No server errors.

---

## 2. Audit report

### A. System flow overview

```
Customer (market) ──► Cart / Rental List ──► Checkout ──► RentalRequest (pending)
      │                                                        │ shop accepts (stock re-checked in a lock)
      │                                                        ▼
      │                      Payments ◄────── accepted (24 h to pay the first payment)
      │   online ──► gateway ──► webhook / check ──► verified ──► reserved_at, receipt, notifications
      │   transfer ──► proof ──► PartyRent cashier verifies          │
      │   cash ──► shop confirms                                     ▼
      │                                         preparing → out for delivery → ongoing → return
      │                                                        → inspection (damage / Shortfall cases)
      │                                                        → deposit settlement → completed
      ▼                                                                       │
Reviews ◄─────────────────────────────────────────────────────────────────────┘
                                                   Payouts: earnings − commission − cash kept → shop
Event companies: inquiry → quotation → booked → reservation / deposit / balance (EventPayment) → parts done → completed
Admin: applications, event-type verification, listings, categories, complaints, cases, disputes, payouts, online payments, reports
Superadmin: admins, roles & permissions, settings, pay accounts, audit
```

The business rules live in `app/Domain`:

- `Rentals`: lifecycle, payments and notifications
- `Finance`: schedules and balances
- `Inventory`: availability
- `Payouts`: what each seller earns
- `Events`, `EventParts`, `EventTeams`, `EventTypes`: event companies
- `Shortfalls`, `Damage`, `Refunds`: problems after the rental
- `OnlinePay`, `PayMongo`, `Xendit`, `PaymentChecks`: gateways

Controllers stay thin and call these classes. `Notifier` writes both the notifications and the audit log.

### B. Flow-by-flow audit

| # | Flow | Expected | Actual | Result |
|---|---|---|---|---|
| 1 | Sign-up, email code, login, mode switch | Account verified; role guard | `AuthController`, `EnsureRole`, `EnsureSameAccount`; covered by `EmailVerificationTest`, `AccountGuardTest`, `PagesTest` | OK |
| 2 | Demo login | All 22 accounts work | 6 were missing and the database was 6 migrations behind | **Fixed** (1.2) |
| 3 | Browse, Rental List, checkout, request | Stock and price checked server-side | `CartController`, `Inventory`, `Pricing`; Checkout*, DirectCheckout tests | OK |
| 4 | Shop accepts or declines | Stock re-checked in a lock | `Rentals::accept…`; `RentalLifecycleTest` | OK |
| 5 | Online payment → verified automatically | Gateway → verified webhook → PAID → recorded | Worked only when the return URL or webhook arrived; no transaction log; online payment was **off** locally (no keys, practice off) | **Fixed** (1.1) |
| 6 | Transfer or cash payment | Cashier verifies the transfer; the shop confirms cash | `Rentals::submitPayment`, `verifyPayment`, `canVerify`; `CashPaymentTest`, `PaymentChannelsTest` | OK (manual by design) |
| 7 | First payment not made in 24 h | Request cancelled, items released | `Rentals::expireUnpaid` (every 5 minutes). It skips requests with an open checkout, and open checkouts now close after 24 h. | OK |
| 8 | Fulfillment, return, inspection, deposit, completion | Steps can't be skipped | `Rentals::stepBlockers`; `RentalLifecycleTest` | OK |
| 9 | Cases, damage, refunds | Compensation and refunds through PartyRent | `Shortfalls`, `Damage`, `Refunds`; their tests | OK, see D-6 |
| 10 | Seller payout | After commission, on schedule | `Payouts::closeDue` (daily); `PayoutsTest`, `XenditPayoutsTest` | OK |
| 11 | Event inquiry → quotation → booking → installments | Same payment rules as rentals | Transfers go **to the business's own account**, which the business confirms; online installments go to PartyRent and are sent on **in full** | **Inconsistent** (D-3, D-4) |
| 12 | Event online payment with PayMongo keys | Works like rentals | `EventOnlinePay::mode()` supports only Xendit or practice | **Defect** (D-2) |
| 13 | Notifications and audit log | Every state change notifies the right role | `Notifier`, role routing (step 4 commit) | OK |
| 14 | Admin and superadmin modules | Permission-gated | `hasPerm` checks plus nav filtering; `PagesTest` renders every page for every role | OK |

### C. Cross-module alignment

**Connected correctly**

- Rentals ↔ Inventory: stock is reserved only after the first verified payment.
- Rentals ↔ Finance ↔ Payouts: only verified money counts, and commission is frozen on the earnings.
- Payments ↔ Notifications ↔ Audit log.
- Online gateway ↔ Cashier queue: anything that can't be auto-verified lands in Payments Waiting.
- Expiry ↔ open checkouts: they now close each other out cleanly.
- Roles ↔ nav ↔ middleware.

**Inconsistent or missing**

- Event payments vs. rental payments: different money path and different deductions (D-3, D-4).
- Gateway support: PayMongo works for rentals but not for event installments (D-2).
- Method labels: "Online payment (Xendit)" is shown whichever gateway is active (D-7).
- Cancelling or auto-declining a request does not close its open gateway checkout (D-1, D-5).

### D. Issues and findings

| ID | Issue | Files | Why it matters / impact | Recommended fix | Priority | Type |
|---|---|---|---|---|---|---|
| D-1 | When an accepted request is auto-declined (stock lost) or expires, its `awaiting` online checkout is set to `rejected` in the database, but the checkout page at the gateway stays open. If the customer pays it, `OnlinePay::settle` sees a non-awaiting payment and returns `done`. The money is taken and **nothing records it**. | `Rentals::dropUnreserved` (line ~929), `OnlinePay::settle` | Money collected with no record and no refund | Call `OnlinePay::discard()` (which expires the session or invoice) for awaiting payments before rejecting them. Also have `settle` log or hand a paid-but-rejected payment to the cashier for a refund. | **Critical** | Confirmed (code path) |
| D-2 | Event installments can be paid online only with Xendit or practice. With only PayMongo keys set, rentals can be paid online but events can't. | `EventOnlinePay::mode()`, `start()` | Inconsistent customer experience; the payment flow isn't applied to events | Add a PayMongo checkout branch to `EventOnlinePay` (same pattern as `PayMongo::open` / `sync`). | High | Confirmed |
| D-3 | Event transfers and GCash go **straight to the business's own account**, and the business confirms its own payments. Your flow's step 3 says the marketplace collects the money. | `Events` (submit / confirm payment), `customer/event.blade.php`, `business` payment pages | PartyRent can't guarantee the money or take commission on most event money | Decide whether events must follow the marketplace flow. If so, send all event payments to PartyRent's account (gateway or PartyRent pay accounts) and pay the business out through `Payouts`. | High | **Needs clarification** |
| D-4 | Online event payments are sent on to the business **in full**: no commission and no gateway fee taken off. | `Events::remit`, `Admin/EventBookingController` | Contradicts step 4 ("after deductions") and differs from rental payouts | Feed event payments into `Payouts` with `commissionPct`, or decide that events are commission-free. | High | Needs clarification |
| D-5 | Cancelling an accepted request does not close an open online checkout. If it is paid later, the money is verified against a cancelled order. | `Rentals::cancel…` (lines ~760-800) | A refund is needed but nobody is told | On cancel, discard awaiting checkouts. If one was already paid, settle it and raise the refund flow (`Notifier::platformFinance`). | High | Potential |
| D-6 | `OnlinePay::start` re-checks **every** awaiting payment of the request, including Xendit refunds (also `awaiting`). For a refund this calls the invoice API with a refund id and gets an error. It does no damage today, but it is wrong. | `OnlinePay::start` (line ~62) | Wasted API calls; a future change could discard a refund | Filter by `whereIn('type', Payment::INFLOW)`. The new checker already does this. | Medium | Confirmed |
| D-7 | The online method label says "Online payment (Xendit)" (`Finance::METHOD_LABEL`, shop page "Accepted methods"), and `Payment::METHOD_LABEL` says "Online (GCash, Maya, card)". | `app/Domain/Finance.php:33`, `resources/views/market/supplier.blade.php:85` | Misleading when PayMongo or practice is active; two names for one thing | Use one label built from `OnlinePay::name()`. | Low | Confirmed (seen in the app) |
| D-8 | Event booking and payment codes are `10000 + max(id) + 1`, and event receipts are `count + 1`. Two requests at the same moment get the same code and hit the unique index (HTTP 500). | `Events::code`, `Events::confirmPayment` (line ~260) | Rare failure under load | Use `Settings::nextSeq()` as rentals and payments do. | Medium | Potential |
| D-9 | The gateway keys in `.env` were empty and practice mode was off, so "Pay online" was hidden and **every** payment needed manual cashier checking. | `.env` | Root cause of "payment checking isn't automatic" | Practice mode is now on locally. For real money, set PayMongo or Xendit test or live keys and the webhook secret (section 3). | High | **Fixed locally**; keys still needed |
| D-10 | The README demo table listed accounts that are not seeded (`tess@`, `dodong@`, `liezl@`…). | `README.md` | Testers try accounts that don't exist | README updated to match the login page. | Low | **Fixed** |
| D-11 | The gateway fee (about 2.5%) is paid by PartyRent and is not taken off the seller's payout. | `Payouts`, `OnlinePay::settle` | The platform's margin is commission minus fee | Decide who pays the fee. | Low | Needs clarification |

### E. Missing or incomplete functionality

- **Real gateway credentials**: `PAYMONGO_SECRET_KEY` + `PAYMONGO_WEBHOOK_SECRET`, or `XENDIT_SECRET_KEY` + `XENDIT_CALLBACK_TOKEN`, plus a public HTTPS URL for the webhook. Until then, the practice gateway stands in.
- **Event payments through the marketplace**: see D-2, D-3 and D-4.
- **A paid-but-closed checkout has no recovery path**: see D-1 and D-5.
- **The scheduler must be running** for every-minute checks, payouts and expiries: `php artisan schedule:work` locally, or cron in production. Page-load checks cover admins and cashiers when it isn't running.

### F. Recommended improvements

1. Fix D-1 and D-5 together: one `OnlinePay::closeOpenCheckouts($request, $reason)` used by cancel, decline and expiry.
2. One payment path for rentals and events: the gateway or PartyRent's accounts, then `Payouts` with commission (D-2, D-3, D-4).
3. Generate every human-readable code with `Settings::nextSeq` (D-8).
4. One method-label helper (D-7).
5. In production, register the webhook URL shown on Admin → Online Payments and keep the scheduler running.

**Not verified by hand:** each flow in table B was checked by reading the code and through the existing tests. Only the online-payment flow and the demo logins were also clicked through in the running app. Before release, these should also be tested by hand: a real PayMongo or Xendit **test-mode** payment with a public webhook URL (ngrok or similar), and the event-company flows on a phone.

---

## 3. What you need to provide

| Setting (`.env`) | For |
|---|---|
| `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET` (or `XENDIT_SECRET_KEY`, `XENDIT_CALLBACK_TOKEN`) | Real GCash, Maya and card checkout instead of the practice gateway |
| Public HTTPS URL registered as the webhook (`/webhooks/paymongo` or `/webhooks/xendit`) | Instant confirmation. Without it, the every-minute check still confirms payments. |
| `PAYMONGO_FAKE=false` once real keys are in | Turns the practice gateway off |
| `php artisan schedule:work` (dev) or cron `schedule:run` (prod) | Automatic checks every minute |
