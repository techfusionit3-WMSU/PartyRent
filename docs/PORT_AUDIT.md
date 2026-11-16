# PartyRent v4 port — Step 1 audit

Reference: `reference/partyrent/` (from `partyrent (1).zip`, files dated 2026-11-14). **Read-only.**
Target: this Laravel app. Checklist with per-row status: `PORTING_CHECKLIST.md`.

## 0. Key finding

The Laravel app is **already a port of an earlier version of this same prototype** (identical `base.css`
header/tokens, same icon set, same nav arrays in `layouts/dash.blade.php`). The zip is **Version 4**. So this is a
**delta port to v4**, not a rebuild:

| Area | Gap |
|---|---|
| `css/base.css` | 144 changed lines vs the copy the app started from (pw-toggle, time-pair, dest-card/dest-line, sel-box, type-card, doc-row, perm-yes/no …) |
| `css/layout.css` | 53 changed lines (date-bar, drawer/filters panel, cart-group, wizard-steps …) |
| Icons | all present except `eyeOff`; app adds `art icons image video` |
| Pages | 6 reference pages have no Laravel page (see checklist); most others exist but lack v4 features |
| v4 logic missing | payment plans (Standard/Full/Installment), reservation fee, rental hours + prep/recovery buffers, multi-location stock + transfers, damage policy %, Individual/Organization applications, org roles, select-all multi-shop checkout, rental-period prompt + date bar, filters side panel, listing status trio + hide reason + delete rule, itemised service fees |

## 1. Pages and entry points (62)

`data-page` key → reference file → Laravel route today (`—` = missing).

**Public / auth / shared (7)**
| Ref | Page key | Title | Laravel |
|---|---|---|---|
| index.html | customer.home | Party Supplies Rental Marketplace | `home` (merged into browse — WIP commit 448074f) |
| login.html | auth.login | Log in (+ demo-account picker) | `login` |
| register.html | auth.register | Create account | `register` (+ OTP verify, app-only) |
| forgot-password.html | auth.forgot | Forgot password | `password.request` |
| reset-password.html | auth.reset | Set a new password / Link expired | `password.reset` |
| notifications.html | shared.notifications | Notifications | `notifications` |
| account.html | shared.account | Account settings | `account` |

**Customer (15)**: browse, ai, suppliers, supplier, product, packages, package, cart, checkout, confirmation,
rentals, rental, payments, profile, start-selling → all exist (`home`, `ai`, `suppliers.*`, `products.show`,
`packages.*`, `cart`, `checkout` *(per supplier today; reference = one checkout, one request per shop)*,
`rentals.*`, `customer.payments`, `profile`, `start-selling`).

**Supplier (16)**: dashboard, requests, request, schedule, products, product-form, packages, inventory,
**locations (—)**, calendar, payments, damages, reports, shop, **team (≈ `supplier.staff`)**. Package form is a
modal in the reference; the app has a separate `package-form` page.

**Cashier (7)**: dashboard, verification, record, balances, history, **receipts (— merged into history)**, reports.

**Admin (10)**: dashboard, users, applications, listings, **edit-listing (—)**, categories, transactions,
complaints, **activity (≈ `admin.audit`)**, reports.

**Superadmin (8)**: dashboard, monitoring, users, **reports (≈ reuses `admin.reports`)**, admins, roles,
**audit (≈ reuses `admin.audit`)**, settings.

**App-only pages (not in reference, kept unless you say otherwise):** supplier reviews, supplier payouts,
admin payouts, admin reviews, superadmin pay-accounts, practice checkout, register OTP / email verify, receipt view.

## 2. Design tokens (`css/base.css :root`)

- **Colors**: brand `#E11D74` (600 `#C2185F`, 50 `#FFF0F6`, 100 `#FFD9E8`); purple `#7C3AED`/50 `#F3EEFF`;
  gold `#F59E0B`/`#FFF7E6`; teal `#0D9488`/`#E6F7F5`; sky `#2563EB`/`#EAF1FF`; red `#DC2626`/`#FDECEC`;
  green `#16A34A`/`#E8F7EE`. Neutrals: bg `#FBF7FC`, surface `#FFF`, surface-2 `#F7F2F9`, ink `#1E1433`,
  ink-2 `#4B4362`, muted `#6E6683`, line `#ECE4F1`, line-2 `#DED3E6`. Sidebar dark text `#8E83A8`, link `#C4B5FD`.
