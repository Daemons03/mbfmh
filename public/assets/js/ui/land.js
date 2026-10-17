import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtInt } from '../format.js';
import { canAfford, store } from '../store.js';

const PREVIEW = 3;

export function landPanel() {
    return {
        title: '🗺️ Terrain',
        render: () => {
            const { land } = store.data;
            const owned = store.state.expansions;
            const total = land.initial_expansions + land.expansions.length;
            const index = owned - land.initial_expansions; // index of the next one in land.expansions
            const next = land.expansions[index];

            return [
                h('p', { class: 'status' }, 'Terrain : ', h('strong', {}, `${fmtInt(owned)} / ${fmtInt(total)}`),
                    ` blocs de ${land.block_size}×${land.block_size}`),
                next ? nextExpansion(next) : h('p', { class: 'muted' }, 'Tout le terrain est à vous !'),
                next ? h('p', { class: 'section-title' }, 'Ensuite') : null,
                h('div', { class: 'list' }, land.expansions.slice(index + 1, index + 1 + PREVIEW).map(preview)),
            ];
        },
    };
}

function nextExpansion(expansion) {
    const tooLow = store.state.level < expansion.unlock_level;

    return h('div', { class: 'contract' },
        h('div', { class: 'row-title' }, `Extension n° ${expansion.number}`,
            tooLow ? h('span', { class: 'row-sub', style: { color: 'var(--danger)' } }, ` — niv. ${expansion.unlock_level} requis`) : null),
        h('p', { class: 'row-sub' }, 'Le bloc marqué 🔒 sur la carte. Choisissez comment payer :'),
        h('div', { class: 'list' }, expansion.costs.map((cost, option) => h('div', { class: 'row' },
            h('span', { class: 'row-emoji' }, cost.gold ? store.data.currencies.gold.emoji : store.data.currencies.cash.emoji),
            h('div', { class: 'row-main' }, h('div', { class: 'row-title' }, fmtCost(cost, store.data))),
            h('div', { class: 'row-actions' },
                h('button', {
                    type: 'button',
                    class: 'btn btn-primary',
                    disabled: tooLow || !canAfford(cost),
                    onclick: () => perform('buy_expansion', { option }),
                }, 'Acheter'))))));
}

function preview(expansion) {
    return h('div', { class: 'row disabled' },
        h('span', { class: 'row-emoji' }, '🔒'),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, `N° ${expansion.number} — niv. ${expansion.unlock_level}`),
            h('div', { class: 'row-sub' }, expansion.costs.map((cost) => fmtCost(cost, store.data)).join(' ou '))),
        h('span'));
}
