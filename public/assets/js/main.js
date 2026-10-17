import { perform, refresh, setUnauthenticatedHandler } from './actions.js';
import { api, ApiError } from './api.js';
import { h, $ } from './dom.js';
import { renderGrid, renderTopbar, tickCountdowns } from './render.js';
import { applyResponse, buildingById, definition, jobStatus, notify, serverNow, store, subscribe } from './store.js';
import { barnPanel } from './ui/barn.js';
import { buildPanel } from './ui/build.js';
import { buildingPanel } from './ui/building.js';
import { landPanel } from './ui/land.js';
import { canFulfill, marketPanel } from './ui/market.js';
import { closeSheet, initSheet, openSheet, refreshSheet } from './ui/sheet.js';
import { soonPanel } from './ui/soon.js';
import { toast } from './ui/toast.js';

const SYNC_INTERVAL_MS = 30_000;
const ZOOM_LEVELS = [28, 36, 44, 56, 72];
const ZOOM_KEY = 'ft.zoom';

let timersStarted = false;

// ------------------------------------------------------------ screens

function showScreen(name) {
    $('#loading').hidden = name !== 'loading';
    $('#login').hidden = name !== 'login';
    $('#app').hidden = name !== 'app';
}

function showLogin(message = null) {
    closeSheet();
    showScreen('login');
    const error = $('#login-error');
    error.hidden = message === null;
    error.textContent = message ?? '';
    $('#login-form [name=login]').focus();
}

async function startGame() {
    const [dataResponse, stateResponse] = await Promise.all([api.data(), api.state()]);
    store.data = dataResponse.data;
    applyResponse(stateResponse);
    showScreen('app');
    notify();
    startTimers();
}

// ------------------------------------------------------------ modes

function setMode(mode) {
    store.mode = mode;
    notify();
}

function startPlacement(type) {
    setMode({ kind: 'build', type });
}

function startMove(id) {
    setMode({ kind: 'move', id });
}

/** Badge on the market tab: contracts deliverable right now. */
function renderTabBadges() {
    const ready = store.state.contracts.filter(canFulfill).length;
    const button = $('#tabs [data-tab=market]');
    button.querySelector('.tab-badge')?.remove();
    if (ready > 0) button.append(h('span', { class: 'tab-badge', 'aria-label': `${ready} contrat(s) livrable(s)` }, ready));
}

function renderModeBanner() {
    const banner = $('#mode-banner');
    const { mode } = store;
    if (!mode) {
        banner.hidden = true;
        return;
    }
    const def = mode.kind === 'build' ? definition(mode.type) : definition(buildingById(mode.id)?.type);
    const text = mode.kind === 'build'
        ? `Touchez une case pour placer : ${def.emoji} ${def.name}`
        : `Touchez la nouvelle position : ${def.emoji} ${def.name}`;
    banner.replaceChildren(
        h('span', {}, text),
        h('button', { type: 'button', class: 'btn', onclick: () => setMode(null) }, 'Annuler'),
    );
    banner.hidden = false;
}

// ------------------------------------------------------------ grid input

function cellFromEvent(event) {
    const grid = $('#grid');
    const rect = grid.getBoundingClientRect();
    const side = store.view.grid.side;
    const x = Math.floor(((event.clientX - rect.left) / rect.width) * side);
    const y = Math.floor(((event.clientY - rect.top) / rect.height) * side);
    if (x < 0 || y < 0 || x >= side || y >= side) return null;

    return { x, y };
}

function cellClasses(cell) {
    return $(`#grid .cell[data-x="${cell.x}"][data-y="${cell.y}"]`)?.classList;
}

async function onGridClick(event) {
    const cell = cellFromEvent(event);
    if (!cell) return;
    const buildingEl = event.target.closest('.building');

    if (store.mode) {
        const { mode } = store;
        const ok = mode.kind === 'build'
            ? await perform('build', { type: mode.type, ...cell })
            : await perform('move', { id: mode.id, ...cell });
        if (ok) setMode(null);
        return;
    }

    if (buildingEl) {
        const building = buildingById(buildingEl.dataset.id);
        if (!building) return;
        const kind = definition(building.type).kind;
        if (jobStatus(building)?.ready && (kind === 'field' || kind === 'production')) {
            await perform(kind === 'field' ? 'harvest' : 'collect', { id: building.id }); // one-tap pick up
            return;
        }
        openSheet(buildingPanel(building.id, { startMove }));
        return;
    }

    const classes = cellClasses(cell);
    if (classes?.contains('next')) {
        openTab('land');
        return;
    }
    if (classes?.contains('locked')) {
        toast('Achetez d\'abord le terrain marqué 🔒 (onglet Terrain).', 'warn');
        return;
    }
    openSheet(buildPanel({ cell, startPlacement }));
}

