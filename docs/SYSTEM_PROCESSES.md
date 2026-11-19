# PartyRent: processes and logic

Plain-language reference of every process in the system, who does it, and the rules behind it.
Status as of 2026-11-19 (after company role modules step 1). Items marked **(planned)** are not built yet.

---

## 0. Who is who

| Level | Roles | Where it is stored |
|---|---|---|
| System (PartyRent platform) | **Customer**, **Cashier** (PartyRent's), **Admin**, **Super Admin** | `users.roles` |
| Company (inside each supplier) | **Owner**, **Front Desk**, **Cashier**, **Inventory Keeper**, **Field Crew** | `shop_staff` |
| Event company (business) | Owner + staff (role modules for events are **planned**: Coordinator, Finance, Event Crew) | `shop_staff` |

- Everyone starts as a **Customer** account. Owning or working in a company is *membership*, not a system role.
- One account can own **one** company, or work for **one** company at a time.
- Company types: **Solo supplier** (rents items/packages directly) and **Business / Event company** (books whole
  events by quotation). Business choosing "Rental company and/or Event company" is **(planned)**.

---

## 1. Accounts

1. **Register:** name, email, phone, address, password; email verified by code.
2. **Log in:** after login the customer may be asked "Select rental dates / I'm just browsing".
3. **Modes:** a supplier or employee switches **Customer Mode ⇄ Business Mode** from the profile menu.
   Opening a page of the other mode switches automatically.
4. **Account guard:** a page opened in another account's tab refuses writes ("Account changed" bar).

---

## 2. Becoming a supplier (Start Selling)

1. Customer opens **Start Selling** and picks **Solo** or **Business**.
2. Solo: shop info, where you operate (shop / home-based / no pickup), delivery options, payment methods,
   policies. Business: company papers, physical workplace + map pin, services and events catered, service areas,
   payment schedule, portfolio photos.
3. Submit → status **Pending** → Admin reviews → **Approved** (Business Mode unlocked) or **Rejected** (with reason).
4. A solo shop can later verify business papers (account type Business) and stays a rental shop.

---

## 3. Company team (roles inside the company)

**Joining (owner invites):**
1. Owner → Team & Roles → **Add employee**: email + one or more roles.
2. System checks the email: existing account (asked to accept) · new email (gets a code) · blocked (PartyRent staff,
   owns a shop, already works elsewhere).
3. Person accepts (existing account) or enters the code and sets their own password (new) → member.
4. Invitations expire; owner can resend (after 60 s) or cancel.
5. "Request to join" started by the employee: **(planned option)**. Job posts / hiring: out of scope.

**Working:**
- Business Mode opens the module of the member's **main role**. With several roles, "Your modules" switches.
- The **Owner** opens every module.
- A page the role does not allow → back to the module home with "Your role cannot open that page".

**Modules (solo shop):**

| Module | Who | Home page shows |
|---|---|---|
| Owner | Owner | Dashboard: new requests, payments to check, upcoming, overdue, revenue, top items |
| Front Desk | Front Desk | Requests to answer, unread customer messages, installment plans, open cases, next 7 days |
| Cashier | Cashier | Payments to confirm, record cash, balances & overdue, history, receipts, reports, payouts |
| Inventory | Inventory Keeper | What to prepare (next 3 days), returns to inspect, units out of service, low stock |
| Field Crew | Field Crew | My Jobs: late / today / next 7 days with contact, address and map |

**Permissions:** each role has defaults; the owner can add or remove single permissions per member
("Custom permissions"). Managing the team always stays with the owner. Deactivating a member ends their shop
access only; their customer account stays.

---

## 4. Catalog (supplier)

- **Products:** rental (per day or per hour, quantity price tiers, stock, condition, replacement value,
  min/max duration, buffer) or for sale (stock on hand, low-stock alert). Category, colors, themes, events, photos.
  Delivery and/or pickup per item. Status: active / hidden / archived.
- **Packages:** bundles of items (fixed or choose-from groups), stages/tiers, package-only items, price, max hours.
- **Discounts:** % of item, ₱ per piece, % or ₱ of whole order, minimum amount, dates.
- **Inventory:** stock per location, transfers, maintenance holds, set-aside, damaged / missing / written off.
- **Availability rule:** Available = Total − Reserved − Damaged − Missing − Maintenance − Set aside.
  Only reserved (paid) bookings hold stock; pending requests show as conflicts.
- **Event packages (event companies):** price per event, guests, inclusions, photos.

---

## 5. Renting from a solo shop (main flow)

1. **Browse / search** (filters, compare up to 3, AI assistant suggests from real stock only).
2. Pick **dates** (one day, or "multiple days"; hourly items pick a time), quantity → **Add to rental list**
   or **Request rental** (direct checkout).
3. **Checkout:** event name and type, fulfillment (delivery with map pin, or pickup), one or several locations,
   quantity per day, payment plan, payment method. Stock is re-checked live.
4. Submit → request **Pending** (one request per shop). Customer and shop can **chat** on the request.
5. Shop (Owner / Front Desk) **Accepts** (stock re-checked inside a lock) or **Declines** (with reason).
6. **Pay** per plan:
   - **Standard:** down payment % now + final balance by the event date.
   - **Full:** 100 % in one payment.
   - **Installment:** down payment + installments the customer proposes; shop approves or rejects.
   - Methods: online (Xendit) or **cash** (the shop records it).
   - Only **verified** payments count. When the down/full payment is verified the booking is **reserved**
     (stock held). Unpaid accepted requests **expire** after the deadline.
7. **Fulfillment steps** (Field Crew / Owner): Accepted → In Progress (preparing) → Ready for Pickup or
   Out for Delivery → **Ongoing** (with customer). Steps cannot skip their requirements; irreversible steps ask to confirm.
8. **Return:** Return Pending → **Under Inspection** → inspection recorded per item: good, repairable,
   non-repairable, lost. Charges follow the shop's damage policy; customer can accept or dispute.
9. Security deposit settled (refund / keep part), final balance checked → **Completed**.
10. Customer **reviews** the item/package/shop; the shop can reply. Admins moderate reported reviews.

**Cancellation:** free until the shop's cut-off; after that the down payment may be forfeited (shop policy).
**Overdue:** daily scan flags late payments and late returns; reminders are sent; the shop can "Notify customer".

---

## 6. Problems and cases

1. Shop reports a **shortfall** (can't supply an item) or the customer **reports a problem**.
2. Case opens; either side proposes a fix: substitute, partial refund, compensation (always paid by the shop
   when the shop is at fault; a pricier substitute is shop-covered), rush fees.
3. Other side accepts/rejects; deadlines apply; unresolved → **escalated** to Admin, who settles it.

---

## 7. Booking an event company (business flow)

1. Customer opens the company page → **Get a quote**: event type, date, time, guests, venue, budget, package.
2. **Inquiry** → company builds a **quotation** (line items, payment schedule %, valid until) → **Quoted**.
3. Customer accepts → **Booked**: pays the **reservation fee** (holds the date) → **deposit** → **balance**,
   each by its due date (online, or to the business's account with proof the business confirms).
4. Dates fill up per "events per day". Quotations and unpaid reservations **expire**; reminders 3 days before,
   on the day, and while overdue.
5. Event done → **Completed** → customer reviews the company.
6. Online event money is held by PartyRent until an admin marks it sent to the business.
7. Cancel / decline at each stage with a reason.

---

## 8. Money: payouts

- Online payments are collected by PartyRent; each shop is paid out on its **payout schedule** (Xendit payout to
  the shop's account). Cash collected by the shop is netted.
- Shop sees Earnings & Payouts; Admin sees Supplier Payouts (batches, release, mark paid).

---

## 9. PartyRent Cashier, Admin and Super Admin

- **PartyRent Cashier** (system role, created by the Super Admin on Administrators): checks **online / bank
  transfer payments of every shop** (the shop's own cashier only handles that shop's cash), records refunds of
  money PartyRent holds, payment history, receipts, financial reports. Permissions per person are set by the
  Super Admin (Roles & Permissions). Admins with "Verify payments" can also check transfers.

- **Admin:** review supplier applications and business verification, users, listings and categories
  (approve typed new categories), payment verification, payouts, transactions, event bookings, complaints,
  shortfall cases, damage disputes, activity log, reports.
- **Super Admin:** everything above + administrators, roles & permissions, platform settings, audit log, monitoring.

---

## 10. Notifications

In-app bell + email for key steps (new request, accepted, payment received/verified, reminders, cases, invites).
Role-targeted company notifications (payment proof → Cashier, new request → Front Desk, return due → Inventory,
delivery today → Field Crew) are **(planned)**.

---

## 11. Planned (from docs/ROLE_MODULES_PLAN.md)

2. Event company role modules + assigning a booking to a crew member / coordinator.
3. Business picks Rental company and/or Event company.
4. Rental types **Event venues** and **Event equipment**.
5. Any-event wording (seminar, graduation, fiesta…), not only weddings.
6. Role-based notifications. 7. Demo data per company type + SE documents.
