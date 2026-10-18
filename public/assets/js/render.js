import { h, $ } from './dom.js';
import { fmtDuration, fmtInt, fmtNumber, fmtShort } from './format.js';
import { buildingArt, s } from './art.js';
import { buildingBox, cellTop, DEPTH, footprintPoints, mapSize } from './iso.js';
import { buildingById, definition, jobStatus, store } from './store.js';

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

export function renderGrid() {
    const { state, view, data, mode } = store;
    const { side, block_size: blockSize, owned_blocks: ownedBlocks, next_block: nextBlock } = view.grid;
    const owned = new Set(ownedBlocks.map(([bx, by]) => `${bx},${by}`));
    const nextKey = nextBlock ? `${nextBlock[0]},${nextBlock[1]}` : null;
    const markOffset = Math.floor(blockSize / 2);
    const size = mapSize(side);

    const grid = $('#grid');
    grid.style.width = `calc(var(--u) * ${size.width})`;
    grid.style.height = `calc(var(--u) * ${size.height})`;
    grid.dataset.side = side;
    grid.classList.toggle('placing', mode !== null);

    // Ground: one diamond per cell, earth edges under the land, lock marks.
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
                    const [cx, cy] = cellTop(side, x, y);
                    marks.push(s('text', { x: cx, y: cy + 0.15, 'font-size': 1.1, 'text-anchor': 'middle', class: 'lock' }, '🔒'));
                }
            }
            if ((x + y) % 2 === 1) classes.push('alt');
            if ((bx + by) % 2 === 1) classes.push('alt-block');
            cells.push(s('polygon', { class: classes.join(' '), points: footprintPoints(side, x, y, 1, 1), 'data-x': x, 'data-y': y }));
        }
    }
    const [lx, ly] = cellTop(side, 0, side);
    const [bx, by] = cellTop(side, side, side);
    const [rx, ry] = cellTop(side, side, 0);
    const ground = s('svg', { class: 'ground', viewBox: `0 0 ${size.width} ${size.height}`, 'aria-hidden': 'true' },
        s('polygon', { class: 'edge-left', points: `${lx},${ly} ${bx},${by} ${bx},${by + DEPTH} ${lx},${ly + DEPTH}` }),
        s('polygon', { class: 'edge-right', points: `${bx},${by} ${rx},${ry} ${rx},${ry + DEPTH} ${bx},${by + DEPTH}` }),
        cells, marks);

    const nodes = [ground];
    const tags = [];
    for (const building of state.buildings) {
        if (building.x === undefined) continue; // outside the farm
        const { node, tag } = buildingNode(building, data, side);
        nodes.push(node);
        if (tag) tags.push(tag);
    }
    nodes.push(...tags); // timers and ready bubbles stay above every building
    // Footprint preview: follows the mouse, or marks the spot picked by a first tap on touch screens.
    const pending = mode?.pending ? ghostFor(mode, mode.pending) : null;
    nodes.push(s('svg', { class: 'ghost-layer', viewBox: `0 0 ${size.width} ${size.height}`, 'aria-hidden': 'true' },
        s('polygon', { id: 'ghost', points: pending?.points ?? '0,0', class: pending && !pending.free ? 'invalid' : null })));

    grid.replaceChildren(...nodes);
}

/** Footprint preview of the building being placed or moved, at cell. */
export function ghostFor(mode, cell) {
    const type = mode.kind === 'build' ? mode.type : buildingById(mode.id)?.type;
    if (!type) return null;
    const [w, h_] = definition(type).size;

    return {
        points: footprintPoints(store.view.grid.side, cell.x, cell.y, w, h_),
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

function buildingNode(building, data, side) {
    const def = definition(building.type);
    const [w, h_] = def.size;
    const classes = ['building', def.kind];
    const status = jobStatus(building);
    const ready = Boolean(status?.ready);
    const product = building.job ? data.items[building.job.product]?.emoji ?? null : null;
    const { svg, top } = buildingArt(building, def, { working: Boolean(building.job) && !ready, ready, product });
    const box = buildingBox(side, building.x, building.y, w, h_, top);
    const flat = FLAT_KINDS.has(def.kind) || FLAT_TYPES.has(building.type);

    if (ready) classes.push('ready');
    if (building.job && !ready) classes.push('working');
    if (store.mode?.kind === 'move' && store.mode.id === building.id) classes.push('moving');

    // Timer / ready bubble above the footprint centre, in a layer over all buildings.
    const [centerX] = cellTop(side, building.x + w / 2, building.y + h_ / 2);
    const tag = status ? h('div', {
        class: 'tags',
        dataset: { id: building.id },
        style: { left: `calc(var(--u) * ${centerX})`, top: `calc(var(--u) * ${box.top + Math.max(0, top - 0.9)})` },
    }, countdownBadge(status, product)) : null;

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

function countdownBadge(status, product) {
    if (!status) return null;
    if (status.ready) return h('span', { class: 'badge ready-bubble', title: 'Prêt' }, product ?? '✓');

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
