# Branches: how a multi-branch shop works

A shop (supplier) can have several **branches**: physical places that keep their own stock. Customers still see
**one shop with one price list**; the system decides which branch serves them.

Code: `App\Domain\Branches`, `App\Domain\Transfers`, `App\Support\Near`, `App\Support\BranchScope`,
`App\Http\Middleware\BranchGuard`. Tests: `tests/Feature/BranchesTest.php`, `tests/Feature/MultiBranchAccessTest.php`.

## 1. Where the customer is ("Renting near")

- Every customer page shows **Renting near: Quezon City · Change** (or "Set where you are renting").
- The place comes from, in order: the place chosen this visit, the place saved on the account, the city of the account
  address. A guest's choice is kept for the visit; a signed-in customer's choice is saved.
- Unknown place = no area limit (every branch counts), so nothing breaks for new visitors.

## 2. Delivery coverage per branch

Branches page → edit branch → **Delivery coverage**:

- **Within a distance of this branch** (km; blank = the shop default, 50 km), measured from the branch pin to the
  customer's pinned address, else to the centre of their city; or
- **Only the areas I list**: cities / municipalities, each optionally limited to some barangays
  (e.g. *Zamboanga City: Tetuan, Putik*).

A branch that does not cover a place is never offered, for delivery or pickup. The owner and Organization Admins set
coverage for every branch; a Branch Admin only for their own (another branch's form is a 404). Changes apply to the
next checkout; existing rentals keep their branch. Each change is in the Store History.

## 3. Stock near you

- Item and package pages show what the customer can actually rent near them: "**45** available near Quezon City",
  or "Not available near Davao City · change place".
- The figure is the best single branch that serves them, or, if the shop combines branches, the nearest branch plus
  what branches near it can lend. The quantity check, calendar and "free" counts use the same figure.

## 4. Checkout: one delivery address, zone / fee / branch worked out by the system

- Checkout keeps one section per store (its items, dates, prices, services, payment plan and subtotal), with a
  *Delivery / Pickup at shop* choice for that store. Below the stores there is **one Delivery information section**.
- **One location (default):** one address for every store set to Delivery. **More locations** only when the customer
  turns on *Deliver to more than one location*: then each location has its own address card, and in the store sections
  each item says which location it goes to (or splits its quantity). A store is only charged for locations its items go to.
- The address: *Find your city or municipality* (type to search; picking one fills region, province and city), *Use my
  current location*, or *Use my saved address*; then barangay and house no. / building / street; optional *Pin on map*;
  optional on-site contact. Kept if the customer leaves checkout and comes back. Contact person, contact number and
  **Special Instructions** (optional) follow once for the whole checkout.
- For each location the system shows, read-only, per store delivering there: the **detected delivery zone**, the
  **delivery fee**, the **branch that delivers**, and the *Deliver by* time (the store's hours). They are worked out again
  whenever the address changes.
- **Zone detection** (Pricing::zoneForAddress): a zone's name lists its places separated by "/" ("Sta. Maria / Tetuan");
  the barangay is matched first, then the city / municipality. No zone names them: the store's standard rate applies and
  the customer is told ("This address is not in any of this store's delivery zones … check the barangay and city").
  Shops that price delivery by distance show "By distance (… km from the branch)".
- **Branch:** from the address and each branch's coverage (section 2); several cover it: every item available there,
  then the lowest fee, then the nearest, then the main branch. None covers it: that store says so; other stores are
  not affected. **Everything is decided on the server**; a zone or branch sent by the browser is ignored.
- The server checks the address: city, barangay and street are required; a typed address must name a known city or
  be pinned. It never guesses ("We cannot tell where … is").
- **Pickup:** the nearest branch that has everything is suggested in the store section; the customer may pick another
  one near them.
- The assigned branch is shown on the rental, the receipt, the shop's Rentals list, Fulfillment & Returns and the crew's jobs.

## 4b. Older notes: the branch at checkout

- Checkout shows one line: **Rented from Pasig · 8 km away**. The system picked the branch that has everything for
  the dates, serves the customer, then the lowest delivery fee, then the nearest.
- *Change branch* lists only other branches that serve the customer and have everything. Most customers never open it.
- The delivery fee is measured from the chosen (home) branch. The branch is shown on the rental and receipt.

## 5. Combining nearby branches (off by default)

Turn on in Branches → *Combine nearby branches*, with a limit for how far apart branches may be (default 30 km).

**Example.** A customer near Quezon City rents 130 Gold Chiavari Chairs. Main Warehouse has 100, Pasig has 50.

1. No single branch has 130, so the system tries each branch that serves the customer as the **home branch** and keeps
   the plan that moves the fewest units: Main (100) + 30 borrowed from Pasig (6 km away).
2. The customer gets **one rental, one price, one pickup / delivery point (Main)** and a short note that some items are
   brought in from another branch. **No extra fee**: moving stock between branches is the shop's cost.
3. The 30 chairs are booked at Pasig for the rental dates, so Pasig cannot rent them to someone else.
4. A **transfer task** appears on the rental and on the dashboards of the branches involved:

| Step | Who | What happens |
|---|---|---|
| To send (planned) | Pasig staff | Due the day before the rental starts. "Mark sent" records who and when. |
| On the way (sent) | Main staff | "Mark received" records who and when. |
| Received | Main staff, after the rental ends | **Sent back** to Pasig (stock counts unchanged), or **Keep here**: the 30 move from Pasig's stock to Main's (recorded as a stock movement). |
| Cancelled | automatic | The rental was cancelled before sending, or a re-plan no longer needs the loan. |

- Returns come back to the home branch; the return inspection and damage charges work as for any rental.
- If the lending branch closes or its stock drops before sending, the rental shows a warning with **Re-plan branches**
  (finds another nearby lender, or confirms the home branch alone can do it).
- Every step goes into the rental's history and the Store History (audit log). Each transfer has a **printable slip**
  with *Released by* and *Received by* signature lines.
- Far branches are never combined. When even nearby branches together are short, checkout says what the nearest branch has.
- If combining is off, a rental always comes from one branch (as before).

## 6. Roles and branch access

| Level | Who | Sees | Can |
|---|---|---|---|
| Organization | Owner | Every branch, organization-wide reports | Everything, including the team, single permissions, branch settings |
| Organization | **Organization Admin** | Every branch, organization-wide reports | Run the business (orders, stock, money, catalog, reports); manage all members except other Organization Admins; not single permissions |
| Branch | **Branch Admin** | Only their branch(es) | Run the branch: rentals, deliveries, returns, stock at the branch, payments, reports, and its own team (Front Desk, Cashier, Inventory, Crew) |
| Branch | Other staff limited to branches | Only their branch(es) | What their role allows, at their branches only |
| — | Staff with no branch set | Every branch | What their role allows |

Rules enforced on the server (not only hidden in the menu):

- Any rental, payment, case, branch or transfer of another branch in a link returns **404**. A branch that only
  lends stock may open that rental read-only and do its transfer steps.
- Lists, counts, badges, dashboards, the cashier pages and reports only include the user's branches.
- Organization pages are closed to branch-level staff: catalog, prices and discounts, shop settings, payouts, Store
  History, adding / closing / removing branches, branch settings, shop-wide restock / maintenance / damaged counts.
  Branch staff set their own branch's stock count on the Inventory page.
- A Branch Admin can only add people to their own branch, only with branch roles, and cannot change single
  permissions. An Organization Admin always covers every branch; a Branch Admin always has at least one branch.

## 7. Branch switcher and reports

- Organization-level users see **Viewing: All branches (whole organization) / Pasig / Makati** at the top of every
  dashboard page. Picking a branch filters every list, count and report until changed.
- Branch-level staff with one branch see **Your branch: Pasig · Locked**; with several, only theirs.
- Printable Reports: the branch filter follows the switcher. *All branches (whole organization)* is only offered to the
  owner and Organization Admins; branch staff get *All my branches* or their one branch, and naming another branch is a 404.
  The *Branch performance* report compares branches side by side.

## Known limits

- Damaged, missing and written-off counts are kept per item, not per branch.
- A combined rental borrows the same number of units for every day of a line (the largest shortfall).
- Distances are straight-line × a road factor, not real routes.

## 8. Location services: search, map pin, current location (checkout and browsing)

One implementation for both (server `App\Domain\Geo` + `/api/geo/search` and `/api/geo/reverse`; browser
`public/js/geo.js`):

- **Search** (streets, buildings, landmarks, barangays, cities) and **address at a pin** use OpenStreetMap Nominatim
  through the server only (`config/partyrent.php` → `geocoder`; `GEOCODER_URL`, `GEOCODER_ENABLED`). Answers are cached
  for 7 days, so the same query or pin never asks twice; requests are rate limited. If the service is slow or down, the
  app's own list of cities and municipalities still answers, and a pin gets its nearest city (marked "not exact").
- **Checkout delivery location** (each location card): search box → the pin moves there and the details fill in;
  tapping / dragging the pin → the address there is looked up and the details fill in (a street the customer typed is
  kept); editing the details → the address is looked up and the pin follows, or the pin is marked "does not match"
  and its coordinates are **not** sent until the customer moves it. "Use my current location" asks first and explains
  why. The customer confirms each location ("Use this location"); any change un-confirms it, and the request cannot be
  sent until every location in use is confirmed. The exact pin is used for coverage and delivery-fee distance; the
  zone, fee and branch are still decided on the server.
- **Browsing**: the *Where are you renting?* page and the Suppliers page (List / Map, Nearest / Top rated) use the
  same search, pin and current location. The Suppliers map shows one marker per open branch (shops working from home:
  their city only), with a popup (shop, branch, rating, items, distance, *View products*). Changing the place refreshes
  the shops. Only a rounded centre (about 1 km) is put in the page; the precise spot is never shown to shops or others.
