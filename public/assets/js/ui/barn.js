import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtInt, fmtNumber } from '../format.js';
import { store } from '../store.js';
import { barnMeter, itemMeter, upgradeRow } from './building.js';

/** Quantity to sell per item, chosen with the slider. */
const sellQuantities = new Map();

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

    if (item.sellable === false) {
        // Special items: used in fields and buildings, never sold.
        return h('div', { class: 'row' },
            h('span', { class: 'row-emoji' }, item.emoji),
            h('div', { class: 'row-main' },
                h('div', { class: 'row-title' }, item.name),
                h('div', { class: 'row-sub' }, `${fmtInt(quantity)} · objet spécial, gratuit à l'usage${item.description ? ` · ${item.description}` : ''}`)),
            h('span'));
    }

    // Quantity picked with the slider, kept while the panel re-renders; all of it by default.
    const chosen = Math.min(sellQuantities.get(id) ?? quantity, quantity);
    const cash = store.data.currencies.cash.emoji;
    const label = (n) => `Vendre ${fmtInt(n)} (+${fmtInt(Math.floor(n * item.barn_price))} ${cash})`;
    const button = h('button', {
        type: 'button',
        class: 'btn btn-primary',
        onclick: () => perform('sell_barn', { item: id, quantity: Math.min(sellQuantities.get(id) ?? quantity, quantity) }),
    }, label(chosen));

    return h('div', { class: 'row stack' },
        h('span', { class: 'row-emoji' }, item.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, item.name),
            item.barn_limit === false
                ? h('div', { class: 'row-sub' }, `${fmtInt(quantity)} · sans limite · ${fmtNumber(item.barn_price)} ${cash} l'unité`)
                : h('div', { class: 'row-sub' }, `${fmtInt(quantity)} / ${fmtInt(store.view.barn.capacity)} · ${fmtNumber(item.barn_price)} ${cash} l'unité`),
            item.barn_limit === false ? null : itemMeter(quantity)),
        h('div', { class: 'row-actions sell' },
            h('input', {
                type: 'range',
                min: 1,
                max: quantity,
                value: chosen,
                disabled: quantity === 1,
                'aria-label': `Quantité de ${item.name} à vendre`,
                // Updated in place: no re-render while the thumb moves.
                oninput: (event) => {
                    const n = Number(event.target.value);
                    sellQuantities.set(id, n);
                    button.textContent = label(n);
                },
            }),
            button));
}
