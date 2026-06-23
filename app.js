/* ============================================================
   Saraswati Library — app.js
   Fully-functional client-side logic
   ============================================================ */

/* ── Config ─────────────────────────────────────────────────── */
const API_URL = 'api.php';

/* ── CSRF token (fetched once, refreshed on 403) ────────────── */
let _csrfToken = '';

async function fetchCsrfToken() {
  try {
    const r = await fetch(`${API_URL}?action=csrf_token`, { credentials: 'include' });
    const d = await r.json();
    _csrfToken = d.token || '';
  } catch (e) {
    console.warn('CSRF fetch failed:', e);
  }
}

/* ── Global state ───────────────────────────────────────────── */
const state = {
  selectedTable: null,
  selectedZone:  null,
  selectedDate:  null,
  selectedTime:  null,
  isLoggedIn:    false,
  user:          JSON.parse(localStorage.getItem('saraswati_user') || 'null'),
  fromBooking:   false,
  payment: {
    orderRef:      null,
    reservationId: null,
    amount:        0,
    upiLink:       null,
    payeeName:     '',
    expiresAt:     null,
    timerInterval: null,
    pollInterval:  null,
    qrInstance:    null,
    lastUtr:       null,
    lastApp:       null,
  },
};

/* ═══════════════════════════════════════════════════════════════
   PAGE NAVIGATION
   ═══════════════════════════════════════════════════════════════ */
