# Progress

Source of truth for in-flight work. Resume with `/pickup`; save with `/checkpoint`.
Phase 2 is finished; its history is in `docs/PHASE2.md`.

## Current task
**v4 port of the reference prototype** (user 2026-11-16; prompt = `PartyRent_port_prompt.md` + "replicate /reference
exactly"). Reference unzipped (read-only) in `reference/partyrent/`. Step 1 audit done: `docs/PORT_AUDIT.md`,
`PORTING_CHECKLIST.md`. APPROVED 2026-11-16. User: "always follow the zip UI/UX and do all the prompts".
Decisions:
1. UI says **Supplier** again (zip wording; supersedes the 11-12 "Rental Partner" rename).
2. Every customer payment goes through **Xendit first**; each shop (individual or organization) has its own
   cashier who sees only that shop's payments / receipts / balances / overdue; platform payouts via Xendit stay.
3. Zip org model: Individual / Organization; roles org_admin, cashier, inventory, staff
   (map manager→org_admin, warehouse→inventory, coordinator→staff, cashier→cashier).
4. Add `booking_days` table for locked availability (keep `lines` JSON for display).
5. Home + browse merged (change list); home sections sit above the browse grid.
6. Package form: inventory section moved right after the items.
7. App-only pages keep working but use the zip UI/UX; menus follow the zip.
8. `reference/` folder: decide later (untracked for now).
The UI/UX batch below = the port's change list (section 6); it is applied AFTER the port.

### ▶ RESUME HERE (checkpoint 2026-11-16, after 1f62c6d)
Also DONE since the notes below: merged home+browse rebuilt from reference (hero + home sections on landing view,
sticky `.browse-sticky` date bar + Items/Packages/Filters/Sort row, filters in side drawer `[data-drawer]`, compare ≤3
floating bar + modal), post-login "Select Rental Dates / I'm Just Browsing" prompt (`partials/date-prompt`,
AuthController flashes `RentalPeriod::ASK`), supplier card, seeded ratings kept (no Reviews::recalcAll in seeder),
UI "Rental Partner" → "Supplier" everywhere, "Booked" → "Accepted", plan badge `@include('partials.badges', ['planBadge' => …])`.
NEXT, in order:
1. DONE (2026-11-16 pm): v4 return inspection. `App\Domain\Damage` (CONDITIONS, policy/rates incl. category
   overrides, assess); `Rentals::recordInspection` needs `confirmed`, repairable→damaged, nonRepairable→written_off,
   lost→missing (+ stock_movements), once only, finance notify; inspection-report partial, supplier inspect modal
   (live calc + "Review assessment" confirm with HTML body — app.js confirmDialog now takes `data-confirm-html`;
   `window.PR.toast` exposed), damages page per SP.damages. `ReplacementValueTest` rewritten (policy tests).
   NOT yet: shop "Damage policy" tab (supplier/shop?tab=damage), product form condition select still old
   Excellent/Good list (→ brand_new|good_as_new|used), "mark repaired" action on inventory.
2. Make `PagesTest` green (smoke test of all pages), then rewrite the other red feature tests for the v4 flows
   (one checkout POST /checkout JSON, payments only after accept, shop cashier cashier@elegantoccasion.ph).
3. Remaining customer pages: product (C3 TikTok-style later), packages, package, suppliers, ai, rentals, payments,
   profile, start-selling (Individual/Organization wizard). Then supplier, cashier, admin, superadmin pages.
4. Change list still open: C1 done, C3, C7–C13.
Screenshot rig: scratchpad (session-specific, may be gone) had `drv/shot.js` (playwright-core 1.48.2 + msedge;
args `<name> <refPath> <appPath> [email] [--full] [--supplier]`), reference served by a node static server on :8080
from reference/partyrent, app `php artisan serve --port=8033` with env DB_CONNECTION=sqlite DB_DATABASE=<scratch>/check.sqlite
XENDIT_SECRET_KEY= MAIL_MAILER=log SESSION_DRIVER=file. Recreate if missing. Gotcha: Blade directives glued to a word
(`time@include`, `Filters@if`) don't compile — keep a space. Never write files via `php -r` + preg_replace without
checking for a null result (it emptied a view once; restored from git).

Port status (update every commit):
- [x] S1 audit · [x] S2 CSS 3-way merge + eyeOff (58c1d35)
- [~] S3 schema: migration `2026_11_16_000100_port_v4` done; org roles (`ShopStaff::ORG_PERMS`, `User::orgRole/orgCan/
  isEmployee`), Team page, reference NAV, profile menu done (1e19ecb). Listing status now active|hidden|inactive|deleted.
  TODO: DemoSeeder → seed.js v4 (drop s7–s10 Visayas/Mindanao demo shops, add Aquino Events Corp. pending org,
  Grace=cashier@elegantoccasion.ph, Rico inventory, Joy staff, locations + stock split, plans/hours/zones/services,
  wedding PR-0008 two venues, payments per seed.js). Needs S4 Pricing/Finance first (seeder snapshots pricing).
- [x] S3 seed: DemoSeeder = seed.js v4 (7 suppliers, s4 org with Grace cashier / Rico inventory / Joy staff,
  locations + stock split, PR-0008 two venues + approved installments, payments from schedule stages).
  DatabaseSeeder now seeds the full demo (SEED_DEMO=false → staff only). `BookingDays::sync()` fills booking_days.
- [~] S4 logic DONE: Slots, Inventory (time windows, locations, written_off), Pricing::quoteOrder (zones per
  destination, services; change-list visit rule), Finance (3 plans), Payouts (service fee → platform).
  `tests/Unit/BusinessRulesTest` 12 green. TODO still: listing moderation service, durationIssues, lockForUpdate
  re-checks (add-to-cart / submit / accept) using booking_days.
- [~] S5 pages. DONE so far: Rental List (cart.blade.php: select-all/shop/item, times, scheduler, Rental Charges estimate,
  compare modal), single checkout (customer/checkout.blade.php + public/js/checkout.js + POST checkout.quote JSON;
  change list C5/C6 applied: zone per location, Delivery↔Pickup keeps split lines, charges collapsed in Order Summary),
  confirmation (multi-request), rental details (plan, locations, installment card + proposal), payment summary,
  supplier request (time-aware availability, allocation, installment approve/reject, record payment), public shop page,
  product/package cards, RentalPeriod (session) + period banner. Feature tests now 35 red (flow tests for old checkout
  URL / platform cashier / pay-before-accept): rewrite per area. Old note: Feature tests were RED (103) because controllers/views still use the old finance/pricing keys
  (`upfront`, `arrangement`, `downRequired`, `delivery['ok']`, Shopee delivery). Fix page by page while porting;
  delete tests for removed app-only behaviour (Shopee/weight delivery, upfront-before-accept) and write v4 ones.
  Order: customer flow (browse/product/cart/checkout/rental/payments) → supplier → cashier → admin → super.
- Screenshot rig: scratchpad `drv/shot.js <name> <refPath> <appPath> [email] [--full]` → `shots/<name>-390|1440.png`
  (ref :8080 node static server in reference/partyrent; app :8033 with `. env.sh` = throwaway SQLite, no Xendit).

### Paused: UI/UX + logic batch
**UI/UX + logic batch** (user spec 2026-11-16, pasted; follow its stated assumptions 1–5). Build order:
- [ ] 1.1 Merge home + browse into one page; sticky date picker/filters; "Switch to supplier" → shop name
- [ ] 1.2 Product page TikTok-Shop style (separate web/mobile layouts); one-line bulk discount text; service-fee rule
      (consecutive same place = 1 fee; place change = +1; each separate date block = +1)
- [ ] 1.3 Rent request: delivery zone pick, nicer location UI, BUG: per-supplier grouping lost when Delivery → Pickup
- [ ] 1.4 Checkout: rental charges collapsible in Order Summary (closed); reservation fee, down payment, total outside
- [ ] 2.1 Product form: order Specs → Pricing → Inventory → Listing; Colors/Themes "Others" + text; "Quantity" label;
      base price above bulk discount; "Tier pricing" → "Bulk discount" (optional); "Add maximum" button;
      shop default time frame (shop settings) + per-product "Set specific time frame" + "Allow overnight rental"
- [ ] 2.2 Package form: "Rental event" tags (type or pick); square crop on photo upload; move/re-lay Inventory section
- [ ] 2.3 Inventory page: show total stock when marking maintenance/set aside; "All N units" select-all
- [ ] 2.4 Inventory calendar mobile layout
- [ ] 2.5 Discounts page (supplier catalog): manage every discount incl. bulk discounts
- [ ] 3 Shortfall/dispute cases: report → flag bookings → notify → shared case page (timeline + thread) → proposals
      (partial, substitute, delayed, refund, cancel) with deadline → accept/counter/decline → escalate to support →
      apply (qty, inventory, calendar, payments) → audit log
- [ ] Tests + browser check, then report assumptions back to the user

### Previous: Business accounts, hourly rentals, item value, simpler dashboard
(user request 2026-11-12). Decisions:
- Business cashiers do NOT take cash: PartyRent collects every payment (Xendit / transfer).
- Rent by time: customer picks ANY start/end time; DB must refuse overlapping bookings (+ supplier buffer).
- Current supplier setup = **Personal** account; add a **Business** type (permits, shop staff incl. cashier,
  event packages with stages + services + tiers + add-ons).
- UI name for suppliers: **Rental Partner** (code keeps `supplier`).
- Simpler supplier dashboard (only important things). Down payment paid + accepted shows as
  "Booked" + "Partially Paid", not "Accepted"/"Reserved".
Build order:
- [x] A. Status wording (Booked) + minimal supplier dashboard
- [x] B. Replacement value per product → inspection pre-fills charges (missing/total 100%, major 50%), cap; shown to customers (migration `2026_11_12_000100`, setting `majorDamagePct`, `ReplacementValueTest`; deposit suggestion + typical-value guess NOT done)
- [x] C. Rename Supplier → Rental Partner in UI text (script: only string literals in app/, text in blade; routes/URLs/DB/code keep `supplier`. Demo seed data + DB-stored old notifications unchanged)
- [x] D. Account type Personal/Business on apply (+ business docs), shop staff (manager/cashier/warehouse/coordinator)
  Migration `2026_11_12_000200`; `ShopStaff::ROLES` (route-name patterns) + `OWNER_ONLY`; `User::shop()/shopRole()/canShop()`
  (supplier controllers use `shop()`, `supplier` = owned shop). Guard in `EnsureRole::shopPage`. Business docs on private disk;
  admin "Business checks" tab for Personal→Business upgrades; approving a business application verifies it. Staff see the owner's
  supplier notifications. `ShopStaffs::add` creates an account + emails a set-password link. `BusinessAccountsTest` (5); 98 green.
  NOTE: the rename script once turned `'supplier.'` into `'rental partner.'` in new code — fixed; committed code audited clean.
- [x] E. Rent by time (rental_mode day|hour, min hours, buffer, hourly rate; overlap check)
  Migration `2026_11_12_000300`; `App\Domain\Hours`; line key `time` {from,to} (cart_lines.time, request lines).
  `Inventory::availableAt` = total − unusable − whole-day demand − PEAK of overlapping windows (± buffer);
  `check()` uses it for timed lines. Date-level views (calendar, holds, stock edits) still count all bookings that day.
  Product page hourly box + `GET /api/slots` (booked times). Price = tier price/h × hours × qty. `HourlyRentalTest` (6).
  NOT done: late-return charge per hour; hourly items inside packages count as whole day; times on supplier schedule page.
- [x] F. Business event packages: stages, services (teams/day), tiers, add-ons, milestones
  Migration `2026_11_12_000400` (packages.stages, tier_group, tier_label). Def gains `stages` [{name,hint}], component
  `stage` index, service `teamsPerDay`; sel gains `stages` [{time, venue}] (customer fills on package page; shown via
  `Packages::describe`). `Inventory::serviceDemand/serviceCap` + check() rows {service, name} (all bad rows now carry
  `name`). Tier switch on package page (same supplier + tier_group). Add-ons/choices/guests already existed;
  milestones = existing Installment arrangement. `EventPackagesTest` (4); 108 green.
- [x] Browser check of A–F (headless Edge, playwright-core 1.65 in session scratchpad `drv/walk.js`, throwaway SQLite
  `check.sqlite`, port 8033, MAIL_MAILER=log). Fixed: hourly product page still ran the per-day RentBox JS (null error);
  hourly cart line had no line total; staged package items were listed twice. No console/500 errors after fixes.
- Scope (user 2026-11-12): keep only the original requests (A–F). Later extras (legal pages, local/nationwide
  categories, quotations, sizes, messages, email copies, late fee) were removed; they are on branch
  `backup/2026-11-12-all-work` if wanted again.

### Previous: Supplier payouts through Xendit
(user request 2026-11-11: "is it not automated?").
- [x] `App\Domain\XenditPayouts`: `POST /v2/payouts` (channel PH_GCASH or bank from `BANKS`), status pending → sending → paid; failure → back to pending with reason. Sync on admin payouts page load + "Check status"; payout webhook events go to the same `/webhooks/xendit`.
- [x] Admin payouts: "Send via Xendit" (manual "Mark paid" kept under "Sent it by hand instead?"). Superadmin setting `payoutAuto` sends on release (release already happens when a rental completes).
- [x] Supplier payout form: bank is a dropdown of supported banks. Migration `2026_11_11_000100`.
- [x] `XenditPayoutsTest` (5); 90 green.
- [ ] User: new Xendit key with Money-in + Money-out WRITE → verify channel codes (GET /payouts_channels) + a real test-mode payout.

### Previous: weight & size auto-fill
(user request 2026-11-11: L/W/H confusing for suppliers; no API).
- [x] `App\Domain\ItemSizes`: ~40 typical party items (keywords EN/Filipino, kg, packed L×W×H) + category fallback. Form suggests from name/category, fills empty fields, never overwrites typed values; "Use this" + "Or pick a similar item"; plain labels Length/Width/Height.
- [x] `ItemSizesTest`; phpunit.xml blanks `XENDIT_*` so tests never use the real key; 85 green. Browser-checked (throwaway SQLite, port 8032).

### Previous: Xendit
(user request 2026-11-11: PayMongo has no keys, "use a different gateway").
- [x] `App\Domain\OnlinePay` picks the gateway (Xendit when `XENDIT_SECRET_KEY` is set, unless `PAYMENT_GATEWAY=paymongo`; else PayMongo / practice) and holds the shared `start` / `sync` / `discard` / `settle`. Payments keep their `gateway`, so old checkouts still work.
- [x] `App\Domain\Xendit`: hosted Invoice (`POST /v2/invoices`, external_id = payment code), sync via `GET /v2/invoices/{id}`, cancel via `POST /invoices/{id}/expire!`, callback `/webhooks/xendit` checked with `x-callback-token` = `XENDIT_CALLBACK_TOKEN`.
- [x] Return route renamed `rentals.paymongo.return` → `rentals.online.return` (`/payments/{p}/online`); texts gateway-neutral; superadmin settings shows Xendit status + webhook URL.
- [x] `XenditTest` (6, Http::fake); 83 green.
- [x] Test key in local `.env` (2026-11-11); API check: invoice created (GCash, Maya, GrabPay, ShopeePay, QR, direct debit) + expired OK.
- [ ] User: real test-mode payment through the app. Webhook needs a public URL (localhost: return URL covers it).

### Previous: Shopee-style delivery + payouts
(user request 2026-11-10: "make it like shopee", chose all 4 parts):
- [x] 1. No commission on delivery: `Payouts::breakdown` splits income into items vs delivery; commission only on items. Payouts table gets `delivery`, `voucher` columns.
- [x] 2. Shopee-style payout breakdown on supplier Earnings page, admin payouts, supplier request page (Items − commission + delivery + voucher + damages = you receive).
- [x] 3. Weight-based delivery: products get weight_kg + L/W/H cm (volumetric ÷ divisor); new delivery mode `weight` ("PartyRent standard rates"), route tier by origin/destination (same province / same island group / other island group), superadmin sets first-kg + per-kg rates. Kept area/distance as custom alternatives (tell user).
- [x] 4. Free Shipping program: supplier opts in (`suppliers.free_ship`), pays +extra % commission; customer gets PartyRent-funded voucher off delivery (min spend, cap) — settings `freeShip*`. Voucher stored in delivery quote snapshot, paid to supplier in payout.
- [x] `ShopeeDeliveryTest` (7 tests); 77 green. Migration `2026_11_10_000600`. Default delivery mode for NEW suppliers is now `weight`; saved suppliers keep their mode.
- [x] Browser check (headless Edge on a throwaway `partyrent_check` DB with demo data, port 8031 — 8010 is used by another session): shop form standard rates + Free Shipping opt-in, product weight/size, supplier payouts (table now full width), superadmin settings, Free Shipping badge; no console/500 errors, no sideways scroll at 390px. Pushed.

### Previous: pay at checkout
Done: **pay at checkout** (user request 2026-11-10: "it goes to Request submitted without paying").
Checkout now shows "Pay now to confirm" (`Finance::upfront` per payment method, `data-due-*`), a "Pay now with"
choice (online via PayMongo/practice, or transfer), and the button "Place request & pay ₱X". After saving the
request: online → `PayMongo::start` → checkout page; transfer → `rentals.show?pay=1`, which opens the pay form.
The confirmation page is no longer used after checkout.
- [x] `PracticeCheckoutTest::test_checkout_goes_straight_to_payment`; 70 tests green
- [x] Browser run (`drv/paynow.js`): Maria, Celebration Hub, down payment → practice pay → PR-0017 verified (OR-10024). PR-0017 "Pay Now Smoke" is leftover test data in the dev DB.

### Previous: PayMongo practice mode
Done: **PayMongo practice mode** (user request 2026-11-10). PayMongo has paused new sign-ups, so there are no keys.
`PAYMONGO_FAKE=true` (set in the local `.env`; never active when `APP_ENV=production`) makes "Pay online" open a
built-in practice checkout (`PracticeCheckoutController`, `customer/practice-checkout`) with Pay / fail / cancel.
Paying redirects to the normal `rentals.paymongo.return`, and `PayMongo::sync` settles it from the cached practice
session with the same `settle()` code, so verification, receipts and "Down payment received" behave as in production.
- [x] `PracticeCheckoutTest` (Http::preventStrayRequests); 69 tests green
- [x] Browser run (`drv/practice.js`): Maria paid PR-0005's ₱6,700 via practice GCash after one simulated failure; verified (OR-10023); Ana's Accept enabled
- [ ] When PayMongo reopens sign-ups: put `sk_test_` key in `.env`, set `PAYMONGO_FAKE=false`, do a sandbox run

### Previous: payment accounts list
GCash caps monthly receiving, so PartyRent's collection accounts are now a list (`pay_accounts`, `PayAccount`) instead of single settings.
- [x] Superadmin "Payment Accounts" page (`super.pay-accounts`): add/edit/QR/turn off/remove (remove only if unused)
- [x] Optional `monthly_cap`: `receivedThisMonth` = pending + verified inflows this month; customer form hides (JS, `data-room`) and server refuses accounts that can't take the amount
- [x] `payments.pay_account_id`: customer must pick the account for bank/debit when any are active; cashier record can set it; verification page shows "Paid to"
- [x] Old `payGcash*/payBank*/payQrPath` settings migrated into accounts and deleted; QR route is now `payments.qr` per account
- [x] Tests in `PaymentChannelsTest`; 66 green
- [ ] Browser check

### Previous: payment channels
Payment channels without a gateway + PayMongo (user request 2026-11-10; migration `2026_11_10_000400`).
- [x] Superadmin settings: PartyRent GCash / bank accounts + QR upload (`Settings::payAccounts`, `payments.qr`); pay modal shows them (it used to say "transfer to the supplier")
- [x] Duplicate reference check: `payments.reference_key` (normalized), `Rentals::referenceUsed` on submit/cashier record, cashier can't verify a reference already verified (`Payment::duplicates`, warning on verification page). Refunds may reuse refs.
- [x] PayMongo Checkout (`App\Domain\PayMongo`): method `online`, status `awaiting` until paid; verified automatically via return URL (`rentals.paymongo.return`) or signed webhook (`/webhooks/paymongo`, CSRF exempt). Mismatch → `pending` for cashier. Unpaid checkouts are expired + deleted when cancelled or replaced.
- [x] Config: `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`, `PAYMONGO_METHODS` (empty key = feature hidden)
- [x] `PaymentChannelsTest` (Http::fake); 65 tests green
- [ ] Browser check; real sandbox run once the user has sk_test keys (localhost can't get webhooks; return URL covers it)

### Previous: down payment before acceptance
Down payment before acceptance (user request 2026-11-10). A supplier can accept a pending
request only once the customer's first payment of the plan is verified by the cashier:
`Finance::upfront` = reservation fee / down payment / 1st installment / full payment; Cash on Delivery +
full payment pays the supplier's down % (default `DEFAULT_COD_DOWN_PCT` = 20) upfront.

- [x] Payments allowed on pending requests (rental types only; deposit after acceptance)
- [x] `acceptRequest` / `acceptReview` gate; supplier notified when the down payment is verified
- [x] Decline with money paid → cashier "Refund due" + customer told; status "Refund Due" → "Refunded"
- [x] Free cancellations never reach payouts (`awaitingRelease`, `release`)
- [x] UI: customer pay-now alert + pay options, confirmation steps, supplier accept button/modal, requests list badges, cashier record list includes pending/declined
- [x] Seeder: PR-0004 reservation fee verified, PR-0005 down payment pending
- [x] `DownPaymentTest`; lifecycle + sale items tests pay before accepting
- [x] Browser check (`drv/downpay.js`): supplier list badge, disabled Accept + next-step text, customer pay-now alert and pay options at phone width; no console/500 errors. Dev DB differs from the seed (PR-0003 declined there), so PR-0005 was used.

### Previous: escrow + payouts
Done: **escrow + supplier payouts (Shopee model)** (migration `2026_11_10_000200`, `App\Domain\Payouts`).
User asked that the cashier not see supplier numbers and chose the full Shopee model: customers pay
PartyRent, PartyRent holds the money and pays the supplier after the rental, minus `commissionPct`.

- [x] `payouts` table + `suppliers.payout_*` account fields; `Payout` model
- [x] `Payouts::breakdown/held/release/markPaid/awaitingRelease/summary`; released automatically by `Rentals::completeRental`
- [x] Cashier refunds blocked once a request's money is released
- [x] Admin/superadmin "Supplier Payouts" page (`admin.payouts`, perm `payouts.manage`): to send / awaiting release / paid / by supplier
- [x] Supplier "Earnings & Payouts" page + payout account form; request page shows held / payout status
- [x] Cashier pages: no supplier names, no per-supplier chart, no supplier CSV column
- [x] Customer/supplier wording: pay PartyRent; COD cash is collected for PartyRent
- [x] Seeder: payout accounts for s1–s3, payouts for completed rentals (older ones paid)
- [x] `PayoutsTest` + payout assertions in `RentalLifecycleTest`
- [x] Browser check (headless Edge, `drv/payouts.js` in the session scratchpad): admin to send/paid/by supplier, mark paid, supplier page + account form (bank field toggles), request page note, phone width, cashier pages free of supplier names, cashier kept out of /admin/payouts
- [x] Payouts can't be marked paid until the supplier has a payout account (supplier is reminded)
- [x] Migration `2026_11_10_000300` adds `payouts.manage` to the stored default admin permissions

## Done
- [x] Phase 2 (see `docs/PHASE2.md`)
- [x] Structured addresses + delivery zones (`DeliveryPricingTest`)
- [x] Supplier location pins (`SupplierLocationTest`)
- [x] Email verification at registration (`EmailVerificationTest`)
- [x] Customer reviews + review reports (`ReviewsTest`)
  (all four landed together in WIP commit 03cee1a; 41 tests green on 2026-11-09)

## Next (sale items + custom packages)
- [x] Product form: listing type rent/sale, perishable, lead days, shelf life, low-stock alert (CatalogController product data)
- [x] Inventory page: stock on hand, restock form, movement log (`CatalogController::restock`)
- [x] Package builder: components editor, pricing mode, guests, draft; `packagePrice`, `previewPackage`
- [x] Package list actions: `duplicatePackage`, `movePackage`, `packageActive`, `deletePackage`
- [x] Market package page: choices / add-ons / guests, `ApiController::packageQuote`, cart add with `sel`
- [x] `SaleItemsTest`
- [x] `PackagesTest`

## Notes (newest first)
- 2026-11-10: PayMongo has paused new sign-ups; online payments stay hidden until the user gets keys. Dev DB: GCash cap 100k + Maya (off) added by tinker.
- 2026-11-10: dev DB got demo PartyRent pay accounts via tinker (not reseeded). GD is not installed: tests use a base64 PNG, not `UploadedFile::fake()->image()`.
- 2026-11-10: dev DB got its demo payouts additively (scratchpad `devpayouts.php`), not by reseeding; the browser run marked PO-10001 paid (ref GC-TEST-1) and set Juan's payout account to BPI.
- 2026-11-10: escrow/payouts built; 51 tests green. Fixed a test-order bug: `Settings` static cache survived DB rollbacks between tests (`tests/TestCase.php` now flushes it). Deposits stay with PartyRent and are never paid out; damage compensation passes through with no commission.
- 2026-11-10: packages/sale items finished and driven in headless Edge (playwright-core): sale form, builder, preview, publish, customer options, cart. Fixed: `[hidden]` lost to `.field{display:flex}`; cart package lines now show choices and price with the saved `sel`; warning when a fixed price exceeds the separate total. 47 tests green.
- 2026-11-10: another session (4c1abe1) briefly worked the same task; user chose this session to continue. Its `CatalogController::restock` is the one kept.
- 2026-11-09 (pickup): routes in `routes/web.php` reference the missing controller methods above; editing a package is broken until the builder posts `components`/`pricing`.
- 2026-11-09: Created this file and the `/pickup` + `/checkpoint` commands.
