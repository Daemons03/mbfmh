import { perform, refresh, setUnauthenticatedHandler } from './actions.js';
import { api, ApiError } from './api.js';
import { h, $ } from './dom.js';
import { cellAt, cellTop } from './iso.js';
import { currentLayout, ghostFor, renderGrid, renderTopbar, tickCountdowns } from './render.js';
import { applyResponse, buildingById, definition, jobStatus, notify, serverNow, store, subscribe } from './store.js';
import { barnPanel } from './ui/barn.js';
import { buildPanel, setBuildTab } from './ui/build.js';
import { adminPanel } from './ui/admin.js';
import { buildingPanel } from './ui/building.js';
import { cafePanel } from './ui/cafe.js';
import { showPanel } from './ui/show.js';
import { landPanel } from './ui/land.js';
import { tournamentPanel } from './ui/tournament.js';
import { libraryPanel } from './ui/library.js';
import { marketPanel } from './ui/market.js';
import { closeSheet, initSheet, openSheet, refreshSheet } from './ui/sheet.js';
import { loadSprites } from './sprites.js';
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
    const [dataResponse, stateResponse] = await Promise.all([api.data(), api.state(), loadSprites()]);
    store.data = dataResponse.data;
    applyResponse(stateResponse);
    showScreen('app');
    notify();
    centerOnFarm();
    startTimers();
}

// ------------------------------------------------------------ modes

function setMode(mode) {
    store.mode = mode;
    notify();
}

function startPlacement(type) {
    setMode({ kind: 'build', type, rotated: false });
}

function startMove(id) {
    setMode({ kind: 'move', id, rotated: Boolean(buildingById(id)?.rotated) });
}

function toggleRotation() {
    setMode({ ...store.mode, rotated: !store.mode.rotated });
}

function renderModeBanner() {
    const banner = $('#mode-banner');
    const { mode } = store;
    if (!mode) {
        banner.hidden = true;
        return;
    }
    const def = mode.kind === 'build' ? definition(mode.type) : definition(buildingById(mode.id)?.type);
    let text = mode.kind === 'build'
        ? `Touchez une case pour placer : ${def.emoji} ${def.name}`
        : `Touchez la nouvelle position : ${def.emoji} ${def.name}`;
    if (mode.pending) text = `${def.emoji} ${def.name} ici ?`;
    banner.replaceChildren(
        h('span', {}, text),
        h('button', { type: 'button', class: 'btn', title: 'Pivoter d\'un quart de tour', onclick: toggleRotation }, '↻ Pivoter'),
        mode.pending ? h('button', { type: 'button', class: 'btn btn-go', onclick: () => place(mode.pending) }, '✔ Valider') : null,
        h('button', { type: 'button', class: 'btn', onclick: () => setMode(null) }, 'Annuler'),
    );
    banner.hidden = false;
}

// ------------------------------------------------------------ grid input

/** Size of one unit (half a tile width) in screen pixels. */
function unitPx() {
    const grid = $('#grid');
    return grid.getBoundingClientRect().width / Number(grid.dataset.width);
}

function cellFromEvent(event) {
    const rect = $('#grid').getBoundingClientRect();
    const u = unitPx();

    return cellAt(currentLayout(), (event.clientX - rect.left) / u, (event.clientY - rect.top) / u);
}

/** Footprint preview under the mouse while placing or moving: green when the spot is free. */
function onGridHover(event) {
    const ghost = $('#ghost');
    if (!ghost || event.pointerType === 'touch' || !store.mode) return;
    const cell = cellFromEvent(event);
    const preview = cell ? ghostFor(store.mode, cell) : null;
    ghost.setAttribute('points', preview?.points ?? '0,0');
    ghost.classList.toggle('invalid', Boolean(preview) && !preview.free);
}

async function place(cell) {
    const { mode } = store;
    const rotated = Boolean(mode.rotated);
    const ok = mode.kind === 'build'
        ? await perform('build', { type: mode.type, ...cell, ...(rotated ? { rotated } : {}) })
        : await perform('move', { id: mode.id, ...cell, rotated });
    if (ok) setMode(null);
}

function cellClasses(cell) {
    return $(`#grid .cell[data-x="${cell.x}"][data-y="${cell.y}"]`)?.classList;
}

/** Scrolls the map so the owned land is in view (first display). */
function centerOnFarm() {
    const { block_size: blockSize, owned_blocks: ownedBlocks } = store.view.grid;
    const xs = ownedBlocks.map(([bx]) => bx);
    const ys = ownedBlocks.map(([, by]) => by);
    const cx = ((Math.min(...xs) + Math.max(...xs) + 1) / 2) * blockSize;
    const cy = ((Math.min(...ys) + Math.max(...ys) + 1) / 2) * blockSize;
    const [ux, uy] = cellTop(currentLayout(), cx, cy);
    const map = $('#map');
    const u = unitPx();
    const gridRect = $('#grid').getBoundingClientRect();
    const mapRect = map.getBoundingClientRect();
    map.scrollLeft += gridRect.left - mapRect.left + ux * u - map.clientWidth / 2;
    map.scrollTop += gridRect.top - mapRect.top + uy * u - map.clientHeight / 2;
}

