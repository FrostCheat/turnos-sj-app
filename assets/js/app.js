const API = '/api/index.php';
const STATE = { user: null, csrf: null, sseSource: null, sseRetries: 0 };

async function api(action, opts = {}) {
  const { method = 'GET', body, params = {} } = opts;
  const url = new URL(API, location.origin);
  url.searchParams.set('action', action);
  Object.entries(params).forEach(([k, v]) => v !== undefined && v !== '' && url.searchParams.set(k, v));
  const headers = { 'Content-Type': 'application/json' };
  if (STATE.csrf && method !== 'GET') headers['X-CSRF-Token'] = STATE.csrf;
  try {
    const res = await fetch(url, { method, headers, credentials: 'include', body: body ? JSON.stringify(body) : undefined });
    return await res.json();
  } catch (e) {
    return { success: false, message: 'Error de conexión. Verifica tu internet.' };
  }
}

async function loadCsrf() {
  const res = await api('csrf_token');
  if (res.success) STATE.csrf = res.token;
}

/* ===== TOAST ===== */
function toast(msg, type = 'info', duration = 4000) {
  const icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
  const c = document.getElementById('toast-container') || (() => {
    const el = document.createElement('div');
    el.id = 'toast-container';
    document.body.appendChild(el);
    return el;
  })();
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.innerHTML = `<span class="toast-icon">${icons[type]}</span><span class="toast-msg">${escHtml(msg)}</span>`;
  c.appendChild(t);
  const remove = () => { t.classList.add('removing'); setTimeout(() => t.remove(), 300); };
  const timer = setTimeout(remove, duration);
  t.addEventListener('click', () => { clearTimeout(timer); remove(); });
  return t;
}

/* ===== CONFIRM ===== */
function confirm(title, msg, okLabel = 'Confirmar', danger = false) {
  return new Promise(resolve => {
    const el = document.createElement('div');
    el.className = 'confirm-overlay';
    el.innerHTML = `<div class="confirm-box">
      <div class="confirm-icon">${danger ? '⚠️' : '❓'}</div>
      <div class="confirm-title">${escHtml(title)}</div>
      <div class="confirm-msg">${escHtml(msg)}</div>
      <div class="confirm-actions">
        <button class="btn btn-secondary" id="cf-no">Cancelar</button>
        <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" id="cf-yes">${escHtml(okLabel)}</button>
      </div>
    </div>`;
    document.body.appendChild(el);
    el.querySelector('#cf-yes').addEventListener('click', () => { el.remove(); resolve(true); });
    el.querySelector('#cf-no').addEventListener('click', () => { el.remove(); resolve(false); });
    el.addEventListener('click', e => { if (e.target === el) { el.remove(); resolve(false); } });
  });
}

/* ===== MODAL ===== */
function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
  el.querySelectorAll('[autofocus]').forEach(f => setTimeout(() => f.focus(), 100));
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('open');
  document.body.style.overflow = '';
}

function closeAllModals() {
  document.querySelectorAll('.modal-backdrop.open').forEach(m => {
    m.classList.remove('open');
    document.body.style.overflow = '';
  });
}

/* ===== ESC KEY / BACKDROP CLOSE ===== */
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllModals(); });
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-backdrop')) closeAllModals();
  if (e.target.classList.contains('modal-close')) closeAllModals();
});

