# Plan: company role modules + event-focused generic rentals

Status: **approved 2026-11-19; step 1 done** (see section 7). Scope stays inside the title
"PartyRent — Party & Event Rental Marketplace": any kind of event, not only weddings.

## 1. Principle (professor's suggestion)

Roles live **inside a company**, not in the system.

| Level | Roles | Stored in |
|---|---|---|
| System (platform) | Customer, Admin, Super Admin | `users.roles` |
| Company (each supplier) | Owner + the company's own roles (below) | `shop_staff.role` (per company) |

A person has **one system account**. Being a supplier is not a system role: it is *owning or working in a company*.
The same account can be a customer on the marketplace and a Cashier in Company A.

**Each company role gets its own module**: its own URL prefix, controllers, dashboard, menu and pages built
for that job. Today all employees share the owner's pages with items hidden; only the solo cashier has its own
module. This plan gives every role one.

## 2. Company types

| Type | Who | How customers book |
|---|---|---|
| **Solo supplier** (individual) | One person renting out party/event items, packages or an event venue | Direct booking (pick dates, pay) |
| **Business: Rental company** | Registered business renting out items, equipment or venues, with staff and branches | Direct booking |
| **Business: Event company** | Coordinators, stylists, caterers, event venues taking whole events | Inquiry → quotation → reservation fee → deposit → balance |

A business picks **Rental company**, **Event company** or **both** when applying. With both, it gets both sets of modules.

## 3. Company roles and their modules

Owner can open **every** module of the company (module switcher) and can run the company alone.
Employees see **only their role's module**.

### Solo supplier and Rental company (direct bookings)

| Role | Module (URL) | What the module is for |
|---|---|---|
| Owner / Manager | `/supplier` | Overview of everything, catalog (items, packages, venues), policies, discounts, team, reports, payouts, settings |
| Cashier | `/cashier` | Verify payments, record cash, receipts, balances, refunds, overdue list, daily collection report |
| Inventory Keeper | `/supplier/stock` | Stock per location, transfers, maintenance, damaged / missing, return inspections, low stock |
| Field Crew (delivery & setup) | `/supplier/crew` | Today's jobs: deliveries, pickups, setups, returns — checklist, address + map, mark done, photo proof (phone-first) |
| Front Desk | `/supplier/desk` | Accept / decline requests, schedule, calendar, chat with customers, problem cases |

Solo suppliers usually have only the Owner; the other roles appear when they add staff.

### Event company (quotation bookings)

| Role | Module (URL) | What the module is for |
|---|---|---|
| Owner / Manager | `/business/manage` | Overview, event packages, booking & payment settings, profile, team, reports |
| Event Coordinator (sales) | `/business/coordinator` | Inquiries, quotation builder, client chat, event calendar, run-of-show per event |
| Finance | `/business/finance` | Reservation fees, deposits, balances, confirm proofs, reminders, refunds, collection report |
| Event Crew / Operations | `/business/crew` | Assigned events: setup / teardown times, venue, checklist, mark done |
| Inventory Keeper (if they also rent items) | shared `/supplier/stock` | as above |

## 4. Making it event-generic (inside the title)

- **Rental types in Browse:** Party & event supplies (current), **Event venues** (function halls, gardens,
  resorts, rooftops — capacity, amenities, house rules, customer goes to the place, per hour / day),
  **Event equipment** (sound, lights, LED walls, projectors, generators, stage).
- **Venues:** a solo owner lists a venue booked directly; a business venue can take quotations (event company flow).
- **Any event, not weddings:** event list adds Seminar / Conference, Graduation, Fiesta, Concert, Sports fest,
  Product launch, Family gathering, Wake / memorial, Other (typed). AI examples cover non-wedding events.
- **Guests** asked only where it matters (venues, event companies, AI planner).

## 5. Extra suggestions that make it work (and "SE ready")

1. **One access rule source** — a `CompanyModules` registry (role → module, menu, dashboard, permissions) used by
   routes, middleware and menus, so there is one place to read the RBAC matrix.
2. **Middleware per module** — `company.role:cashier` on each module's route group; a wrong role gets 403 (tested).
3. **Owner may fine-tune permissions per member** (e.g. a cashier who can also accept bookings) on top of role
   defaults, without inventing new roles.
4. **Job assignment** — bookings / events can be assigned to a crew member or coordinator; their module shows only
   their jobs. Activity is written to the audit log with the role used.
5. **Role-based notifications** — payment proof → Finance/Cashier; new request → Front Desk/Coordinator;
   return due → Inventory; delivery today → Crew.
6. **Invite flow stays** — owner invites by email, the person accepts with their own customer account.
7. **Tests per role** — for every module: the right role gets in, every other role gets 403.
8. **SE documentation** (in `docs/`): actors and use cases per role, RBAC matrix, module list, ERD changes, state
   diagrams (rental request, event booking, payments), test plan. Generated from the registry where possible so it
   stays true.

## 6. Build order (each step commits and keeps tests green)

1. Registry + middleware + module switcher; split today's supplier workspace into Owner / Cashier / Inventory /
   Crew / Front desk modules (solo first).
2. Event company modules: Owner / Coordinator / Finance / Crew; job assignment.
3. Business chooses Rental company and/or Event company (application, admin review, workspaces).
4. Rental types: Event venues + Event equipment (categories, listing fields, browse, checkout without delivery for venues).
5. Event list and wording for any event; AI examples.
6. Role-based notifications; per-member permission tweaks.
7. Demo data: one company per type with a member in every role; SE docs.

## 7. What step 1 built (2026-11-19)

- **Data:** `shop_staff.roles` (list, main role first), `perms_add` / `perms_remove` (owner's tweaks per member);
  `staff_invites.roles`. Role key `staff` renamed `crew` (migration `2026_11_21_000100_company_role_modules`).
- **Roles:** Owner (`org_admin`), Front Desk (`desk`), Cashier, Inventory Keeper (`inventory`), Field Crew (`crew`).
  Defaults in `ShopStaff::ORG_PERMS`; `team.manage` can never be given away (`ShopStaff::OWNER_ONLY`).
- **Registry:** `App\Support\CompanyModules` holds each module's label, roles, home page, menu and owned pages.
  `CompanyModules::matrix()` is the module × role table shown on Team & Roles.
- **How a module works:** each module has its own home page and menu. Shared feature pages (a request, inventory,
  the schedule) keep one URL and are guarded by permission (`ShopStaff::ROUTE_PERMS`); opening a page that belongs
  to another of your modules switches the menu to that module. A refused page sends you to your module's home.
- **New home pages:** Front Desk (`supplier.desk`): requests to answer, unread messages, installment plans, open
  cases. Stock Room (`supplier.stock`): what to prepare in the next 3 days, returns to inspect, units out of
  service, low stock. My Jobs (`supplier.crew`): late / today / next 7 days, contact, address + map, link to steps.
  `App\Domain\Jobs` builds the job list for both My Jobs and Fulfillment & Returns.
- **Switcher:** `POST /module` (`module.switch`); the sidebar shows "Your modules" when you have more than one.
- **Team & Roles:** invite with one or more roles; "Roles" (main role + also works as) and "Permissions"
  (tick / untick, marked *added* / *removed*) per member; pages gained by a tweak show under "Also allowed".
- **Demo team (Elegant Occasion, owner liza@demo.ph):** Grace = Cashier, Rico = Inventory Keeper + Field Crew,
  Joy = Field Crew, Leah (desk@elegantoccasion.ph, new) = Front Desk. Password `demo123`.
- **Tests:** `CompanyModulesTest` (8 tests: one per role, switching, tweaks, multi-role invite).
