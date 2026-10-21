/* NON-RUNTIME DESIGN CONTRACT — fixture data only */
const GOLDEN_FIELDS = [
  { key: 'sku', label: 'SKU', type: 'text', required: true, options: 0, change: 'unchanged' },
  { key: 'name', label: 'Product Name', type: 'text', required: false, options: 0, change: 'unchanged' },
  { key: 'description', label: 'Description', type: 'textarea', required: true, options: 0, change: 'changed' },
  { key: 'short_description', label: 'Short Description', type: 'textarea', required: false, options: 0, change: 'unchanged' },
  { key: 'category', label: 'Category', type: 'relation', required: false, options: 0, change: 'added' },
  { key: 'status', label: 'Enable Product', type: 'select', required: false, options: 2, change: 'removed' },
  { key: 'color', label: 'Color', type: 'select', required: false, options: 3, change: 'added' },
  { key: 'material', label: 'Material', type: 'text', required: false, options: 0, change: 'added' },
  { key: 'weight', label: 'Weight', type: 'text', required: false, options: 0, change: 'changed' },
];

const CONNECTIONS = [
  {
    id: 'acc-connected',
    platform: 'Adobe Commerce',
    name: 'Babypark UA Store',
    context: 'shop.babypark.example / default',
    status: 'connected',
    statusLabel: 'Підключено',
    lastCheck: '21.07.2026 14:12',
    lastDiscovery: '21.07.2026 13:55',
    attention: null,
    action: 'Відкрити',
  },
  {
    id: 'acc-new',
    platform: 'Adobe Commerce',
    name: 'New EU storefront',
    context: 'eu-store.example / en',
    status: 'untested',
    statusLabel: 'Не перевірено',
    lastCheck: '—',
    lastDiscovery: '—',
    attention: 'Перевірте з’єднання після збереження налаштувань.',
    action: 'Налаштувати',
  },
  {
    id: 'acc-attention',
    platform: 'Adobe Commerce',
    name: 'Outlet channel',
    context: 'outlet.example / outlet',
    status: 'attention',
    statusLabel: 'Потребує уваги',
    lastCheck: '20.07.2026 09:40',
    lastDiscovery: '18.07.2026 11:20',
    attention: 'Оновіть облікові дані — термін дії доступу закінчився.',
    action: 'Оновити доступ',
  },
  {
    id: 'acc-outage',
    platform: 'Adobe Commerce',
    name: 'Wholesale B2B',
    context: 'b2b.example / default',
    status: 'temporary',
    statusLabel: 'Тимчасово недоступно',
    lastCheck: '21.07.2026 08:05',
    lastDiscovery: '19.07.2026 16:30',
    attention: 'Магазин тимчасово не відповідає. Спробуйте пізніше.',
    action: 'Повторити перевірку',
  },
  {
    id: 'acc-disabled',
    platform: 'Adobe Commerce',
    name: 'Legacy sandbox',
    context: 'sandbox.example / default',
    status: 'disabled',
    statusLabel: 'Вимкнено',
    lastCheck: '10.07.2026 12:00',
    lastDiscovery: '10.07.2026 11:50',
    attention: null,
    action: 'Увімкнути',
  },
];

const CHECK_SCENARIOS = {
  success: {
    title: 'З’єднання встановлено',
    badge: 'success',
    message: 'Магазин відповідає, доступ до списку атрибутів товарів підтверджено.',
    detail: 'Adobe Commerce 2.4.9 (PaaS) · 842 мс',
    next: 'Запустити отримання полів',
  },
  auth: {
    title: 'Невірні облікові дані',
    badge: 'danger',
    message: 'Магазин відхилив доступ. Перевірте ключі інтеграції та збережіть їх знову.',
    detail: 'HTTP 401 · Ref: ADOBE-REQ-88421',
    next: 'Замінити облікові дані',
  },
  forbidden: {
    title: 'Недостатньо прав доступу',
    badge: 'warning',
    message: 'Підключення авторизовано, але роль інтеграції не має доступу до атрибутів товарів.',
    detail: 'HTTP 403 · Ref: ADOBE-REQ-88455',
    next: 'Оновити роль Adobe для інтеграції',
  },
  config: {
    title: 'Невірна адреса магазину',
    badge: 'warning',
    message: 'Не вдалося знайти магазин за вказаною адресою. Перевірте URL і код store view.',
    detail: 'DNS / host unreachable',
    next: 'Виправити адресу підключення',
  },
  rate: {
    title: 'Занадто багато запитів',
    badge: 'info',
    message: 'Магазин тимчасово обмежив кількість запитів. Спробуйте ще раз за кілька хвилин.',
    detail: 'HTTP 429',
    next: 'Повторити пізніше',
  },
  outage: {
    title: 'Магазин тимчасово недоступний',
    badge: 'info',
    message: 'Сервер магазину не відповідає. Це може бути тимчасовий збій.',
    detail: 'Timeout after 30s · Ref: ADOBE-REQ-88501',
    next: 'Повторити перевірку',
  },
};