/* ===== UTILS ===== */
function escHtml(str) {
  return String(str ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function formatDate(str) {
  if (!str) return '—';
  return new Date(str).toLocaleString('es-CO', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' });
}

function formatDateShort(str) {
  if (!str) return '—';
  return new Date(str).toLocaleString('es-CO', { day:'2-digit', month:'short', hour:'2-digit', minute:'2-digit' });
}

function statusBadge(s) {
  const labels = { waiting:'En espera', active:'Activo', completed:'Completado', cancelled:'Cancelado' };
  return `<span class="badge badge-${s}">${labels[s] ?? s}</span>`;
}

function roleBadge(r) {
  return `<span class="badge badge-${r}">${r === 'admin' ? 'Administrador' : 'Usuario'}</span>`;
}

function setLoading(btn, state) {
  if (state) { btn._txt = btn.innerHTML; btn.classList.add('btn-loading'); btn.disabled = true; btn.innerHTML = btn._txt; }
  else { btn.classList.remove('btn-loading'); btn.disabled = false; if (btn._txt) btn.innerHTML = btn._txt; }
}

function showFieldError(name, msg) {
  const input = document.querySelector(`[name="${name}"]`);
  const errEl = document.getElementById(`err-${name}`);
  if (input) input.classList.add('error');
  if (errEl) { errEl.textContent = msg; errEl.classList.add('visible'); }
}

function clearFieldErrors(form) {
  form.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));
  form.querySelectorAll('.form-error').forEach(el => { el.textContent = ''; el.classList.remove('visible'); });
}

/* ===== SESSION CHECK ===== */
async function checkSession() {
  const res = await api('get_session');
  if (res.success && res.authenticated) {
    STATE.user = res.user;
    return res.user;
  }
  return null;
}

/* ===== BROWSER NOTIFICATIONS ===== */
async function requestNotifPermission() {
  if (!('Notification' in window)) return false;
  if (Notification.permission === 'granted') return true;
  const perm = await Notification.requestPermission();
  return perm === 'granted';
}

function sendNotification(title, body, opts = {}) {
  if (Notification.permission !== 'granted') return;
  const n = new Notification(title, { body, icon: '/assets/imgs/icon.png', badge: '/assets/imgs/fav_icon.png', ...opts });
  setTimeout(() => n.close(), 8000);
}

/* ===== SSE ===== */
function connectSSE(onMessage) {
  if (STATE.sseSource) { STATE.sseSource.close(); STATE.sseSource = null; }
  const es = new EventSource(`${API}?action=sse_queue`, { withCredentials: true });
  STATE.sseSource = es;

  es.onmessage = e => {
    try {
      const data = JSON.parse(e.data);
      if (data.error) return;
      STATE.sseRetries = 0;
      onMessage(data);
    } catch {}
  };

  es.onerror = () => {
    es.close();
    STATE.sseSource = null;
    STATE.sseRetries++;
    const delay = Math.min(30000, 2000 * STATE.sseRetries);
    setTimeout(() => connectSSE(onMessage), delay);
    updateConnStatus(false);
  };

  es.onopen = () => {
    STATE.sseRetries = 0;
    updateConnStatus(true);
  };
}

function updateConnStatus(ok) {
  const el = document.getElementById('conn-status');
  if (!el) return;
  el.className = `conn-badge ${ok ? 'connected' : 'disconnected'}`;
  el.textContent = ok ? '● En línea' : '● Desconectado';
}

/* ===== PAGES ===== */
const PAGE = document.body.dataset.page;

if (PAGE === 'landing') initLanding();
if (PAGE === 'auth') initAuth();
if (PAGE === 'home') initHome();
if (PAGE === 'admin') initAdmin();

/* ===== LANDING ===== */
function initLanding() {
  document.querySelectorAll('a[href^="#"]').forEach(a => {
    a.addEventListener('click', e => {
      e.preventDefault();
      document.querySelector(a.getAttribute('href'))?.scrollIntoView({ behavior:'smooth' });
    });
  });
  const nav = document.querySelector('.navbar');
  window.addEventListener('scroll', () => nav?.classList.toggle('scrolled', window.scrollY > 60));
}

/* ===== AUTH ===== */
function initAuth() {
  loadCsrf();

  const tabs    = document.querySelectorAll('.auth-tab');
  const panels  = document.querySelectorAll('.auth-panel');
  const hash    = location.hash;

  function switchTab(tab) {
    tabs.forEach(t => t.classList.remove('active'));
    panels.forEach(p => p.classList.remove('active'));
    const t = document.querySelector(`.auth-tab[data-tab="${tab}"]`);
    const p = document.getElementById(`panel-${tab}`);
    if (t) t.classList.add('active');
    if (p) p.classList.add('active');
  }

  switchTab(hash === '#register' ? 'register' : 'login');
  tabs.forEach(t => t.addEventListener('click', () => switchTab(t.dataset.tab)));

  const loginForm = document.getElementById('login-form');
  if (loginForm) {
    loginForm.addEventListener('submit', async e => {
      e.preventDefault();
      clearFieldErrors(loginForm);
      const btn = loginForm.querySelector('[type="submit"]');
      setLoading(btn, true);
      const res = await api('login', {
        method: 'POST',
        body: { email: loginForm.querySelector('[name="email"]').value, password: loginForm.querySelector('[name="password"]').value },
      });
      setLoading(btn, false);
      if (res.success) {
        STATE.user = res.user;
        toast(res.message, 'success');
        setTimeout(() => location.href = res.user.role === 'admin' ? '/admin/' : '/home/', 600);
      } else {
        toast(res.message, 'error');
      }
    });
  }

  const regForm = document.getElementById('register-form');
  if (regForm) {
    regForm.addEventListener('submit', async e => {
      e.preventDefault();
      clearFieldErrors(regForm);
      const btn = regForm.querySelector('[type="submit"]');
      const pass  = regForm.querySelector('[name="password"]').value;
      const pass2 = regForm.querySelector('[name="password2"]').value;
      if (pass !== pass2) { showFieldError('password2', 'Las contraseñas no coinciden.'); return; }
      setLoading(btn, true);
      const res = await api('register', {
        method: 'POST',
        body: {
          name: regForm.querySelector('[name="name"]').value,
          email: regForm.querySelector('[name="email"]').value,
          phone: regForm.querySelector('[name="phone"]')?.value,
          password: pass,
        },
      });
      setLoading(btn, false);
      if (res.success) {
        STATE.user = res.user;
        toast(res.message, 'success');
        setTimeout(() => location.href = '/home/', 600);
      } else {
        toast(res.message, 'error');
      }
    });
  }
}

/* ===== HOME ===== */
async function initHome() {
  const user = await checkSession();
  if (!user) { location.href = '/auth/'; return; }

  document.getElementById('user-name').textContent = user.name;
  document.getElementById('user-initial').textContent = user.name[0].toUpperCase();
  await loadCsrf();

  const notifBanner = document.getElementById('notif-banner');
  if (Notification.permission === 'default' && notifBanner) {
    notifBanner.classList.remove('hidden');
    document.getElementById('enable-notif')?.addEventListener('click', async () => {
      const ok = await requestNotifPermission();
      if (ok) { notifBanner.classList.add('hidden'); toast('Notificaciones activadas.', 'success'); }
      else toast('Permiso denegado.', 'warning');
    });
  } else if (notifBanner) {
    notifBanner.classList.add('hidden');
  }

  document.getElementById('logout-btn')?.addEventListener('click', async () => {
    await api('logout', { method: 'POST' });
    location.href = '/auth/';
  });

  document.getElementById('request-turn-btn')?.addEventListener('click', () => openModal('modal-turn'));
  document.getElementById('cancel-turn-btn')?.addEventListener('click', cancelMyTurn);

  const services = (await api('get_services')).services ?? [];
  const svcSel = document.getElementById('turn-service');
  if (svcSel) services.forEach(s => { const o = document.createElement('option'); o.value = o.textContent = s; svcSel.appendChild(o); });

  document.getElementById('turn-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = e.target.querySelector('[type="submit"]');
    setLoading(btn, true);
    const res = await api('create_turn', {
      method: 'POST',
      body: { service: document.getElementById('turn-service')?.value, notes: document.getElementById('turn-notes')?.value },
    });
    setLoading(btn, false);
    if (res.success) {
      toast(`Turno #${res.turn_number} asignado. Posición: ${res.position}`, 'success');
      closeModal('modal-turn');
      renderQueueData(await api('get_queue'));
    } else {
      toast(res.message, 'error');
    }
  });

  let prevMyTurnStatus = null;
  let prevCurrentTurn  = null;

  function handleSSEData(data) {
    renderQueueData(data);

    if (data.my_turn) {
      const t = data.my_turn;
      if (prevMyTurnStatus !== t.status) {
        if (t.status === 'active') {
          toast('¡Es tu turno! Pasa a la ventanilla.', 'success', 8000);
          sendNotification('¡Tu turno!', 'Es tu turno. Por favor acércate a la ventanilla.', { requireInteraction: true });
        }
        if (t.status === 'cancelled') {
          toast('Tu turno fue cancelado.', 'warning');
        }
        prevMyTurnStatus = t.status;
      }
      if (t.status === 'waiting' && t.position <= 2 && prevCurrentTurn !== data.current_turn) {
        toast('¡Prepárate! Eres el próximo en ser atendido.', 'info', 6000);
        sendNotification('¡Prepárate!', `Eres el número ${t.position} en la cola.`);
      }
    }

    prevCurrentTurn = data.current_turn;
  }

  renderQueueData(await api('get_queue'));
  connectSSE(handleSSEData);
}

