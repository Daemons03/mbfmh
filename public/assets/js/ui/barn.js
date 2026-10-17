import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtInt, fmtNumber } from '../format.js';
import { store } from '../store.js';
import { barnMeter, itemMeter, upgradeRow } from './building.js';

export function barnPanel() {
    return {
        title: '🛖 Grange',
        render: () => {
            const { data, state } = store;
            const items = Object.entries(state.barn.items)
                .filter(([, quantity]) => quantity > 0)
                .sort(([a], [b]) => data.items[a].name.localeCompare(data.items[b].name, 'fr'));
            const barn = state.buildings.find((b) => b.type === 'barn');

            return [
                barnMeter(),
                items.length
                    ? h('div', { class: 'list' }, items.map(([id, quantity]) => itemRow(id, quantity)))
                    : h('p', { class: 'muted' }, 'La grange est vide. Récoltez vos champs pour la remplir.'),
                barn ? h('p', { class: 'section-title' }, 'Agrandir') : null,
                barn ? upgradeRow(barn) : null,
            ];
        },
    };
}

function itemRow(id, quantity) {
    const item = store.data.items[id];
    const total = Math.floor(quantity * item.barn_price);

    return h('div', { class: 'row stack' },
        h('span', { class: 'row-emoji' }, item.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, item.name),
            h('div', { class: 'row-sub' }, `${fmtInt(quantity)} / ${fmtInt(store.view.barn.capacity)} · ${fmtNumber(item.barn_price)} ${store.data.currencies.cash.emoji} l'unité`),
            itemMeter(quantity)),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn',
                onclick: () => perform('sell_barn', { item: id, quantity: 1 }),
            }, 'Vendre 1'),
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                onclick: () => perform('sell_barn', { item: id, quantity }),
            }, `Tout (+${fmtInt(total)})`)));
}
