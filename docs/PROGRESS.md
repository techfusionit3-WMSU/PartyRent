# Progress

Source of truth for in-flight work. Resume with `/pickup`; save with `/checkpoint`.
Phase 2 is finished; its history is in `docs/PHASE2.md`.

## Current task
Post-phase-2 features, all landed in one `WIP: auto-save` commit (03cee1a; not split, history is not rewritten).
Finishing the UI for sale items + custom packages: the domain (`Packages`, `Rentals::savePackage/restock/...`,
`Pricing`, `Inventory`) and migration are done, but routes point at controller methods that do not exist yet.

## Done
- [x] Phase 2 (see `docs/PHASE2.md`)

## Next
- [x] Migrations applied, 41 tests pass (2026-11-09)
- [x] Structured addresses + delivery zones (`DeliveryPricingTest` passes)
- [x] Supplier location pins (`SupplierLocationTest` passes)
- [x] Email verification at registration (`EmailVerificationTest` passes)
- [x] Customer reviews + review reports (`ReviewsTest` passes)
- [ ] Sale items + custom packages — UI layer:
  - [ ] CatalogController: `packagePrice`, `previewPackage`, `duplicatePackage`, `movePackage`, `packageActive`, `deletePackage`, `restock`; new `packageData` (components/pricing/guests/draft)
  - [ ] `ApiController::packageQuote` (customer package configurator)
  - [ ] Views: package builder (`supplier/package-form`), packages list actions, product form rent/sale fields, inventory restock, market package page choices/add-ons/guests, add-to-cart with `sel`
  - [ ] `PackagesTest` + `SaleItemsTest`

## Notes (newest first)
- 2026-11-09: Created this file and the `/pickup` + `/checkpoint` commands. The status of each feature above is not yet verified; check tests before ticking.