function renderQueueData(data) {
  if (!data.success) return;

  const ct = document.getElementById('current-turn-num');
  if (ct) ct.textContent = data.current_turn || '—';

  const svcEl = document.getElementById('active-service');
  if (svcEl) svcEl.textContent = data.active_turn ? data.active_turn.service : 'Sin turno activo';

  const wc = document.getElementById('waiting-count');
  if (wc) wc.textContent = data.waiting_count ?? 0;

  if (data.my_turn) {
    const mt = data.my_turn;
    document.getElementById('my-turn-section')?.style.setProperty('display','block');
    document.getElementById('no-turn-section')?.style.setProperty('display','none');
    document.getElementById('my-turn-number')?.childNodes.forEach(n => { if (n.nodeType === 3) n.nodeValue = ''; });
    const mtn = document.getElementById('my-turn-number');
    if (mtn) mtn.textContent = `#${mt.turn_number}`;
    const mts = document.getElementById('my-turn-status');
    if (mts) mts.innerHTML = statusBadge(mt.status);
    const mtsvc = document.getElementById('my-turn-service');
    if (mtsvc) mtsvc.textContent = mt.service;
    const mtp = document.getElementById('my-turn-position');
    if (mtp) mtp.textContent = mt.status === 'active' ? '¡Es tu turno!' : mt.position ? `Posición ${mt.position}` : '—';

    const prog = document.getElementById('queue-progress');
    if (prog && mt.status === 'waiting' && mt.position) {
      const total = (data.waiting_count || 1);
      const pct = Math.max(5, Math.min(95, Math.round((1 - (mt.position - 1) / total) * 100)));
      prog.style.width = pct + '%';
    }

    const cancelBtn = document.getElementById('cancel-turn-btn');
    if (cancelBtn) cancelBtn.dataset.id = mt.id;
    const reqBtn = document.getElementById('request-turn-btn');
    if (reqBtn) reqBtn.style.display = 'none';
  } else {
    document.getElementById('my-turn-section')?.style.setProperty('display','none');
    document.getElementById('no-turn-section')?.style.setProperty('display','block');
    const reqBtn = document.getElementById('request-turn-btn');
    if (reqBtn) reqBtn.style.display = '';
  }
}

