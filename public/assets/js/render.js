import { h, $ } from './dom.js';
import { fmtDuration, fmtInt, fmtNumber, fmtShort } from './format.js';
import { buildingArt, outsideArt, pennant, s, tierOf } from './art.js';
import { buildingBox, cellTop, DEPTH, footprintPoints, layout, mapSize, outsideLot } from './iso.js';
import { buildingById, definition, jobStatus, store } from './store.js';
import { canFulfill } from './ui/market.js';

export function renderTopbar() {
    const { state, view, data } = store;
    const needed = view.xp_needed;
    const percent = needed > 0 ? Math.min(100, (state.xp / needed) * 100) : 100;

    const materials = Object.entries(state.materials)
        .filter(([, quantity]) => quantity > 0)
        .map(([id, quantity]) => h('span', { title: data.materials[id]?.name ?? id }, `${data.materials[id]?.emoji ?? id} ${fmtInt(quantity)}`));

    const barnFull = view.barn.full.length > 0;

    const children = [
        h('div', { class: 'level' },
            h('span', { class: 'level-badge' }, h('span', {}, h('span', { class: 'sr' }, 'Niv. '), String(state.level))),
            h('div', { class: 'xp-bar', role: 'progressbar', 'aria-valuenow': Math.round(percent), 'aria-valuemin': 0, 'aria-valuemax': 100 },
                h('div', { style: { width: `${percent}%` } }),
                h('span', { class: 'xp-text' }, needed > 0 ? `${fmtInt(state.xp)} / ${fmtInt(needed)} XP` : 'Niveau max')),
        ),
        h('div', { class: 'wallet' },
            h('span', { class: 'res', title: data.currencies.cash.name }, `${data.currencies.cash.emoji} ${fmtInt(state.cash)}`),
            h('span', { class: 'res', title: data.currencies.gold.name }, `${data.currencies.gold.emoji} ${fmtInt(state.gold)}`),
            h('span', { class: 'res', title: 'Ouvriers libres / total' },
                `👷 ${fmtInt(view.workers.free)}/${fmtInt(view.workers.total)}`),
            h('span', {
                class: 'res',
                title: `Bonheur : coût des productions ×${fmtNumber(view.happiness.cost_factor)}`,
                style: view.happiness.value < 0 ? { color: 'var(--danger)' } : {},
            }, `😊 ${fmtInt(view.happiness.value)} (${happinessEffect(view.happiness.cost_factor)})`),
            h('span', {
                class: 'res',
                title: barnFull ? 'Grange : un produit est plein' : 'Grange : capacité par produit',
                style: barnFull ? { color: 'var(--danger)' } : {},
            }, `🛖 ${fmtInt(view.barn.capacity)}`),
        ),
    ];
    // Wallet and materials share one row that scrolls sideways on phones.
    const wallet = children.pop();
    children.push(h('div', { class: 'hud-res' }, wallet, materials.length ? h('div', { class: 'materials' }, materials) : null));
    $('#topbar').replaceChildren(...children);
}

/** "-63 %" / "+54 %" effect of happiness on production costs. */
export function happinessEffect(factor) {
    const percent = Math.round((factor - 1) * 100);
    return `${percent > 0 ? '+' : ''}${percent} %`;
}

/** Outside buildings shown along the farm, from its top corner down. */
export const OUTSIDE_ORDER = ['market', 'show_animal', 'library', 'external_barn', 'cafe'];

export function currentLayout() {
    return layout(store.view.grid.side, OUTSIDE_ORDER.length);
}