- **Gradients**: `--grad` 135° `#E11D74 → #A21CAF → #7C3AED`; `--grad-soft` `#FFF0F6 → #F3EEFF`; `--grad-gold` `#F59E0B → #E11D74`.
- **Font**: Plus Jakarta Sans 400/500/600/700/800 (Google Fonts), fallback system-ui. Body 15px / 1.5.
  h1 1.75rem, h2 1.35rem, h3 1.1rem, h4 1rem, line-height 1.2, letter-spacing −.01em. Small scale used:
  .66 .68 .7 .72 .74 .76 .78 .8 .82 .84 .86 .88 .9 1.05 1.3 2.1 2.6rem.
- **Radius**: 14px (default), 10px (sm), 20px (lg). **Shadows**: sm `0 1px 2px /.06`, md `0 4px 16px /.08`, lg `0 18px 48px /.16` (all `rgba(30,20,51,…)`).
- **Sizes**: header 64px, bottom nav 76px.
- **Breakpoints** (max-width): 1180, 1024, 768, 640, 480, 360, plus `@media print` (receipts).

## 3. Components (classes) and states

- **Buttons** `.btn` + `-primary -solid -outline -ghost -danger -success`, sizes `-xs -sm -lg`, `-block`, `-icon`, `.btn-group`, `.icon-btn`. States: hover, `:active` (translateY press .08s), `:disabled`/`.disabled`, loading (`.spinner` inside).
- **Forms** `.field .input .input-sm .input-group .form-grid .range-fields .range-inline .time-pair .check .seg .qty .upload-box .pw-wrap/.pw-toggle .sel-box .type-card`; states `:focus` ring (border-color/box-shadow .15s), `.invalid` + `.error` message (from `UI.validate`), disabled.
- **Cards & data**: `.card .card-flat .card-head .stat .stats .kv .sum-line .table-wrap .table .progress .timeline .stepper .step .receipt .doc-row .dest-card .dest-line .logo-tile .thumb(s) .avatar .swatch .stars .price .price-lg .tag .chip .ai-badge`.
- **Badges** `.badge` + `b-pink b-purple b-gold b-teal b-sky b-red b-green b-gray`, `.plain`; helpers statusBadge, payBadge, verBadge, availBadge.
- **Feedback**: `.alert-info -success -warn -danger -ai`, `.toast(s)` (pop .2s), `.empty`, `.skeleton` (shimmer 1.2s), `.page-loading`, `.spinner` (spin .7s), `.demo-note`.
- **Overlays**: `.modal-backdrop .modal .modal-head/-body/-foot` (fade .15s + pop .18s), `UI.confirm`, `UI.prompt`, `.drawer` + backdrop (drawerIn .22s, filters side panel), `.dropdown .menu` (profile + notifications), lightbox.
- **Navigation/shells**: `.mk-header` (sticky, blur, 64px) + `.mk-search` with AI Search button + `.mk-nav` + `.mk-actions` + `.cat-strip`; `.mk-footer`; `.bottom-nav` floating with centre `.fab` (AI) and badge `.bn-dot`; `.dash` / `.side` (sticky dark sidebar, collapses to off-canvas < 1024) / `.dash-top` / `.side-backdrop` / bottom nav with **More** button; `.mode-pill`, `.mode-switch`, `.user-btn`; `.crumbs`, `.page-head`, `.tabs/.tab`, `.wizard-steps`.
- **Marketplace**: `.hero .hero-stats .cat-grid .cat-card .theme-card .product-grid .p-card(.p-img .p-body .p-name .p-price .p-sup .p-meta .p-compare) .sup-card .pd .pd-gallery .tiers/.tier .avail-pill .date-bar .filter-count .cart-group .cart-line .day-editor .day-row .sticky-side .sugg-row .extract .ai-hero`.
- **Charts** (`js/ui/charts.js`): bar, line, donut + `.donut-legend`, `.hbar`, `.legend`; calendars `.cal .icals`.
- **Chat**: `.chat-fab .chat-panel .chat-head .chat-body .msg .chat-sugg .chat-input`.

## 4. Interactions

- Sticky: marketplace header (top 0, z 500, blur); dashboard top bar (z 400); sidebar (100vh sticky); `.sticky-side` summary (top header+16px). Change list adds sticky date bar + filters.
- Motion: modal fade+pop, toast pop, drawer slide-in .22s, dropdown pop .15s, card hover lift (transform/box-shadow .15s), button press .08s, skeleton shimmer, spinner, bottom-nav/side transform .2s.
- Collapsibles: Rental Charges (product estimate, cart, checkout, order details); filters drawer; "More" sheet (= off-canvas sidebar); FAQ-like policy blocks.
- Mobile vs desktop: < 1024 sidebar → off-canvas + bottom nav with More; < 768 marketplace nav hides, floating bottom nav (Browse, Suppliers, AI fab, Requests, Profile); `.hide-sm/.hide-xs`; tables scroll in `.table-wrap`; grids collapse 4→2→1.
- Behaviour: post-login "Select Rental Dates / I'm Just Browsing" prompt (once per login, customers only); rental period follows customer, cleared at logout; date bar `12/12/2026 - 12/15/2026 | 4 Days`; filters side panel with count badge + "Show results"; compare bar (up to N products); select-all / per-shop / per-item checkboxes in Rental List; multi-day scheduler (per-date qty, non-consecutive dates); split quantity across delivery locations ("Divide evenly"); installment proposal at checkout; supplier approve/reject schedule; chat widget with suggestion chips (session-persisted).