async function cancelMyTurn() {
  const btn = document.getElementById('cancel-turn-btn');
  const id  = btn?.dataset.id;
  if (!id) return;
  const ok = await confirm('Cancelar turno', '¿Estás seguro de que deseas cancelar tu turno?', 'Sí, cancelar', true);
  if (!ok) return;
  const res = await api('cancel_turn', { method: 'POST', body: { id: +id } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) renderQueueData(await api('get_queue'));
}

/* ===== ADMIN ===== */
const ADMIN = {
  usersPage: 1, usersSearch: '', usersTotal: 0, usersPages: 1,
  turnsPage: 1, turnsSearch: '', turnsStatus: '', turnsTotal: 0, turnsPages: 1,
  currentSection: 'dashboard',
  dragSrcRow: null,
};

async function initAdmin() {
  const user = await checkSession();
  if (!user) { location.href = '/auth/'; return; }
  if (user.role !== 'admin') { location.href = '/home/'; return; }

  STATE.user = user;
  document.getElementById('admin-name').textContent = user.name;
  document.getElementById('admin-initial').textContent = user.name[0].toUpperCase();
  await loadCsrf();

  document.getElementById('logout-btn-admin')?.addEventListener('click', async () => {
    await api('logout', { method: 'POST' });
    location.href = '/auth/';
  });

  const menuToggle = document.getElementById('sidebar-toggle');
  const sidebar    = document.getElementById('admin-sidebar');
  const overlay    = document.getElementById('sidebar-overlay');
  menuToggle?.addEventListener('click', () => {
    sidebar.classList.toggle('open');
    overlay.classList.toggle('open');
  });
  overlay?.addEventListener('click', () => {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
  });

  document.querySelectorAll('.sidebar-link[data-section]').forEach(link => {
    link.addEventListener('click', e => {
      e.preventDefault();
      showSection(link.dataset.section);
      if (window.innerWidth <= 768) { sidebar.classList.remove('open'); overlay.classList.remove('open'); }
    });
  });

  document.getElementById('admin-search-users')?.addEventListener('input', debounce(e => {
    ADMIN.usersSearch = e.target.value;
    ADMIN.usersPage = 1;
    loadUsers();
  }, 350));

  document.getElementById('admin-search-turns')?.addEventListener('input', debounce(e => {
    ADMIN.turnsSearch = e.target.value;
    ADMIN.turnsPage = 1;
    loadTurns();
  }, 350));

  document.querySelectorAll('[data-filter-status]').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('[data-filter-status]').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      ADMIN.turnsStatus = btn.dataset.filterStatus;
      ADMIN.turnsPage = 1;
      loadTurns();
    });
  });

  document.getElementById('btn-activate-next')?.addEventListener('click', async () => {
    const res = await api('activate_next', { method: 'POST' });
    toast(res.message, res.success ? 'success' : 'warning');
    if (res.success) { loadDashboard(); loadTurns(); }
  });

  document.getElementById('btn-increase-turn')?.addEventListener('click', async () => {
    const res = await api('increase_turn', { method: 'POST' });
    if (res.success) updateCurrentTurnDisplay(res.current_turn);
  });

  document.getElementById('btn-decrease-turn')?.addEventListener('click', async () => {
    const res = await api('decrease_turn', { method: 'POST' });
    if (res.success) updateCurrentTurnDisplay(res.current_turn);
  });

  document.getElementById('btn-new-user')?.addEventListener('click', () => openUserModal());
  document.getElementById('btn-new-turn')?.addEventListener('click', () => openTurnModal());

  document.getElementById('user-form')?.addEventListener('submit', handleUserFormSubmit);
  document.getElementById('turn-form-admin')?.addEventListener('submit', handleTurnFormSubmit);

  initAdminServices();
  showSection('dashboard');
  connectSSE(data => {
    updateCurrentTurnDisplay(data.current_turn);
    if (ADMIN.currentSection === 'dashboard') loadDashboard();
    if (ADMIN.currentSection === 'turns') loadTurns();
  });
}