function showPage(pageId) {
  document.querySelectorAll('.page').forEach(p => {
    p.classList.remove('active');
    p.style.display = 'none';
  });
  const page = document.getElementById(pageId);
  if (page) {
    page.style.display = 'block';
    page.offsetHeight;
    page.classList.add('active');
  }
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

function returnToBooking() { showPage('page-booking'); }

/* ═══════════════════════════════════════════════════════════════
   API HELPERS
   ═══════════════════════════════════════════════════════════════ */
async function apiPost(payload) {
  try {
    const form = new FormData();
    Object.entries(payload).forEach(([k, v]) => form.append(k, v));
    const res = await fetch(API_URL, {
      method: 'POST', body: form,
      credentials: 'include',
      headers: { 'X-CSRF-Token': _csrfToken },
    });
    if (res.status === 403) {
      await fetchCsrfToken();
      return { ok: false, message: 'Session expired. Please refresh and try again.', csrf_expired: true };
    }
    if (res.status === 429) return { ok: false, message: 'Too many requests. Please wait a moment.', rate_limited: true };
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return await res.json();
  } catch (e) {
    console.error('API error:', e);
    return { ok: false, message: 'Network error. Please check your connection.' };
  }
}

async function apiGet(params = {}) {
  try {
    const qs  = new URLSearchParams(params).toString();
    const res = await fetch(`${API_URL}?${qs}`, { credentials: 'include' });
    if (!res.ok) throw new Error('HTTP ' + res.status);
    return await res.json();
  } catch (e) {
    console.error('API error:', e);
    return { ok: false, message: 'Network error.' };
  }
}

/* ═══════════════════════════════════════════════════════════════
   SESSION RESTORE
   Called on page-load when localStorage has a user but we need
   to verify the PHP session is still alive.
   ═══════════════════════════════════════════════════════════════ */
async function restoreSession() {
  if (!state.user) return;
  try {
    const data = await apiGet({ action: 'me' });
    if (data.ok && data.user) {
      // Session is alive — update localStorage with fresh data
      state.user      = data.user;
      state.isLoggedIn = true;
      localStorage.setItem('saraswati_user', JSON.stringify(data.user));
    } else {
      // Session expired but keep localStorage for display purposes
      // Non-admin actions still work; admin will prompt re-login
      state.isLoggedIn = true;
    }
  } catch (_) {
    state.isLoggedIn = !!state.user;
  }
  updateNavbars();
}

/* ═══════════════════════════════════════════════════════════════
   TABLE SELECTION
   ═══════════════════════════════════════════════════════════════ */
function selectTable(el) {
  if (el.classList.contains('booked') || el.classList.contains('maintenance')) return;

  if (state.selectedTable) {
    const prev = document.querySelector(`[data-table="${state.selectedTable}"]`);
    if (prev) prev.classList.remove('selected');
  }
  el.classList.add('selected');
  state.selectedTable = el.dataset.table;
  state.selectedZone  = el.dataset.zone;
  ripple(el);

  const zoneLabels = { window: '🪟 Window Zone', silent: '🤫 Silent Zone', group: '👥 Group Zone', power: '⚡ Power Zone' };
  const seatsMap   = { window: '2 seats', silent: '1 seat', group: '4 seats', power: '2 seats · Power outlet' };

  document.getElementById('summary-table-name').textContent = `Table ${state.selectedTable}`;
  document.getElementById('summary-table-meta').textContent = `${zoneLabels[state.selectedZone]} · ${seatsMap[state.selectedZone]}`;
  document.getElementById('booking-summary')?.classList.remove('hidden');

  showToast(`✅ Table ${state.selectedTable} selected!`);
}

function ripple(el) {
  const r = document.createElement('div');
  r.style.cssText = 'position:absolute;width:60px;height:60px;border-radius:50%;background:rgba(124,58,237,0.3);transform:scale(0);animation:rippleAnim 0.6s ease-out forwards;pointer-events:none;left:50%;top:50%;margin:-30px 0 0 -30px;z-index:10;';
  el.style.position = 'relative';
  el.appendChild(r);
  setTimeout(() => r.remove(), 700);
}
if (!document.getElementById('ripple-style')) {
  const s = document.createElement('style');
  s.id = 'ripple-style';
  s.textContent = '@keyframes rippleAnim{to{transform:scale(3);opacity:0;}}';
  document.head.appendChild(s);
}

/* ── Zone filter ──────────────────────────────────────────────── */
function filterZone() {
  const val = document.getElementById('zone-filter').value;
  document.querySelectorAll('.zone-section').forEach(section => {
    section.classList.toggle('hidden', val !== 'all' && section.dataset.zone !== val);
  });
}

/* ── Proceed to booking ─────────────────────────────────────── */
function proceedToAuth() {
  const date = document.getElementById('booking-date').value;
  const time = document.getElementById('booking-time').value;
  if (!state.selectedTable) { showToast('⚠️ Please select a table first!', 'warn'); return; }
  if (!date)  { showToast('⚠️ Please select a date', 'warn'); return; }
  if (!time)  { showToast('⚠️ Please select a time slot', 'warn'); return; }

  state.selectedDate = date;
  state.selectedTime = time;
  state.fromBooking  = true;

  if (state.isLoggedIn) {
    startPayment();
  } else {
    showPage('page-login');
    showToast('🔐 Sign in to complete your booking', 'info');
  }
}

/* ═══════════════════════════════════════════════════════════════
   AUTH
   ═══════════════════════════════════════════════════════════════ */
function handleLogin() {
  const email = document.getElementById('login-email').value.trim();
  const pass  = document.getElementById('login-password').value;
  if (!email || !pass) { showToast('⚠️ Fill in all fields', 'warn'); return; }
  if (!isValidEmail(email)) { showToast('⚠️ Enter a valid email', 'warn'); return; }

  const btn = document.querySelector('#page-login .btn-cta');
  setLoading(btn, true);
  showToast('🔄 Signing you in…', 'info');

  apiPost({ action: 'login', email, password: pass }).then(data => {
    setLoading(btn, false);
    if (!data.ok) { showToast('❌ ' + (data.message || 'Wrong credentials'), 'warn'); return; }
    onLoginSuccess(data.user);
  });
}

function handleRegister() {
  const terms = document.getElementById('terms-check').checked;
  if (!terms) { showToast('⚠️ Accept the Library Rules to continue', 'warn'); return; }

  const first    = document.getElementById('reg-first').value.trim();
  const last     = document.getElementById('reg-last').value.trim();
  const email    = document.getElementById('reg-email').value.trim();
  const phone    = document.getElementById('reg-phone').value.trim();
  const password = document.getElementById('reg-password').value;

  if (!first || !email || !password) { showToast('⚠️ Name, email and password are required', 'warn'); return; }
  if (password.length < 8) { showToast('⚠️ Password must be at least 8 characters', 'warn'); return; }

  const btn = document.querySelector('#page-register .btn-cta');
  setLoading(btn, true);
  showToast('🔄 Creating your account…', 'info');

  apiPost({ action: 'register', name: `${first} ${last}`.trim(), email, phone, password }).then(data => {
    setLoading(btn, false);
    if (!data.ok) { showToast('❌ ' + (data.message || 'Registration failed'), 'warn'); return; }
    onLoginSuccess(data.user);
  });
}

function onLoginSuccess(user) {
  state.isLoggedIn = true;
  state.user = user;
  localStorage.setItem('saraswati_user', JSON.stringify(user));
  updateNavbars();

  if (state.fromBooking && state.selectedTable) {
    startPayment();
  } else {
    showPage('page-booking');
    showToast(`👋 Welcome, ${user.name}!`);
  }
}

function handleLogout() {
  apiPost({ action: 'logout' });
  state.isLoggedIn = false;
  state.user = null;
  localStorage.removeItem('saraswati_user');
  updateNavbars();
  showToast('🔒 Signed out');
  showPage('page-landing');
  refreshTables();
}

/* ═══════════════════════════════════════════════════════════════
   NAVBARS
   ═══════════════════════════════════════════════════════════════ */
function updateNavbars() {
  const ids = ['nav-actions-landing', 'nav-actions-booking', 'nav-actions-confirm'];
  ids.forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    if (state.isLoggedIn) {
      const isAdmin = state.user?.role === 'admin';
      el.innerHTML = `
        ${isAdmin ? `<button class="btn-ghost small" onclick="showAdminPage()">⚙️ Admin</button>` : ''}
        <button class="btn-ghost small" onclick="showMyBookings()">📋 My Bookings</button>
        <span class="user-chip">👤 ${state.user?.name || 'Member'}</span>
        <button class="btn-ghost small" onclick="handleLogout()">Sign Out</button>
      `;
    } else {
      el.innerHTML = `
        <button class="btn-ghost" onclick="showAdminPage()">Admin</button>
        <button class="btn-ghost" onclick="showPage('page-login')">Sign In</button>
        <button class="btn-primary" onclick="showPage('page-register')">Register</button>
      `;
    }
  });

  // Admin nav
  const adminNav = document.getElementById('nav-actions-admin');
  if (adminNav) {
    adminNav.innerHTML = state.isLoggedIn ? `
      <button class="btn-ghost small" onclick="showPage('page-qrgen')">📲 QR Generator</button>
      <button class="btn-ghost small" onclick="showMyBookings()">📋 My Bookings</button>
      <span class="user-chip">⚙️ ${state.user?.name || 'Admin'}</span>
      <button class="btn-ghost small" onclick="handleLogout()">Sign Out</button>
    ` : `<button class="btn-ghost" onclick="showPage('page-login')">Sign In</button>`;
  }
}

