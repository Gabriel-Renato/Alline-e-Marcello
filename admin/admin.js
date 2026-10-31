const API = '../api/index.php?r=';

const loginView = document.getElementById('loginView');
const appView = document.getElementById('appView');
const loginForm = document.getElementById('loginForm');
const loginError = document.getElementById('loginError');
const statsEl = document.getElementById('stats');
const giftsList = document.getElementById('giftsList');

function token() {
  return localStorage.getItem('am_admin_token');
}

function setToken(value) {
  if (value) localStorage.setItem('am_admin_token', value);
  else localStorage.removeItem('am_admin_token');
}

async function api(path, options = {}) {
  const headers = { 'Content-Type': 'application/json', ...(options.headers || {}) };
  if (token()) headers.Authorization = `Bearer ${token()}`;

  let method = (options.method || 'GET').toUpperCase();
  let body = options.body;
  // InfinityFree costuma bloquear PUT/DELETE: envia como POST + _method
  if (method === 'PUT' || method === 'DELETE') {
    const payload = body ? JSON.parse(body) : {};
    payload._method = method;
    body = JSON.stringify(payload);
    method = 'POST';
  }

  const res = await fetch(API + path, { ...options, method, headers, body });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || 'Erro na requisição');
  return data;
}

function money(v) {
  return Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function statusBadge(status) {
  if (status === 'confirmed') return '<span class="badge confirmed">Confirmado</span>';
  if (status === 'declined') return '<span class="badge declined">Não vai</span>';
  return '<span class="badge pending">Pendente</span>';
}

function modeBadge(mode) {
  return mode === 'mao'
    ? '<span class="badge mao">Na mão</span>'
    : '<span class="badge pix">PIX</span>';
}

async function boot() {
  if (!token()) {
    showLogin();
    return;
  }
  try {
    await loadDashboard();
    showApp();
  } catch {
    setToken(null);
    showLogin();
  }
}

function showLogin() {
  loginView.classList.add('is-active');
  appView.classList.remove('is-active');
  window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
}

function showApp() {
  loginView.classList.remove('is-active');
  appView.classList.add('is-active');
  window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
}

loginForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  loginError.hidden = true;
  try {
    const data = await api('admin/login', {
      method: 'POST',
      body: JSON.stringify({
        email: document.getElementById('email').value.trim(),
        password: document.getElementById('password').value,
      }),
    });
    setToken(data.token);
    await loadDashboard();
    showApp();
  } catch (err) {
    loginError.textContent = err.message;
    loginError.hidden = false;
  }
});

document.getElementById('logoutBtn').addEventListener('click', async () => {
  try { await api('admin/logout', { method: 'POST' }); } catch {}
  setToken(null);
  showLogin();
});

document.querySelectorAll('.tabs button').forEach((btn) => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tabs button').forEach((b) => b.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('.panel').forEach((p) => {
      p.hidden = p.dataset.panel !== btn.dataset.tab;
    });
  });
});

async function loadDashboard() {
  const data = await api('admin/dashboard');
  const s = data.stats;
  statsEl.innerHTML = `
    <div class="stat"><span>Convidados</span><strong>${s.total_guests}</strong></div>
    <div class="stat"><span>Confirmados</span><strong>${s.confirmed}</strong></div>
    <div class="stat"><span>Não vão</span><strong>${s.declined}</strong></div>
    <div class="stat"><span>Pendentes</span><strong>${s.pending}</strong></div>
    <div class="stat"><span>Presentes</span><strong>${s.gifts}</strong></div>
    <div class="stat"><span>Escolhas</span><strong>${s.gift_claims}</strong></div>
  `;

  document.getElementById('confirmationsBody').innerHTML = data.confirmations.length
    ? data.confirmations.map((c) => `
        <tr>
          <td>${escapeHtml(c.guest_name)}</td>
          <td>${statusBadge(c.status)}</td>
          <td>${c.confirmed_at || '-'}</td>
        </tr>`).join('')
    : '<tr><td colspan="3">Nenhuma confirmação ainda.</td></tr>';

  document.getElementById('guestsBody').innerHTML = data.wedding_guests.map((g) => `
    <tr>
      <td>${escapeHtml(g.name)}</td>
      <td>${statusBadge(g.status)}</td>
      <td><button class="small danger" data-del-guest="${g.id}">Excluir</button></td>
    </tr>`).join('');

  document.getElementById('claimsBody').innerHTML = data.gift_claims.length
    ? data.gift_claims.map((c) => `
        <tr>
          <td>${escapeHtml(c.gift_name)}</td>
          <td>${escapeHtml(c.guest_name)}</td>
          <td>${modeBadge(c.delivery_mode)}</td>
          <td>${money(c.amount)}</td>
          <td>${c.created_at}</td>
        </tr>`).join('')
    : '<tr><td colspan="5">Nenhuma escolha de presente.</td></tr>';

  giftsList.innerHTML = data.gifts.map((g) => `
    <article class="gift-card ${g.is_full ? 'gift-full' : ''}">
      <img src="../${escapeAttr(g.image || '')}" alt="" onerror="this.style.opacity=.2">
      <div>
        <h3>${escapeHtml(g.name)}</h3>
        <p>${money(g.price)}</p>
        <p><strong>Meta:</strong> ${g.claimed_count}/${g.max_people} pessoa(s)
          ${g.is_full ? '<span class="badge pending">Esgotado</span>' : ''}
        </p>
        <p>${modeBadge(g.delivery_mode)} ${g.is_active ? '' : '<span class="badge pending">Inativo</span>'}</p>
      </div>
      <div class="gift-actions">
        <button class="small" data-max="${g.id}" data-current="${g.max_people}">Alterar meta</button>
        <button class="small" data-mode="${g.id}" data-to="${g.delivery_mode === 'pix' ? 'mao' : 'pix'}">
          Mudar para ${g.delivery_mode === 'pix' ? 'mão' : 'PIX'}
        </button>
        ${g.delivery_mode === 'pix' ? `<button class="small" data-pix="${g.id}">Ver PIX</button>` : ''}
        <button class="small danger" data-del-gift="${g.id}">Excluir</button>
      </div>
    </article>`).join('');
}