const HISTORY = [
  { time: '21.07 14:12', type: 'Перевірка з’єднання', status: 'Успішно', cause: '—', action: '—', by: 'Олена К.', duration: '842 мс', snapshot: '—', ref: 'ADOBE-REQ-88400' },
  { time: '21.07 13:55', type: 'Отримання полів', status: 'Успішно', cause: '—', action: '—', by: 'Олена К.', duration: '4.2 с', snapshot: 'Знімок #12', ref: 'ADOBE-REQ-88390' },
  { time: '21.07 08:05', type: 'Перевірка з’єднання', status: 'Помилка', cause: 'vendor_unavailable', action: 'automatic_retry', by: 'Система', duration: '30.0 с', snapshot: '—', ref: 'ADOBE-REQ-88310' },
  { time: '20.07 09:40', type: 'Перевірка з’єднання', status: 'Помилка', cause: 'authentication', action: 'user_action_required', by: 'Ігор П.', duration: '1.1 с', snapshot: '—', ref: 'ADOBE-REQ-88200' },
  { time: '18.07 11:20', type: 'Отримання полів', status: 'Успішно', cause: '—', action: '—', by: 'Система', duration: '3.8 с', snapshot: 'Знімок #11', ref: 'ADOBE-REQ-88100' },
  { time: '17.07 16:00', type: 'Отримання полів', status: 'Помилка', cause: 'authorization', action: 'workspace_admin_required', by: 'Олена К.', duration: '0.9 с', snapshot: '—', ref: 'ADOBE-REQ-88050' },
];

function statusBadgeClass(status) {
  const map = {
    connected: 'success',
    untested: 'muted',
    attention: 'warning',
    temporary: 'info',
    disabled: 'muted',
  };
  return map[status] || 'muted';
}

function changeBadge(change) {
  const labels = { added: 'Додано', removed: 'Видалено', changed: 'Змінено', unchanged: 'Без змін' };
  const cls = { added: 'success', removed: 'danger', changed: 'warning', unchanged: 'muted' };
  return `<span class="badge ${cls[change] || 'muted'}">${labels[change] || change}</span>`;
}

function renderConnections() {
  const tbody = document.querySelector('#connections-table tbody');
  tbody.innerHTML = CONNECTIONS.map((c) => `
    <tr>
      <td>${c.platform}</td>
      <td><strong>${c.name}</strong></td>
      <td class="mono">${c.context}</td>
      <td><span class="badge ${statusBadgeClass(c.status)}">${c.statusLabel}</span></td>
      <td>${c.lastCheck}</td>
      <td>${c.lastDiscovery}</td>
      <td>${c.attention ? `<span class="hint">${c.attention}</span>` : '—'}</td>
      <td><button class="btn primary" data-open-settings="${c.id}">${c.action}</button></td>
    </tr>
  `).join('');
}

function renderDiscoveryFields(filter = '') {
  const tbody = document.querySelector('#discovery-fields tbody');
  const q = filter.trim().toLowerCase();
  const rows = GOLDEN_FIELDS.filter((f) => !q || f.key.includes(q) || f.label.toLowerCase().includes(q));
  tbody.innerHTML = rows.map((f) => `
    <tr data-field="${f.key}">
      <td class="mono">${f.key}</td>
      <td>${f.label}</td>
      <td>${f.type}</td>
      <td>${f.required ? 'Так' : 'Ні'}</td>
      <td>${f.options}</td>
      <td>${changeBadge(f.change)}</td>
      <td><button class="btn ghost" data-diff="${f.key}">Деталі</button></td>
    </tr>
  `).join('');
}

function renderHistory() {
  const tbody = document.querySelector('#history-table tbody');
  tbody.innerHTML = HISTORY.map((h) => `
    <tr>
      <td>${h.time}</td>
      <td>${h.type}</td>
      <td>${h.status}</td>
      <td class="mono">${h.cause}</td>
      <td class="mono">${h.action}</td>
      <td>${h.by}</td>
      <td>${h.duration}</td>
      <td>${h.snapshot}</td>
      <td class="mono">${h.ref}</td>
    </tr>
  `).join('');
}