/* ═══════════════════════════════════════════════════════════════
   MY BOOKINGS
   ═══════════════════════════════════════════════════════════════ */
function showMyBookings() {
  if (!state.isLoggedIn) {
    state.fromBooking = false;
    showPage('page-login');
    showToast('🔐 Sign in to view your bookings', 'info');
    return;
  }
  showPage('page-my-bookings');
  document.getElementById('mybookings-username').textContent =
    `Showing bookings for ${state.user?.name || 'you'}`;
  loadMyBookings();
}

async function loadMyBookings() {
  const list = document.getElementById('mybookings-list');
  if (!list) return;
  list.innerHTML = '<div class="mybookings-loading">⏳ Loading…</div>';

  const data = await apiGet({ action: 'my_bookings' });

  if (data.login_required) {
    list.innerHTML = '<div class="mybookings-empty">Please sign in to view bookings.</div>';
    return;
  }
  if (!data.ok) {
    list.innerHTML = `<div class="mybookings-empty">❌ ${data.message || 'Failed to load'}</div>`;
    return;
  }
  if (!data.bookings || data.bookings.length === 0) {
    list.innerHTML = `
      <div class="mybookings-empty">
        <div style="font-size:48px;margin-bottom:12px;">📭</div>
        <p>No bookings yet.</p>
        <button class="btn-cta" onclick="showPage('page-booking')" style="margin-top:16px;">Book a Table</button>
      </div>`;
    return;
  }

  const statusColors = {
    paid:                 'var(--emerald)',
    pending_verification: 'var(--amber)',
    pending:              'var(--accent-light)',
    failed:               'var(--rose)',
    expired:              'var(--text-dim)',
  };
  const statusLabels = {
    paid:                 '✅ Confirmed',
    pending_verification: '⏳ Verification Pending',
    pending:              '⌛ Awaiting Payment',
    failed:               '❌ Payment Failed',
    expired:              '🕛 Expired',
  };
  const zoneIcons = { window: '🪟', silent: '🤫', group: '👥', power: '⚡' };
  const appLabels = { gpay: 'Google Pay', phonepe: 'PhonePe', paytm: 'Paytm', bhim: 'BHIM', other: 'UPI' };

  list.innerHTML = data.bookings.map(b => {
    const pColor = statusColors[b.payment_status] || 'var(--text-muted)';
    const pLabel = statusLabels[b.payment_status] || b.payment_status;
    const icon   = zoneIcons[b.zone] || '🪑';
    const isPaid = b.payment_status === 'paid';
    const isPending = b.payment_status === 'pending_verification';

    return `
    <div class="booking-card ${b.payment_status}">
      <div class="bc-left">
        <div class="bc-table">${icon} Table ${b.table_id}</div>
        <div class="bc-zone" style="text-transform:capitalize;">${b.zone || ''} Zone · ${b.plan_name || 'Plan'}</div>
        <div class="bc-date">📅 ${b.booking_date} &nbsp;⏰ ${b.time_slot}</div>
        ${b.utr_number ? `<div class="bc-utr">UTR: <span class="mono">${b.utr_number}</span> via ${appLabels[b.upi_app] || 'UPI'}</div>` : ''}
        <div class="bc-ref mono" style="font-size:11px;color:var(--text-dim);">${b.order_ref || ''}</div>
      </div>
      <div class="bc-right">
        <div class="bc-amount">₹${Number(b.amount_inr).toLocaleString('en-IN')}</div>
        <div class="bc-status" style="color:${pColor}">${pLabel}</div>
        ${isPaid ? `<button class="btn-ghost small" onclick="showBookingTicket('${b.order_ref}', ${b.table_id}, '${b.booking_date}', '${b.time_slot}', ${b.amount_inr}, '${b.utr_number || ''}', '${appLabels[b.upi_app] || 'UPI'}')">View Ticket</button>` : ''}
        ${isPending ? `<div style="font-size:11px;color:var(--amber);margin-top:4px;">Admin is verifying your UTR</div>` : ''}
        ${b.payment_status === 'pending' ? `<button class="btn-cta small" onclick="resumePayment('${b.order_ref}')">Resume Payment</button>` : ''}
      </div>
    </div>`;
  }).join('');
}

/* Show booking ticket on confirm page */
function showBookingTicket(ref, tableId, date, time, amount, utr, app) {
  document.getElementById('confirm-ticket-id').textContent = '#' + ref;
  document.getElementById('confirm-table').textContent    = `Table ${tableId}`;
  document.getElementById('confirm-date').textContent     = date;
  document.getElementById('confirm-time').textContent     = time;
  document.getElementById('confirm-payment').textContent  = `₹${Number(amount).toLocaleString('en-IN')} · ${app}`;
  document.getElementById('confirm-student').textContent  = state.user?.name || 'Member';
  document.getElementById('confirm-barcode-text').textContent = ref;
  showPage('page-confirm');
}

