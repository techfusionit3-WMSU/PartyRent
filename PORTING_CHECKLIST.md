# PartyRent v4 port — checklist

Status: `todo` / `in progress` / `done` / `verified` (verified = side-by-side 390px + 1440px screenshots match, or
test passes; the note says how). On a context reset: re-read the port prompt, `docs/PORT_AUDIT.md`, then continue
from the first row that is not `verified`.
"Laravel today" = what exists before the port (E = exists, needs v4 gaps; M = missing; P = partial).

## Steps
| # | Step | Status | Note |
|---|---|---|---|
| S1 | Audit, checklist, schema plan, route map (`docs/PORT_AUDIT.md`) | done | waiting for approval + answers to open questions |
| S2 | Design system: base.css / layout.css v4 diff, Blade components, `eyeOff` icon | done | 3-way merge (phase-1 copy, app edits, v4); app keeps 2 modal-scroll fixes; eyeOff added; screenshot rig works |
| S3 | Schema + models + seeders (10 demo accounts, seed.js data) | todo | |
| S4 | Core logic services + unit tests | todo | |
| S5 | Pages per role | todo | |
| S6 | Change list (section 6) | todo | |
| S7 | Final audit + report | todo | |

## Public & shared
| Page | Laravel today | Status | Note |
|---|---|---|---|
| index (home) | E (merged into browse, WIP 448074f) | todo | |
| login + demo-account picker | E | todo | |
| register | E (+OTP) | todo | |
| forgot-password | E | todo | |
| reset-password (+ link expired) | E | todo | |
| notifications | E | todo | |
| account | E | todo | |
| Layout adapts to active role | E | todo | |

## Customer
| Page / flow | Laravel today | Status | Note |
|---|---|---|---|
| browse (sort tabs, compare bar) | E | todo | |
| ai (interpret → checklist → packages) | E | done | 1440 compared incl. results; app city typeahead kept |
| suppliers | E | done | 1440 compared; seeded ratings shown; live search button removed |
| supplier shop (payment plans, policies) | E | done | ported from C.supplier; screenshot pending |
| product (availability calendar, rental charges estimate) | E | in progress | 1440 compared: time selects + duration (partials/rent-times), duration badge, condition label, supplier rows; C3 redesign + Rental Charges block pending |
| packages | E | done | 1440 identical |
| package | E | done | 1440 compared: Included items (plain packages), time selects; app reviews kept |
| cart / Rental List (grouped by shop, select-all/shop/item) | P | done | 1440 side-by-side matches (c143a1e); 390 to recheck |
| checkout (one request per supplier, locations, split qty, services, plan, installments) | P (per supplier) | done | 1440 compared; e2e: 3 shops → PR-0014..16; C5/C6 applied |
| confirmation (multi-request) | E | done | reached in e2e run |
| rentals (tabs) | E | done | 390/1440 identical |
| rental (daily qty, payments, installment schedule, propose schedule, cancel, review) | E | in progress | plan/locations/installments ported; screenshot pending |
| payments | E | done | 1440 compared: reference type/method labels, Details button, My Rentals nav |
| profile | E | done | 1440 compared; app PH address picker kept |
| start-selling (Individual / Organization wizard, documents, revision) | P (Personal/Business) | todo | |
| Post-login "Select Rental Dates / I'm Just Browsing" prompt | M | todo | |
| Persistent rental period bar `12/12/2026 - 12/15/2026 \| 4 Days` | P | todo | |
| Filters side panel (drawer + count + Show results) | M | todo | |

## Supplier & organization staff
| Page / flow | Laravel today | Status | Note |
|---|---|---|---|
| dashboard (charts, upcoming schedule) | E (simplified 11-12) | todo | |
| requests (6 tabs) | E | todo | |
| request (availability date+time, allocation, schedule approve/reject, accept, release, inspect, settle) | E | in progress | 390+1440 match for pending view; inspection still old damage model |
| schedule (next 14 days) | E | todo | |
| products | E | todo | |
| product-form | E | todo | |
| packages (+ package modal) | E (separate page) | todo | |
| inventory (stock, holds, damaged/missing) | E | todo | |
| locations (stock by location, transfers, movement log) | M | todo | |
| calendar | E | todo | |
| payments | E | done | 1440 compared: reference type/method labels, Details button, My Rentals nav |
| damages (+ damage policy) | E | todo | |
| reports (8 reports, CSV) | E | todo | |
| shop (zones, services, damage policy, payment plans, rental hours) | P | todo | |
| team (Org Admin, Cashier, Inventory Staff, Staff) | P (`staff`) | done | TeamRolesTest 6 green; screenshot pending |
| Org permissions (`Auth.orgCan`) on nav + actions | P (`canShop`) | done | ShopStaff::ORG_PERMS + ROUTE_PERMS, nav filtered |
| Mode switch customer ⇄ supplier | E | todo | |

