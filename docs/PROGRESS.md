# Progress

Source of truth for in-flight work. Resume with `/pickup`; save with `/checkpoint`.
Phase 2 is finished; its history is in `docs/PHASE2.md`.

## Current task
Post-phase-2 features (uncommitted as of 2026-11-09, ~55 changed + ~35 new files):
delivery anywhere in the Philippines, supplier location pins, email verification at
registration, real customer reviews, sale items and custom packages.

## Done
- [x] Phase 2 (see `docs/PHASE2.md`)

## Next
- [ ] Review the uncommitted work feature by feature, run `php artisan migrate` + `php artisan test`, and commit each feature separately
- [ ] Structured addresses + delivery zones (`Locations`, `DeliveryInput`, `AddressInput`, `DeliveryPricingTest`)
- [ ] Supplier location pins (Leaflet map partials, `SupplierLocationTest`)
- [ ] Email verification at registration (`PendingRegistration`, `register-verify`/`verify` views, `EmailVerificationTest`)
- [ ] Customer reviews + review reports (`Reviews`, `ReviewController`, admin/supplier review pages, `ReviewsTest`)
- [ ] Sale items + custom packages (migration `2026_11_10_000100`, `Packages`)

## Notes (newest first)
- 2026-11-09: Created this file and the `/pickup` + `/checkpoint` commands. The status of each feature above is not yet verified; check tests before ticking.