## 5. Schema plan (extend existing tables; no duplicates)

Existing: users, suppliers, shop_staff, categories, products, holds, packages, cart_lines, rental_requests
(`lines` JSON with `days {date: qty}`), request_events, payments (gateway, Xendit refs, receipt_no), payouts
(Xendit), pay_accounts, reviews, complaints, app_notifications, audit_logs, stock_movements, settings.

| Change | Tables / columns |
|---|---|
| Supplier type & org | `suppliers.type` individual/organization (map Personal/Business), org fields (company reg., rep.), application status pending/revision/approved/rejected + `revision_note`; `shop_staff.role` → org_admin/cashier/inventory/staff (+ map existing manager/coordinator/warehouse) |
| Shop settings | `suppliers.rental_open`, `rental_close`, `prep_buffer_min`, `recovery_buffer_min`; `supplier_payment_plans` (supplier, plan standard/full/installment, enabled, reservation_pct, down_pct, min_months); `supplier_services` (name, description, amount, per order/location/date-block, required); `damage_policies` (supplier, condition, repairable_pct, nonrepairable_pct, lost_pct, category_id nullable); delivery zones (already) |
| Listings | products/packages `status` active/hidden/inactive (+ map published/draft/archived), `hidden_reason`, `hidden_by`, `hidden_at`, soft delete `deleted_at`; products `written_off`, `min_hours`, `max_hours`, `time_from/time_to` (per-product frame), `allow_overnight`, `base_price`; packages `rental_events` JSON |
| Multi-location | `inventory_locations` (supplier, name, address, contact, phone); `location_stock` (location, product, qty; product total = sum); `stock_transfers`; reuse `stock_movements` as movement log (+ location columns) |
| Bookings | `request_lines` (request, type item/package, ref, location_id source, price snapshot) + **`booking_days`** (line, product_id, day, qty, time_from, time_to, delivery_location_id) — written at submit, used for locked availability queries; `request_locations` (address, zone, fee, time); `request_services` (service, amount, basis). `rental_requests.order_group` (one checkout → N requests), `payment_plan`, plan snapshot |
| Money | `payment_schedules` (request, kind reservation/down/installment/final, due_date, amount, status, approved_at); payments keep Xendit columns; `damage_assessments` (one per rental, from `inspection` JSON) |
| Logs | `audit_logs` gets `kind` (activity vs audit) — feeds admin Activity and superadmin Audit |
| Change list | `discounts` (supplier, type bulk/percent/fixed/date, scope product/package/all, min_qty, amount, active window); `shortfall_cases`, `case_proposals` (type, amounts, qty, deadline, status), `case_messages`, `case_events` |

## 6. Route map (new / changed only)

```
GET  /                         browse (merged home)        GET /checkout            single checkout (selected lines)
POST /checkout                 submitOrders (1 txn, N requests)
POST /rental-period            set/clear customer period    POST /cart/select        select / select-all toggles
GET  /supplier/locations       + POST/PUT/DELETE locations, POST /supplier/locations/transfer
GET  /supplier/team            (rename of /supplier/staff; keep redirect)
GET  /supplier/discounts       + CRUD   (change list)
POST /supplier/requests/{r}/schedule/{approve|reject}
GET  /cases/{case}             shared shortfall case page; POST proposals, counter, accept, decline, messages
GET  /cashier/receipts         receipt management
GET  /admin/listings/{type}/{id}   edit-listing; POST hide (reason) / unhide / DELETE
GET  /admin/activity           (split from audit)
GET  /superadmin/reports, /superadmin/audit   (own pages instead of reusing admin)
```

## 7. Tooling for visual checks

Node + Edge are installed: `playwright-core` (scratchpad) with `channel: msedge`. Reference served by a static
server on :8080, app by `php artisan serve` (throwaway SQLite DB). Screenshots at 390×844 and 1440×900, both
logged in as the same demo role, saved under the scratchpad and listed per page in the checklist.
