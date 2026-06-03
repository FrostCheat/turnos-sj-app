const API = '../../api/index.php';
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
    const text = await res.text();
    try {
      return JSON.parse(text);
    } catch {
      if (res.status === 403) return { success: false, message: 'Acceso bloqueado por el servidor.' };
      if (res.status === 404) return { success: false, message: 'API no encontrada.' };
      if (res.status >= 500) return { success: false, message: 'Error interno del servidor.' };
      return { success: false, message: 'Respuesta inesperada del servidor.' };
    }
  } catch {
    return { success: false, message: 'Error de conexión.' };
  }
}

async function loadCsrf() {
  const res = await api('csrf_token');
  if (res.success) STATE.csrf = res.token;
}

function toast(msg, type = 'info', duration = 4000) {
  const icons = { success: '✓', error: '✕', info: 'ℹ', warning: '⚠' };
  let c = document.getElementById('toast-container');
  if (!c) { c = document.createElement('div'); c.id = 'toast-container'; document.body.appendChild(c); }
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.innerHTML = `<span class="toast-icon">${icons[type]}</span><span class="toast-msg">${escHtml(msg)}</span>`;
  c.appendChild(t);
  const remove = () => { t.classList.add('removing'); setTimeout(() => t.remove(), 300); };
  const timer = setTimeout(remove, duration);
  t.addEventListener('click', () => { clearTimeout(timer); remove(); });
}

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

function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  document.body.style.overflow = 'hidden';
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

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAllModals(); });
document.addEventListener('click', e => {
  if (e.target.classList.contains('modal-backdrop')) closeAllModals();
  if (e.target.classList.contains('modal-close')) closeAllModals();
});

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
  if (!btn) return;
  if (state) { btn._txt = btn.innerHTML; btn.classList.add('btn-loading'); btn.disabled = true; }
  else { btn.classList.remove('btn-loading'); btn.disabled = false; if (btn._txt !== undefined) btn.innerHTML = btn._txt; }
}

function showFieldError(name, msg) {
  const input = document.querySelector(`[name="${name}"]`);
  const errEl = document.getElementById(`err-${name}`);
  if (input) input.classList.add('error');
  if (errEl) { errEl.textContent = msg; errEl.classList.add('visible'); }
}

function clearFieldErrors(form) {
  if (!form) return;
  form.querySelectorAll('.form-control').forEach(el => el.classList.remove('error'));
  form.querySelectorAll('.form-error').forEach(el => { el.textContent = ''; el.classList.remove('visible'); });
}

async function checkSession() {
  const res = await api('get_session');
  if (res.success && res.authenticated) { STATE.user = res.user; return res.user; }
  return null;
}

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

function connectSSE(onMessage) {
  if (STATE.sseSource) { STATE.sseSource.close(); STATE.sseSource = null; }
  const sseUrl = new URL(API, location.origin);
  sseUrl.searchParams.set('action', 'sse_queue');
  const es = new EventSource(sseUrl.toString(), { withCredentials: true });
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
  es.onopen = () => { STATE.sseRetries = 0; updateConnStatus(true); };
}

function updateConnStatus(ok) {
  const el = document.getElementById('conn-status');
  if (!el) return;
  el.className = `conn-badge ${ok ? 'connected' : 'disconnected'}`;
  el.textContent = ok ? '● En línea' : '● Desconectado';
}

const PAGE = document.body.dataset.page;
if (PAGE === 'landing') initLanding();
if (PAGE === 'auth') initAuth();
if (PAGE === 'home') initHome();

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

function initAuth() {
  loadCsrf();
  const tabs   = document.querySelectorAll('.auth-tab');
  const panels = document.querySelectorAll('.auth-panel');
  const hash   = location.hash;

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
        setTimeout(() => { location.href = res.user.role === 'admin' ? '/admin/' : '/home/'; }, 600);
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
          name:     regForm.querySelector('[name="name"]').value,
          email:    regForm.querySelector('[name="email"]').value,
          phone:    regForm.querySelector('[name="phone"]')?.value,
          password: pass,
        },
      });
      setLoading(btn, false);
      if (res.success) {
        STATE.user = res.user;
        toast(res.message, 'success');
        setTimeout(() => { location.href = '/home/'; }, 600);
      } else {
        toast(res.message, 'error');
      }
    });
  }
}

