# Phase 2 progress

Source of truth for what's left. Tick items as they land; each tick = one commit.
Prototype: `C:\Users\Enchang\Downloads\partyrent.zip` (page names below match its HTML files).
Resume in Claude Code with `/resume-phase2`. Unfinished work is auto-saved as a `WIP: auto-save` commit when a session ends.

## Supplier
- [x] Inventory calendar (`supplier/calendar.html`)
- [x] Damage & compensation (`supplier/damages.html`)
- [x] Business reports + charts (`supplier/reports.html`)

## Cashier
- [x] Financial reports + charts (`cashier/reports.html`)

## Admin
- [x] Listings moderation (`admin/listings.html`)
- [x] Categories CRUD (`admin/categories.html`)
- [x] Complaints handling (`admin/complaints.html`), incl. "Log an issue"
- [x] Reports + charts (`admin/reports.html`)

## Superadmin (no views yet)
- [ ] Role + dashboard (`superadmin/dashboard.html`)
- [ ] System monitoring (`superadmin/monitoring.html`)
- [ ] Administrators management (`superadmin/admins.html`)
- [ ] Roles & permissions editor (`superadmin/roles.html`)
- [ ] System settings (`superadmin/settings.html`)
- [ ] Users / audit / reports (`superadmin/users.html`, `audit.html`, `reports.html`)

## Wrap-up
- [ ] Feature tests for every new page, full suite green
- [ ] README "Build status" updated

## Notes (where things stand)
- Finding: most phase 2 controllers + views were already written in phase 1 but never routed or linked in the nav. Each item = add routes + nav + page tests, then check against the prototype.
- Admin section done (2026-11-08): routes, permission-gated sidebar, "Log an issue" form from the prototype, fixed crash when restoring a listing without a reason. Next: superadmin (controller SuperController + views in resources/views/super already exist; need routes named super.* under /superadmin).
- Cashier reports done. Sidebar items take an optional 6th element = permission key; hidden when the user lacks it.
- Supplier section done: routes under /supplier (calendar, damages, reports, reports.csv) + sidebar links.
- 2026-11-07: Phase 1 committed (`12f9ac5`). Phase 2 not started. Some supplier views (calendar, damages, reports) exist as files; check how complete they are against the prototype before rebuilding.
