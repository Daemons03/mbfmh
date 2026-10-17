import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtInt } from '../format.js';
import { canAfford, store } from '../store.js';
import { upgradeRow } from './building.js';
import { closeSheet } from './sheet.js';

/**
 * Build list. With a cell, builds right there; without, enters placement
 * mode (startPlacement) and also lists the buildings outside the farm.
 */
export function buildPanel({ cell = null, startPlacement }) {
    return {
        title: cell ? 'Construire ici' : 'Construire',
        render: () => {
            const types = Object.entries(store.data.buildings);
            const farm = types.filter(([, def]) => (def.zone ?? 'farm') === 'farm');
            const outside = types.filter(([, def]) => def.zone === 'outside');

            return [
                h('div', { class: 'list' }, farm.map(([type, def]) => farmRow(type, def, cell, startPlacement))),
                cell ? null : h('p', { class: 'section-title' }, 'Hors de la ferme'),
                cell ? null : h('div', { class: 'list' }, outside.map(([type, def]) => outsideRow(type, def))),
            ];
        },
    };
}

function farmRow(type, def, cell, startPlacement) {
    const first = def.levels[0];
    const count = store.state.buildings.filter((b) => b.type === type).length;
    const locked = store.state.level < (first.unlock_level ?? 1);
    const full = count >= def.max_count;
    const [w, hh] = def.size;

    let label = cell ? 'Construire' : 'Placer';
    if (locked) label = `Niv. ${first.unlock_level}`;
    else if (full) label = 'Maximum';

    return h('div', { class: `row${locked ? ' disabled' : ''}` },
        h('span', { class: 'row-emoji' }, def.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, def.name),
            h('div', { class: 'row-sub' }, `${fmtCost(first.cost, store.data)} · ${w}×${hh} · ${fmtInt(count)}/${fmtInt(def.max_count)}`)),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: locked || full || !canAfford(first.cost),
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
        h('span', { class: 'row-emoji' }, def.emoji),
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
