/* =========================================================
   PartyRent — seed.js
   Realistic demonstration data. Dates are generated relative
   to "today" so the demo stays consistent whenever it is opened.
   ========================================================= */
(function (g) {
  'use strict';
  const { addDays, today } = U;

  function seed() {
    const T = today();
    const D = n => addDays(T, n);
    const ts = (n, h) => { const d = U.parse(D(n)); d.setHours(h == null ? 10 : h, 15, 0, 0); return d.toISOString(); };

    const categories = [
      { id: 'tables', name: 'Tables', icon: 'table', grad: ['#FFB4D1', '#E11D74'], active: true },
      { id: 'chairs', name: 'Chairs', icon: 'chair', grad: ['#C4B5FD', '#7C3AED'], active: true },
      { id: 'tents', name: 'Tents & Canopies', icon: 'tent', grad: ['#99F6E4', '#0D9488'], active: true },
      { id: 'tableware', name: 'Tableware', icon: 'plate', grad: ['#FDE68A', '#F59E0B'], active: true },
      { id: 'sound', name: 'Sound Systems', icon: 'speaker', grad: ['#A5B4FC', '#4338CA'], active: true },
      { id: 'lighting', name: 'Lighting Equipment', icon: 'bulb', grad: ['#FDE68A', '#D97706'], active: true },
      { id: 'decorations', name: 'Decorations', icon: 'balloon', grad: ['#FBCFE8', '#DB2777'], active: true },
      { id: 'backdrops', name: 'Backdrops', icon: 'frame', grad: ['#F5D0FE', '#A21CAF'], active: true },
      { id: 'games', name: 'Party Games', icon: 'game', grad: ['#BAE6FD', '#0284C7'], active: true },
      { id: 'catering', name: 'Catering Equipment', icon: 'pot', grad: ['#FED7AA', '#EA580C'], active: true },
      { id: 'furniture', name: 'Event Furniture', icon: 'sofa', grad: ['#DDD6FE', '#6D28D9'], active: true },
      { id: 'other', name: 'Other Party Supplies', icon: 'gift', grad: ['#FECDD3', '#E11D48'], active: true }
    ];

    const pw = 'demo123';
    const users = [
      { id: 'u1', name: 'Maria Santos', email: 'maria@demo.ph', phone: '0917 555 0101', address: '23 Mabini St., Brgy. Kamuning, Quezon City', roles: ['customer'], status: 'active', createdAt: ts(-120) },
      { id: 'u2', name: 'Juan Dela Cruz', email: 'juan@demo.ph', phone: '0918 555 0202', address: '88 Katipunan Ave., Loyola Heights, Quezon City', roles: ['customer'], supplierId: 's1', status: 'active', createdAt: ts(-300) },
      { id: 'u3', name: 'Ana Reyes', email: 'ana@demo.ph', phone: '0919 555 0303', address: '12 Caruncho Ave., Pasig City', roles: ['customer'], supplierId: 's2', status: 'active', createdAt: ts(-280) },
      { id: 'u4', name: 'Carlo Mendoza', email: 'carlo@demo.ph', phone: '0920 555 0404', address: '401 Chino Roces Ave., Makati City', roles: ['customer'], supplierId: 's3', status: 'active', createdAt: ts(-260) },
      { id: 'u5', name: 'Liza Navarro', email: 'liza@demo.ph', phone: '0921 555 0505', address: '5th Ave., Bonifacio Global City, Taguig', roles: ['customer'], supplierId: 's4', status: 'active', createdAt: ts(-240) },
      { id: 'u6', name: 'Ramon Bautista', email: 'ramon@demo.ph', phone: '0922 555 0606', address: '1530 Rizal Ave., Sta. Cruz, Manila', roles: ['customer'], supplierId: 's5', status: 'active', createdAt: ts(-220) },
      { id: 'u7', name: 'Jose Garcia', email: 'jose@demo.ph', phone: '0923 555 0707', address: '7 Sampaguita St., Marikina Heights, Marikina', roles: ['customer'], status: 'active', createdAt: ts(-90) },
      { id: 'u8', name: 'Bea Mendoza', email: 'bea@demo.ph', phone: '0924 555 0808', address: '19 Rizal Ave. Ext., Grace Park, Caloocan', roles: ['customer'], supplierId: 's6', status: 'active', createdAt: ts(-60) },
      { id: 'u9', name: 'Kristine Aquino', email: 'kristine@demo.ph', phone: '0925 555 0909', address: '33 Aguirre Ave., BF Homes, Parañaque', roles: ['customer'], supplierId: 's7', status: 'active', createdAt: ts(-75) },
      { id: 'u14', name: 'Miguel Torres', email: 'miguel@demo.ph', phone: '0926 555 1414', address: '2 Gen. Luna St., Malabon', roles: ['customer'], status: 'suspended', createdAt: ts(-40) },
      { id: 'u10', name: 'Grace Villanueva', email: 'cashier@elegantoccasion.ph', phone: '0917 800 1010', address: '5th Ave., Bonifacio Global City, Taguig', roles: ['cashier'], supplierId: 's4', orgRole: 'cashier', status: 'active', createdAt: ts(-230), createdBy: 'u5' },
      { id: 'u15', name: 'Rico Santos', email: 'inventory@elegantoccasion.ph', phone: '0917 800 1515', address: '5th Ave., Bonifacio Global City, Taguig', roles: ['employee'], supplierId: 's4', orgRole: 'inventory', status: 'active', createdAt: ts(-200), createdBy: 'u5' },
      { id: 'u16', name: 'Joy Lim', email: 'staff@elegantoccasion.ph', phone: '0917 800 1616', address: 'Pasig City', roles: ['employee'], supplierId: 's4', orgRole: 'staff', status: 'active', createdAt: ts(-120), createdBy: 'u5' },
      { id: 'u11', name: 'Mark Reyes', email: 'admin@partyrent.ph', phone: '0917 800 1111', address: 'PartyRent HQ, Ortigas Center, Pasig', roles: ['admin'], status: 'active', createdAt: ts(-340) },
      { id: 'u13', name: 'Paolo Cruz', email: 'paolo.admin@partyrent.ph', phone: '0917 800 1313', address: 'PartyRent HQ, Ortigas Center, Pasig', roles: ['admin'], status: 'active', createdAt: ts(-100), adminPerms: ['customers.view', 'suppliers.view', 'listings.view', 'transactions.view', 'complaints.manage', 'reports.view'] },
      { id: 'u12', name: 'Patricia Lim', email: 'super@partyrent.ph', phone: '0917 800 1212', address: 'PartyRent HQ, Ortigas Center, Pasig', roles: ['superadmin'], status: 'active', createdAt: ts(-365) }
    ].map(u => Object.assign({ password: pw }, u));

    const basePolicy = { cancellation: 'Free cancellation up to 3 days before the rental start date. Cancellations within 3 days forfeit the reservation fee.', returns: 'Items must be returned clean and complete on the agreed return date. Late returns are charged one extra rental day.', conditions: 'Renter is responsible for items while in their care. Damaged or missing items are charged at repair or replacement cost.' };
    const suppliers = [
      { id: 's1', ownerId: 'u2', name: 'Happy Moments Party Rentals', logo: '🎈', color: '#E11D74', description: 'Family-run party rentals serving Quezon City since 2016. Birthday setups, kiddie parties, and backyard celebrations made easy.', city: 'Quezon City', address: '88 Katipunan Ave., Loyola Heights, Quezon City', phone: '0918 555 0202', email: 'hello@happymoments.ph', hours: 'Mon–Sun, 8:00 AM – 7:00 PM', fulfillment: 'both', deliveryFee: 500, methods: ['cod', 'bank', 'debit'], arrangements: ['reservation', 'down', 'installment', 'full'], policy: Object.assign({ reservationFee: 500, reservationCredited: true, downType: 'percent', downValue: 30, depositType: 'fixed', depositValue: 1000, installments: 3, cancelDays: 3 }, basePolicy), rating: 4.8, status: 'approved', appliedAt: ts(-300), approvedAt: ts(-298) },
      { id: 's2', ownerId: 'u3', name: 'Celebration Hub Rentals', logo: '✨', color: '#7C3AED', description: 'Elegant Tiffany chairs, lights, and floral backdrops for debuts, weddings and milestone events in Pasig and the east of Metro Manila.', city: 'Pasig City', address: '12 Caruncho Ave., Pasig City', phone: '0919 555 0303', email: 'book@celebrationhub.ph', hours: 'Mon–Sat, 9:00 AM – 6:00 PM', fulfillment: 'delivery', deliveryFee: 800, methods: ['bank', 'debit'], arrangements: ['reservation', 'down', 'full'], policy: Object.assign({ reservationFee: 300, reservationCredited: false, downType: 'percent', downValue: 50, depositType: 'percent', depositValue: 15, installments: 2, cancelDays: 5 }, basePolicy, { cancellation: 'Free cancellation up to 5 days before the event. The reservation fee is non-refundable.' }), rating: 4.6, status: 'approved', appliedAt: ts(-280), approvedAt: ts(-279) },
      { id: 's3', ownerId: 'u4', name: 'Dream Events Supplies', logo: '🎪', color: '#0D9488', description: 'High-volume tents, banquet tables and pro sound for corporate events and big gatherings. Warehouse pickup in Makati.', city: 'Makati City', address: '401 Chino Roces Ave., Makati City', phone: '0920 555 0404', email: 'ops@dreamevents.ph', hours: 'Mon–Sat, 7:00 AM – 6:00 PM', fulfillment: 'pickup', deliveryFee: 0, methods: ['cod', 'bank'], arrangements: ['down', 'full'], policy: Object.assign({ reservationFee: 0, reservationCredited: true, downType: 'percent', downValue: 20, depositType: 'fixed', depositValue: 2000, installments: 2, cancelDays: 2 }, basePolicy), rating: 4.5, status: 'approved', appliedAt: ts(-260), approvedAt: ts(-258) },
      { id: 's4', ownerId: 'u5', name: 'Elegant Occasion Rentals', logo: '💍', color: '#B45309', description: 'Premium chiavari chairs, gold-and-white backdrops and lounge furniture for weddings and formal receptions across BGC and the south.', city: 'Taguig City', address: '5th Ave., Bonifacio Global City, Taguig', phone: '0921 555 0505', email: 'events@elegantoccasion.ph', hours: 'Tue–Sun, 10:00 AM – 7:00 PM', fulfillment: 'both', deliveryFee: 1200, methods: ['bank', 'debit'], arrangements: ['reservation', 'down', 'installment', 'full'], policy: Object.assign({ reservationFee: 1000, reservationCredited: true, downType: 'percent', downValue: 40, depositType: 'fixed', depositValue: 3000, installments: 3, cancelDays: 7 }, basePolicy, { cancellation: 'Free cancellation up to 7 days before the event. Later cancellations forfeit the reservation fee and 20% of payments made.' }), rating: 4.9, status: 'approved', appliedAt: ts(-240), approvedAt: ts(-238) },
      { id: 's5', ownerId: 'u6', name: 'Party Essentials Shop', logo: '🥳', color: '#2563EB', description: 'Affordable kiddie party must-haves in Manila: small chairs, kiddie tables, bubble machines, photo booth props and more.', city: 'Manila', address: '1530 Rizal Ave., Sta. Cruz, Manila', phone: '0922 555 0606', email: 'partyessentials@demo.ph', hours: 'Mon–Sun, 8:00 AM – 8:00 PM', fulfillment: 'both', deliveryFee: 350, methods: ['cod', 'bank'], arrangements: ['installment', 'full'], policy: Object.assign({ reservationFee: 0, reservationCredited: true, downType: 'percent', downValue: 0, depositType: 'fixed', depositValue: 500, installments: 2, cancelDays: 1 }, basePolicy), rating: 4.4, status: 'approved', appliedAt: ts(-220), approvedAt: ts(-219) },
      { id: 's6', ownerId: 'u8', name: "Bea's Balloon Corner", logo: '🎀', color: '#DB2777', description: 'Custom balloon garlands, arches and pastel party décor in Caloocan and nearby cities.', city: 'Caloocan City', address: '19 Rizal Ave. Ext., Grace Park, Caloocan', phone: '0924 555 0808', email: 'bea.balloons@demo.ph', hours: 'Mon–Sat, 9:00 AM – 5:00 PM', fulfillment: 'both', deliveryFee: 400, methods: ['cod', 'bank'], arrangements: ['down', 'full'], policy: Object.assign({ reservationFee: 0, reservationCredited: true, downType: 'percent', downValue: 50, depositType: 'fixed', depositValue: 500, installments: 2, cancelDays: 3 }, basePolicy), rating: 0, status: 'pending', appliedAt: ts(-2) }
    ];
    // Organization application (pending) — Kristine applies on behalf of a company
    suppliers.push({ id: 's7', ownerId: 'u9', name: 'Aquino Events Corp.', logo: '🏢', color: '#0EA5E9', description: 'Corporate event production company with two warehouses, offering staging, tents, lights and furniture for company events in the south of Metro Manila.', city: 'Parañaque', address: '120 Dr. A. Santos Ave., Parañaque', phone: '(02) 8820 1234', email: 'partners@aquinoevents.ph', hours: 'Mon–Sat, 8:00 AM – 6:00 PM', fulfillment: 'both', deliveryFee: 900, methods: ['bank'], policy: Object.assign({ depositType: 'fixed', depositValue: 2000, cancelDays: 7 }, basePolicy), rating: 0, status: 'pending', appliedAt: ts(-1) });

    /* ---------- v4 supplier settings: payment plans, rental hours, delivery zones, services,
       damage policy, inventory locations and supplier type / application ---------- */
    const DMG = (o) => Object.assign({ repairable: { enabled: true, pct: 50 }, nonRepairable: { enabled: true, pct: 70 }, lost: { enabled: true, brand_new: 100, good_as_new: 100, used: 70 }, categories: {} }, o || {});
    const loc = (id, name, address, contact, phone) => ({ id, name, address, contact, phone });
    const V4 = {
      s1: { type: 'individual', paymentPlans: { standard: { enabled: true, resPct: 10, resDays: 3, downPct: 30, downDays: 10 }, full: { enabled: true, days: 5 }, installment: { enabled: true, resPct: 10, resDays: 3, downPct: 20, downDays: 14, minMonths: 2, maxInstallments: 4 } },
        timeSettings: { open: '06:00', close: '22:00', prepHours: 1, recoveryHours: 1 },
        deliveryZones: [{ id: 'z1', name: 'Quezon City', fee: 350 }, { id: 'z2', name: 'Marikina / San Juan', fee: 450 }, { id: 'z3', name: 'Manila / Caloocan', fee: 600 }],
        services: [{ id: 'sv1', name: 'Setup and installation', description: 'Our crew arranges the tables, chairs and décor at the venue.', amount: 300, per: 'location', required: false }, { id: 'sv2', name: 'Dismantling and packing', description: 'Pack-up and pickup right after the event.', amount: 200, per: 'location', required: false }],
        damagePolicy: DMG(), locations: [loc('L1', 'Main Warehouse', '88 Katipunan Ave., Loyola Heights, Quezon City', 'Juan Dela Cruz', '0918 555 0202'), loc('L2', 'Branch A – Marikina', '7 Gil Fernando Ave., Marikina', 'Nestor Dela Cruz', '0918 555 0222')] },
      s2: { type: 'individual', paymentPlans: { standard: { enabled: true, resPct: 10, resDays: 2, downPct: 40, downDays: 7 }, full: { enabled: true, days: 3 }, installment: { enabled: false } },
        timeSettings: { open: '08:00', close: '22:00', prepHours: 2, recoveryHours: 1 },
        deliveryZones: [{ id: 'z1', name: 'Pasig', fee: 500 }, { id: 'z2', name: 'Mandaluyong / San Juan', fee: 700 }],
        services: [{ id: 'sv1', name: 'Equipment preparation', description: 'Cleaning, cushion fitting and quality check before delivery.', amount: 250, per: 'order', required: true }, { id: 'sv2', name: 'Styling and setup', description: 'Arrangement of chairs, lights and backdrop by our stylist.', amount: 800, per: 'location', required: false }],
        damagePolicy: DMG(), locations: [loc('L1', 'Pasig Showroom', '12 Caruncho Ave., Pasig City', 'Ana Reyes', '0919 555 0303')] },
      s3: { type: 'individual', paymentPlans: { standard: { enabled: true, resPct: 10, resDays: 3, downPct: 20, downDays: 7 }, full: { enabled: true, days: 7 }, installment: { enabled: false } },
        timeSettings: { open: '07:00', close: '21:00', prepHours: 2, recoveryHours: 2 }, deliveryZones: [],
        services: [{ id: 'sv1', name: 'Tent assembly crew', description: 'Two-person crew to assemble and dismantle tents at your venue.', amount: 1500, per: 'order', required: false }],
        damagePolicy: DMG({ repairable: { enabled: true, pct: 40 } }), locations: [loc('L1', 'Makati Warehouse', '401 Chino Roces Ave., Makati City', 'Carlo Mendoza', '0920 555 0404')] },
      s4: { type: 'organization', paymentPlans: { standard: { enabled: true, resPct: 10, resDays: 3, downPct: 30, downDays: 14 }, full: { enabled: true, days: 7 }, installment: { enabled: true, resPct: 10, resDays: 3, downPct: 20, downDays: 14, minMonths: 2, maxInstallments: 6 } },
        timeSettings: { open: '06:00', close: '23:00', prepHours: 1, recoveryHours: 1 },
        deliveryZones: [{ id: 'z1', name: 'BGC / Taguig', fee: 500 }, { id: 'z2', name: 'Makati / Pasig', fee: 700 }],
        services: [{ id: 'sv1', name: 'Setup and installation', description: 'Styling crew sets up furniture, backdrop and décor at each venue.', amount: 300, per: 'location', required: true }, { id: 'sv2', name: 'Dismantling and packing', description: 'Crew dismantles and packs everything after the event.', amount: 200, per: 'location', required: true }, { id: 'sv3', name: 'On-site coordinator', description: 'A coordinator stays during the event for adjustments.', amount: 1500, per: 'order', required: false }],
        damagePolicy: DMG({ categories: { decorations: { repairable: { enabled: true, pct: 40 }, nonRepairable: { enabled: true, pct: 80 }, lost: { enabled: true, brand_new: 100, good_as_new: 100, used: 80 } } } }),
        locations: [loc('L1', 'Main Warehouse', 'Lot 4, 32nd St., Bonifacio Global City, Taguig', 'Rico Santos', '0917 800 1515'), loc('L2', 'Branch A – Pasig', 'Ortigas Ave. Ext., Pasig City', 'Joy Lim', '0917 800 1616'), loc('L3', 'Branch B – Makati', 'Pasong Tamo Ext., Makati City', 'Liza Navarro', '0921 555 0505')],
        company: { legalName: 'Elegant Occasion Rentals Inc.', companyType: 'corporation', natureOfBusiness: 'Event furniture and décor rental', branches: 3, representative: 'Liza Navarro', position: 'President' } },
      s5: { type: 'individual', paymentPlans: { standard: { enabled: true, resPct: 0, resDays: 2, downPct: 50, downDays: 5 }, full: { enabled: true, days: 3 }, installment: { enabled: false } },
        timeSettings: { open: '08:00', close: '21:00', prepHours: 1, recoveryHours: 1 }, deliveryZones: [{ id: 'z1', name: 'Manila', fee: 250 }],
        services: [], damagePolicy: DMG(), locations: [loc('L1', 'Sta. Cruz Shop', '1530 Rizal Ave., Sta. Cruz, Manila', 'Ramon Bautista', '0922 555 0606')] },
      s6: { type: 'individual', paymentPlans: { standard: { enabled: true, resPct: 10, resDays: 3, downPct: 40, downDays: 7 }, full: { enabled: true, days: 3 }, installment: { enabled: false } },
        timeSettings: { open: '09:00', close: '20:00', prepHours: 1, recoveryHours: 1 }, deliveryZones: [], services: [], damagePolicy: DMG(), locations: [loc('L1', 'Home studio', '19 Rizal Ave. Ext., Grace Park, Caloocan', 'Bea Mendoza', '0924 555 0808')] },
      s7: { type: 'organization', paymentPlans: { standard: { enabled: true, resPct: 10, resDays: 3, downPct: 30, downDays: 14 }, full: { enabled: true, days: 7 }, installment: { enabled: false } },
        timeSettings: { open: '07:00', close: '22:00', prepHours: 2, recoveryHours: 2 }, deliveryZones: [], services: [], damagePolicy: DMG(), locations: [loc('L1', 'Parañaque Warehouse', '120 Dr. A. Santos Ave., Parañaque', 'Kristine Aquino', '0925 555 0909'), loc('L2', 'Las Piñas Warehouse', 'Alabang–Zapote Rd., Las Piñas', 'Ben Aquino', '0925 555 0919')],
        company: { legalName: 'Aquino Events Corporation', companyType: 'corporation', natureOfBusiness: 'Corporate event production and equipment rental', branches: 2, representative: 'Kristine Aquino', position: 'Operations Director' } }
    };
    const docs = (list) => Object.fromEntries(list.map(k => [k, { name: k.replace(/_/g, '-') + '.pdf', size: 184000 + k.length * 731, uploadedAt: ts(-2) }]));
    suppliers.forEach(sp => {
      Object.assign(sp, V4[sp.id]);
      delete sp.arrangements;
      ['reservationFee', 'reservationCredited', 'downType', 'downValue', 'installments'].forEach(k => { delete sp.policy[k]; });
      sp.application = sp.type === 'organization'
        ? { type: 'organization', submittedAt: sp.appliedAt, company: Object.assign({ email: sp.email, phone: sp.phone, address: sp.address, description: sp.description }, sp.company), representative: { name: sp.company.representative, position: sp.company.position, email: sp.email, phone: sp.phone, idType: 'Philippine National ID' }, docs: docs(['sec_certificate', 'bir_cor', 'mayors_permit', 'barangay_clearance', 'rep_id', 'proof_of_authority', 'board_resolution', 'establishment_photos']), payout: { method: 'bank', provider: 'BDO', accountName: sp.company.legalName, accountNumber: '0012-' + (3000 + sp.id.length * 111) + '-7788', financeContact: sp.company.representative } }
        : { type: 'individual', submittedAt: sp.appliedAt, personal: { fullName: (users.find(u => u.id === sp.ownerId) || {}).name, birthDate: '1990-05-14', address: sp.address, phone: sp.phone, email: sp.email }, identity: { idType: 'Philippine National ID', idNumber: 'PSN-' + (4000 + sp.id.length * 37) + '-1188' }, business: { shopName: sp.name, address: sp.address, productTypes: sp.description, phone: sp.phone }, docs: docs(sp.id === 's6' ? ['gov_id', 'selfie_id', 'barangay_clearance'] : ['gov_id', 'selfie_id', 'dti_registration', 'mayors_permit']), payout: { method: 'ewallet', provider: 'GCash', accountName: (users.find(u => u.id === sp.ownerId) || {}).name, accountNumber: '0917-555-' + (1000 + sp.id.length * 21) } };
      delete sp.company;
      if (sp.type === 'organization') sp.company = { legalName: sp.application.company.legalName, companyType: sp.application.company.companyType };
    });


    const P = (id, supplierId, name, categoryId, stock, tiers, extra) => Object.assign({
      id, supplierId, name, categoryId, totalStock: stock, damaged: 0, missing: 0,
      tiers: tiers.map(t => ({ min: t[0], max: t[1] || null, price: t[2] })),
      unit: 'piece / day', condition: 'Excellent', colors: [], themes: [], events: [], images: [],
      status: 'published', rating: 4.6, rentCount: 0, createdAt: ts(-200), delivery: true, pickup: true,
      description: ''
    }, extra || {});

    const products = [
      P('p1', 's1', 'White Monoblock Chair', 'chairs', 100, [[1, 10, 15], [11, 30, 13], [31, 50, 11], [51, null, 10]], { colors: ['White'], themes: ['Classic', 'Minimalist'], events: ['Birthday', 'Christening', 'Reunion', 'Corporate'], rating: 4.7, rentCount: 412, description: 'Sturdy, stackable white monoblock chairs. Cleaned and sanitized after every rental. Ideal for backyard parties, reunions and community events.' }),
      P('p2', 's1', 'Round Table (8-Seater)', 'tables', 20, [[1, 9, 150], [10, null, 130]], { colors: ['White'], themes: ['Classic'], events: ['Birthday', 'Wedding', 'Reunion', 'Christening'], rating: 4.8, rentCount: 188, description: '60-inch round table that comfortably seats 8 guests. Pair with our round tablecloths for a polished look.' }),
      P('p3', 's1', 'Rectangular Table (6 ft)', 'tables', 15, [[1, null, 180]], { colors: ['White'], themes: ['Classic'], events: ['Birthday', 'Corporate', 'Reunion'], rating: 4.6, rentCount: 96, description: 'Foldable 6-foot rectangular table for buffets, gift tables and registration areas.' }),
      P('p4', 's1', '10×10 ft Canopy Tent (White)', 'tents', 6, [[1, null, 1200]], { colors: ['White'], themes: ['Outdoor', 'Garden'], events: ['Birthday', 'Reunion', 'Christening'], rating: 4.7, rentCount: 64, unit: 'unit / day', description: 'Pop-up canopy with side walls, sandbags and setup included. Shelters about 20–25 seated guests.' }),
      P('p5', 's1', 'Pink Balloon Garland Set', 'decorations', 8, [[1, null, 850]], { colors: ['Pink', 'White'], themes: ['Pastel', 'Princess'], events: ['Birthday', 'Debut', 'Baby Shower'], rating: 4.9, rentCount: 120, unit: 'set / day', description: '4-meter pastel pink and white balloon garland with frame stand. Reusable frame, fresh balloons per rental.' }),
      P('p6', 's1', 'Party Speaker 1000W with Mic', 'sound', 4, [[1, null, 1500]], { colors: ['Black'], themes: ['Modern'], events: ['Birthday', 'Reunion', 'Corporate', 'Debut'], rating: 4.5, rentCount: 77, unit: 'unit / day', description: 'Bluetooth party speaker with 2 wireless microphones and stand. Good for up to 80 guests.' }),
      P('p7', 's1', 'Kids Party Games Kit', 'games', 5, [[1, null, 600]], { colors: ['Multicolor'], themes: ['Kids', 'Carnival'], events: ['Birthday'], rating: 4.6, rentCount: 54, unit: 'kit / day', description: 'Bring-me game cards, sack race bags, pabitin frame and prize bowl. Host guide included.' }),
      P('p8', 's1', 'White Round Tablecloth', 'tableware', 40, [[1, 19, 60], [20, null, 50]], { colors: ['White'], themes: ['Classic', 'Elegant'], events: ['Birthday', 'Wedding', 'Christening'], rating: 4.5, rentCount: 140, description: 'Floor-length polyester tablecloth for 60-inch round tables. Laundered and pressed.' }),

      P('p9', 's2', 'Tiffany Chair (Gold)', 'chairs', 120, [[1, 50, 45], [51, null, 40]], { colors: ['Gold'], themes: ['Elegant', 'Glam'], events: ['Wedding', 'Debut', 'Anniversary'], rating: 4.8, rentCount: 356, description: 'Classic gold Tiffany chairs with ivory seat cushions. Delivered and arranged by our crew.' }),
      P('p10', 's2', 'Tiffany Chair (Clear Ghost)', 'chairs', 60, [[1, null, 55]], { colors: ['Clear', 'White'], themes: ['Modern', 'Minimalist'], events: ['Wedding', 'Debut', 'Corporate'], rating: 4.7, rentCount: 141, description: 'Transparent acrylic Tiffany chairs for a modern, airy setup.' }),
      P('p11', 's2', 'LED Fairy Light Curtain', 'lighting', 15, [[1, null, 450]], { colors: ['Warm White', 'Gold'], themes: ['Elegant', 'Romantic', 'Garden'], events: ['Wedding', 'Debut', 'Birthday'], rating: 4.7, rentCount: 98, unit: 'panel / day', description: '3m × 3m warm-white curtain light. Perfect behind backdrops or along stage edges.' }),
      P('p12', 's2', 'Moving Head Party Lights (Pair)', 'lighting', 6, [[1, null, 1800]], { colors: ['Multicolor'], themes: ['Disco', 'Modern'], events: ['Debut', 'Birthday', 'Corporate'], rating: 4.5, rentCount: 40, unit: 'pair / day', maxRentalDays: 1, description: 'DMX moving head beam lights with controller. Operator available on request. Maximum 1 day per booking.' }),
      P('p13', 's2', 'Floral Arch Backdrop (Pink & White)', 'backdrops', 3, [[1, null, 2500]], { colors: ['Pink', 'White'], themes: ['Romantic', 'Pastel', 'Garden'], events: ['Debut', 'Wedding', 'Birthday'], rating: 4.9, rentCount: 37, unit: 'set / day', description: '2.4m round arch with silk florals in blush pink and white. Includes setup.' }),
      P('p14', 's2', 'Pastel Pink Centerpiece Set', 'decorations', 25, [[1, null, 180]], { colors: ['Pink'], themes: ['Pastel', 'Romantic'], events: ['Debut', 'Birthday', 'Baby Shower'], rating: 4.6, rentCount: 75, unit: 'set / day', description: 'Glass vase with silk peonies and votive candles, one set per table.' }),

      P('p15', 's3', 'White Monoblock Chair', 'chairs', 200, [[1, 50, 12], [51, null, 9]], { colors: ['White'], themes: ['Classic'], events: ['Corporate', 'Reunion', 'Birthday'], rating: 4.4, rentCount: 520, description: 'Bulk monoblock chairs for large events. Warehouse pickup only.', delivery: false }),
      P('p16', 's3', 'Folding Banquet Table', 'tables', 30, [[1, null, 200]], { colors: ['White'], themes: ['Classic'], events: ['Corporate', 'Reunion'], rating: 4.5, rentCount: 210, description: '8-foot banquet table, seats 8–10. Pickup only.', delivery: false }),
      P('p17', 's3', '20×20 ft Party Tent', 'tents', 4, [[1, null, 3500]], { colors: ['White'], themes: ['Outdoor'], events: ['Corporate', 'Reunion', 'Wedding', 'Birthday'], rating: 4.6, rentCount: 52, unit: 'unit / day', description: 'Large frame tent for up to 60 seated guests. Assembly crew available for an extra fee.', delivery: false }),
      P('p18', 's3', 'Chafing Dish Set', 'catering', 20, [[1, null, 250]], { colors: ['Silver'], themes: ['Classic'], events: ['Corporate', 'Wedding', 'Reunion', 'Birthday'], rating: 4.5, rentCount: 160, unit: 'set / day', description: 'Stainless chafing dish with water pan, food pan and fuel holder.', delivery: false }),
      P('p19', 's3', 'Line Array Sound System', 'sound', 2, [[1, null, 6500]], { colors: ['Black'], themes: ['Modern'], events: ['Corporate', 'Wedding', 'Debut'], rating: 4.8, rentCount: 33, unit: 'system / day', description: 'Professional line array with mixer, 4 wireless mics and technician for up to 300 guests.', delivery: false }),
      P('p20', 's3', 'Drink Station with Water Dispenser', 'catering', 6, [[1, null, 400]], { colors: ['Silver'], themes: ['Classic'], events: ['Corporate', 'Reunion', 'Birthday'], rating: 4.3, rentCount: 48, unit: 'set / day', description: 'Two 5-gallon dispensers, juice jars and a skirted serving table.', delivery: false }),

      P('p21', 's4', 'Gold Chiavari Chair', 'chairs', 150, [[1, 99, 65], [100, null, 55]], { colors: ['Gold'], themes: ['Elegant', 'Glam', 'Classic'], events: ['Wedding', 'Debut', 'Anniversary'], rating: 4.9, rentCount: 290, description: 'Resin chiavari chairs in champagne gold with white cushions.' }),
      P('p22', 's4', 'Elegant White & Gold Backdrop', 'backdrops', 4, [[1, null, 4500]], { colors: ['White', 'Gold'], themes: ['Elegant', 'Glam'], events: ['Wedding', 'Debut', 'Anniversary'], rating: 4.9, rentCount: 46, unit: 'set / day', description: '3m × 2.4m panel backdrop with draping, gold frame accents and floral corners.' }),
      P('p23', 's4', 'Crystal Centerpiece', 'decorations', 30, [[1, null, 350]], { colors: ['White', 'Gold', 'Clear'], themes: ['Elegant', 'Glam'], events: ['Wedding', 'Anniversary', 'Debut'], rating: 4.8, rentCount: 112, unit: 'piece / day', description: 'Crystal candelabra with white roses. One per guest table.' }),
      P('p24', 's4', 'Satin Tablecloth (Champagne)', 'tableware', 60, [[1, null, 90]], { colors: ['Gold', 'Champagne'], themes: ['Elegant'], events: ['Wedding', 'Debut', 'Anniversary'], rating: 4.7, rentCount: 150, description: 'Floor-length satin cloth for round tables, champagne gold.' }),
      P('p25', 's4', 'Lounge Sofa Set (White)', 'furniture', 5, [[1, null, 2800]], { colors: ['White'], themes: ['Elegant', 'Modern'], events: ['Wedding', 'Corporate', 'Debut'], rating: 4.8, rentCount: 29, unit: 'set / day', maxRentalDays: 2, description: '3-seater sofa, 2 armchairs and acrylic coffee table for VIP lounges.' }),
      P('p26', 's4', 'LED Uplighting (Set of 8)', 'lighting', 6, [[1, null, 1600]], { colors: ['Warm White', 'Gold', 'Multicolor'], themes: ['Elegant', 'Romantic'], events: ['Wedding', 'Debut', 'Corporate'], rating: 4.7, rentCount: 58, unit: 'set / day', description: 'Battery uplights with remote color control. Wash walls in any color.' }),

      P('p27', 's5', 'Kids Monoblock Chair (Blue)', 'chairs', 80, [[1, null, 10]], { colors: ['Blue'], themes: ['Kids'], events: ['Birthday', 'Christening'], rating: 4.4, rentCount: 260, description: 'Small chairs sized for kids 3–10 years old.' }),
      P('p28', 's5', 'Kiddie Table', 'tables', 20, [[1, null, 80]], { colors: ['Blue', 'Pink', 'Multicolor'], themes: ['Kids'], events: ['Birthday', 'Christening'], rating: 4.4, rentCount: 140, description: 'Low rectangular table for 6 kids.' }),
      P('p29', 's5', 'Photo Booth Frame & Props', 'other', 5, [[1, null, 700]], { colors: ['Multicolor', 'Gold'], themes: ['Fun', 'Kids', 'Carnival'], events: ['Birthday', 'Debut', 'Corporate', 'Wedding'], rating: 4.6, rentCount: 66, unit: 'set / day', description: 'Giant frame, 30 props and a ring light. Fun for all ages.' }),
      P('p30', 's5', 'Bubble Machine', 'games', 6, [[1, null, 500]], { colors: ['Multicolor'], themes: ['Kids', 'Fun'], events: ['Birthday', 'Christening'], rating: 4.5, rentCount: 81, unit: 'unit / day', description: 'Automatic bubble machine with 2 liters of bubble solution.' }),
      P('p31', 's5', 'Plates, Glasses & Cutlery (10 pax)', 'tableware', 30, [[1, null, 120]], { colors: ['White'], themes: ['Classic'], events: ['Birthday', 'Reunion', 'Christening'], rating: 4.3, rentCount: 97, unit: 'set / day', description: 'Ceramic plates, glasses, spoons and forks for 10 guests.' }),
      P('p32', 's5', 'Popcorn Machine', 'catering', 3, [[1, null, 900]], { colors: ['Red'], themes: ['Carnival', 'Kids'], events: ['Birthday', 'Corporate'], rating: 4.6, rentCount: 44, unit: 'unit / day', description: 'Cart-style popcorn machine with kernels for 50 servings.' }),
      P('p33', 's4', 'Wedding Decoration Set (Floral)', 'decorations', 100, [[1, 39, 250], [40, null, 220]], { colors: ['White', 'Gold'], themes: ['Elegant', 'Romantic', 'Garden'], events: ['Wedding', 'Debut', 'Anniversary'], rating: 4.8, rentCount: 64, unit: 'set / day', description: 'Aisle and table floral decoration set (pew bows, flower stands and garlands). Stored across our three warehouses.' })
    ];
    // staggered "recently added"
    products.forEach((p, i) => { p.createdAt = ts(-200 + i * 6); });
    products.find(p => p.id === 'p13').createdAt = ts(-3);
    products.find(p => p.id === 'p25').createdAt = ts(-5);
    products.find(p => p.id === 'p30').createdAt = ts(-6);
    products.find(p => p.id === 'p1').damaged = 2;
    products.find(p => p.id === 'p8').missing = 1;
    products.find(p => p.id === 'p15').writtenOff = 2; // non-repairable units from PR-0006
    products.find(p => p.id === 'p15').missing = 1;     // chair not returned on PR-0006
    /* v4: replacement value & condition (damage compensation basis), optional hour limits,
       and dedicated stock per inventory location (sums to totalStock) */
    const VAL = { p1: [500, 'good_as_new'], p2: [3500, 'good_as_new'], p3: [3000, 'used'], p4: [15000, 'good_as_new'], p5: [2500, 'brand_new'], p6: [18000, 'good_as_new'], p7: [2500, 'used'], p8: [600, 'used'], p9: [3500, 'good_as_new'], p10: [4000, 'brand_new'], p11: [2500, 'good_as_new'], p12: [35000, 'good_as_new'], p13: [12000, 'good_as_new'], p14: [1200, 'used'], p15: [450, 'used'], p16: [3200, 'used'], p17: [45000, 'good_as_new'], p18: [2800, 'used'], p19: [120000, 'good_as_new'], p20: [3500, 'used'], p21: [4500, 'good_as_new'], p22: [25000, 'good_as_new'], p23: [3000, 'good_as_new'], p24: [1500, 'used'], p25: [60000, 'good_as_new'], p26: [18000, 'good_as_new'], p27: [300, 'used'], p28: [1500, 'used'], p29: [6000, 'used'], p30: [4500, 'good_as_new'], p31: [1800, 'used'], p32: [10000, 'good_as_new'], p33: [2000, 'used'] };
    const HOURS = { p6: { minRentalHours: 4 }, p12: { minRentalHours: 3 }, p19: { minRentalHours: 4 }, p29: { minRentalHours: 3, maxRentalHours: 12 } };
    const SPLIT = { p1: { L1: 60, L2: 40 }, p2: { L1: 12, L2: 8 }, p21: { L1: 100, L2: 50 }, p23: { L1: 20, L3: 10 }, p33: { L1: 50, L2: 30, L3: 20 } };
    products.forEach(p => {
      p.value = VAL[p.id][0]; p.condition = VAL[p.id][1];
      Object.assign(p, HOURS[p.id] || {});
      p.stockByLocation = SPLIT[p.id] || { L1: p.totalStock };
      p.writtenOff = p.writtenOff || 0;
    });

    // Maintenance / set-aside holds (date-ranged; end null = until released)
    const holds = [
      { id: 'h1', productId: 'p1', type: 'maintenance', qty: 5, start: D(-2), end: D(10), note: 'Repainting scuffed chairs' },
      { id: 'h2', productId: 'p9', type: 'setaside', qty: 10, start: D(0), end: null, note: 'Reserved for showroom display' },
      { id: 'h3', productId: 'p17', type: 'maintenance', qty: 1, start: D(-5), end: D(4), note: 'Replacing torn tent panel' }
    ];

    const packages = [
      { id: 'k1', supplierId: 's1', name: 'Birthday Party Package', emoji: '🎂', description: 'Everything for a 50-guest birthday: chairs, round tables with cloths and a pink balloon garland.', items: [{ productId: 'p1', qty: 50 }, { productId: 'p2', qty: 5 }, { productId: 'p5', qty: 1 }, { productId: 'p8', qty: 5 }], price: 3500, conditions: 'Price is per day. Setup within Quezon City included with delivery.', events: ['Birthday'], active: true, images: [], createdAt: ts(-150) },
      { id: 'k2', supplierId: 's1', name: 'Outdoor Party Package', emoji: '⛺', description: 'Two canopy tents, 40 chairs, 4 long tables and a party speaker for garden or street parties.', items: [{ productId: 'p4', qty: 2 }, { productId: 'p1', qty: 40 }, { productId: 'p3', qty: 4 }, { productId: 'p6', qty: 1 }], price: 5800, conditions: 'Price is per day. Requires a flat area of at least 6 × 3 m.', events: ['Birthday', 'Reunion', 'Christening'], active: true, images: [], createdAt: ts(-120) },
      { id: 'k3', supplierId: 's4', name: 'Wedding Reception Package', emoji: '💒', description: 'An elegant white-and-gold reception for 100 guests: chiavari chairs, backdrop, crystal centerpieces, satin cloths and uplighting.', items: [{ productId: 'p21', qty: 100 }, { productId: 'p22', qty: 1 }, { productId: 'p23', qty: 12 }, { productId: 'p24', qty: 12 }, { productId: 'p26', qty: 1 }], price: 18500, conditions: 'Price is per day. Includes styling crew for 4 hours.', events: ['Wedding', 'Anniversary'], minRentalHours: 6, active: true, images: [], createdAt: ts(-140) },
      { id: 'k4', supplierId: 's2', name: 'Debut Party Package', emoji: '👑', description: '80 gold Tiffany chairs, floral arch, fairy-light curtains and pastel centerpieces for a dreamy 18th.', items: [{ productId: 'p9', qty: 80 }, { productId: 'p13', qty: 1 }, { productId: 'p11', qty: 4 }, { productId: 'p14', qty: 10 }], price: 9900, conditions: 'Price is per day. Delivery within Pasig, Mandaluyong and San Juan.', events: ['Debut'], active: true, images: [], createdAt: ts(-110) },
      { id: 'k5', supplierId: 's5', name: "Kids' Party Package", emoji: '🧸', description: '30 kids chairs, 6 kiddie tables, a bubble machine, photo booth props and dinnerware for 30.', items: [{ productId: 'p27', qty: 30 }, { productId: 'p28', qty: 6 }, { productId: 'p30', qty: 1 }, { productId: 'p29', qty: 1 }, { productId: 'p31', qty: 3 }], price: 2200, conditions: 'Price is per day.', events: ['Birthday', 'Christening'], active: true, images: [], createdAt: ts(-100) },
      { id: 'k6', supplierId: 's3', name: 'Corporate Event Package', emoji: '🏢', description: '100 chairs, 12 banquet tables, line array sound and 6 chafing dishes for company events.', items: [{ productId: 'p15', qty: 100 }, { productId: 'p16', qty: 12 }, { productId: 'p19', qty: 1 }, { productId: 'p18', qty: 6 }], price: 12000, conditions: 'Price is per day. Warehouse pickup only.', events: ['Corporate'], active: true, images: [], createdAt: ts(-90) }
    ];

    const days = (start, qtys) => { const o = {}; qtys.forEach((q, i) => { o[addDays(start, i)] = q; }); return o; };
    const hist = (arr) => arr.map(([status, n, by, note]) => ({ status, at: ts(n), by, note: note || '' }));

    const requests = [
      { id: 'PR-0001', customerId: 'u1', supplierId: 's1', eventName: "Sofia's 7th Birthday", eventType: 'Birthday', eventDate: D(6), start: D(5), end: D(7), fulfillment: 'delivery', address: '23 Mabini St., Brgy. Kamuning, Quezon City', instructions: 'Please set up the tables in the garden. Gate code 2468.', lines: [{ id: 'l1', type: 'item', refId: 'p1', days: days(D(5), [30, 20, 40]) }, { id: 'l2', type: 'item', refId: 'p2', days: days(D(5), [5, 8, 5]) }], arrangement: 'down', method: 'bank', status: 'accepted', createdAt: ts(-6), acceptedAt: D(-4), history: hist([['pending', -6, 'u1'], ['accepted', -4, 'u2', 'Confirmed. We will deliver at 8 AM on day one.']]), supplierNotes: 'Deliver 8 AM day one. Customer will keep chairs on site; quantity changes daily as per schedule.' },
      { id: 'PR-0002', customerId: 'u9', supplierId: 's1', eventName: 'Aquino Family Reunion', eventType: 'Reunion', eventDate: D(5), start: D(5), end: D(5), fulfillment: 'pickup', address: '', instructions: '', lines: [{ id: 'l1', type: 'package', refId: 'k1', days: days(D(5), [1]) }], arrangement: 'full', method: 'debit', status: 'accepted', createdAt: ts(-10), acceptedAt: D(-9), history: hist([['pending', -10, 'u9'], ['accepted', -9, 'u2']]), supplierNotes: 'Pickup at 7 AM.' },
      { id: 'PR-0003', customerId: 'u7', supplierId: 's1', eventName: 'Barangay Fiesta Booth', eventType: 'Community', eventDate: D(5), start: D(5), end: D(5), fulfillment: 'delivery', address: '7 Sampaguita St., Marikina Heights, Marikina', instructions: 'Need chairs by 6 AM.', lines: [{ id: 'l1', type: 'item', refId: 'p1', days: days(D(5), [40]) }, { id: 'l2', type: 'item', refId: 'p3', days: days(D(5), [3]) }], arrangement: 'full', method: 'cod', status: 'pending', createdAt: ts(-1, 15), history: hist([['pending', -1, 'u7']]), supplierNotes: '' },
      { id: 'PR-0004', customerId: 'u8', supplierId: 's1', eventName: "Baby Liam's Christening", eventType: 'Christening', eventDate: D(9), start: D(9), end: D(9), fulfillment: 'delivery', address: '19 Rizal Ave. Ext., Grace Park, Caloocan', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p2', days: days(D(9), [6]) }, { id: 'l2', type: 'item', refId: 'p8', days: days(D(9), [6]) }], arrangement: 'reservation', method: 'bank', status: 'pending', createdAt: ts(0, 9), history: hist([['pending', 0, 'u8']]), supplierNotes: '' },
      { id: 'PR-0005', customerId: 'u1', supplierId: 's2', eventName: "Camille's Debut", eventType: 'Debut', eventDate: D(21), start: D(21), end: D(21), fulfillment: 'delivery', address: 'Valle Verde Clubhouse, Pasig City', instructions: 'Ingress allowed from 2 PM only.', lines: [{ id: 'l1', type: 'package', refId: 'k4', days: days(D(21), [1]) }, { id: 'l2', type: 'item', refId: 'p12', days: days(D(21), [1]) }], arrangement: 'down', method: 'bank', status: 'pending', createdAt: ts(-1, 11), history: hist([['pending', -1, 'u1']]), supplierNotes: '' },
      { id: 'PR-0006', customerId: 'u1', supplierId: 's3', eventName: 'Santos Company Outing', eventType: 'Corporate', eventDate: D(-19), start: D(-20), end: D(-18), fulfillment: 'pickup', address: '', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p15', days: days(D(-20), [80, 80, 60]) }, { id: 'l2', type: 'item', refId: 'p16', days: days(D(-20), [10, 10, 8]) }, { id: 'l3', type: 'item', refId: 'p17', days: days(D(-20), [1, 1, 1]) }], arrangement: 'down', method: 'cod', status: 'completed', createdAt: ts(-35), acceptedAt: D(-33), history: hist([['pending', -35, 'u1'], ['accepted', -33, 'u4'], ['in_progress', -21, 'u4'], ['ready_pickup', -21, 'u4'], ['ongoing', -20, 'u4'], ['return_pending', -18, 'u4'], ['inspection', -17, 'u4'], ['completed', -15, 'u4', 'Settled. 2 chairs broken, 1 missing; charged from deposit.']]), supplierNotes: 'Picked up by Mr. Rodel Santos.',
        fulfillmentLog: { releaseDate: D(-20), releaseQty: '80 chairs, 10 tables, 1 tent', receivedBy: 'Rodel Santos', returnDate: D(-17), returnedQty: '79 chairs, 10 tables, 1 tent', method: 'pickup' },
        inspection: { at: ts(-17), by: 'u4', returnDate: D(-17), confirmed: true, confirmedAt: ts(-17), items: [{ productId: 'p15', rented: 80, returned: 79, repairable: 0, nonRepairable: 2, lost: 1, condition: 'used', value: 450, pcts: { repairable: 40, nonRepairable: 70, lost: 70 }, amounts: { repairable: 0, nonRepairable: 630, lost: 315 }, total: 945, description: 'Two chairs with cracked legs (cannot be repaired); one chair was not returned.' }, { productId: 'p16', rented: 10, returned: 10, repairable: 0, nonRepairable: 0, lost: 0, condition: 'used', value: 3200, pcts: { repairable: 40, nonRepairable: 70, lost: 70 }, amounts: { repairable: 0, nonRepairable: 0, lost: 0 }, total: 0, description: '' }, { productId: 'p17', rented: 1, returned: 1, repairable: 0, nonRepairable: 0, lost: 0, condition: 'good_as_new', value: 45000, pcts: { repairable: 40, nonRepairable: 70, lost: 100 }, amounts: { repairable: 0, nonRepairable: 0, lost: 0 }, total: 0, description: '' }], notes: 'Two chairs with cracked legs; one chair not returned.', photos: [], compensation: 945, settlement: 'deducted', settledAt: ts(-15) } },
      { id: 'PR-0007', customerId: 'u1', supplierId: 's5', eventName: 'Office Christmas Party', eventType: 'Corporate', eventDate: D(-30), start: D(-30), end: D(-30), fulfillment: 'delivery', address: 'Sta. Mesa, Manila', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p32', days: days(D(-30), [2]) }], arrangement: 'full', method: 'cod', status: 'declined', createdAt: ts(-40), declineReason: 'Popcorn machines are already booked for that date. Sorry!', history: hist([['pending', -40, 'u1'], ['declined', -39, 'u6', 'Popcorn machines are already booked for that date. Sorry!']]), supplierNotes: '' },
      { id: 'PR-0008', customerId: 'u7', supplierId: 's4', eventName: 'Garcia–Lopez Wedding', eventType: 'Wedding', eventDate: D(70), start: D(70), end: D(70), fulfillment: 'delivery', address: 'The Glass Garden, Pasig City', instructions: 'Styling crew should arrive by 1 PM.', lines: [{ id: 'l1', type: 'package', refId: 'k3', days: days(D(70), [1]) }, { id: 'l2', type: 'item', refId: 'p25', days: days(D(70), [1]) }], arrangement: 'installment', method: 'bank', status: 'accepted', createdAt: ts(-20), acceptedAt: D(-18), history: hist([['pending', -20, 'u7'], ['accepted', -18, 'u5']]), supplierNotes: 'Installment schedule approved: 3 payments before the wedding. Church décor comes from the Main Warehouse, reception décor from Branch A.' },
      { id: 'PR-0009', customerId: 'u2', supplierId: 's5', eventName: "Juan's Son's 5th Birthday", eventType: 'Birthday', eventDate: D(-45), start: D(-45), end: D(-45), fulfillment: 'delivery', address: '88 Katipunan Ave., Loyola Heights, Quezon City', instructions: '', lines: [{ id: 'l1', type: 'package', refId: 'k5', days: days(D(-45), [1]) }], arrangement: 'full', method: 'cod', status: 'completed', createdAt: ts(-55), acceptedAt: D(-54), history: hist([['pending', -55, 'u2'], ['accepted', -54, 'u6'], ['out_delivery', -45, 'u6'], ['ongoing', -45, 'u6'], ['return_pending', -44, 'u6'], ['inspection', -44, 'u6'], ['completed', -44, 'u6', 'All items returned in good condition. Deposit refunded.']]), supplierNotes: '',
        fulfillmentLog: { releaseDate: D(-45), releaseQty: 'Full package', receivedBy: 'Juan Dela Cruz', returnDate: D(-44), returnedQty: 'Full package', method: 'delivery' },
        inspection: { at: ts(-44), by: 'u6', returnDate: D(-44), confirmed: true, confirmedAt: ts(-44), items: [], notes: 'All good.', photos: [], compensation: 0, settlement: 'settled', settledAt: ts(-44) } },
      { id: 'PR-0010', customerId: 'u9', supplierId: 's3', eventName: 'Aquino Corp. Team Building', eventType: 'Corporate', eventDate: D(0), start: D(-1), end: D(1), fulfillment: 'pickup', address: '', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p17', days: days(D(-1), [2, 2, 2]) }, { id: 'l2', type: 'item', refId: 'p19', days: days(D(-1), [1, 1, 1]) }, { id: 'l3', type: 'item', refId: 'p15', days: days(D(-1), [60, 120, 60]) }], arrangement: 'down', method: 'cod', status: 'ongoing', createdAt: ts(-12), acceptedAt: D(-11), history: hist([['pending', -12, 'u9'], ['accepted', -11, 'u4'], ['in_progress', -2, 'u4'], ['ready_pickup', -2, 'u4'], ['ongoing', -1, 'u4']]), supplierNotes: '', fulfillmentLog: { releaseDate: D(-1), releaseQty: '2 tents, 1 sound system, 120 chairs', receivedBy: 'Kristine Aquino', method: 'pickup' } },
      { id: 'PR-0011', customerId: 'u7', supplierId: 's1', eventName: 'Garcia Housewarming', eventType: 'Birthday', eventDate: D(-3), start: D(-3), end: D(-2), fulfillment: 'delivery', address: '7 Sampaguita St., Marikina Heights, Marikina', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p1', days: days(D(-3), [25, 25]) }, { id: 'l2', type: 'item', refId: 'p3', days: days(D(-3), [3, 3]) }, { id: 'l3', type: 'item', refId: 'p6', days: days(D(-3), [1, 1]) }], arrangement: 'full', method: 'bank', status: 'return_pending', createdAt: ts(-14), acceptedAt: D(-13), history: hist([['pending', -14, 'u7'], ['accepted', -13, 'u2'], ['in_progress', -4, 'u2'], ['out_delivery', -3, 'u2'], ['ongoing', -3, 'u2'], ['return_pending', -2, 'u2']]), supplierNotes: 'Pick up items on the morning after the event.', fulfillmentLog: { releaseDate: D(-3), releaseQty: '25 chairs, 3 tables, 1 speaker', receivedBy: 'Jose Garcia', method: 'delivery' } },
      { id: 'PR-0012', customerId: 'u1', supplierId: 's5', eventName: 'Mini Movie Night', eventType: 'Birthday', eventDate: D(2), start: D(2), end: D(2), fulfillment: 'pickup', address: '', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p32', days: days(D(2), [1]) }], arrangement: 'full', method: 'cod', status: 'cancelled', createdAt: ts(-8), acceptedAt: D(-7), history: hist([['pending', -8, 'u1'], ['accepted', -7, 'u6'], ['cancelled', -5, 'u1', 'Event moved to next month.']]), supplierNotes: '' },
      { id: 'PR-0013', customerId: 'u9', supplierId: 's2', eventName: "Kristine's 30th", eventType: 'Birthday', eventDate: D(3), start: D(3), end: D(3), fulfillment: 'delivery', address: '33 Aguirre Ave., BF Homes, Parañaque', instructions: '', lines: [{ id: 'l1', type: 'item', refId: 'p10', days: days(D(3), [30]) }, { id: 'l2', type: 'item', refId: 'p11', days: days(D(3), [2]) }, { id: 'l3', type: 'item', refId: 'p13', days: days(D(3), [1]) }], arrangement: 'full', method: 'bank', status: 'in_progress', createdAt: ts(-4), acceptedAt: D(-2), history: hist([['pending', -4, 'u9'], ['accepted', -2, 'u3'], ['in_progress', 0, 'u3', 'Preparing items for delivery.']]), supplierNotes: '' }
    ];

    /* v4: payment plan names, rental times, delivery locations, services, inventory sources */
    const PLAN = { down: 'standard', reservation: 'standard', full: 'full', installment: 'installment' };
    const dest = (id, label, address, zoneId, time) => ({ id, label, address, zoneId: zoneId || '', deliveryTime: time || '', contactName: '', contactPhone: '' });
    const RQ = {
      'PR-0001': { src: 'L2', time: { start: '08:00', end: '20:00' }, dest: [dest('d1', 'Home – garden party', '23 Mabini St., Brgy. Kamuning, Quezon City', 'z1', '07:00')], services: ['sv1'] },
      'PR-0002': { time: { start: '06:00', end: '11:00' } },   // morning booking
      'PR-0003': { time: { start: '13:00', end: '18:00' }, dest: [dest('d1', 'Barangay fiesta booth', '7 Sampaguita St., Marikina Heights, Marikina', 'z2', '12:00')] }, // afternoon
      'PR-0004': { time: { start: '09:00', end: '17:00' }, dest: [dest('d1', 'Christening reception', '19 Rizal Ave. Ext., Grace Park, Caloocan', 'z3', '08:00')] },
      'PR-0005': { time: { start: '14:00', end: '22:00' }, dest: [dest('d1', 'Valle Verde Clubhouse', 'Valle Verde Clubhouse, Pasig City', 'z1', '13:00')], services: ['sv2'] },
      'PR-0007': { dest: [dest('d1', 'Office', 'Sta. Mesa, Manila', 'z1')] },
      'PR-0009': { dest: [dest('d1', 'Home', '88 Katipunan Ave., Loyola Heights, Quezon City', '')] },
      'PR-0011': { time: { start: '10:00', end: '21:00' }, dest: [dest('d1', 'Home', '7 Sampaguita St., Marikina Heights, Marikina', '')] },
      'PR-0013': { time: { start: '15:00', end: '22:00' }, dest: [dest('d1', 'Home', '33 Aguirre Ave., BF Homes, Parañaque', '')] }
    };
    requests.forEach(r => {
      r.plan = PLAN[r.arrangement] || 'full'; delete r.arrangement;
      const x = RQ[r.id] || {};
      if (x.time) r.lines.forEach(l => { l.time = Object.assign({}, x.time); });
      r.destinations = r.fulfillment === 'delivery' ? (x.dest || [dest('d1', 'Event venue', r.address, '')]) : [];
      r.lines.forEach(l => { l.destId = r.destinations.length ? r.destinations[0].id : null; if (x.src) l.sourceId = x.src; });
      r.services = x.services || [];
    });
    // Garcia–Lopez Wedding: two venues on the same day, décor split between them and two warehouses
    const wed = requests.find(r => r.id === 'PR-0008');
    wed.address = 'Santuario de San Antonio, Forbes Park, Makati City';
    wed.destinations = [dest('d1', 'Church – Wedding Ceremony', 'Santuario de San Antonio, McKinley Rd., Forbes Park, Makati City', 'z2', '06:00'), dest('d2', 'Reception – The Glass Garden', 'The Glass Garden, Ortigas Ave., Pasig City', 'z2', '11:30')];
    wed.lines = [
      { id: 'l1', type: 'package', refId: 'k3', days: days(D(70), [1]), time: { start: '13:00', end: '22:00' }, destId: 'd2', sourceId: 'L1' },
      { id: 'l2', type: 'item', refId: 'p25', days: days(D(70), [1]), time: { start: '13:00', end: '22:00' }, destId: 'd2', sourceId: 'L1' },
      { id: 'l3', type: 'item', refId: 'p33', days: days(D(70), [20]), time: { start: '07:00', end: '11:00' }, destId: 'd1', sourceId: 'L1' },
      { id: 'l4', type: 'item', refId: 'p33', days: days(D(70), [20]), time: { start: '13:00', end: '22:00' }, destId: 'd2', sourceId: 'L2' }
    ];
    wed.allocation = { status: 'approved', at: ts(-18), by: 'u5' };
    wed.installmentPlan = { status: 'approved', split: [D(10), D(40), D(69)], proposedAt: ts(-20), reviewedAt: ts(-18), reviewedBy: 'u5' };

    // Payments — only "verified" ones count toward balances.
    // Payments — only "verified" ones count toward balances. `stage` amounts are filled in from
    // each request's payment schedule when the demo data is created (Store.finalizeSeed).
    const pay = (id, requestId, type, method, amount, n, status, ref, extra) => Object.assign({ id, requestId, type, method, amount, date: D(n), submittedAt: ts(n, 13), reference: ref, status, proof: null }, status === 'verified' ? { verifiedAt: ts(n, 16), receiptNo: 'OR-' + id.slice(4) } : {}, extra || {});
    const st = k => ({ stage: k });
    const payments = [
      pay('TXN-10001', 'PR-0001', 'reservation', 'bank', null, -4, 'verified', 'BDO-884120', st('res')),
      pay('TXN-10002', 'PR-0001', 'down', 'bank', null, -3, 'verified', 'BDO-884377', st('down')),
      pay('TXN-10003', 'PR-0001', 'balance', 'bank', 1000, 0, 'pending', 'BPI-551093', { proofName: 'bpi-transfer-receipt.jpg', notes: 'Partial final balance payment' }),
      pay('TXN-10004', 'PR-0002', 'full', 'debit', null, -9, 'verified', 'DC-7781-0021', st('full')),
      pay('TXN-10005', 'PR-0002', 'deposit', 'debit', 1000, -9, 'verified', 'DC-7781-0022'),
      pay('TXN-10006', 'PR-0006', 'reservation', 'cash', null, -33, 'verified', 'CASH-0912', st('res')),
      pay('TXN-10024', 'PR-0006', 'down', 'cash', null, -30, 'verified', 'CASH-0931', st('down')),
      pay('TXN-10007', 'PR-0006', 'balance', 'cash', null, -20, 'verified', 'CASH-0957', st('bal')),
      pay('TXN-10008', 'PR-0006', 'deposit', 'cash', 2000, -20, 'verified', 'CASH-0958'),
      pay('TXN-10009', 'PR-0006', 'deposit_refund', 'cash', 1055, -15, 'verified', 'RF-0031', { notes: '₱945 damage compensation deducted from ₱2,000 deposit' }),
      pay('TXN-10010', 'PR-0008', 'reservation', 'bank', null, -17, 'verified', 'UB-220145', st('res')),
      pay('TXN-10023', 'PR-0008', 'down', 'bank', null, -8, 'verified', 'UB-220377', st('down')),
      pay('TXN-10011', 'PR-0008', 'installment', 'bank', 6000, -6, 'rejected', 'UB-22O1A9', { proofName: 'screenshot.png', rejectReason: 'Reference number not found in bank statement. Please resubmit a clear receipt.' }),
      pay('TXN-10012', 'PR-0009', 'full', 'cash', null, -45, 'verified', 'CASH-0810', st('full')),
      pay('TXN-10013', 'PR-0009', 'deposit', 'cash', 500, -45, 'verified', 'CASH-0811'),
      pay('TXN-10014', 'PR-0009', 'deposit_refund', 'cash', 500, -44, 'verified', 'RF-0022'),
      pay('TXN-10015', 'PR-0010', 'reservation', 'bank', null, -10, 'verified', 'MBTC-11902', st('res')),
      pay('TXN-10025', 'PR-0010', 'down', 'bank', null, -9, 'verified', 'MBTC-11950', st('down')),
      pay('TXN-10022', 'PR-0010', 'balance', 'cash', null, -1, 'verified', 'CASH-0998', st('bal')),
      pay('TXN-10016', 'PR-0010', 'deposit', 'cash', 2000, -1, 'verified', 'CASH-0999'),
      pay('TXN-10017', 'PR-0011', 'full', 'bank', null, -12, 'verified', 'GCASH-BANK-77810', st('full')),
      pay('TXN-10018', 'PR-0011', 'deposit', 'bank', 1000, -12, 'verified', 'GCASH-BANK-77811'),
      pay('TXN-10019', 'PR-0013', 'full', 'bank', null, -1, 'pending', 'BDO-901233', Object.assign(st('full'), { proofName: 'bdo-online-receipt.png' })),
      pay('TXN-10020', 'PR-0012', 'full', 'cash', null, -7, 'verified', 'CASH-0977', st('full')),
      pay('TXN-10021', 'PR-0012', 'refund', 'cash', null, -5, 'verified', 'RF-0035', Object.assign(st('full'), { notes: 'Cancelled within free cancellation window' }))
    ];

    const reviews = [
      { id: 'rv1', supplierId: 's1', productId: 'p1', customerId: 'u9', rating: 5, comment: 'Chairs were spotless and delivered early. Super bait ng crew!', at: ts(-30) },
      { id: 'rv2', supplierId: 's1', productId: 'p5', customerId: 'u1', rating: 5, comment: 'The pink garland made the whole party. My daughter loved it.', at: ts(-60) },
      { id: 'rv3', supplierId: 's1', productId: 'p6', customerId: 'u7', rating: 4, comment: 'Speaker was loud and clear, one mic had a weak battery.', at: ts(-25) },
      { id: 'rv4', supplierId: 's2', productId: 'p9', customerId: 'u9', rating: 5, comment: 'Gold Tiffany chairs looked so elegant for our debut.', at: ts(-50) },
      { id: 'rv5', supplierId: 's3', productId: 'p17', customerId: 'u1', rating: 4, comment: 'Big tent, easy pickup. Wish they offered delivery.', at: ts(-14) },
      { id: 'rv6', supplierId: 's4', productId: 'p22', customerId: 'u7', rating: 5, comment: 'Backdrop was exactly like the photos. Worth it!', at: ts(-70) },
      { id: 'rv7', supplierId: 's5', productId: 'p30', customerId: 'u2', rating: 4, comment: 'Kids had so much fun with the bubble machine.', at: ts(-43) }
    ];

    const complaints = [
      { id: 'CMP-001', reporterId: 'u7', subject: 'Payment rejected but money was deducted', against: 's4', requestId: 'PR-0008', body: 'My bank transfer for the wedding installment was rejected but the amount left my account. Please help check.', status: 'open', priority: 'high', at: ts(-5), updates: [] },
      { id: 'CMP-002', reporterId: 'u9', subject: 'Listing photos look different', against: 's3', requestId: null, body: 'The tent listing photo shows side walls but the tent we got had none.', status: 'in_review', priority: 'normal', at: ts(-9), updates: [{ at: ts(-8), by: 'u11', note: 'Contacted supplier for clarification.' }] },
      { id: 'CMP-003', reporterId: 'u1', subject: 'Late delivery', against: 's5', requestId: null, body: 'Delivery arrived 2 hours late during a past rental.', status: 'resolved', priority: 'low', at: ts(-50), updates: [{ at: ts(-48), by: 'u11', note: 'Supplier apologized and issued a discount voucher.' }] }
    ];

    const n = (to, scope, title, body, link, n0, read) => ({ id: U.uid('n'), to, scope, title, body, link, at: ts(n0, 9 + Math.floor(Math.random() * 8)), readBy: read ? [to] : [] });
    const notifications = [
      n('u1', 'customer', 'Request accepted', "Happy Moments Party Rentals accepted PR-0001 (Sofia's 7th Birthday). Down payment is now due.", 'customer/rental.html?id=PR-0001', -4, true),
      n('u1', 'customer', 'Payment verified', 'Your down payment for PR-0001 was verified. The reservation fee and down payment are credited to your total. Receipt OR-10002 is ready.', 'customer/rental.html?id=PR-0001', -3, false),
      n('u1', 'customer', 'Request submitted', "PR-0005 (Camille's Debut) was sent to Celebration Hub Rentals and is pending review.", 'customer/rental.html?id=PR-0005', -1, false),
      n('u2', 'supplier', 'New rental request', 'Jose Garcia requested 40 Monoblock Chairs for ' + U.fmtDate(D(5)) + '. Check for inventory conflicts.', 'supplier/request.html?id=PR-0003', -1, false),
      n('u2', 'supplier', 'New rental request', "Bea Mendoza sent PR-0004 (Baby Liam's Christening).", 'supplier/request.html?id=PR-0004', 0, false),
      n('u2', 'supplier', 'Return due', 'PR-0011 (Garcia Housewarming) items are due for return inspection.', 'supplier/request.html?id=PR-0011', -2, false),
      n('u2', 'supplier', 'Payment submitted', 'Maria Santos submitted ₱1,000.00 for PR-0001. Review it in Payments → Verification queue.', 'supplier/payments.html?tab=verify', 0, false),
      n('u2', 'customer', 'Rental completed', "PR-0009 is complete and your ₱500 deposit was refunded.", 'customer/rental.html?id=PR-0009', -44, true),
      n('u7', 'customer', 'Payment rejected', 'Your ₱6,000.00 installment for PR-0008 was rejected: reference number not found.', 'customer/rental.html?id=PR-0008', -6, false),
      n('u3', 'supplier', 'New rental request', "Maria Santos sent PR-0005 (Camille's Debut).", 'supplier/request.html?id=PR-0005', -1, false),
      n('u10', 'cashier', 'Installment schedule approved', 'PR-0008 (Garcia–Lopez Wedding) now has 3 scheduled installments. Watch for the first one.', 'cashier/balances.html', -18, false),
      n('u3', 'supplier', 'Payment submitted', 'Kristine Aquino submitted a full payment (BDO-901233) for PR-0013. Review it in Payments → Verification queue.', 'supplier/payments.html?tab=verify', -1, false),
      n('admin', 'admin', 'New supplier application', "Bea Mendoza applied as an Individual Supplier (Bea's Balloon Corner).", 'admin/applications.html', -2, false),
      n('admin', 'admin', 'New organization application', 'Kristine Aquino applied for Aquino Events Corp. as an Organization / Company Supplier.', 'admin/applications.html', -1, false),
      n('admin', 'admin', 'New complaint', 'Jose Garcia reported a payment issue on PR-0008.', 'admin/complaints.html', -5, false),
      n('superadmin', 'superadmin', 'Suspicious account activity', 'Account miguel@demo.ph was suspended after 6 failed payment proofs.', 'superadmin/users.html', -3, false)
    ];

    const audit = [
      ['u12', 'superadmin', 'Created admin account', 'Paolo Cruz (paolo.admin@partyrent.ph)', -100],
      ['u12', 'superadmin', 'Updated permissions', 'Paolo Cruz: removed suppliers.manage, applications.review', -99],
      ['u11', 'admin', 'Approved supplier application', 'Party Essentials Shop', -219],
      ['u11', 'admin', 'Suspended account', 'Miguel Torres (miguel@demo.ph) — repeated invalid payment proofs', -3],
      ['u2', 'supplier', 'Verified payment', 'TXN-10002 · down payment · PR-0001', -3],
      ['u10', 'cashier', 'Rejected payment', 'TXN-10011 · ₱6,000.00 · PR-0008 — reference not found', -6],
      ['u5', 'supplier', 'Approved installment schedule', 'PR-0008 · 3 installments until the event date', -18],
      ['u2', 'supplier', 'Accepted rental request', 'PR-0001 · Happy Moments Party Rentals', -4],
      ['u4', 'supplier', 'Completed return inspection', 'PR-0006 · ₱945.00 compensation deducted from deposit', -17],
      ['u12', 'superadmin', 'Changed platform setting', 'Overdue grace period set to 0 days', -30]
    ].map(([actorId, role, action, details, d], i) => ({ id: 'AUD-' + (1000 + i), at: ts(d, 11), actorId, role, action, details }));

    return {
      meta: { version: 4, seededOn: T, seq: { request: 13, payment: 10025, receipt: 10025, complaint: 3, movement: 0 } },
      settings: { platformName: 'PartyRent', requireSupplierApproval: true, aiEnabled: true, overdueGraceDays: 0, maxRentalDays: 30, supportEmail: 'support@partyrent.ph', supportPhone: '(02) 8123 4567', commissionPct: 5 },
      permissions: {
        admin: ['customers.view', 'customers.manage', 'suppliers.view', 'suppliers.manage', 'applications.review', 'listings.view', 'listings.manage', 'categories.manage', 'transactions.view', 'complaints.manage', 'reports.view'],
        cashier: ['payments.view', 'payments.verify', 'payments.record', 'receipts.issue', 'reports.view']
      },
      users, suppliers, categories, products, holds, packages, requests, payments, reviews, complaints, notifications, audit,
      carts: { u1: [{ id: 'c1', supplierId: 's2', type: 'item', refId: 'p9', days: days(D(12), [60, 60]), time: { start: '14:00', end: '22:00' }, selected: true }, { id: 'c2', supplierId: 's1', type: 'item', refId: 'p5', days: days(D(12), [1]), time: { start: '10:00', end: '18:00' }, selected: true }, { id: 'c3', supplierId: 's4', type: 'item', refId: 'p21', days: days(D(12), [50]), time: { start: '08:00', end: '20:00' }, selected: true }] },
      movements: []
    };
  }

  g.Seed = { build: seed };
})(window);