export function renderGrid() {
    const { state, view, data, mode } = store;
    const { side, block_size: blockSize, owned_blocks: ownedBlocks, next_block: nextBlock } = view.grid;
    const owned = new Set(ownedBlocks.map(([bx, by]) => `${bx},${by}`));
    const nextKey = nextBlock ? `${nextBlock[0]},${nextBlock[1]}` : null;
    const markOffset = Math.floor(blockSize / 2);
    const L = currentLayout();
    const size = mapSize(L);

    const grid = $('#grid');
    grid.style.width = `calc(var(--u) * ${size.width})`;
    grid.style.height = `calc(var(--u) * ${size.height})`;
    grid.dataset.side = side;
    grid.dataset.width = size.width;
    grid.classList.toggle('placing', mode !== null);

    // Ground: one diamond per farm cell, lock marks.
    const cells = [];
    const marks = [];
    for (let y = 0; y < side; y++) {
        for (let x = 0; x < side; x++) {
            const bx = Math.floor(x / blockSize);
            const by = Math.floor(y / blockSize);
            const key = `${bx},${by}`;
            const classes = ['cell'];
            if (!owned.has(key)) classes.push('locked');
            if (key === nextKey) {
                classes.push('next');
                if (x % blockSize === markOffset && y % blockSize === markOffset) {
                    classes.push('block-mark');
                    const [cx, cy] = cellTop(L, x, y);
                    marks.push(s('text', { x: cx, y: cy + 0.15, 'font-size': 1.1, 'text-anchor': 'middle', class: 'lock' }, '🔒'));
                }
            }
            if ((x + y) % 2 === 1) classes.push('alt');
            if ((bx + by) % 2 === 1) classes.push('alt-block');
            cells.push(s('polygon', { class: classes.join(' '), points: footprintPoints(L, x, y, 1, 1), 'data-x': x, 'data-y': y }));
        }
    }
    // The island: farm plus the outside strip, with earth edges at the front.
    const yTop = -L.out;
    const corner = (x, y) => cellTop(L, x, y).join(',');
    const [fx, fy] = cellTop(L, L.x1, side);
    const [lx, ly] = cellTop(L, 0, side);
    const [rx, ry] = cellTop(L, L.x1, yTop);
    const ground = s('svg', { class: 'ground', viewBox: `0 0 ${size.width} ${size.height}`, 'aria-hidden': 'true' },
        s('polygon', { class: 'island', points: [corner(0, yTop), corner(L.x1, yTop), corner(L.x1, side), corner(0, side)].join(' ') }),
        s('polygon', { class: 'edge-left', points: `${lx},${ly} ${fx},${fy} ${fx},${fy + DEPTH} ${lx},${ly + DEPTH}` }),
        s('polygon', { class: 'edge-right', points: `${fx},${fy} ${rx},${ry} ${rx},${ry + DEPTH} ${fx},${fy + DEPTH}` }),
        s('polygon', { class: 'path', points: footprintPoints(L, -0.2, -1.75, L.x1 + 0.2, 1.3) }),
        cells, marks, farmFence(L, side));

    const nodes = [ground];
    const tags = [];
    OUTSIDE_ORDER.forEach((type, n) => nodes.push(outsideNode(type, n, L, data)));
    for (const building of state.buildings) {
        if (building.x === undefined) continue; // outside the farm
        const { node, tag } = buildingNode(building, data, L);
        nodes.push(node);
        if (tag) tags.push(tag);
    }
    const all = collectAllTag(L);
    if (all) tags.push(all);
    const market = marketTag(L);
    if (market) tags.push(market);
    nodes.push(...tags); // banners and timers stay above every building
    // Footprint preview: follows the mouse, or marks the spot picked by a first tap on touch screens.
    const pending = mode?.pending ? ghostFor(mode, mode.pending) : null;
    nodes.push(s('svg', { class: 'ghost-layer', viewBox: `0 0 ${size.width} ${size.height}`, 'aria-hidden': 'true' },
        s('polygon', { id: 'ghost', points: pending?.points ?? '0,0', class: pending && !pending.free ? 'invalid' : null })));

    grid.replaceChildren(...nodes);
}

/**
 * Fence along the farm's two back edges; it follows the fence level
 * (wooden rails, then white pickets, then white pickets with stone pillars).
 */
function farmFence(L, side) {
    const fence = store.state.buildings.find((b) => b.type === 'fence');
    const def = fence ? definition('fence') : null;
    const tier = fence ? tierOf(fence.level, def.levels.length) : 1;
    const white = tier >= 2;
    const parts = [];
    const post = (x, y, height, pillar) => {
        const [px, py] = cellTop(L, x, y);
        if (pillar) {
            parts.push(s('rect', { x: px - 0.09, y: py - height - 0.12, width: 0.18, height: height + 0.12, fill: '#d8d2c6', stroke: '#8a8478', 'stroke-width': 0.02 }));
            parts.push(s('rect', { x: px - 0.12, y: py - height - 0.17, width: 0.24, height: 0.07, fill: '#bdb6a8' }));
        } else {
            parts.push(s('line', { x1: px, y1: py, x2: px, y2: py - height, stroke: white ? '#fffdf6' : '#8a5525', 'stroke-width': 0.06, 'stroke-linecap': 'round' }));
        }
    };
    const rail = (a, b, z) => {
        const [ax, ay] = cellTop(L, ...a);
        const [bx, by] = cellTop(L, ...b);
        parts.push(s('line', { x1: ax, y1: ay - z, x2: bx, y2: by - z, stroke: white ? '#e6dfd0' : '#a86b33', 'stroke-width': 0.05 }));
    };
    const height = 0.32;
    for (const [from, to] of [[[0, 0], [side, 0]], [[0, 0], [0, side]]]) {
        rail(from, to, height * 0.4);
        rail(from, to, height * 0.85);
        const steps = side * (white ? 3 : 1);
        for (let i = 0; i <= steps; i++) {
            const t = i / steps;
            const x = from[0] + (to[0] - from[0]) * t;
            const y = from[1] + (to[1] - from[1]) * t;
            post(x, y, height, tier >= 3 && i % 12 === 0);
        }
    }
    return s('g', { class: 'fence' }, parts);
}