document.getElementById('guestForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const name = document.getElementById('guestName').value.trim();
  await api('admin/guests', { method: 'POST', body: JSON.stringify({ name }) });
  document.getElementById('guestName').value = '';
  await loadDashboard();
});

document.getElementById('giftForm').addEventListener('submit', async (e) => {
  e.preventDefault();
  const delivery_mode = document.querySelector('input[name="delivery_mode"]:checked').value;
  await api('admin/gifts', {
    method: 'POST',
    body: JSON.stringify({
      name: document.getElementById('giftName').value.trim(),
      price: Number(document.getElementById('giftPrice').value),
      image: document.getElementById('giftImage').value.trim(),
      max_people: Number(document.getElementById('giftMax').value || 1),
      details: document.getElementById('giftDetails').value.trim(),
      delivery_mode,
    }),
  });
  e.target.reset();
  document.querySelector('input[name="delivery_mode"][value="pix"]').checked = true;
  await loadDashboard();
});

document.body.addEventListener('click', async (e) => {
  const delGuest = e.target.closest('[data-del-guest]');
  if (delGuest) {
    if (!confirm('Excluir convidado?')) return;
    await api('admin/guests/' + delGuest.dataset.delGuest, { method: 'DELETE' });
    await loadDashboard();
    return;
  }

  const delGift = e.target.closest('[data-del-gift]');
  if (delGift) {
    if (!confirm('Excluir presente?')) return;
    await api('admin/gifts/' + delGift.dataset.delGift, { method: 'DELETE' });
    await loadDashboard();
    return;
  }

  const maxBtn = e.target.closest('[data-max]');
  if (maxBtn) {
    const next = prompt('Quantas pessoas podem presentear este item?', maxBtn.dataset.current);
    if (next === null) return;
    const max_people = Math.max(1, parseInt(next, 10) || 1);
    await api('admin/gifts/' + maxBtn.dataset.max, {
      method: 'PUT',
      body: JSON.stringify({ max_people }),
    });
    await loadDashboard();
    return;
  }

  const modeBtn = e.target.closest('[data-mode]');
  if (modeBtn) {
    await api('admin/gifts/' + modeBtn.dataset.mode, {
      method: 'PUT',
      body: JSON.stringify({ delivery_mode: modeBtn.dataset.to }),
    });
    await loadDashboard();
    return;
  }

  const pixBtn = e.target.closest('[data-pix]');
  if (pixBtn) {
    try {
      const res = await fetch(API + 'gifts/' + pixBtn.dataset.pix + '/pix');
      const pix = await res.json();
      if (!res.ok || !pix.payload) {
        alert(pix.message || 'Não foi possível gerar o PIX.');
        return;
      }
      openPixModal(pix);
    } catch (err) {
      alert('Erro ao carregar o PIX.');
    }
  }
});

function openPixModal(pix) {
  const modal = document.getElementById('pixModal');
  document.getElementById('pixTitle').textContent = pix.gift_name || 'PIX';
  document.getElementById('pixAmount').textContent = pix.amount_label || '';
  document.getElementById('pixPayload').value = pix.payload || '';
  modal.classList.add('is-open');
  modal.setAttribute('aria-hidden', 'false');
}

function closePixModal() {
  const modal = document.getElementById('pixModal');
  modal.classList.remove('is-open');
  modal.setAttribute('aria-hidden', 'true');
  document.getElementById('pixPayload').value = '';
}

document.getElementById('closePix').onclick = closePixModal;
document.getElementById('pixModal').addEventListener('click', (e) => {
  if (e.target.id === 'pixModal') closePixModal();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') closePixModal();
});
closePixModal();

document.getElementById('copyPix').onclick = async () => {
  const text = document.getElementById('pixPayload').value;
  await navigator.clipboard.writeText(text);
  document.getElementById('copyPix').textContent = 'Copiado!';
  setTimeout(() => { document.getElementById('copyPix').textContent = 'Copiar código PIX'; }, 1500);
};

function escapeHtml(str) {
  return String(str || '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function escapeAttr(str) {
  return escapeHtml(str).replaceAll("'", '&#39;');
}

boot();
