# Progress

Source of truth for in-flight work. Resume with `/pickup`; save with `/checkpoint`.
Phase 2 is finished; its history is in `docs/PHASE2.md`.

## Current task
Done: **sale items + custom packages** (migration `2026_11_10_000100`, `App\Domain\Packages`).
Domain, pricing, inventory, stock deduction (`Rentals::deductSaleStock/restock/savePackage/...`) and
routes exist; controllers + UI were never written.

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
- 2026-11-10: packages/sale items finished; 47 tests green. Not yet tried in a real browser (builder JS, configurator JS): do that next.
- 2026-11-10: another session (4c1abe1) briefly worked the same task; user chose this session to continue. Its `CatalogController::restock` is the one kept.
- 2026-11-09 (pickup): routes in `routes/web.php` reference the missing controller methods above; editing a package is broken until the builder posts `components`/`pricing`.
- 2026-11-09: Created this file and the `/pickup` + `/checkpoint` commands.
