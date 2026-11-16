/* PartyRent — auth pages: login, register, forgot password, reset password */
(function () {
  'use strict';
  const { esc, url, $ } = U;
  const I = Icons.icon;

  function shell(inner, heading, sub) {
    return '<div class="auth"><div class="auth-art"><a class="brand" href="' + url('index.html') + '"><span class="brand-mark">' + I('party') + '</span><span>Party<b>Rent</b></span></a>' +
      '<svg class="confetti" viewBox="0 0 400 400" style="position:absolute;inset:0;width:100%;height:100%;opacity:.35">' + confetti() + '</svg>' +
      '<div style="position:relative"><h2>' + esc(heading) + '</h2><p style="opacity:.9;font-size:1.05rem;max-width:420px">' + esc(sub) + '</p><div class="hero-stats"><div><b>' + Store.where('suppliers', s => s.status === 'approved').length + '</b><span>trusted suppliers</span></div><div><b>' + Store.where('products', p => p.status === 'published').length + '+</b><span>rental items</span></div><div><b>AI</b><span>event planning</span></div></div></div>' +
      '<div class="small" style="opacity:.8;position:relative">Tables · Chairs · Tents · Sound · Lights · Décor — for every Filipino celebration.</div></div>' +
      '<div class="auth-form"><div class="box">' + inner + '</div></div></div>';
  }
  const confetti = Icons.confetti;

  const DEMO = [['maria@demo.ph', 'Maria Santos', 'Customer'], ['juan@demo.ph', 'Juan Dela Cruz', 'Customer + Individual Supplier'], ['liza@demo.ph', 'Liza Navarro', 'Organization Admin · Elegant Occasion'], ['cashier@elegantoccasion.ph', 'Grace Villanueva', 'Cashier · Elegant Occasion'], ['inventory@elegantoccasion.ph', 'Rico Santos', 'Inventory Staff · Elegant Occasion'], ['bea@demo.ph', 'Bea Mendoza', 'Individual application pending'], ['kristine@demo.ph', 'Kristine Aquino', 'Organization application pending'], ['admin@partyrent.ph', 'Mark Reyes', 'Platform Admin'], ['super@partyrent.ph', 'Patricia Lim', 'Superadmin']];

  Pages.auth = {
    login(ctx) {
      const s = Auth.session();
      ctx.el.innerHTML = shell('<h1>Welcome back</h1><p class="muted">Log in to manage your rentals' + (s ? ' — you are signed in as <b>' + esc(s.user.name) + '</b>.' : '.') + '</p>' +
        '<form id="f" novalidate class="mt-2"><div class="field"><label for="email">Email address</label><input class="input" id="email" name="email" type="email" required autocomplete="email" placeholder="you@email.com"></div>' +
        '<div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" required autocomplete="current-password" placeholder="••••••••"></div>' +
        '<div class="row between mb-2"><label class="check"><input type="checkbox" checked> Remember me</label><a href="' + url('forgot-password.html') + '" class="small strong">Forgot password?</a></div>' +
        '<div id="err"></div><button class="btn btn-primary btn-block btn-lg">Log in</button></form>' +
        '<p class="center mt-2 small">New to PartyRent? <a href="' + url('register.html') + '" class="strong">Create an account</a></p>' +
        '<div class="divider-text">Demo accounts · password <b>demo123</b></div><div class="demo-accounts">' + DEMO.map(d => '<button type="button" data-demo="' + d[0] + '">' + esc(d[1]) + '<span>' + esc(d[2]) + '</span></button>').join('') + '</div>' +
        '<p class="center mt-2"><a href="' + url('index.html') + '" class="small">← Continue browsing without logging in</a></p>', 'Every celebration, perfectly supplied.', 'Discover party supplies from local suppliers, schedule multi-day rentals with different quantities per day, and track every payment in one place.');
      const f = $('#f');
      const doLogin = (email, pw) => {
        const r = Auth.login(email, pw);
        if (!r.ok) { $('#err').innerHTML = '<div class="alert alert-danger mb-2">' + I('alert') + '<div>' + esc(r.error) + '</div></div>'; return; }
        UI.toast('Welcome, ' + r.user.name.split(' ')[0] + '!', 'success');
        const next = ctx.params.next; const staff = Auth.isStaff(r.user);
        const goNext = next && !staff && !next.startsWith('supplier') && next.endsWith('.html');
        if (goNext) RentalPeriod.requestPrompt(r.user.id, next); // after the date prompt, return to where they were
        setTimeout(() => { location.href = url(goNext ? next : r.redirect); }, 300);
      };
      f.addEventListener('submit', e => { e.preventDefault(); if (!UI.validate(f)) return; doLogin(f.email.value, f.password.value); });
      ctx.el.querySelectorAll('[data-demo]').forEach(b => b.onclick = () => { f.email.value = b.dataset.demo; f.password.value = 'demo123'; doLogin(b.dataset.demo, 'demo123'); });
    },

    register(ctx) {
      ctx.el.innerHTML = shell('<h1>Create your account</h1><p class="muted">One account lets you rent supplies — and later start your own rental shop.</p>' +
        '<form id="f" novalidate class="mt-2"><div class="field"><label for="name">Full name</label><input class="input" id="name" name="name" required minlength="3" placeholder="Juana Dela Cruz" autocomplete="name"></div>' +
        '<div class="form-grid"><div class="field"><label for="email">Email address</label><input class="input" id="email" name="email" type="email" required placeholder="you@email.com" autocomplete="email"></div>' +
        '<div class="field"><label for="phone">Contact number</label><input class="input" id="phone" name="phone" required data-phone placeholder="0917 555 0101" autocomplete="tel"></div></div>' +
        '<div class="field"><label for="address">Address</label><input class="input" id="address" name="address" required minlength="8" placeholder="House no., street, barangay, city" autocomplete="street-address"></div>' +
        '<div class="form-grid"><div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" required minlength="8" placeholder="At least 8 characters" autocomplete="new-password"></div>' +
        '<div class="field"><label for="confirm">Confirm password</label><input class="input" id="confirm" name="confirm" type="password" required data-match="password" placeholder="Re-type password" autocomplete="new-password"></div></div>' +
        '<div class="field"><label class="check"><input type="checkbox" name="terms" required> I agree to the rental terms and privacy policy.</label></div>' +
        '<div id="err"></div><button class="btn btn-primary btn-block btn-lg">Create account</button></form><p class="center mt-2 small">Already have an account? <a class="strong" href="' + url('login.html') + '">Log in</a></p>',
        'Plan less. Party more.', 'Tell our AI planner about your event and get a checklist of available items from real suppliers — with prices and availability for your dates.');
      const f = $('#f');
      f.addEventListener('submit', e => {
        e.preventDefault(); if (!UI.validate(f)) return;
        const r = Auth.register(UI.formData(f));
        if (!r.ok) { $('#err').innerHTML = '<div class="alert alert-danger mb-2">' + I('alert') + '<div>' + esc(r.error) + '</div></div>'; return; }
        UI.toast('Account created — welcome to PartyRent!', 'success');
        setTimeout(() => location.href = url('customer/profile.html', { welcome: 1 }), 400);
      });
    },

    forgot(ctx) {
      ctx.el.innerHTML = shell('<h1>Forgot password</h1><p class="muted">Enter your account email and we\'ll send a reset link.</p><form id="f" novalidate class="mt-2"><div class="field"><label for="email">Email address</label><input class="input" id="email" name="email" type="email" required></div><div id="out"></div><button class="btn btn-primary btn-block btn-lg">Send reset link</button></form><p class="center mt-2 small"><a href="' + url('login.html') + '">← Back to login</a></p>', 'We\'ve got you.', 'Reset your password in a minute and get back to planning.');
      const f = $('#f');
      f.addEventListener('submit', e => {
        e.preventDefault(); if (!UI.validate(f)) return;
        const u = Store.all('users').find(x => x.email.toLowerCase() === f.email.value.trim().toLowerCase());
        const token = Math.random().toString(36).slice(2, 10);
        if (u) { u.resetToken = token; Store.save(); }
        $('#out').innerHTML = '<div class="alert alert-success mb-2">' + I('check') + '<div>If an account exists for that email, a reset link has been sent.' + (u ? '<br><span class="small"><b>Demo:</b> email sending is simulated. <a href="' + url('reset-password.html', { email: u.email, token }) + '">Open the reset link</a>.</span>' : '') + '</div></div>';
      });
    },

    reset(ctx) {
      const p = ctx.params;
      const u = Store.all('users').find(x => x.email === p.email && x.resetToken && x.resetToken === p.token);
      ctx.el.innerHTML = shell(u ? '<h1>Set a new password</h1><p class="muted">For ' + esc(u.email) + '</p><form id="f" novalidate class="mt-2"><div class="field"><label for="password">New password</label><input class="input" id="password" name="password" type="password" required minlength="8"></div><div class="field"><label for="confirm">Confirm new password</label><input class="input" id="confirm" type="password" required data-match="password"></div><button class="btn btn-primary btn-block btn-lg">Update password</button></form>' : '<h1>Link expired</h1><div class="alert alert-warn">' + I('alert') + '<div>This password reset link is invalid or has already been used.</div></div><a class="btn btn-primary mt-2" href="' + url('forgot-password.html') + '">Request a new link</a>', 'Almost there.', 'Choose a strong password you don\'t use anywhere else.');
      const f = $('#f'); if (!f) return;
      f.addEventListener('submit', e => { e.preventDefault(); if (!UI.validate(f)) return; u.password = f.password.value; delete u.resetToken; Store.save(); UI.toast('Password updated. Please log in.', 'success'); setTimeout(() => location.href = url('login.html'), 600); });
    }
  };
})();