/* Resume a pending payment */
async function resumePayment(orderRef) {
  const data = await apiGet({ action: 'payment_status', order_ref: orderRef });
  if (!data.ok) { showToast('❌ ' + data.message, 'warn'); return; }
  if (data.status === 'paid') { showToast('✅ This booking is already confirmed!'); return; }
  if (data.status === 'expired') { showToast('🕛 This session has expired. Please book again.', 'warn'); return; }

  // Re-open payment modal from existing session data
  state.payment.orderRef      = orderRef;
  state.payment.reservationId = data.reservation_id;
  state.payment.amount        = data.amount;
  state.payment.expiresAt     = new Date(data.expires_at + (data.expires_at.includes('+') ? '' : '+05:30')).getTime();
  state.payment.payeeName     = 'Saraswati Library';

  // We need to regenerate UPI link client-side since we don't store it
  const upiLink = `upi://pay?pa=pratikshingare2002%40okicici&pn=Saraswati+Library&am=${data.amount}.00&tn=Saraswati+Library+${orderRef}&cu=INR`;
  state.payment.upiLink = upiLink;

  openPaymentModal({
    order_ref:      orderRef,
    amount:         data.amount,
    payee_name:     'Saraswati Library',
    upi_link:       upiLink,
    expires_at:     data.expires_at,
    reservation_id: data.reservation_id,
  });
  showPage('page-booking');
}

/* ═══════════════════════════════════════════════════════════════
   UPI PAYMENT FLOW
   ═══════════════════════════════════════════════════════════════ */

/** Step 1: Initialise — call init_payment endpoint */
async function startPayment() {
  const planEl   = document.getElementById('plan-select');
  const planId   = planEl ? planEl.value : '1';

  showToast('⏳ Preparing secure payment…', 'info');

  const data = await apiPost({
    action:   'init_payment',
    user_id:  state.user?.id || 0,
    table_id: state.selectedTable,
    date:     state.selectedDate,
    slot:     state.selectedTime,
    plan_id:  planId,
  });

  if (!data.ok) {
    if (data.csrf_expired) {
      showToast('🔄 Session refreshed. Tap "Proceed to Book" again.', 'info');
      return;
    }
    showToast('❌ ' + (data.message || 'Could not create payment'), 'warn');
    return;
  }

  Object.assign(state.payment, {
    orderRef:      data.order_ref,
    reservationId: data.reservation_id,
    amount:        data.amount,
    upiLink:       data.upi_link,
    payeeName:     data.payee_name,
    expiresAt:     new Date(data.expires_at + '+05:30').getTime(),
    lastUtr: null, lastApp: null,
  });

  openPaymentModal(data);
}

/** Step 2: Open the bottom-sheet modal */
function openPaymentModal(data) {
  document.getElementById('upi-display-amount').textContent =
    '₹' + Number(data.amount).toLocaleString('en-IN');
  document.getElementById('upi-payee-name').textContent  = data.payee_name;
  document.getElementById('upi-order-ref').textContent   = data.order_ref;
  document.getElementById('upi-fail-title').textContent = 'Payment Failed';
  document.getElementById('upi-fail-msg').textContent   = 'We could not verify your payment. The booking slot has been released.';

  switchPaymentState('scan');

  // Reset UTR input
  const utrInput = document.getElementById('upi-utr-input');
  if (utrInput) { utrInput.value = ''; }
  const btn = document.getElementById('upi-submit-btn');
  if (btn)  { btn.disabled = true; btn.style.opacity = '0.5'; }

  const modal = document.getElementById('upi-modal');
  modal.classList.remove('hidden');
  document.body.style.overflow = 'hidden';

  // Delay open class by one frame for CSS transition to work
  requestAnimationFrame(() => requestAnimationFrame(() => modal.classList.add('open')));

  generatePaymentQR(data.upi_link);
  startPaymentTimer();

  clearInterval(state.payment.pollInterval);
  state.payment.pollInterval = setInterval(pollPaymentStatus, 12000);
}

/** Render QR code */
function generatePaymentQR(upiLink) {
  const canvas = document.getElementById('upi-qr-canvas');
  canvas.innerHTML = '';

  if (typeof QRCode === 'undefined') {
    canvas.innerHTML = `<div class="upi-qr-fallback"><p>QR unavailable. Use app buttons below.</p></div>`;
    return;
  }
  try {
    state.payment.qrInstance = new QRCode(canvas, {
      text: upiLink, width: 220, height: 220,
      colorDark: '#ffffff', colorLight: '#1c1c2e',
      correctLevel: QRCode.CorrectLevel.M,
    });
  } catch (_) {
    canvas.innerHTML = `<p style="color:var(--text-muted);font-size:13px">QR unavailable. Use app buttons.</p>`;
  }
}

/** Open specific UPI app via deep link */
function openUpiApp(app) {
  if (!state.payment.upiLink) return;
  const baseQuery = state.payment.upiLink.replace('upi://pay?', '');
  const links = {
    gpay:    'tez://upi/pay?' + baseQuery,
    phonepe: 'phonepe://pay?' + baseQuery,
    paytm:   'paytmmp://pay?' + baseQuery,
    bhim:    state.payment.upiLink,
  };
  const link = links[app] || state.payment.upiLink;
  const sel  = document.getElementById('upi-app-select');
  if (sel) sel.value = app;

  // Try open via hidden anchor (works on most mobile browsers)
  const a = document.createElement('a');
  a.href = link; a.target = '_self';
  document.body.appendChild(a);
  a.click();
  setTimeout(() => a.remove(), 1000);

  showToast(`📱 Opening ${appDisplayName(app)}…`, 'info');
}

function appDisplayName(app) {
  return { gpay: 'Google Pay', phonepe: 'PhonePe', paytm: 'Paytm', bhim: 'BHIM' }[app] || 'UPI App';
}

