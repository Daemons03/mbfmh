import { perform } from '../actions.js';
import { buildingPicture } from '../sprites.js';
import { h } from '../dom.js';
import { fmtCost, fmtInt } from '../format.js';
import { canAfford, store, workersNeeded } from '../store.js';
import { upgradeRow } from './building.js';
import { closeSheet, refreshSheet } from './sheet.js';

/** Open tab, kept while the panel re-renders. */
let buildTab = 'normal';

const TABS = [
    ['normal', '🏠', 'Normaux'],
    ['premium', '✨', 'Premium'],
    ['free_range', '🌳', 'Plein air'],
    ['decoration', '🌷', 'Décoration'],
    ['outside', '🏞️', 'Extérieur'],
];

/** Opens the build panel on this tab next time (an empty outside lot shows 'outside'). */
export function setBuildTab(id) {
    buildTab = id;
}

/** Tab of a building type: variants by kind (fertile goes with premium), decorations, outside the farm. */
function tabOf(def) {
    if (def.zone === 'outside') return 'outside';
    if (def.variant === 'premium' || def.variant === 'fertile') return 'premium';
    if (def.variant === 'free_range') return 'free_range';
    if (def.kind === 'decoration') return 'decoration';
    return 'normal';
}

/**
 * Build list, one tab per category. With a cell, builds right there (no
 * outside tab); without, enters placement mode (startPlacement).
 */
export function buildPanel({ cell = null, startPlacement }) {
    return {
        title: cell ? 'Construire ici' : 'Construire',
        render: () => {
            const types = Object.entries(store.data.buildings)
                .sort(([, a], [, b]) => (a.levels[0].unlock_level ?? 1) - (b.levels[0].unlock_level ?? 1));
            const tabs = TABS.filter(([id]) => !(cell && id === 'outside'));
            if (!tabs.some(([id]) => id === buildTab)) buildTab = 'normal';
            const shown = types.filter(([, def]) => tabOf(def) === buildTab);

            return [
                h('div', { class: 'subtabs build', role: 'tablist', style: { 'grid-template-columns': `repeat(${tabs.length}, 1fr)` } },
                    tabs.map(([id, emoji, name]) => h('button', {
                        type: 'button',
                        role: 'tab',
                        class: `subtab${buildTab === id ? ' active' : ''}`,
                        'aria-selected': buildTab === id ? 'true' : 'false',
                        onclick: () => {
                            buildTab = id;
                            refreshSheet();
                        },
                    }, h('span', { class: 'subtab-emoji' }, emoji), h('span', { class: 'subtab-name' }, name)))),
                h('div', { class: 'list' }, shown.map(([type, def]) => (buildTab === 'outside'
                    ? outsideRow(type, def)
                    : farmRow(type, def, cell, startPlacement)))),
            ];
        },
    };
}

function farmRow(type, def, cell, startPlacement) {
    const first = def.levels[0];
    const count = store.state.buildings.filter((b) => b.type === type).length;
    const locked = store.state.level < (first.unlock_level ?? 1);
    const full = def.max_count !== null && count >= def.max_count; // null: unlimited
    const [w, hh] = def.size;
    const need = workersNeeded(def, 0, 1);
    const noWorkers = need > store.view.workers.free;

    let label = cell ? 'Construire' : 'Placer';
    if (locked) label = `Niv. ${first.unlock_level}`;
    else if (full) label = 'Maximum';
    else if (noWorkers) label = '👷 insuffisants';

    const stats = [];
    if (need > 0) stats.push(`${fmtInt(need)} 👷`);
    if (need < 0) stats.push(`+${fmtInt(-need)} 👷`);
    if (first.happiness) stats.push(`${first.happiness > 0 ? '+' : ''}${fmtInt(first.happiness)} 😊`);

    return h('div', { class: `row stack${locked ? ' disabled' : ''}` },
        h('span', { class: 'row-emoji' }, buildingPicture(type, def) ?? def.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, def.name),
            h('div', { class: 'row-sub' }, [fmtCost(first.cost, store.data), ...stats, `${w}×${hh}`, def.max_count === null ? `${fmtInt(count)} construit${count > 1 ? 's' : ''}` : `${fmtInt(count)}/${fmtInt(def.max_count)}`].join(' · '))),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: locked || full || noWorkers || !canAfford(first.cost),
                onclick: async () => {
                    if (!cell) {
                        closeSheet();
                        startPlacement(type);
                        return;
                    }
                    if (await perform('build', { type, x: cell.x, y: cell.y })) closeSheet();
                },
            }, label)));
}

function outsideRow(type, def) {
    const built = store.state.buildings.find((b) => b.type === type);
    if (built) {
        return h('div', { class: 'list' },
            h('div', { class: 'row-title' }, `${def.emoji} ${def.name} — niv. ${built.level}`),
            upgradeRow(built));
    }

    const first = def.levels[0];
    const locked = store.state.level < (first.unlock_level ?? 1);

    return h('div', { class: `row${locked ? ' disabled' : ''}` },
        h('span', { class: 'row-emoji' }, buildingPicture(type, def) ?? def.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, def.name),
            h('div', { class: 'row-sub' }, fmtCost(first.cost, store.data))),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: locked || !canAfford(first.cost),
                onclick: () => perform('build', { type }),
            }, locked ? `Niv. ${first.unlock_level}` : 'Construire')));
}
