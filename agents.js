/* ==========================================================================
   Smart Text — Find Your Local Agent
   Plain vanilla JS. No build step, no framework — edit this file directly.

   DATA & STORAGE
   This site is a static set of files with no server/database. Agents are
   seeded from SEED_AGENTS below, then persisted to the browser's
   localStorage so admin add/remove edits survive a page reload. That means
   changes made in one browser are NOT automatically visible to other
   visitors/devices — there's no shared backend. To ship agents that every
   visitor sees by default, edit SEED_AGENTS directly and redeploy.

   ADMIN ACCESS
   The "Agent Portal" login below is a soft, client-side gate for
   convenience — the password lives in this file, in plain text, shipped to
   every visitor's browser. Anyone who views page source can read or bypass
   it. It is NOT real security. Before relying on this for anything
   sensitive, replace it with a real backend + authentication.
   ========================================================================== */

const ADMIN_PASSWORD = 'DealerMarketing2026'; // change this, or replace with real auth

const SEED_AGENTS = [
  {
    id: 'eca-south',
    name: 'ECA South',
    region: 'United Kingdom',
    type: 'UK Agent',
    codes: ['SO', 'PO', 'BH', 'GU', 'RG', 'SP'],
    lat: 50.9097,
    lng: -1.4044,
    blurb: 'Covering Southern England — Southampton, Portsmouth, Bournemouth, Guildford, Reading & Salisbury postcode areas.',
    email: '',
    phone: '',
  },
  {
    id: 'dealer-marketing',
    name: 'Dealer Marketing',
    region: 'Ireland',
    type: 'Irish Agent',
    codes: ['IE'],
    lat: 53.3498,
    lng: -6.2603,
    blurb: 'Covering all of Ireland, nationwide.',
    email: 'dealermarketingie@gmail.com',
    phone: '',
  },
];

const STORAGE_KEY = 'smarttext_agents_v1';
const SESSION_KEY = 'smarttext_admin_unlocked';

function loadAgents() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY);
    if (raw) {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed) && parsed.length) return parsed;
    }
  } catch (err) { /* ignore malformed storage */ }
  return SEED_AGENTS.slice();
}

function persistAgents() {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(agents));
}

function slugify(str) {
  return String(str).toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '') || ('agent-' + Date.now());
}

/* ---------- State ---------- */

let agents = loadAgents();
let filterText = '';
let filterRegion = 'all';
let selectedAgentId = null;
let adminUnlocked = sessionStorage.getItem(SESSION_KEY) === '1';

let map = null;
let markerLayer = null;
const markerRefs = {};

/* ---------- Helpers ---------- */

function esc(str) {
  return String(str).replace(/[&<>"']/g, (c) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
  }[c]));
}

function distinctRegions() {
  return Array.from(new Set(agents.map((a) => a.region))).sort();
}

function matchesFilter(agent) {
  if (filterRegion !== 'all' && agent.region !== filterRegion) return false;
  const q = filterText.trim().toUpperCase();
  if (!q) return true;
  if (agent.name.toUpperCase().includes(q)) return true;
  if (agent.region.toUpperCase().includes(q)) return true;
  if (agent.type.toUpperCase().includes(q)) return true;
  return agent.codes.some((c) => {
    const code = c.toUpperCase();
    return code.startsWith(q) || q.startsWith(code);
  });
}

function filteredAgents() {
  return agents.filter(matchesFilter);
}

/* ---------- Map ---------- */

function initMap() {
  if (typeof L === 'undefined') return; // Leaflet failed to load (e.g. offline)
  map = L.map('agent-map', { scrollWheelZoom: false }).setView([52.5, -3.5], 5);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 18,
  }).addTo(map);
  markerLayer = L.layerGroup().addTo(map);
  renderMarkers();
}