async function initHome() {
  const user = await checkSession();
  if (!user) { location.href = '/auth/'; return; }

  document.getElementById('user-name').textContent = user.name;
  document.getElementById('user-initial').textContent = user.name[0].toUpperCase();
  await loadCsrf();

  const notifBanner = document.getElementById('notif-bar');
  if (notifBanner) {
    if ('Notification' in window && Notification.permission === 'default') {
      notifBanner.classList.remove('hidden');
    } else {
      notifBanner.classList.add('hidden');
    }
  }
  document.getElementById('btn-enable-notif')?.addEventListener('click', async () => {
    const ok = await requestNotifPermission();
    if (ok) { document.getElementById('notif-bar')?.classList.add('hidden'); toast('Notificaciones activadas.', 'success'); }
    else toast('Permiso denegado.', 'warning');
  });
  document.getElementById('btn-dismiss-notif')?.addEventListener('click', () => {
    document.getElementById('notif-bar')?.classList.add('hidden');
  });

  document.getElementById('logout-btn')?.addEventListener('click', async () => {
    await api('logout', { method: 'POST' });
    location.href = '/auth/';
  });

  document.getElementById('request-turn-btn')?.addEventListener('click', async () => {
    await loadHomeServices();
    openModal('modal-turn');
  });

  document.getElementById('turn-form-cancel')?.addEventListener('click', () => closeModal('modal-turn'));

  document.getElementById('cancel-turn-btn')?.addEventListener('click', cancelMyTurn);

  document.getElementById('turn-form')?.addEventListener('submit', async e => {
    e.preventDefault();
    const btn = document.getElementById('turn-form-submit') || e.target.querySelector('[type="submit"]');
    setLoading(btn, true);
    const res = await api('create_turn', {
      method: 'POST',
      body: {
        service: document.getElementById('turn-service')?.value || 'Atención General',
        notes:   document.getElementById('turn-notes')?.value || '',
      },
    });
    setLoading(btn, false);
    if (res.success) {
      toast(`Turno #${res.turn_number} asignado. Posición: ${res.position}`, 'success');
      closeModal('modal-turn');
      e.target.reset();
      const qdata = await api('get_queue');
      if (qdata.success) renderQueueData(qdata);
    } else {
      toast(res.message, 'error');
    }
  });

  let prevMyTurnStatus = null;
  let prevCurrentTurn  = null;

  function handleSSEData(data) {
    if (!data || data.type !== 'queue_update') return;
    renderQueueData({
      success:       true,
      current_turn:  data.current_turn,
      active_turn:   data.active_turn,
      waiting_count: data.waiting_count,
      my_turn:       data.my_turn ?? null,
    });
    if (data.my_turn) {
      const t = data.my_turn;
      if (prevMyTurnStatus !== t.status) {
        if (t.status === 'active') {
          toast('¡Es tu turno! Acércate a la ventanilla.', 'success', 8000);
          sendNotification('¡Tu turno!', 'Es tu turno. Por favor acércate a la ventanilla.', { requireInteraction: true });
        }
        if (t.status === 'cancelled' && prevMyTurnStatus !== null) {
          toast('Tu turno fue cancelado.', 'warning');
        }
        prevMyTurnStatus = t.status;
      }
      if (t.status === 'waiting' && t.position <= 2 && prevCurrentTurn !== data.current_turn) {
        toast('¡Prepárate! Estás próximo a ser atendido.', 'info', 6000);
        sendNotification('¡Prepárate!', `Posición ${t.position} en la cola.`);
      }
    } else {
      if (prevMyTurnStatus !== null) prevMyTurnStatus = null;
    }
    prevCurrentTurn = data.current_turn;
  }

  const initialData = await api('get_queue');
  if (initialData.success) {
    renderQueueData(initialData);
    if (initialData.my_turn) prevMyTurnStatus = initialData.my_turn.status;
    prevCurrentTurn = initialData.current_turn;
  }

  connectSSE(handleSSEData);
}

let _homeServicesLoaded = false;
async function loadHomeServices() {
  if (_homeServicesLoaded) return;
  const sel = document.getElementById('turn-service');
  if (!sel) return;
  const res = await api('get_services');
  const svcs = (res && res.services) ? res.services : ['Atención General','Trámites Documentales','Certificados','Consultas','Pagos y Recaudos','Orientación Ciudadana'];
  sel.innerHTML = '';
  svcs.forEach(s => { const o = document.createElement('option'); o.value = o.textContent = s; sel.appendChild(o); });
  _homeServicesLoaded = true;
}