function debounce(fn, ms) {
  let t;
  return function(...args) { clearTimeout(t); t = setTimeout(() => fn.apply(this, args), ms); };
}

function showSection(name) {
  ADMIN.currentSection = name;
  document.querySelectorAll('.admin-section').forEach(s => s.style.display = 'none');
  const el = document.getElementById(`section-${name}`);
  if (el) el.style.display = 'block';
  document.querySelectorAll('.sidebar-link').forEach(l => l.classList.toggle('active', l.dataset.section === name));

  if (name === 'dashboard') loadDashboard();
  if (name === 'users') loadUsers();
  if (name === 'turns') loadTurns();
}

function updateCurrentTurnDisplay(val) {
  const el = document.getElementById('current-turn-admin');
  if (el) el.textContent = val ?? 0;
}

async function loadDashboard() {
  const res = await api('get_dashboard');
  if (!res.success) return;
  const s = res.stats;

  [
    ['stat-total-users', s.total_users],
    ['stat-total-turns', s.total_turns],
    ['stat-waiting', s.waiting_turns],
    ['stat-active', s.active_turns],
    ['stat-completed', s.completed_turns],
  ].forEach(([id, val]) => {
    const el = document.getElementById(id);
    if (el) el.textContent = val ?? 0;
  });

  updateCurrentTurnDisplay(s.current_turn);

  const recentBody = document.getElementById('recent-turns-body');
  if (recentBody) {
    if (!res.recent_turns.length) {
      recentBody.innerHTML = '<tr><td colspan="5" class="empty-state"><div class="empty-state-icon">📋</div><p>Sin turnos recientes.</p></td></tr>';
    } else {
      recentBody.innerHTML = res.recent_turns.map(t => `
        <tr>
          <td><strong>#${t.turn_number}</strong></td>
          <td>${escHtml(t.user_name)}</td>
          <td>${escHtml(t.service)}</td>
          <td>${statusBadge(t.status)}</td>
          <td>${formatDateShort(t.created_at)}</td>
        </tr>`).join('');
    }
  }

  const svcBody = document.getElementById('service-stats-body');
  if (svcBody) {
    if (!res.by_service.length) {
      svcBody.innerHTML = '<tr><td colspan="4" class="empty-state"><p>Sin datos.</p></td></tr>';
    } else {
      svcBody.innerHTML = res.by_service.map(s => `
        <tr>
          <td>${escHtml(s.service)}</td>
          <td>${s.total}</td>
          <td>${s.completed}</td>
          <td>${s.waiting}</td>
        </tr>`).join('');
    }
  }
}

