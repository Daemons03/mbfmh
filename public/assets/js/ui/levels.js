import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { productionCost, store } from '../store.js';
import { refreshSheet } from './sheet.js';

/** Folds left open and the resource tab chosen per building type, kept while the panel re-renders. */
const opened = new Set();
const chosen = new Map();

/**
 * Two folds under a building: the cost of the levels to come, and what it
 * makes at each level (one tab per resource when it makes several).
 */
export function levelBlocks(building, def) {
    const production = productionTable(building, def);
    return [
        fold('costs', 'Coût des niveaux suivants', costTable(building, def)),
        production ? fold('production', 'Production par niveau', production) : null,
    ];
}

function fold(key, title, body) {
    return h('details', {
        class: 'fold',
        open: opened.has(key),
        ontoggle: (event) => (event.currentTarget.open ? opened.add(key) : opened.delete(key)),
    }, h('summary', {}, title), body);
}

function table(headers, rows) {
    return h('div', { class: 'table-wrap' },
        h('table', { class: 'levels' },
            h('thead', {}, h('tr', {}, headers.map((cell) => h('th', {}, cell)))),
            h('tbody', {}, rows)));
}

function costTable(building, def) {
    const next = def.levels.slice(building.level);
    if (!next.length) return h('p', { class: 'muted' }, 'Niveau maximum atteint.');
    const happiness = def.levels.some((l) => l.happiness);
    const workers = def.levels.some((l) => l.workers);

    return table(
        ['Niv.', 'Joueur', 'Coût', workers ? '👷' : null, happiness ? '😊' : null].filter(Boolean),
        next.map((level) => h('tr', {},
            h('td', {}, fmtInt(level.level)),
            h('td', {}, level.unlock_level ? `niv. ${fmtInt(level.unlock_level)}` : '—'),
            h('td', {}, fmtCost(level.cost, store.data)),
            workers ? h('td', {}, workersText(level.workers)) : null,
            happiness ? h('td', {}, signed(level.happiness ?? 0)) : null)),
    );
}

/** "Loge 10" for houses, "Emploie 5" for buildings. */
function workersText(workers = 0) {
    if (workers > 0) return `Loge ${fmtInt(workers)}`;
    if (workers < 0) return `Emploie ${fmtInt(-workers)}`;
    return '—';
}

function signed(n) {
    return `${n > 0 ? '+' : ''}${fmtInt(n)}`;
}

/** What the building makes per level, or null when it makes nothing. */
function productionTable(building, def) {
    if (def.kind === 'field') return fieldYields(building, def);
    if (def.levels.some((l) => l.recipes)) return recipeTable(building, def);
    if (def.levels.some((l) => l.capacity)) {
        return table(['Niv.', 'Capacité par produit'], def.levels.map((l) => row(building, l, [fmtInt(l.capacity)])));
    }
    if (def.kind === 'house' || def.levels.some((l) => (l.workers ?? 0) > 0)) {
        return table(['Niv.', 'Ouvriers logés', '😊'], def.levels.map((l) => row(building, l, [fmtInt(Math.max(0, l.workers ?? 0)), signed(l.happiness ?? 0)])));
    }
    if (def.levels.some((l) => l.happiness)) {
        return table(['Niv.', '😊 Bonheur'], def.levels.map((l) => row(building, l, [signed(l.happiness ?? 0)])));
    }
    return null;
}

function row(building, level, cells) {
    return h('tr', { class: level.level === building.level ? 'current' : null },
        h('td', {}, fmtInt(level.level)),
        cells.map((cell) => h('td', {}, cell)));
}

/** Resource tabs: one button per product. */
function resourceTabs(type, ids, current) {
    if (ids.length < 2) return null;
    const { items } = store.data;
    return h('div', { class: 'restabs', role: 'tablist' }, ids.map((id) => h('button', {
        type: 'button',
        role: 'tab',
        class: `restab${id === current ? ' active' : ''}`,
        title: items[id].name,
        'aria-label': items[id].name,
        'aria-selected': id === current ? 'true' : 'false',
        onclick: () => {
            chosen.set(type, id);
            refreshSheet();
        },
    }, items[id].emoji)));
}

function pick(type, ids) {
    const current = chosen.get(type);
    return ids.includes(current) ? current : ids[0];
}

/** Harvest per field level for each crop (same rounding as the server). */
function fieldYields(building, def) {
    const { data } = store;
    const factor = def.yield_factor ?? 1;
    const up = (value) => Math.ceil(Number((value * factor).toFixed(6)));
    const ids = Object.keys(data.crops).sort((a, b) => data.items[a].unlock_level - data.items[b].unlock_level);
    const id = pick(building.type, ids);
    const crop = data.crops[id];
    const item = data.items[id];
    const nectar = (crop.flower_nectar ?? []).some((n) => n > 0);
    const at = (list, i) => list[Math.min(i, list.length - 1)] ?? 0;

    return [
        resourceTabs(building.type, ids, id),
        h('p', { class: 'row-sub' }, `${item.emoji} ${item.name} · ${fmtDuration(crop.duration / store.state.settings.speed)} · culture débloquée au niveau ${fmtInt(item.unlock_level)}`),
        table(
            ['Niv.', 'Récolte', nectar ? data.items.flower_nectar.emoji : null].filter(Boolean),
            def.levels.map((level, i) => row(building, level, [
                `${fmtInt(up(at(crop.yields, i)))} ${item.emoji}`,
                nectar ? fmtInt(up(at(crop.flower_nectar, i))) : null,
            ].filter((cell) => cell !== null))),
        ),
    ];
}

/** Ingredients, products, duration, XP (and batches) of each recipe per level. */
function recipeTable(building, def) {
    const { data } = store;
    const ids = [...new Set(def.levels.flatMap((l) => Object.keys(l.recipes ?? {})))];
    const id = pick(building.type, ids);
    const batch = def.levels.some((l) => l.max_batches || l.max_output);
    const amounts = (list) => Object.entries(list).map(([k, v]) => `${fmtInt(v)} ${data.items[k]?.emoji ?? k}`).join(' + ') || '—';

    return [
        resourceTabs(building.type, ids, id),
        h('p', { class: 'row-sub' }, `${data.items[id].emoji} ${data.items[id].name}${batch ? ' · par lot' : ''}`),
        table(
            ['Niv.', 'Ingrédients', 'Produits', 'Durée', 'XP', batch ? 'Lots max' : null].filter(Boolean),
            def.levels.map((level) => {
                const recipe = level.recipes?.[id];
                if (!recipe) return row(building, level, ['—', '—', '—', '—', batch ? '—' : null].filter((c) => c !== null));
                const cost = productionCost(recipe.cost ?? {});
                const inputs = [amounts(recipe.inputs ?? {}), Object.values(cost).some((v) => v > 0) ? fmtCost(cost, data) : null].filter(Boolean).join(' + ');
                const lots = level.max_batches ?? (level.max_output ? Math.max(1, Math.floor(level.max_output / recipe.outputs[id])) : 1);
                return row(building, level, [
                    inputs || '—',
                    amounts(recipe.outputs),
                    recipe.duration ? fmtDuration(recipe.duration / store.state.settings.speed) : 'Instantané',
                    fmtInt(recipe.xp ?? 0),
                    batch ? fmtInt(lots) : null,
                ].filter((c) => c !== null));
            }),
        ),
    ];
}