/** Countdown timer */
function startPaymentTimer() {
  clearInterval(state.payment.timerInterval);
  const bar  = document.getElementById('upi-timer-bar');
  const text = document.getElementById('upi-timer-text');

  state.payment.timerInterval = setInterval(() => {
    const remaining = Math.max(0, state.payment.expiresAt - Date.now());
    const pct = (remaining / (15 * 60 * 1000)) * 100;

    if (bar)  { bar.style.width = pct + '%'; bar.style.background = pct > 40 ? 'var(--emerald)' : pct > 15 ? 'var(--amber)' : 'var(--rose)'; }
    if (text) {
      const m = Math.floor(remaining / 60000);
      const s = Math.floor((remaining % 60000) / 1000);
      text.textContent = remaining > 0 ? `${m}:${String(s).padStart(2,'0')} remaining` : 'Session expired';
    }
    if (remaining === 0) {
      clearInterval(state.payment.timerInterval);
      clearInterval(state.payment.pollInterval);
      switchPaymentState('failed');
      document.getElementById('upi-fail-title').textContent = 'Session Expired';
      document.getElementById('upi-fail-msg').textContent   = 'Your 15-minute payment window expired. Please book again.';
    }
  }, 1000);
}

/** Poll payment status */
async function pollPaymentStatus() {
  if (!state.payment.orderRef) return;
  const data = await apiGet({ action: 'payment_status', order_ref: state.payment.orderRef });
  if (!data.ok) return;
  if (data.status === 'paid') {
    clearInterval(state.payment.pollInterval);
    clearInterval(state.payment.timerInterval);
    showPaymentSuccess(data);
  } else if (data.status === 'failed' || data.status === 'expired') {
    clearInterval(state.payment.pollInterval);
    clearInterval(state.payment.timerInterval);
    showPaymentFailed(data.status);
  }
}

function pollNow() {
  pollPaymentStatus();
  showToast('🔄 Checking status…', 'info');
}

/** UTR input: enable Submit when format is valid */
function onUtrInput(input) {
  const val   = input.value.replace(/\s/g, '').toUpperCase();
  input.value = val;
  const valid = /^[A-Z0-9]{12,22}$/.test(val);
  const btn   = document.getElementById('upi-submit-btn');
  if (btn) { btn.disabled = !valid; btn.style.opacity = valid ? '1' : '0.5'; }
}

/** Step 3: Submit UTR */
async function submitUtr() {
  const utr = document.getElementById('upi-utr-input').value.replace(/\s/g, '').toUpperCase();
  const app = document.getElementById('upi-app-select').value;

  if (utr.length < 12) { showToast('⚠️ Enter a valid UTR (12–22 characters)', 'warn'); return; }
  if (!state.payment.orderRef) { showToast('⚠️ No active session. Please restart booking.', 'warn'); return; }
  if (state.payment.expiresAt && Date.now() > state.payment.expiresAt) {
    switchPaymentState('failed'); return;
  }

  state.payment.lastUtr = utr;
  state.payment.lastApp = app;
  switchPaymentState('submitting');

  const data = await apiPost({ action: 'submit_utr', order_ref: state.payment.orderRef, utr, upi_app: app });

  if (!data.ok) {
    switchPaymentState('scan');
    showToast('❌ ' + (data.message || 'Submission failed'), 'warn');
    return;
  }

  clearInterval(state.payment.timerInterval);
  switchPaymentState('pending');

  // Fill pending details card
  const card = document.getElementById('upi-pending-details');
  if (card) {
    card.innerHTML = `
      <div class="upi-pending-row"><span>Order Ref</span><span class="mono">${state.payment.orderRef}</span></div>
      <div class="upi-pending-row"><span>Amount</span><span class="green">₹${Number(state.payment.amount).toLocaleString('en-IN')}</span></div>
      <div class="upi-pending-row"><span>UTR Number</span>
        <span class="mono" style="display:flex;gap:6px;align-items:center;">
          ${utr}
          <button onclick="copyText('${utr}')" class="copy-btn" title="Copy">📋</button>
        </span>
      </div>
      <div class="upi-pending-row"><span>UPI App</span><span>${appDisplayName(app)}</span></div>
      <div class="upi-pending-row"><span>Submitted</span><span>${new Date().toLocaleTimeString('en-IN')}</span></div>`;
  }

  clearInterval(state.payment.pollInterval);
  state.payment.pollInterval = setInterval(pollPaymentStatus, 15000);
}

/** Show success receipt */
function showPaymentSuccess(data) {
  switchPaymentState('success');
  const fmt = n => '₹' + Number(n).toLocaleString('en-IN');
  const appNames = { gpay: 'Google Pay', phonepe: 'PhonePe', paytm: 'Paytm', bhim: 'BHIM', other: 'UPI' };

  document.getElementById('upi-receipt-ref').textContent      = data.order_ref || state.payment.orderRef;
  document.getElementById('upi-receipt-amount').textContent   = fmt(data.amount || state.payment.amount);
  document.getElementById('upi-receipt-table').textContent    = `Table ${data.table_id}`;
  document.getElementById('upi-receipt-datetime').textContent = `${data.booking_date} · ${data.time_slot}`;
  document.getElementById('upi-receipt-utr').textContent      = data.utr_number || state.payment.lastUtr || '—';
  document.getElementById('upi-receipt-app').textContent      = appNames[data.upi_app || state.payment.lastApp] || 'UPI';
  document.getElementById('upi-receipt-payee').textContent    = state.payment.payeeName;
  document.getElementById('upi-receipt-time').textContent     = new Date().toLocaleString('en-IN', { dateStyle: 'medium', timeStyle: 'short' });

  // Mark table as booked in the map
  const tableEl = document.querySelector(`[data-table="${state.selectedTable}"]`);
  if (tableEl) {
    tableEl.classList.remove('available', 'selected');
    tableEl.classList.add('booked');
    tableEl.removeAttribute('onclick');
    const tag = tableEl.querySelector('.table-tag');
    if (tag) tag.textContent = 'Booked';
  }

  state.selectedTable = null;
  state.fromBooking   = false;
  document.getElementById('booking-summary')?.classList.add('hidden');
}