async function loadUsers() {
  const tbody = document.getElementById('users-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7"><div class="skeleton" style="height:40px;border-radius:4px;margin:8px 16px"></div></td></tr>';

  const res = await api('get_users', { params: { search: ADMIN.usersSearch, page: ADMIN.usersPage, per_page: 20 } });
  if (!res.success) { tbody.innerHTML = `<tr><td colspan="7" class="empty-state"><p>${escHtml(res.message)}</p></td></tr>`; return; }

  ADMIN.usersTotal = res.total;
  ADMIN.usersPages = res.pages;

  const totalEl = document.getElementById('users-total');
  if (totalEl) totalEl.textContent = `${res.total} usuario${res.total !== 1 ? 's' : ''}`;

  if (!res.users.length) {
    tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-state-icon">👥</div><p>Sin usuarios encontrados.</p></div></td></tr>';
  } else {
    tbody.innerHTML = res.users.map(u => `
      <tr>
        <td>${u.id}</td>
        <td><strong>${escHtml(u.name)}</strong></td>
        <td>${escHtml(u.email)}</td>
        <td>${escHtml(u.phone || '—')}</td>
        <td>${roleBadge(u.role)}</td>
        <td><span class="badge ${u.is_active ? 'badge-active' : 'badge-cancelled'}">${u.is_active ? 'Activo' : 'Inactivo'}</span></td>
        <td>
          <div class="action-btns">
            <button class="action-btn edit" title="Editar" onclick="openUserModal(${u.id})">✎</button>
            <button class="action-btn delete" title="Eliminar" onclick="deleteUser(${u.id},'${escHtml(u.name)}')">🗑</button>
          </div>
        </td>
      </tr>`).join('');
  }

  renderPagination('users-pagination', ADMIN.usersPage, ADMIN.usersPages, p => { ADMIN.usersPage = p; loadUsers(); });
}

async function loadTurns() {
  const tbody = document.getElementById('turns-tbody');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="7"><div class="skeleton" style="height:40px;border-radius:4px;margin:8px 16px"></div></td></tr>';

  const res = await api('get_turns', { params: { search: ADMIN.turnsSearch, status: ADMIN.turnsStatus, page: ADMIN.turnsPage, per_page: 20 } });
  if (!res.success) { tbody.innerHTML = `<tr><td colspan="7" class="empty-state"><p>${escHtml(res.message)}</p></td></tr>`; return; }

  ADMIN.turnsTotal = res.total;
  ADMIN.turnsPages = res.pages;

  const totalEl = document.getElementById('turns-total');
  if (totalEl) totalEl.textContent = `${res.total} turno${res.total !== 1 ? 's' : ''}`;

  if (!res.turns.length) {
    tbody.innerHTML = '<tr><td colspan="7"><div class="empty-state"><div class="empty-state-icon">🎫</div><p>Sin turnos encontrados.</p></div></td></tr>';
  } else {
    tbody.innerHTML = res.turns.map(t => `
      <tr class="draggable-row" data-id="${t.id}" draggable="${t.status === 'waiting'}">
        <td><strong>#${t.turn_number}</strong></td>
        <td>${escHtml(t.user_name)}</td>
        <td>${escHtml(t.service)}</td>
        <td>${statusBadge(t.status)}</td>
        <td>${t.position ?? '—'}</td>
        <td>${formatDateShort(t.created_at)}</td>
        <td>
          <div class="action-btns">
            <button class="action-btn edit" title="Editar" onclick="openTurnModal(${t.id})">✎</button>
            ${t.status === 'waiting' ? `<button class="action-btn complete" title="Activar" onclick="setTurnStatus(${t.id},'active')">▶</button>` : ''}
            ${t.status === 'active' ? `<button class="action-btn complete" title="Completar" onclick="completeTurnAdmin(${t.id})">✓</button>` : ''}
            ${['waiting','active'].includes(t.status) ? `<button class="action-btn cancel" title="Cancelar" onclick="cancelTurnAdmin(${t.id})">✕</button>` : ''}
            <button class="action-btn delete" title="Eliminar" onclick="deleteTurnAdmin(${t.id})">🗑</button>
          </div>
        </td>
      </tr>`).join('');

    initDragReorder();
  }

  renderPagination('turns-pagination', ADMIN.turnsPage, ADMIN.turnsPages, p => { ADMIN.turnsPage = p; loadTurns(); });
}

function renderPagination(containerId, current, total, onPage) {
  const el = document.getElementById(containerId);
  if (!el || total <= 1) { if (el) el.innerHTML = ''; return; }
  let html = '';
  if (current > 1) html += `<button class="page-btn" onclick="(${onPage})(${current - 1})">‹</button>`;
  for (let i = Math.max(1, current - 2); i <= Math.min(total, current + 2); i++) {
    html += `<button class="page-btn ${i === current ? 'active' : ''}" onclick="(${onPage})(${i})">${i}</button>`;
  }
  if (current < total) html += `<button class="page-btn" onclick="(${onPage})(${current + 1})">›</button>`;
  el.innerHTML = html;
}

async function openUserModal(id = null) {
  const form = document.getElementById('user-form');
  if (!form) return;
  clearFieldErrors(form);
  form.reset();
  form.dataset.id = id ?? '';
  document.getElementById('user-modal-title').textContent = id ? 'Editar Usuario' : 'Nuevo Usuario';
  document.getElementById('user-pass-field').style.display = id ? 'none' : '';
  document.getElementById('user-pass-field').querySelector('input').required = !id;
  document.getElementById('user-pass-change').style.display = id ? '' : 'none';

  if (id) {
    const res = await api('get_user', { params: { id } });
    if (res.success) {
      const u = res.user;
      form.querySelector('[name="name"]').value   = u.name;
      form.querySelector('[name="email"]').value  = u.email;
      form.querySelector('[name="phone"]').value  = u.phone ?? '';
      form.querySelector('[name="role"]').value   = u.role;
      form.querySelector('[name="is_active"]').value = u.is_active;
    }
  }
  openModal('modal-user');
}

async function handleUserFormSubmit(e) {
  e.preventDefault();
  clearFieldErrors(e.target);
  const btn = e.target.querySelector('[type="submit"]');
  const id  = e.target.dataset.id;
  const data = {
    name:      e.target.querySelector('[name="name"]').value,
    email:     e.target.querySelector('[name="email"]').value,
    phone:     e.target.querySelector('[name="phone"]').value,
    role:      e.target.querySelector('[name="role"]').value,
    is_active: +e.target.querySelector('[name="is_active"]').value,
  };
  if (!id) data.password = e.target.querySelector('[name="password"]').value;
  const newPass = e.target.querySelector('[name="new_password"]')?.value;
  if (newPass) data.password = newPass;

  setLoading(btn, true);
  const res = await api(id ? 'update_user' : 'create_user', {
    method: 'POST',
    body: id ? { ...data, id: +id } : data,
  });
  setLoading(btn, false);

  if (res.success) {
    toast(res.message, 'success');
    closeModal('modal-user');
    loadUsers();
  } else {
    toast(res.message, 'error');
  }
}

async function deleteUser(id, name) {
  const ok = await confirm('Eliminar usuario', `¿Eliminar a "${name}"? Esta acción es irreversible.`, 'Eliminar', true);
  if (!ok) return;
  const res = await api('delete_user', { method: 'POST', body: { id } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) loadUsers();
}

async function initAdminServices() {
  const services = (await api('get_services')).services ?? [];
  ['turn-service-admin', 'user-service-filter'].forEach(selId => {
    const sel = document.getElementById(selId);
    if (!sel) return;
    services.forEach(s => { const o = document.createElement('option'); o.value = o.textContent = s; sel.appendChild(o); });
  });
}

async function openTurnModal(id = null) {
  const form = document.getElementById('turn-form-admin');
  if (!form) return;
  clearFieldErrors(form);
  form.reset();
  form.dataset.id = id ?? '';
  document.getElementById('turn-modal-title').textContent = id ? 'Editar Turno' : 'Nuevo Turno';

  if (!id) {
    const usersRes = await api('get_users', { params: { per_page: 100 } });
    const sel = document.getElementById('turn-user-select');
    if (sel && usersRes.success) {
      sel.innerHTML = '<option value="">— Seleccionar usuario —</option>';
      usersRes.users.forEach(u => {
        const o = document.createElement('option');
        o.value = u.id;
        o.textContent = `${u.name} (${u.email})`;
        sel.appendChild(o);
      });
    }
  }

  if (id) {
    const res = await api('get_turn', { params: { id } });
    if (res.success) {
      const t = res.turn;
      document.getElementById('turn-service-admin').value = t.service;
      document.getElementById('turn-notes-admin').value = t.notes ?? '';
      document.getElementById('turn-status-admin').value = t.status;
      const userSel = document.getElementById('turn-user-select');
      if (userSel) { userSel.parentElement.style.display = 'none'; }
    }
  } else {
    const userSel = document.getElementById('turn-user-select');
    if (userSel) { userSel.parentElement.style.display = ''; }
  }

  openModal('modal-turn-admin');
}

async function handleTurnFormSubmit(e) {
  e.preventDefault();
  const btn = e.target.querySelector('[type="submit"]');
  const id  = e.target.dataset.id;
  const data = {
    service: document.getElementById('turn-service-admin').value,
    notes:   document.getElementById('turn-notes-admin').value,
  };
  if (!id) {
    const uid = document.getElementById('turn-user-select').value;
    if (!uid) { toast('Selecciona un usuario.', 'error'); return; }
    data.user_id = +uid;
  } else {
    data.id     = +id;
    data.status = document.getElementById('turn-status-admin').value;
  }

  setLoading(btn, true);
  const res = await api(id ? 'update_turn' : 'create_turn', { method: 'POST', body: data });
  setLoading(btn, false);

  if (res.success) {
    toast(res.message, 'success');
    closeModal('modal-turn-admin');
    loadTurns();
    if (ADMIN.currentSection === 'dashboard') loadDashboard();
  } else {
    toast(res.message, 'error');
  }
}

async function setTurnStatus(id, status) {
  const res = await api('update_turn', { method: 'POST', body: { id, status } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) { loadTurns(); loadDashboard(); }
}

async function completeTurnAdmin(id) {
  const res = await api('complete_turn', { method: 'POST', body: { id } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) { loadTurns(); loadDashboard(); }
}

async function cancelTurnAdmin(id) {
  const ok = await confirm('Cancelar turno', '¿Cancelar este turno?', 'Sí, cancelar', true);
  if (!ok) return;
  const res = await api('cancel_turn', { method: 'POST', body: { id } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) { loadTurns(); loadDashboard(); }
}

async function deleteTurnAdmin(id) {
  const ok = await confirm('Eliminar turno', '¿Eliminar este turno? Esta acción no se puede deshacer.', 'Eliminar', true);
  if (!ok) return;
  const res = await api('delete_turn', { method: 'POST', body: { id } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) { loadTurns(); loadDashboard(); }
}

/* ===== DRAG REORDER ===== */
function initDragReorder() {
  const rows = document.querySelectorAll('.draggable-row[draggable="true"]');
  rows.forEach(row => {
    row.addEventListener('dragstart', e => {
      ADMIN.dragSrcRow = row;
      e.dataTransfer.effectAllowed = 'move';
      row.classList.add('dragging');
    });
    row.addEventListener('dragend', () => {
      row.classList.remove('dragging');
      document.querySelectorAll('.draggable-row').forEach(r => r.classList.remove('drag-over'));
    });
    row.addEventListener('dragover', e => {
      e.preventDefault();
      e.dataTransfer.dropEffect = 'move';
      document.querySelectorAll('.draggable-row').forEach(r => r.classList.remove('drag-over'));
      row.classList.add('drag-over');
    });
    row.addEventListener('drop', async e => {
      e.stopPropagation();
      if (ADMIN.dragSrcRow === row) return;
      const tbody = row.parentNode;
      const rows = Array.from(tbody.querySelectorAll('.draggable-row'));
      const fromIdx = rows.indexOf(ADMIN.dragSrcRow);
      const toIdx   = rows.indexOf(row);
      if (fromIdx < toIdx) row.parentNode.insertBefore(ADMIN.dragSrcRow, row.nextSibling);
      else row.parentNode.insertBefore(ADMIN.dragSrcRow, row);
      const ids = Array.from(tbody.querySelectorAll('.draggable-row')).map(r => +r.dataset.id);
      const res = await api('reorder_queue', { method: 'POST', body: { ids } });
      if (!res.success) toast(res.message, 'error');
    });
  });
}

/* ===== SETUP ADMIN ===== */
async function setupAdminDB() {
  try {
    await fetch('/api/setup.php');
  } catch {}
}