async function onGridClick(event) {
    if (!store.mode && event.target.closest('[data-collect-all]')) {
        await perform('collect_all', {});
        return;
    }
    const outside = store.mode ? null : event.target.closest('.outside, [data-outside]');
    if (outside) {
        const tabs = { market: 'market', show_animal: 'show', library: 'library', external_barn: 'barn', cafe: 'cafe' };
        const type = outside.dataset.outside;
        const building = store.state.buildings.find((b) => b.type === type);
        if (!outside.dataset.built) {
            setBuildTab('outside');
            openTab('build');
        } else if (tabs[type]) openTab(tabs[type]);
        else openSheet(buildingPanel(building.id, { startMove })); // sewing workshop: its own panel
        return;
    }
    const cell = cellFromEvent(event);
    const buildingEl = event.target.closest('.building, .tags');
    if (!cell && (store.mode || !buildingEl)) return;

    if (store.mode) {
        const { pending } = store.mode;
        // On touch screens the first tap only shows the footprint; tap it again (or Valider) to place.
        if (event.pointerType === 'touch' && !(pending && pending.x === cell.x && pending.y === cell.y)) {
            setMode({ ...store.mode, pending: cell });
            return;
        }
        await place(cell);
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
        cafe: () => cafePanel(),
        show: () => showPanel(),
        library: () => libraryPanel(),
        tournament: () => tournamentPanel(),
        land: () => landPanel(),
        admin: () => adminPanel(() => h('section', { class: 'admin-section' },
            h('p', { class: 'section-title' }, 'Compte'),
            h('div', { class: 'actions' },
                h('a', { class: 'btn', href: 'admin.html' }, '🖥️ Page admin complète'),
                h('button', { type: 'button', class: 'btn btn-danger', onclick: logout }, 'Se déconnecter')))),
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
    const map = $('#map');
    const before = { x: map.scrollLeft + map.clientWidth / 2, y: map.scrollTop + map.clientHeight / 2, w: map.scrollWidth, h: map.scrollHeight };
    document.documentElement.style.setProperty('--cell', `${ZOOM_LEVELS[zoomIndex]}px`);
    if (before.w > 0) {
        map.scrollLeft = (before.x * map.scrollWidth) / before.w - map.clientWidth / 2;
        map.scrollTop = (before.y * map.scrollHeight) / before.h - map.clientHeight / 2;
    }
    $('#zoom-in').disabled = zoomIndex === ZOOM_LEVELS.length - 1;
    $('#zoom-out').disabled = zoomIndex === 0;
    try {
        localStorage.setItem(ZOOM_KEY, String(ZOOM_LEVELS[zoomIndex]));
    } catch {
        // storage unavailable: zoom just isn't remembered
    }
}

/** Two-finger pinch on the map: one zoom step each time the spread changes by 25 %. */
function wirePinchZoom() {
    const map = $('#map');
    let base = null;
    const spread = (touches) => Math.hypot(touches[0].clientX - touches[1].clientX, touches[0].clientY - touches[1].clientY);
    map.addEventListener('touchstart', (event) => {
        if (event.touches.length === 2) base = spread(event.touches);
    }, { passive: true });
    map.addEventListener('touchmove', (event) => {
        if (event.touches.length !== 2 || base === null) return;
        const ratio = spread(event.touches) / base;
        if (ratio > 1.25 || ratio < 0.8) {
            zoom(ratio > 1 ? 1 : -1);
            base = spread(event.touches);
        }
    }, { passive: true });
    map.addEventListener('touchend', (event) => {
        if (event.touches.length < 2) base = null;
    });
    // Ctrl + wheel (and trackpad pinch) on computers.
    let wheel = 0;
    map.addEventListener('wheel', (event) => {
        if (!event.ctrlKey) return;
        event.preventDefault();
        wheel += event.deltaY;
        if (Math.abs(wheel) > 60) {
            zoom(wheel < 0 ? 1 : -1);
            wheel = 0;
        }
    }, { passive: false });
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
        refreshSheet();
    });
    setUnauthenticatedHandler(() => showLogin('Session expirée, reconnectez-vous.'));

    $('#grid').addEventListener('click', onGridClick);
    $('#grid').addEventListener('pointermove', onGridHover);
    $('#zoom-in').addEventListener('click', () => zoom(1));
    $('#zoom-out').addEventListener('click', () => zoom(-1));
    wirePinchZoom();
    for (const button of document.querySelectorAll('#tabs button')) {
        button.addEventListener('click', () => openTab(button.dataset.tab));
    }
    document.addEventListener('sheet:closed', () => {
        for (const button of document.querySelectorAll('#tabs button')) button.removeAttribute('aria-current');
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && store.mode) setMode(null);
        if ((event.key === 'r' || event.key === 'R') && store.mode && !event.target.matches?.('input, textarea')) toggleRotation();
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