// ------------------------------------------------------------ tabs

function openTab(tab) {
    setMode(null);
    const panels = {
        build: () => buildPanel({ startPlacement }),
        barn: () => barnPanel(),
        market: () => marketPanel(),
        land: () => landPanel(),
        admin: () => soonPanel('⚙️ Admin', 'Le panneau admin arrive bientôt.', () => h('div', { class: 'actions' },
            h('button', { type: 'button', class: 'btn btn-danger', onclick: logout }, 'Se déconnecter'))),
    };
    openSheet(panels[tab]());
    for (const button of document.querySelectorAll('#tabs button')) {
        button.setAttribute('aria-current', String(button.dataset.tab === tab));
    }
}

async function logout() {
    try {
        await api.logout();
    } catch {
        // session already gone
    }
    store.state = null;
    showLogin();
}

// ------------------------------------------------------------ zoom

function readZoom() {
    try {
        const saved = Number(localStorage.getItem(ZOOM_KEY));
        return ZOOM_LEVELS.includes(saved) ? ZOOM_LEVELS.indexOf(saved) : 2;
    } catch {
        return 2;
    }
}

let zoomIndex = readZoom();

function applyZoom() {
    document.documentElement.style.setProperty('--cell', `${ZOOM_LEVELS[zoomIndex]}px`);
    $('#zoom-in').disabled = zoomIndex === ZOOM_LEVELS.length - 1;
    $('#zoom-out').disabled = zoomIndex === 0;
    try {
        localStorage.setItem(ZOOM_KEY, String(ZOOM_LEVELS[zoomIndex]));
    } catch {
        // storage unavailable: zoom just isn't remembered
    }
}

function zoom(delta) {
    zoomIndex = Math.min(ZOOM_LEVELS.length - 1, Math.max(0, zoomIndex + delta));
    applyZoom();
}

// ------------------------------------------------------------ timers

function startTimers() {
    if (timersStarted) return;
    timersStarted = true;

    setInterval(() => {
        if (!store.state || $('#app').hidden) return;
        if (tickCountdowns(serverNow())) notify();
    }, 1000);

    setInterval(() => {
        if (store.state && !document.hidden) refresh();
    }, SYNC_INTERVAL_MS);

    document.addEventListener('visibilitychange', () => {
        if (store.state && !document.hidden) refresh();
    });
}

// ------------------------------------------------------------ boot

function wire() {
    initSheet();
    subscribe(() => {
        if (!store.state) return;
        renderTopbar();
        renderGrid();
        renderModeBanner();
        renderTabBadges();
        refreshSheet();
    });
    setUnauthenticatedHandler(() => showLogin('Session expirée, reconnectez-vous.'));

    $('#grid').addEventListener('click', onGridClick);
    $('#zoom-in').addEventListener('click', () => zoom(1));
    $('#zoom-out').addEventListener('click', () => zoom(-1));
    for (const button of document.querySelectorAll('#tabs button')) {
        button.addEventListener('click', () => openTab(button.dataset.tab));
    }
    document.addEventListener('sheet:closed', () => {
        for (const button of document.querySelectorAll('#tabs button')) button.removeAttribute('aria-current');
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && store.mode) setMode(null);
    });

    $('#login-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('button[type=submit]');
        submit.disabled = true;
        try {
            await api.session(); // fresh CSRF token for this session
            await api.login(form.elements.login.value.trim(), form.elements.password.value);
            form.elements.password.value = '';
            await startGame();
        } catch (error) {
            showLogin(error instanceof ApiError ? error.message : 'Une erreur est survenue.');
        } finally {
            submit.disabled = false;
        }
    });
}

async function boot() {
    wire();
    applyZoom();
    try {
        const session = await api.session();
        if (session.authenticated) await startGame();
        else showLogin();
    } catch (error) {
        $('#loading').textContent = error instanceof ApiError ? error.message : 'Impossible de charger le jeu.';
    }
}

boot();