## Cashier
| Page | Laravel today | Status | Note |
|---|---|---|---|
| dashboard | E | todo | |
| verification | E | todo | |
| record | E | todo | |
| balances | E | todo | |
| history | E | todo | |
| receipts | M (in history) | todo | |
| reports | E | todo | |

## Admin
| Page | Laravel today | Status | Note |
|---|---|---|---|
| dashboard | E | todo | |
| users | E | todo | |
| applications (approve / reject / request revision) | E | todo | |
| listings (Products + Packages tabs; hide/unhide/delete rules) | P | todo | |
| edit-listing | M | todo | |
| categories | E | todo | |
| transactions | E | todo | |
| complaints | E | todo | |
| activity | P (`audit`) | todo | |
| reports | E | todo | |

## Superadmin
| Page | Laravel today | Status | Note |
|---|---|---|---|
| dashboard | E | todo | |
| monitoring | E | todo | |
| users | E | todo | |
| reports | P (reuses admin) | todo | |
| admins | E | todo | |
| roles (permission editor) | E | todo | |
| audit | P (reuses admin) | todo | |
| settings (+ payout / Xendit settings) | E | todo | |

## Cross-cutting
| Item | Status | Note |
|---|---|---|
| Role navs = `js/ui/layout.js` NAV (incl. bottom nav + More) | done | layouts/dash.blade.php = reference NAV |
| Customer header, cat-strip, footer, floating bottom nav with AI fab | todo | |
| Notifications, activity log, audit log | todo | |
| AI module EN + Filipino, guardrails (no accept/cancel/reserve/price/verify; real listings only) | todo | |
| Charts on every dashboard/report | todo | |
| 10 demo accounts seeded, password `demo123` | todo | |

## Business rules (unit tests)
| Rule | Status | Note |
|---|---|---|
| Available(date) = Total − Reserved − Damaged − Missing − Maintenance − Set-aside; 120/20/20 → Mon 80, Tue 100 | verified | tests/Unit/BusinessRulesTest |
| Only accepted-or-later reserve; packages expand; pending never consumes; period = lowest day | verified | BusinessRulesTest |
| Time slots: 50 chairs, 25 @ 6–11 → 25/50; +20 @ 13–18 → 25/30; prep/recovery buffers | verified | BusinessRulesTest |
| Re-check at add-to-cart, submit, accept in a transaction with `lockForUpdate` | done | Rentals::lockProducts in addToCart/submitOrders/acceptRequest |
| Multi-day lines: qty per date, non-consecutive, price per date | verified | BusinessRulesTest |
| Max rental duration at add, submit, accept (`durationIssues`) | done | Rentals::durationIssues; product form field pending (C7) |
| Pricing: tiered per date, delivery fee per location/zone, itemised service fees, snapshot | verified | BusinessRulesTest |
| Finance: verified only; reservation credited once; Final = Total − RF − DP; 3 plans; installment min-months; dup ref + overpay rejected; statuses; overdue notices; never auto-cancel | verified | BusinessRulesTest + validatePayment |
| Listing moderation: hide needs reason; delete refused with active rentals; perms + activity log | todo | |
| Permission keys identical to `auth.js` / `services.js` | todo | |
| Payout formula: items − commission + delivery + voucher | verified | BusinessRulesTest; service fee stays with platform |

## Change list (after port)
| Item | Status | Note |
|---|---|---|
| C1 Merge index + browse; sticky date bar + filters | in progress | WIP 448074f, pre-port |
| C2 Shop name replaces "Switch to supplier" | done | partials/profile-menu |
| C3 Product page TikTok-Shop style (mobile + web); one-line bulk discount text | todo | |
| C4 Service-fee rule (consecutive same place = 1; place change +1; each separate block +1) | verified | Pricing::serviceVisits + test |
| C5 Rent request: delivery zone, location UI, Delivery→Pickup grouping bug | done | public/js/checkout.js |
| C6 Checkout: charges collapsible in Order Summary; RF / DP / total outside | done | customer/_checkout-summary |
| C7 Product form order, Others tags, Quantity, base price, Bulk discount, Add maximum, time frame + overnight | todo | |
| C8 Package form: Rental event tags, square crop, inventory section position | todo | |
| C9 Inventory: total stock + "All N units" in maintenance/set-aside | todo | |
| C10 Inventory calendar mobile | todo | |
| C11 Discounts page | todo | |
| C12 Shortfall/dispute cases (report → flag → notify → case page → proposals → deadline/escalate → apply → audit) | todo | |
| C13 Xendit: totals match charge; service fees to platform; refunds + payout reduction; practice mode; payout tests | todo | |