/** One lot of the outside strip: the building, or an empty lot to build on. */
function outsideNode(type, n, L, data) {
    const lot = outsideLot(n);
    let def = data.buildings[type];
    let level = 1;
    let levels = def?.levels?.length ?? 1;
    let built = true;
    if (type === 'market') {
        def = { name: 'Marché', emoji: '🏪', levels: [] };
        level = store.view.market?.level?.level ?? 1;
        levels = (data.market.market_xp?.levels?.length ?? 0) + 1;
    } else {
        const building = store.state.buildings.find((b) => b.type === type);
        built = Boolean(building);
        level = building?.level ?? 1;
    }
    const { svg, top } = outsideArt(type, def, { level, levels, built });
    const box = buildingBox(L, lot.x, lot.y, lot.w, lot.h, top);

    return h('div', {
        class: `outside${built ? '' : ' empty'}`,
        dataset: { outside: type, built: built ? '1' : '' },
        title: built ? `${def.name} (niv. ${level})` : `${def.name} : à construire`,
        style: {
            left: `calc(var(--u) * ${box.left})`,
            top: `calc(var(--u) * ${box.top})`,
            width: `calc(var(--u) * ${box.width})`,
            height: `calc(var(--u) * ${box.height})`,
            'z-index': 2 + n,
        },
    }, svg);
}

/** Footprint preview of the building being placed or moved, at cell. */
export function ghostFor(mode, cell) {
    const type = mode.kind === 'build' ? mode.type : buildingById(mode.id)?.type;
    if (!type) return null;
    const [w, h_] = definition(type).size;

    return {
        points: footprintPoints(currentLayout(), cell.x, cell.y, w, h_),
        free: spotIsFree(cell, w, h_, mode.kind === 'move' ? mode.id : null),
    };
}

/** Client-side hint only (owned land, no overlap); the server decides. */
function spotIsFree(cell, w, h_, ignoreId) {
    const { side, block_size: blockSize, owned_blocks: ownedBlocks } = store.view.grid;
    const owned = new Set(ownedBlocks.map(([bx, by]) => `${bx},${by}`));
    for (let y = cell.y; y < cell.y + h_; y++) {
        for (let x = cell.x; x < cell.x + w; x++) {
            if (x >= side || y >= side || !owned.has(`${Math.floor(x / blockSize)},${Math.floor(y / blockSize)}`)) return false;
        }
    }
    return store.state.buildings.every((b) => {
        if (b.x === undefined || b.id === ignoreId) return true;
        const [bw, bh] = definition(b.type).size;
        return cell.x >= b.x + bw || b.x >= cell.x + w || cell.y >= b.y + bh || b.y >= cell.y + h_;
    });
}

const FLAT_KINDS = new Set(['field']);
const FLAT_TYPES = new Set(['meadow', 'premium_meadow', 'flowerbed']);