function showPaymentFailed(reason) {
  switchPaymentState('failed');
  document.getElementById('upi-fail-title').textContent = reason === 'expired' ? 'Session Expired' : 'Payment Not Verified';
  document.getElementById('upi-fail-msg').textContent   = reason === 'expired'
    ? 'Your 15-minute window expired. Please book again.'
    : 'We could not verify your payment. The slot has been released.';
}

function cancelPayment() {
  if (!confirm('Cancel this payment? Your table reservation will be released.')) return;
  stopPaymentSession();
  closePaymentModal();
  showToast('Booking cancelled.', 'info');
}

function retryPayment() {
  stopPaymentSession();
  closePaymentModal();
  showToast('Select your table again to retry.', 'info');
}

function stopPaymentSession() {
  clearInterval(state.payment.timerInterval);
  clearInterval(state.payment.pollInterval);
  state.payment.orderRef = null;
}

function closePaymentModal() {
  const modal = document.getElementById('upi-modal');
  if (!modal) return;
  modal.classList.remove('open');
  setTimeout(() => {
    modal.classList.add('hidden');
    document.body.style.overflow = '';
  }, 380);
  stopPaymentSession();
}

function switchPaymentState(id) {
  ['scan','submitting','pending','success','failed'].forEach(s => {
    const section = document.getElementById(`upi-state-${s}`);
    if (!section) return;
    const shouldHide = s !== id;
    section.classList.toggle('hidden', shouldHide);
    section.setAttribute('aria-hidden', String(shouldHide));
  });
}

/* ═══════════════════════════════════════════════════════════════
   QR CODE GENERATOR (standalone admin tool)
   ═══════════════════════════════════════════════════════════════ */
let _qrgenInstance = null;

function generateQRCode() {
  const amount = parseInt(document.getElementById('qrgen-amount')?.value || '0', 10);
  const desc   = (document.getElementById('qrgen-desc')?.value || '').trim().substring(0, 60);

  if (!amount || amount < 1 || amount > 100000) {
    showToast('⚠️ Enter a valid amount (₹1 – ₹1,00,000)', 'warn');
    return;
  }

  const ref     = 'QR' + Date.now().toString(36).toUpperCase();
  const upiLink = `upi://pay?pa=pratikshingare2002%40okicici&pn=Saraswati+Library&am=${amount}.00&tn=${encodeURIComponent('Saraswati Library ' + (desc || ref))}&cu=INR`;
  const canvas  = document.getElementById('qrgen-canvas');
  canvas.innerHTML = '';

  if (typeof QRCode === 'undefined') {
    showToast('❌ QR library not loaded. Check internet connection.', 'warn');
    return;
  }
  try {
    if (_qrgenInstance) { try { _qrgenInstance.clear(); } catch (_) {} }
    _qrgenInstance = new QRCode(canvas, {
      text: upiLink, width: 240, height: 240,
      colorDark: '#1a0533', colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M,
    });
  } catch (e) { showToast('❌ QR generation failed', 'warn'); return; }

  document.getElementById('qrgen-amount-display').textContent = '₹' + amount.toLocaleString('en-IN');
  document.getElementById('qrgen-upi-display').textContent    = 'pratikshingare2002@okicici';
  document.getElementById('qrgen-output')?.classList.remove('hidden');
  showToast('✅ QR code generated!');
}

function downloadQR() {
  const canvas = document.querySelector('#qrgen-canvas canvas');
  if (!canvas) { showToast('⚠️ Generate a QR first', 'warn'); return; }
  const a    = document.createElement('a');
  a.download = 'saraswati-library-payment-qr.png';
  a.href     = canvas.toDataURL('image/png');
  a.click();
}

/* ═══════════════════════════════════════════════════════════════
   TABLE REFRESH FROM API
   ═══════════════════════════════════════════════════════════════ */
