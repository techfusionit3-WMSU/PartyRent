# Progress

Source of truth for in-flight work. Resume with `/pickup`; save with `/checkpoint`.
Phase 2 is finished; its history is in `docs/PHASE2.md`.

## Current task
Done: **payment channels without a gateway + PayMongo** (user request 2026-11-10; migration `2026_11_10_000400`).
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
- 2026-11-10: dev DB got demo PartyRent pay accounts via tinker (not reseeded). GD is not installed: tests use a base64 PNG, not `UploadedFile::fake()->image()`.
- 2026-11-10: dev DB got its demo payouts additively (scratchpad `devpayouts.php`), not by reseeding; the browser run marked PO-10001 paid (ref GC-TEST-1) and set Juan's payout account to BPI.
- 2026-11-10: escrow/payouts built; 51 tests green. Fixed a test-order bug: `Settings` static cache survived DB rollbacks between tests (`tests/TestCase.php` now flushes it). Deposits stay with PartyRent and are never paid out; damage compensation passes through with no commission.
- 2026-11-10: packages/sale items finished and driven in headless Edge (playwright-core): sale form, builder, preview, publish, customer options, cart. Fixed: `[hidden]` lost to `.field{display:flex}`; cart package lines now show choices and price with the saved `sel`; warning when a fixed price exceeds the separate total. 47 tests green.
- 2026-11-10: another session (4c1abe1) briefly worked the same task; user chose this session to continue. Its `CatalogController::restock` is the one kept.
- 2026-11-09 (pickup): routes in `routes/web.php` reference the missing controller methods above; editing a package is broken until the builder posts `components`/`pricing`.
- 2026-11-09: Created this file and the `/pickup` + `/checkpoint` commands.