function renderMarkers() {
  if (!map || !markerLayer) return;
  markerLayer.clearLayers();
  Object.keys(markerRefs).forEach((k) => delete markerRefs[k]);

  const list = filteredAgents();
  list.forEach((agent) => {
    const marker = L.marker([agent.lat, agent.lng]).addTo(markerLayer);
    marker.bindPopup(
      `<strong>${esc(agent.name)}</strong><br>${esc(agent.type)}<br>${esc(agent.blurb)}`
    );
    marker.on('click', () => selectAgent(agent.id, { fromMap: true }));
    markerRefs[agent.id] = marker;
  });

  if (list.length) {
    const bounds = L.latLngBounds(list.map((a) => [a.lat, a.lng]));
    map.fitBounds(bounds, { padding: [40, 40], maxZoom: 9 });
  }
}

function selectAgent(id, opts) {
  opts = opts || {};
  selectedAgentId = id;
  renderList();
  const agent = agents.find((a) => a.id === id);
  if (!agent) return;
  if (map && markerRefs[id] && !opts.fromMap) {
    map.setView([agent.lat, agent.lng], 10);
    markerRefs[id].openPopup();
  }
  if (!opts.fromMap) {
    const card = document.getElementById('agent-card-' + id);
    if (card) card.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
}

/* ---------- Rendering: search + list ---------- */

function renderFilters() {
  const regions = distinctRegions();
  const mount = document.getElementById('region-filter-mount');
  if (!mount) return;
  mount.innerHTML = `
    <button class="chip ${filterRegion === 'all' ? 'is-active' : ''}" data-region="all">All regions</button>
    ${regions.map((r) => `<button class="chip ${filterRegion === r ? 'is-active' : ''}" data-region="${esc(r)}">${esc(r)}</button>`).join('')}
  `;
}

function renderList() {
  const mount = document.getElementById('agent-list');
  if (!mount) return;
  const list = filteredAgents();

  if (!list.length) {
    mount.innerHTML = `<div class="agent-empty">No agents match your search. Try a different name or location code.</div>`;
    return;
  }

  mount.innerHTML = list.map((agent) => `
    <div id="agent-card-${agent.id}" class="agent-card ${selectedAgentId === agent.id ? 'is-selected' : ''}" data-agent-id="${agent.id}">
      <div class="agent-card-top">
        <div>
          <div class="agent-type">${esc(agent.type)}</div>
          <h3>${esc(agent.name)}</h3>
        </div>
        <button class="btn btn-outline btn-sm" data-action="viewOnMap" data-id="${agent.id}">View on map</button>
      </div>
      <p class="agent-blurb">${esc(agent.blurb)}</p>
      <div class="agent-meta">
        <span class="agent-region">${esc(agent.region)}</span>
        ${agent.codes.length ? `<span class="agent-codes">Codes: ${agent.codes.map(esc).join(', ')}</span>` : ''}
      </div>
      ${(agent.email || agent.phone) ? `
        <div class="agent-contact">
          ${agent.email ? `<a href="mailto:${esc(agent.email)}">${esc(agent.email)}</a>` : ''}
          ${agent.phone ? `<span>${esc(agent.phone)}</span>` : ''}
        </div>` : ''}
    </div>
  `).join('');
}

function renderAll() {
  renderFilters();
  renderList();
  renderMarkers();
}

/* ---------- Become an Agent form ---------- */

function scrollToBecomeAgent() {
  const el = document.getElementById('become-agent');
  if (!el) return;
  el.scrollIntoView({ behavior: 'smooth', block: 'start' });
  const first = el.querySelector('input');
  if (first) setTimeout(() => first.focus(), 400);
}

function validateSimpleForm(form) {
  let valid = true;
  form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
  form.querySelectorAll('.field.has-error').forEach((el) => el.classList.remove('has-error'));

  form.querySelectorAll('input[required], select[required]').forEach((input) => {
    const field = input.closest('.field');
    const errorEl = field.querySelector('.field-error');
    let message = '';
    if (!input.value.trim()) {
      message = input.tagName === 'SELECT' ? 'Please choose an option.' : 'This field is required.';
    } else if (input.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
      message = 'Enter a valid email address.';
    }
    if (message) {
      valid = false;
      field.classList.add('has-error');
      errorEl.textContent = message;
    }
  });
  return valid;
}

function handleBecomeAgentSubmit(form) {
  if (!validateSimpleForm(form)) return;
  // No backend wired up — static local file. Point this at a real endpoint
  // or form service (Formspree, Getform, etc.) when you're ready to
  // actually receive applications.
  form.querySelector('.form-fields').hidden = true;
  form.querySelector('.form-success').hidden = false;
  form.reset();
}

/* ---------- Admin panel ---------- */

function renderAdminPanel() {
  const mount = document.getElementById('admin-panel-mount');
  if (!mount) return;

  if (!adminUnlocked) {
    mount.innerHTML = `
      <form class="admin-login" data-action="adminLogin">
        <label class="field field-inline">
          <span>Admin password</span>
          <input type="password" name="password" autocomplete="off" required>
        </label>
        <button type="submit" class="btn btn-primary btn-sm">Unlock admin panel</button>
        <span class="admin-login-error"></span>
      </form>`;
    return;
  }

  mount.innerHTML = `
    <div class="admin-panel">
      <div class="admin-panel-header">
        <div>
          <div class="admin-panel-title">Manage agents</div>
          <p class="admin-panel-note">Changes save to this browser only (localStorage) — see note above.</p>
        </div>
        <button class="btn btn-outline btn-sm" data-action="adminLogout">Log out</button>
      </div>

      <div class="admin-agent-rows">
        ${agents.map((a) => `
          <div class="admin-agent-row">
            <div>
              <b>${esc(a.name)}</b>
              <span class="admin-agent-row-meta">${esc(a.type)} · ${esc(a.region)} · ${esc(a.codes.join(', '))}</span>
            </div>
            <button class="btn btn-outline btn-sm btn-danger" data-action="adminRemoveAgent" data-id="${a.id}">Remove</button>
          </div>
        `).join('') || '<p class="admin-panel-note">No agents yet.</p>'}
      </div>

      <div class="admin-add-form-wrap">
        <div class="admin-panel-title admin-panel-title-sm">Add a new agent</div>
        <form class="admin-add-form" data-action="adminAddAgent">
          <div class="field-row">
            <label class="field">
              <span>Agent / company name</span>
              <input type="text" name="name" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Type / label</span>
              <input type="text" name="type" placeholder="e.g. UK Agent" required>
              <span class="field-error"></span>
            </label>
          </div>
          <div class="field-row">
            <label class="field">
              <span>Region / country</span>
              <input type="text" name="region" placeholder="e.g. United Kingdom" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Location codes (comma separated)</span>
              <input type="text" name="codes" placeholder="e.g. SO, PO, BH">
              <span class="field-error"></span>
            </label>
          </div>
          <div class="field-row">
            <label class="field">
              <span>Latitude</span>
              <input type="text" name="lat" placeholder="e.g. 50.9097" required>
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Longitude</span>
              <input type="text" name="lng" placeholder="e.g. -1.4044" required>
              <span class="field-error"></span>
            </label>
          </div>
          <p class="admin-panel-hint">Tip: find coordinates by right-clicking a location on Google Maps and copying the numbers shown, or use latlong.net.</p>
          <label class="field">
            <span>Short description</span>
            <input type="text" name="blurb" placeholder="What area/market do they cover?">
            <span class="field-error"></span>
          </label>
          <div class="field-row">
            <label class="field">
              <span>Contact email (optional)</span>
              <input type="email" name="email">
              <span class="field-error"></span>
            </label>
            <label class="field">
              <span>Contact phone (optional)</span>
              <input type="text" name="phone">
              <span class="field-error"></span>
            </label>
          </div>
          <button type="submit" class="btn btn-primary btn-sm">Add agent</button>
        </form>
      </div>

      <button class="btn btn-outline btn-sm" data-action="adminReset">Reset to default agents</button>
    </div>`;
}

function validateAdminAddForm(form) {
  let valid = true;
  form.querySelectorAll('.field-error').forEach((el) => { el.textContent = ''; });
  form.querySelectorAll('.field.has-error').forEach((el) => el.classList.remove('has-error'));

  form.querySelectorAll('input[required]').forEach((input) => {
    const field = input.closest('.field');
    const errorEl = field.querySelector('.field-error');
    let message = '';
    if (!input.value.trim()) {
      message = 'This field is required.';
    } else if ((input.name === 'lat' || input.name === 'lng') && isNaN(parseFloat(input.value))) {
      message = 'Enter a valid number.';
    } else if (input.type === 'email' && input.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
      message = 'Enter a valid email address.';
    }
    if (message) {
      valid = false;
      field.classList.add('has-error');
      errorEl.textContent = message;
    }
  });
  return valid;
}

function handleAdminAddAgent(form) {
  if (!validateAdminAddForm(form)) return;
  const data = new FormData(form);
  const name = data.get('name').trim();
  const newAgent = {
    id: slugify(name) + '-' + Math.random().toString(36).slice(2, 6),
    name,
    type: data.get('type').trim(),
    region: data.get('region').trim(),
    codes: (data.get('codes') || '').split(',').map((c) => c.trim()).filter(Boolean),
    lat: parseFloat(data.get('lat')),
    lng: parseFloat(data.get('lng')),
    blurb: (data.get('blurb') || '').trim(),
    email: (data.get('email') || '').trim(),
    phone: (data.get('phone') || '').trim(),
  };
  agents.push(newAgent);
  persistAgents();
  renderAdminPanel();
  renderAll();
}

function handleAdminRemoveAgent(id) {
  agents = agents.filter((a) => a.id !== id);
  persistAgents();
  renderAdminPanel();
  renderAll();
}

function handleAdminReset() {
  agents = SEED_AGENTS.slice();
  localStorage.removeItem(STORAGE_KEY);
  renderAdminPanel();
  renderAll();
}

function handleAdminLogin(form) {
  const input = form.querySelector('input[name="password"]');
  const errorEl = form.querySelector('.admin-login-error');
  if (input.value === ADMIN_PASSWORD) {
    adminUnlocked = true;
    sessionStorage.setItem(SESSION_KEY, '1');
    errorEl.textContent = '';
    renderAdminPanel();
  } else {
    errorEl.textContent = 'Incorrect password.';
  }
}

function handleAdminLogout() {
  adminUnlocked = false;
  sessionStorage.removeItem(SESSION_KEY);
  renderAdminPanel();
}

/* ---------- Event delegation ---------- */

document.addEventListener('click', (e) => {
  const chip = e.target.closest('[data-region]');
  if (chip) {
    filterRegion = chip.getAttribute('data-region');
    renderAll();
    return;
  }

  const actionEl = e.target.closest('[data-action]');
  if (!actionEl) return;
  const action = actionEl.getAttribute('data-action');

  if (action === 'viewOnMap') {
    selectAgent(actionEl.getAttribute('data-id'));
  } else if (action === 'scrollToBecomeAgent') {
    scrollToBecomeAgent();
  } else if (action === 'adminRemoveAgent') {
    if (confirm('Remove this agent?')) handleAdminRemoveAgent(actionEl.getAttribute('data-id'));
  } else if (action === 'adminReset') {
    if (confirm('Reset agents to the built-in defaults? This clears any local changes.')) handleAdminReset();
  } else if (action === 'adminLogout') {
    handleAdminLogout();
  }
});

document.addEventListener('click', (e) => {
  const card = e.target.closest('.agent-card');
  if (card && !e.target.closest('[data-action]')) {
    selectAgent(card.getAttribute('data-agent-id'));
  }
});

document.addEventListener('submit', (e) => {
  const becomeForm = e.target.closest('#become-agent form');
  if (becomeForm) {
    e.preventDefault();
    handleBecomeAgentSubmit(becomeForm);
    return;
  }
  const loginForm = e.target.closest('form[data-action="adminLogin"]');
  if (loginForm) {
    e.preventDefault();
    handleAdminLogin(loginForm);
    return;
  }
  const addForm = e.target.closest('form[data-action="adminAddAgent"]');
  if (addForm) {
    e.preventDefault();
    handleAdminAddAgent(addForm);
    return;
  }
});

document.addEventListener('input', (e) => {
  if (e.target.id === 'agent-search') {
    filterText = e.target.value;
    renderList();
    renderMarkers();
  }
});

/* ---------- Boot ---------- */

renderAll();
renderAdminPanel();
if (typeof L !== 'undefined') initMap();