function buildingNode(building, data, L) {
    const def = definition(building.type);
    const [w, h_] = def.size;
    const classes = ['building', def.kind];
    const status = jobStatus(building);
    const ready = Boolean(status?.ready);
    const product = building.job ? data.items[building.job.product]?.emoji ?? null : null;
    const { svg, top } = buildingArt(building, def, { working: Boolean(building.job) && !ready, ready, product });
    const box = buildingBox(L, building.x, building.y, w, h_, top);
    const flat = FLAT_KINDS.has(def.kind) || FLAT_TYPES.has(building.type);

    if (ready) classes.push('ready');
    if (building.job && !ready) classes.push('working');
    if (store.mode?.kind === 'move' && store.mode.id === building.id) classes.push('moving');

    // Banner or timer above the footprint centre, in a layer over all buildings.
    const [centerX] = cellTop(L, building.x + w / 2, building.y + h_ / 2);
    const marker = jobMarker(building, def, status, product, data);
    const tag = marker ? h('div', {
        class: 'tags',
        dataset: { id: building.id },
        title: marker.title,
        style: { left: `calc(var(--u) * ${centerX})`, top: `calc(var(--u) * ${box.top + Math.max(0, top - 1.1)})` },
    }, marker.node) : null;

    const node = h('div', {
        class: classes.join(' '),
        dataset: { id: building.id },
        title: `${def.name} (niv. ${building.level})`,
        style: {
            left: `calc(var(--u) * ${box.left})`,
            top: `calc(var(--u) * ${box.top})`,
            width: `calc(var(--u) * ${box.width})`,
            height: `calc(var(--u) * ${box.height})`,
            'z-index': flat ? 1 : 10 + 2 * (building.x + w + building.y + h_),
        },
    }, svg);

    return { node, tag };
}

/** Does this building make things with a job (fields, non-instant production)? */
function hasJobs(def) {
    return def.kind === 'field' || (def.kind === 'production' && def.production?.mode !== 'instant');
}

/** Yellow banner when ready, timer while working, pale blue "zZ" banner when idle. */
function jobMarker(building, def, status, product, data) {
    if (!hasJobs(def)) return null;
    if (status?.ready) return { node: pennant('ready', product ?? '✓'), title: 'Prêt' };
    if (status) return { node: countdownBadge(status), title: null };

    return { node: pennant('idle', idleProduct(building, def, data)), title: 'Inactif' };
}

/** What an idle building would make: its first recipe, a crop sign for fields. */
function idleProduct(building, def, data) {
    if (def.kind === 'field') return '🌾';
    const recipes = store.view.production?.[building.id]?.recipes ?? {};
    const first = Object.keys(recipes)[0];

    return first ? data.items[first]?.emoji ?? def.emoji : def.emoji;
}

/** "Tout récolter" banner above the barn when at least two jobs are ready. */
function collectAllTag(L) {
    const ready = store.state.buildings.filter((b) => b.job && jobStatus(b)?.ready && hasJobs(definition(b.type))).length;
    const barn = store.state.buildings.find((b) => b.type === 'barn' && b.x !== undefined);
    if (ready < 2 || !barn) return null;
    const def = definition(barn.type);
    const [w, h_] = def.size;
    const [centerX] = cellTop(L, barn.x + w / 2, barn.y + h_ / 2);
    const box = buildingBox(L, barn.x, barn.y, w, h_, 2.3);

    return h('div', {
        class: 'tags collect-all',
        dataset: { collectAll: '1' },
        title: `Tout récolter (${ready})`,
        style: { left: `calc(var(--u) * ${centerX})`, top: `calc(var(--u) * ${box.top + 0.2})` },
    }, pennant('collect-all', '🛖'));
}

/** Yellow banner above the market with the number of contracts deliverable now. */
function marketTag(L) {
    const ready = store.state.contracts.filter(canFulfill).length;
    if (ready === 0) return null;
    const lot = outsideLot(OUTSIDE_ORDER.indexOf('market'));
    const [centerX, centerY] = cellTop(L, lot.x + lot.w / 2, lot.y + lot.h / 2);

    return h('div', {
        class: 'tags market-ready',
        dataset: { outside: 'market', built: '1' },
        title: `${ready} contrat(s) livrable(s)`,
        style: { left: `calc(var(--u) * ${centerX})`, top: `calc(var(--u) * ${centerY - 2.6})` },
    }, pennant('ready', '🏪'), h('span', { class: 'tab-badge' }, String(ready)));
}

function countdownBadge(status) {
    return h('span', { class: 'badge', dataset: { readyAt: status.readyAt } }, fmtShort(status.remaining));
}

/**
 * Called every second: refreshes countdown texts in place.
 * Returns true when a job just became ready (caller re-renders).
 */
export function tickCountdowns(now) {
    let becameReady = false;
    for (const el of document.querySelectorAll('[data-ready-at]')) {
        const remaining = Number(el.dataset.readyAt) - now;
        if (remaining <= 0) {
            becameReady = true;
            continue;
        }
        el.textContent = el.dataset.format === 'long' ? `${el.dataset.prefix ?? ''}${fmtDuration(remaining)}` : fmtShort(remaining);
    }

    return becameReady;
}
