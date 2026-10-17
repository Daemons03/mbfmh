import { h, $ } from './dom.js';
import { fmtDuration, fmtInt, fmtShort } from './format.js';
import { definition, jobStatus, store } from './store.js';

export function renderTopbar() {
    const { state, view, data } = store;
    const needed = view.xp_needed;
    const percent = needed > 0 ? Math.min(100, (state.xp / needed) * 100) : 100;

    const materials = Object.entries(state.materials)
        .filter(([, quantity]) => quantity > 0)
        .map(([id, quantity]) => h('span', { title: data.materials[id]?.name ?? id }, `${data.materials[id]?.emoji ?? id} ${fmtInt(quantity)}`));

    const barnFull = view.barn.used >= view.barn.capacity;

    const children = [
        h('div', { class: 'level' },
            h('span', { class: 'level-badge' }, `Niv. ${state.level}`),
            h('div', { class: 'xp-bar', role: 'progressbar', 'aria-valuenow': Math.round(percent), 'aria-valuemin': 0, 'aria-valuemax': 100 },
                h('div', { style: { width: `${percent}%` } })),
            h('span', { class: 'xp-text' }, needed > 0 ? `${fmtInt(state.xp)} / ${fmtInt(needed)} XP` : 'Niveau max'),
        ),
        h('div', { class: 'wallet' },
            h('span', { class: 'res', title: data.currencies.cash.name }, `${data.currencies.cash.emoji} ${fmtInt(state.cash)}`),
            h('span', { class: 'res', title: data.currencies.gold.name }, `${data.currencies.gold.emoji} ${fmtInt(state.gold)}`),
            h('span', { class: 'res', title: 'Grange', style: barnFull ? { color: 'var(--danger)' } : {} },
                `🛖 ${fmtInt(view.barn.used)}/${fmtInt(view.barn.capacity)}`),
        ),
    ];
    if (materials.length) children.push(h('div', { class: 'materials' }, materials));
    $('#topbar').replaceChildren(...children);
}

export function renderGrid() {
    const { state, view, data, mode } = store;
    const { side, block_size: blockSize, owned_blocks: ownedBlocks, next_block: nextBlock } = view.grid;
    const owned = new Set(ownedBlocks.map(([bx, by]) => `${bx},${by}`));
    const nextKey = nextBlock ? `${nextBlock[0]},${nextBlock[1]}` : null;
    const markOffset = Math.floor(blockSize / 2);

    const grid = $('#grid');
    grid.style.gridTemplateColumns = `repeat(${side}, var(--cell))`;
    grid.style.gridTemplateRows = `repeat(${side}, var(--cell))`;
    grid.classList.toggle('placing', mode !== null);

    const nodes = [];
    for (let y = 0; y < side; y++) {
        for (let x = 0; x < side; x++) {
            const bx = Math.floor(x / blockSize);
            const by = Math.floor(y / blockSize);
            const key = `${bx},${by}`;
            const classes = ['cell'];
            if (!owned.has(key)) classes.push('locked');
            if (key === nextKey) {
                classes.push('next');
                if (x % blockSize === markOffset && y % blockSize === markOffset) classes.push('block-mark');
            }
            if ((bx + by) % 2 === 1) classes.push('alt');
            nodes.push(h('div', {
                class: classes.join(' '),
                dataset: { x, y },
                style: { 'grid-column': x + 1, 'grid-row': y + 1 },
            }));
        }
    }

    for (const building of state.buildings) {
        if (building.x === undefined) continue; // outside the farm
        nodes.push(buildingNode(building, data));
    }

    grid.replaceChildren(...nodes);
}

function buildingNode(building, data) {
    const def = definition(building.type);
    const [w, h_] = def.size;
    const classes = ['building', def.kind];
    const children = [];
    const status = jobStatus(building);

    if (def.kind === 'field') {
        if (building.job) {
            const crop = data.items[building.job.product];
            children.push(h('span', { class: `crop${status?.ready ? '' : ' growing'}` }, crop?.emoji ?? '🌱'));
            children.push(countdownBadge(status));
            if (status?.ready) classes.push('ready');
        }
    } else {
        children.push(h('span', {}, def.emoji));
        children.push(h('span', { class: 'lvl' }, building.level));
        if (building.job) {
            children.push(countdownBadge(status));
            if (status?.ready) classes.push('ready');
        }
    }
    if (store.mode?.kind === 'move' && store.mode.id === building.id) classes.push('moving');

    return h('div', {
        class: classes.join(' '),
        dataset: { id: building.id },
        title: `${def.name} (niv. ${building.level})`,
        style: { 'grid-column': `${building.x + 1} / span ${w}`, 'grid-row': `${building.y + 1} / span ${h_}` },
    }, children);
}

function countdownBadge(status) {
    if (!status) return null;
    if (status.ready) return h('span', { class: 'badge' }, 'Prêt');

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