function renderQueueData(data) {
  if (!data || !data.success) return;

  const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  const pad = n => String(n).padStart(3,'0');
  const fmt = s => { if (!s) return '—'; return new Date(s).toLocaleString('es-CO',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}); };

  const ct = document.getElementById('h-current-num');
  if (ct) {
    const v = data.current_turn;
    ct.textContent = v ? pad(v) : '—';
    ct.classList.toggle('empty', !v);
  }

  const as = document.getElementById('h-active-svc');
  if (as) as.textContent = data.active_turn ? data.active_turn.service : 'Sin turno activo';

  const wc = document.getElementById('h-waiting-count');
  if (wc) wc.textContent = data.waiting_count ?? 0;

  const sbw = document.getElementById('sb-waiting');
  if (sbw) sbw.textContent = data.waiting_count ?? 0;

  const sbc = document.getElementById('sb-current');
  if (sbc) sbc.textContent = data.current_turn || '—';

  const atd = document.getElementById('active-turn-display');
  if (atd) {
    if (data.active_turn) {
      const t = data.active_turn;
      atd.innerHTML = `<div class="act-card"><div class="act-num">#${t.turn_number}</div><div class="act-info"><div class="act-lbl">En atención</div><div class="act-svc">${esc(t.service)}</div>${t.user_name ? `<div style="font-size:.72rem;color:rgba(255,255,255,.25);margin-top:2px">${esc(t.user_name)}</div>` : ''}</div><div class="pulse-dot"></div></div>`;
    } else {
      atd.innerHTML = '<div style="text-align:center;padding:10px 0;color:rgba(255,255,255,.2);font-size:.82rem">Sin turno activo</div>';
    }
  }

  const mt = data.my_turn;
  const alertEl = document.getElementById('my-turn-alert');
  const mySection = document.getElementById('my-turn-section');
  const noSection = document.getElementById('no-turn-section');
  const reqBtn = document.getElementById('request-turn-btn');

  if (mt) {
    const isActive = mt.status === 'active';
    if (mySection) mySection.style.display = 'block';
    if (noSection) noSection.style.display = 'none';
    if (reqBtn) reqBtn.style.display = 'none';
    if (alertEl) alertEl.style.display = isActive ? 'flex' : 'none';

    const cancelBtn = document.getElementById('cancel-turn-btn');
    if (cancelBtn) cancelBtn.dataset.id = mt.id;

    const num = document.getElementById('my-turn-number');
    if (num) num.textContent = '#' + pad(mt.turn_number);

    const svcTag = document.getElementById('my-turn-svc-tag');
    if (svcTag) svcTag.textContent = mt.service;

    const svc = document.getElementById('my-turn-svc');
    if (svc) svc.textContent = mt.service;

    const badge = document.getElementById('my-turn-badge');
    if (badge) badge.innerHTML = statusBadge(mt.status);

    const pos = document.getElementById('my-turn-pos');
    if (pos) pos.textContent = isActive ? '¡Es tu turno!' : (mt.position ? `${mt.position}°` : '—');

    const tm = document.getElementById('my-turn-time');
    if (tm) tm.textContent = fmt(mt.created_at);

    const pw = document.getElementById('prog-wrap');
    const pf = document.getElementById('prog-fill');
    const pp = document.getElementById('prog-pct');
    if (pw && pf) {
      if (!isActive && mt.position) {
        pw.style.display = 'block';
        const total = Math.max(1, data.waiting_count || 1);
        const pct = Math.max(5, Math.min(95, Math.round((1 - (mt.position - 1) / total) * 100)));
        pf.style.width = pct + '%';
        if (pp) pp.textContent = pct + '%';
      } else if (isActive) {
        pw.style.display = 'block';
        pf.style.width = '100%';
        if (pp) pp.textContent = '100%';
      } else {
        pw.style.display = 'none';
      }
    }
  } else {
    if (mySection) mySection.style.display = 'none';
    if (noSection) noSection.style.display = 'block';
    if (reqBtn) reqBtn.style.display = '';
    if (alertEl) alertEl.style.display = 'none';
  }
}

async function cancelMyTurn() {
  const btn = document.getElementById('cancel-turn-btn');
  const id  = btn?.dataset.id;
  if (!id) return;
  const ok = await confirm('Cancelar turno', '¿Deseas cancelar tu turno?', 'Sí, cancelar', true);
  if (!ok) return;
  const res = await api('cancel_turn', { method: 'POST', body: { id: +id } });
  toast(res.message, res.success ? 'success' : 'error');
  if (res.success) {
    const qdata = await api('get_queue');
    if (qdata.success) renderQueueData(qdata);
  }
}