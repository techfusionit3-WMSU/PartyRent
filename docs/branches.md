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

## 4. Checkout: one address, branch assigned automatically

- Checkout has one section per shop (its items, dates, prices, delivery or pickup, payment plan) and then a single
  **Delivery information** section: the address is entered **once** for every shop that delivers, with contact person,
  contact number and **General Instructions** (optional; landmarks, gate details, directions).
- The address: *Find your city or municipality* (type to search; picking one fills region, province and city), or
  *Use my current location* (pins you and fills the city), or *Use my saved address*; then barangay and house no. /
  street; optional *Pin on map* for the exact spot. It is kept if the customer leaves checkout and comes back.
- The server checks it: city, barangay and street are required; a typed address must name a known city or be pinned.
  It never guesses: "We cannot tell where … is".
- **Delivery: the branch is assigned by the server** from the address and the coverage above; the customer cannot
  choose or override it (a branch id sent by the browser is ignored). If several branches cover the address: the one
  with every item for the dates, then the lowest delivery fee, then the nearest, then the main branch. If none covers
  it, that shop says *None of its branches delivers to Davao City … Choose Pickup or another address*; other shops in
  the same checkout are not affected.
- **Pickup**: the nearest branch that has everything is suggested; the customer may pick another one near them.
- The assigned branch is shown on the shop section, the rental, the receipt, the shop's Rentals list,
  Fulfillment & Returns and the crew's jobs ("Delivered by …").

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