async function refreshTables() {
  const date = document.getElementById('booking-date')?.value || '';
  const slot = document.getElementById('booking-time')?.value || '';
  const data = await apiGet({ action: 'tables', date, slot });
  if (!data.ok || !data.tables) return;

  data.tables.forEach(t => {
    const el = document.querySelector(`[data-table="${t.id}"]`);
    if (!el) return;
    const liveStatus = t.live_status || t.status;
    el.classList.remove('available', 'booked', 'maintenance', 'selected');
    el.classList.add(liveStatus);
    el.setAttribute('onclick', liveStatus === 'available' ? 'selectTable(this)' : '');
    if (liveStatus !== 'available') el.removeAttribute('onclick');
    const tag = el.querySelector('.table-tag');
    if (tag) {
      tag.textContent = liveStatus === 'available'
        ? `${t.seats} seat${t.seats > 1 ? 's' : ''}${t.zone === 'power' ? ' ⚡' : ''}`
        : liveStatus === 'maintenance' ? '🔧 Maint.' : 'Booked';
    }
  });
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN PANEL
   ═══════════════════════════════════════════════════════════════ */
function showAdminPage() {
  if (!state.isLoggedIn) {
    showPage('page-login');
    showToast('🔐 Admin login required', 'warn');
    return;
  }
  if (state.user?.role !== 'admin') {
    showToast('⚠️ Admin access required', 'warn');
    return;
  }
  showPage('page-admin');
  renderAdminPanel();
}

async function renderAdminPanel() {
  const data = await apiGet({ action: 'admin_data' });
  if (!data.ok) {
    if (data.message?.includes('Admin')) {
      showToast('⚠️ Session expired. Please log in again.', 'warn');
      showPage('page-login');
    } else {
      showToast('❌ Failed to load admin data', 'warn');
    }
    return;
  }

  const { tables, reservations, pending_payments } = data;

  // Tables grid
  const grid = document.getElementById('admin-tables-grid');
  if (grid) {
    grid.innerHTML = tables.map(t => `
      <div class="admin-card">
        <b>Table ${t.id} · ${t.zone?.toUpperCase()}</b>
        <small>${t.features}</small>
        <select onchange="updateTableStatus(${t.id}, this.value)" class="dt-input">
          <option value="available"   ${t.status === 'available'   ? 'selected' : ''}>available</option>
          <option value="booked"      ${t.status === 'booked'      ? 'selected' : ''}>booked</option>
          <option value="maintenance" ${t.status === 'maintenance' ? 'selected' : ''}>maintenance</option>
        </select>
      </div>`).join('');
  }

  // Reservations list
  const list = document.getElementById('admin-reservations-list');
  if (!list) return;

  let html = '';

  // Pending payments first
  if (pending_payments?.length) {
    html += `
    <div style="background:rgba(245,158,11,0.08);border:1px solid rgba(245,158,11,0.3);border-radius:12px;padding:16px 20px;margin-bottom:24px;">
      <h3 style="color:var(--amber);margin-bottom:12px;">⚠️ Pending Verification (${pending_payments.length})</h3>
      ${pending_payments.map(p => `
      <div class="admin-row" style="padding:14px 0;border-bottom:1px solid var(--border);">
        <div style="flex:1;">
          <b>Ref: ${p.order_ref} · Table ${p.table_id}</b><br>
          <span style="color:var(--text-muted);font-size:13px;">${p.booking_date} · ${p.time_slot} · ₹${Number(p.amount_inr).toLocaleString('en-IN')}</span><br>
          <small style="color:var(--text-dim);">By: ${p.full_name || 'Guest'} (${p.phone || 'N/A'})</small><br>
          <span style="color:var(--emerald);font-weight:700;font-size:13px;">UTR: ${p.utr_number || 'Not submitted'} · ${p.upi_app || '?'}</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <button onclick="adminVerifyPayment('${p.order_ref}','approve')" class="btn-cta" style="padding:8px 16px;font-size:13px;">✓ Approve</button>
          <button onclick="adminVerifyPayment('${p.order_ref}','reject')"  class="btn-ghost small" style="border-color:var(--rose);color:var(--rose);">✗ Reject</button>
        </div>
      </div>`).join('')}
    </div>`;
  }

  // All reservations table
  if (!reservations?.length) {
    html += '<p style="color:var(--text-dim);text-align:center;padding:20px;">No reservations yet.</p>';
  } else {
    const sColors = { paid:'var(--emerald)', pending_verification:'var(--amber)', pending:'var(--accent-light)', failed:'var(--rose)', expired:'var(--text-dim)' };
    html += reservations.map(r => `
      <div class="admin-row" style="padding:12px 20px;margin-bottom:8px;font-size:13px;">
        <div>
          <b>#${r.id} · Table ${r.table_id} · ${r.order_ref || '—'}</b><br>
          <span style="color:var(--text-muted);">${r.booking_date} · ${r.time_slot} · ₹${Number(r.amount_inr).toLocaleString('en-IN')}</span><br>
          <small style="color:var(--text-dim);">${r.full_name || 'Guest'} · ${r.phone || 'N/A'} · ${r.email || ''}</small>
          ${r.utr_number ? `<br><small style="color:var(--accent-light);">UTR: ${r.utr_number} via ${r.upi_app || '?'}</small>` : ''}
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
          <span style="color:${sColors[r.payment_status]||'var(--text-muted)'};font-weight:700;font-size:12px;">${(r.payment_status||'').toUpperCase()}</span>
          ${r.reservation_status === 'reserved' ? `<button onclick="cancelReservation(${r.id})" class="btn-ghost small" style="border-color:var(--rose);color:var(--rose);">Free Table</button>` : `<span style="color:var(--text-dim);font-style:italic;">${r.reservation_status}</span>`}
        </div>
      </div>`).join('');
  }

  list.innerHTML = html;
}

async function adminVerifyPayment(orderRef, decision) {
  const notes = decision === 'reject' ? (prompt('Rejection reason (optional):') || '') : '';
  const data  = await apiPost({ action: 'admin_verify_payment', order_ref: orderRef, decision, notes });
  if (data.ok) { showToast(data.message, 'success'); renderAdminPanel(); refreshTables(); }
  else         { showToast(data.message || 'Action failed', 'warn'); }
}

async function updateTableStatus(id, status) {
  const data = await apiPost({ action: 'admin_table', table_id: id, status });
  if (data.ok) { showToast(`Table ${id} → ${status}`); refreshTables(); }
  else         { showToast(data.message || 'Failed', 'warn'); }
}

async function cancelReservation(id) {
  const data = await apiPost({ action: 'admin_cancel', reservation_id: id });
  if (data.ok) { showToast(`Reservation #${id} cancelled`); renderAdminPanel(); refreshTables(); }
  else         { showToast(data.message || 'Failed', 'warn'); }
}

/* ═══════════════════════════════════════════════════════════════
   UTILITIES
   ═══════════════════════════════════════════════════════════════ */
function isValidEmail(e) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(e); }