function showCheckResult(key) {
  const s = CHECK_SCENARIOS[key];
  const el = document.getElementById('check-result');
  el.innerHTML = `
    <div class="card">
      <span class="badge ${s.badge}">${s.title}</span>
      <p style="margin:0.75rem 0 0.25rem">${s.message}</p>
      <p class="hint">${s.detail}</p>
      <div style="margin-top:1rem;display:flex;gap:0.5rem;flex-wrap:wrap">
        <button class="btn primary">${s.next}</button>
        ${key === 'success' ? '<button class="btn" data-screen="discovery">Запустити отримання полів</button>' : ''}
      </div>
    </div>
  `;
}

function showDiffDetail(key) {
  const field = GOLDEN_FIELDS.find((f) => f.key === key) || GOLDEN_FIELDS[2];
  const panel = document.getElementById('diff-detail');
  const drawer = document.getElementById('diff-drawer');
  let body = '';
  if (field.change === 'added') {
    body = `<pre>${JSON.stringify({ external_field_key: field.key, label: field.label, type: field.type }, null, 2)}</pre>`;
  } else if (field.change === 'removed') {
    body = `<pre>${JSON.stringify({ external_field_key: field.key, label: field.label, type: field.type }, null, 2)}</pre>`;
  } else {
    body = `
      <div class="compare">
        <div><strong>Було</strong><pre>${JSON.stringify({ is_required: false, type: field.type }, null, 2)}</pre></div>
        <div><strong>Стало</strong><pre>${JSON.stringify({ is_required: true, type: field.type }, null, 2)}</pre></div>
      </div>
      <p class="hint">Змінені шляхи: <span class="mono">is_required</span></p>`;
  }
  panel.innerHTML = `
    <h3>${field.key}</h3>
    ${body}
  `;
  document.querySelector('.drawer-backdrop').classList.add('open');
  drawer.classList.add('open');
}

function openDrawer() {
  document.getElementById('filter-backdrop').classList.add('open');
  document.querySelectorAll('.drawer').forEach((d) => {
    if (d.id !== 'diff-drawer') d.classList.add('open');
  });
}

function closeDrawer() {
  document.getElementById('filter-backdrop').classList.remove('open');
  document.querySelectorAll('.drawer').forEach((d) => d.classList.remove('open'));
}

function setScreen(id) {
  document.querySelectorAll('.screen').forEach((s) => s.classList.toggle('active', s.id === `screen-${id}`));
  document.querySelectorAll('.nav-tabs button').forEach((b) => b.classList.toggle('active', b.dataset.screen === id));
}

function bindUi() {
  document.querySelectorAll('[data-screen]').forEach((el) => {
    el.addEventListener('click', () => setScreen(el.dataset.screen));
  });
  document.querySelectorAll('.nav-tabs button').forEach((btn) => {
    btn.addEventListener('click', () => setScreen(btn.dataset.screen));
  });
  document.getElementById('theme-toggle').addEventListener('click', () => {
    const root = document.documentElement;
    const next = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
  });
  document.getElementById('deployment-type').addEventListener('change', (e) => {
    const paas = e.target.value === 'paas';
    document.getElementById('paas-fields').classList.toggle('hidden', !paas);
    document.getElementById('saas-fields').classList.toggle('hidden', paas);
  });
  document.querySelectorAll('[data-check]').forEach((btn) => {
    btn.addEventListener('click', () => showCheckResult(btn.dataset.check));
  });
  document.getElementById('discovery-search').addEventListener('input', (e) => renderDiscoveryFields(e.target.value));
  document.getElementById('filter-trigger').addEventListener('click', openDrawer);
  document.getElementById('filter-close').addEventListener('click', closeDrawer);
  document.getElementById('filter-backdrop').addEventListener('click', closeDrawer);
  document.addEventListener('click', (e) => {
    const diff = e.target.closest('[data-diff]');
    if (diff) showDiffDetail(diff.dataset.diff);
  });
  document.getElementById('discovery-mode').addEventListener('change', (e) => {
    const first = e.target.value === 'first';
    document.getElementById('discovery-summary-first').classList.toggle('hidden', !first);
    document.getElementById('discovery-summary-diff').classList.toggle('hidden', first);
  });
}

document.addEventListener('DOMContentLoaded', () => {
  renderConnections();
  renderDiscoveryFields();
  renderHistory();
  showCheckResult('success');
  const hash = (window.location.hash || '#connections').replace('#', '');
  if (hash.startsWith('check-')) {
    setScreen('check');
    showCheckResult(hash.replace('check-', ''));
  } else if (hash === 'discovery-dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
    setScreen('discovery');
  } else if (hash === 'connections-dark') {
    document.documentElement.setAttribute('data-theme', 'dark');
    setScreen('connections');
  } else if (['connections', 'settings', 'check', 'discovery', 'diff', 'history'].includes(hash)) {
    setScreen(hash);
  } else {
    setScreen('connections');
  }
  bindUi();
});