function copyText(text) {
  navigator.clipboard?.writeText(text).then(() => showToast('📋 Copied to clipboard!')).catch(() => {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); ta.remove();
    showToast('📋 Copied!');
  });
}

function setLoading(btn, loading) {
  if (!btn) return;
  if (loading) {
    btn.dataset.originalText = btn.innerHTML;
    btn.innerHTML = '<span>Loading…</span>';
    btn.disabled  = true;
  } else {
    btn.innerHTML = btn.dataset.originalText || btn.innerHTML;
    btn.disabled  = false;
  }
}

/* ── Toast ───────────────────────────────────────────────────── */
function showToast(msg, type = 'success') {
  const toast = document.getElementById('toast');
  if (!toast) return;
  const bg = { success: 'rgba(16,185,129,0.15)', warn: 'rgba(245,158,11,0.15)', info: 'rgba(124,58,237,0.15)' };
  const bc = { success: 'rgba(16,185,129,0.3)', warn: 'rgba(245,158,11,0.3)', info: 'var(--border-strong)' };
  toast.style.background  = `linear-gradient(135deg, var(--surface2), ${bg[type] || bg.success})`;
  toast.style.borderColor = bc[type] || bc.success;
  toast.textContent = msg;
  toast.classList.remove('hidden');
  toast.offsetHeight;
  toast.classList.add('show');
  clearTimeout(toast._t);
  toast._t = setTimeout(() => { toast.classList.remove('show'); setTimeout(() => toast.classList.add('hidden'), 400); }, 3200);
}

/* ═══════════════════════════════════════════════════════════════
   DOMContentLoaded INIT
   ═══════════════════════════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', async () => {
  // 1. Fetch CSRF token (starts PHP session)
  await fetchCsrfToken();

  // 2. Restore session if user is stored in localStorage
  if (state.user) {
    await restoreSession();
  } else {
    state.isLoggedIn = false;
    updateNavbars();
  }

  // 3. Date / time inputs
  const today     = new Date().toISOString().split('T')[0];
  const dateInput = document.getElementById('booking-date');
  const timeInput = document.getElementById('booking-time');

  if (dateInput) {
    dateInput.min   = today;
    dateInput.value = today;
    state.selectedDate = today;
    dateInput.addEventListener('change', e => { state.selectedDate = e.target.value; refreshTables(); });
  }
  if (timeInput) {
    timeInput.addEventListener('change', e => { state.selectedTime = e.target.value; if (e.target.value) refreshTables(); });
  }

  // 4. Load live table statuses
  refreshTables();

  // 5. 3D tilt effect on zone cards
  document.querySelectorAll('.zone-card').forEach(card => {
    card.addEventListener('mousemove', e => {
      const rect = card.getBoundingClientRect();
      const x = (e.clientX - rect.left) / rect.width  - 0.5;
      const y = (e.clientY - rect.top)  / rect.height - 0.5;
      card.style.transform = `translateY(-8px) rotateX(${-y * 14}deg) rotateY(${x * 14}deg)`;
    });
    card.addEventListener('mouseleave', () => { card.style.transform = ''; });
    card.addEventListener('click', () => {
      showPage('page-booking');
      const zoneText = card.querySelector('.zone-name')?.textContent.toLowerCase();
      setTimeout(() => {
        const zf = document.getElementById('zone-filter');
        const zone = ['window','silent','group','power'].find(z => zoneText?.includes(z)) || 'all';
        if (zf) { zf.value = zone; filterZone(); }
      }, 300);
    });
  });

  // 6. Staggered table animation
  document.querySelectorAll('.table-unit').forEach((t, i) => {
    t.style.animation = `cardIn 0.5s ease ${i * 0.03}s both`;
  });

  // 7. Password strength meter
  const pwdInput = document.getElementById('reg-password');
  if (pwdInput) {
    pwdInput.addEventListener('input', () => {
      const v = pwdInput.value;
      let s = 0;
      if (v.length >= 8) s++;
      if (/[A-Z]/.test(v)) s++;
      if (/[0-9]/.test(v)) s++;
      if (/[^A-Za-z0-9]/.test(v)) s++;
      const m = [
        { w: '0%', c: '#f43f5e', t: 'Enter a password' },
        { w: '25%', c: '#f43f5e', t: 'Too weak' },
        { w: '50%', c: '#f59e0b', t: 'Fair' },
        { w: '75%', c: '#60a5fa', t: 'Good' },
        { w: '100%', c: '#10b981', t: 'Strong ✓' },
      ][s];
      const fill  = document.querySelector('.strength-fill');
      const label = document.querySelector('.strength-label');
      if (fill)  { fill.style.width = m.w; fill.style.background = m.c; }
      if (label) { label.textContent = m.t; label.style.color = m.c; }
    });
  }

  // 8. Login on Enter key
  document.getElementById('login-password')?.addEventListener('keypress', e => {
    if (e.key === 'Enter') handleLogin();
  });
  document.getElementById('login-email')?.addEventListener('keypress', e => {
    if (e.key === 'Enter') handleLogin();
  });

  // 9. Escape to close modal
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      const modal = document.getElementById('upi-modal');
      if (modal && !modal.classList.contains('hidden')) {
        const isSubmitting = !document.getElementById('upi-state-submitting')?.classList.contains('hidden');
        if (!isSubmitting) cancelPayment();
      }
    }
  });

  // 10. Show landing page
  showPage('page-landing');
});
