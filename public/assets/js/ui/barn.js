import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtInt, fmtNumber } from '../format.js';
import { store } from '../store.js';
import { barnMeter, itemMeter, upgradeRow } from './building.js';
import { refreshSheet } from './sheet.js';

/** Quantity to sell per item, chosen with the slider. */
const sellQuantities = new Map();

/** Open tab ('products' or 'resources') and the item shown in each, kept while the panel re-renders. */
let tab = 'products';
const selected = { products: null, resources: null };

const GROUPS = [
    ['fields', 'Champs'],
    ['animals', 'Animaux'],
    ['orchards', 'Vergers'],
    ['others', 'Autres'],
];

export function barnPanel() {
    return {
        title: '🛖 Grange',
        render: () => {
            const lists = itemLists();
            const items = tab === 'products' ? lists.products : lists.resources;
            const flat = items.flatMap(([, ids]) => ids);
            if (!flat.includes(selected[tab])) selected[tab] = flat.find((id) => stock(id) > 0) ?? flat[0] ?? null;
            const barn = store.state.buildings.find((b) => b.type === 'barn');

            return [
                tabs(lists),
                tab === 'products' ? barnMeter() : null,
                selected[tab] ? detail(selected[tab]) : null,
                flat.length
                    ? items.map(([title, ids]) => [
                        title ? h('p', { class: 'section-title' }, title) : null,
                        h('div', { class: `tiles ${tab}` }, ids.map((id) => tile(id))),
                    ])
                    : h('p', { class: 'muted' }, tab === 'products'
                        ? 'La grange est vide. Récoltez vos champs pour la remplir.'
                        : 'Aucune ressource pour le moment.'),
                tab === 'products' && barn ? h('p', { class: 'section-title' }, 'Agrandir') : null,
                tab === 'products' && barn ? upgradeRow(barn) : null,
            ];
        },
    };
}

function stock(id) {
    return store.state.barn.items[id] ?? 0;
}

function tabs(lists) {
    const count = (groups) => groups.reduce((n, [, ids]) => n + ids.filter((id) => stock(id) > 0).length, 0);
    const button = (id, label) => h('button', {
        type: 'button',
        role: 'tab',
        class: `subtab${tab === id ? ' active' : ''}`,
        'aria-selected': tab === id ? 'true' : 'false',
        onclick: () => {
            tab = id;
            refreshSheet();
        },
    }, label, h('span', { class: 'subtab-count' }, fmtInt(count(lists[id]))));

    return h('div', { class: 'subtabs', role: 'tablist' },
        button('products', '🧺 Produits'),
        button('resources', '🌱 Ressources'));
}

/**
 * Products (fields, animals, orchards, others) and resources (seeds and other
 * special items, feeds, fertilizer, humus, dung, leaves...): what the barn
 * holds plus what the farm can make now, sorted by unlock level.
 */
function itemLists() {
    const { data, state } = store;
    const shown = new Set(Object.keys(state.barn.items).filter((id) => stock(id) > 0 && data.items[id]));
    for (const [id, item] of Object.entries(data.items)) {
        if (item.category === 'crop' && item.unlock_level <= state.level) shown.add(id);
    }
    for (const building of state.buildings) {
        for (const recipe of Object.values(store.view.production?.[building.id]?.recipes ?? {})) {
            for (const id of Object.keys(recipe.outputs ?? {})) shown.add(id);
        }
    }

    const groups = itemGroups();
    const byLevel = (a, b) => (data.items[a].unlock_level ?? 0) - (data.items[b].unlock_level ?? 0)
        || data.items[a].name.localeCompare(data.items[b].name, 'fr');
    const products = GROUPS.map(([group, title]) => [title, [...shown].filter((id) => !isResource(id) && (groups.get(id) ?? 'others') === group).sort(byLevel)]);
    const resources = [['', [...shown].filter(isResource).sort(byLevel)]];

    return {
        products: products.filter(([, ids]) => ids.length),
        resources: resources.filter(([, ids]) => ids.length),
    };
}

/** Seeds, boosts, feeds, fertilizer, humus, dung, leaves, nectar: used on the farm, not products. */
function isResource(id) {
    const item = store.data.items[id];
    return item.category === 'special' || item.category === 'byproduct' || item.barn_limit === false;
}

/**
 * Item -> 'fields' | 'animals' | 'orchards' | 'others', from the building
 * making it: animals eat feed, orchards take fertilizer.
 */
let groupCache = null;
function itemGroups() {
    if (groupCache?.data === store.data) return groupCache.groups;
    const { items, buildings } = store.data;
    const groups = new Map();
    for (const [id, item] of Object.entries(items)) {
        if (item.category === 'crop') groups.set(id, 'fields');
    }
    groups.set('flower_nectar', 'fields');
    for (const def of Object.values(buildings)) {
        if (def.kind !== 'production' || def.variant_of) continue;
        for (const recipe of Object.values(def.levels?.[0]?.recipes ?? {})) {
            const inputs = Object.keys(recipe.inputs ?? {});
            const group = inputs.some((k) => k.endsWith('_feed')) ? 'animals'
                : inputs.includes('fertilizer') ? 'orchards' : 'others';
            for (const out of Object.keys(recipe.outputs ?? {})) {
                if (!groups.has(out)) groups.set(out, group);
            }
        }
    }
    groupCache = { data: store.data, groups };
    return groups;
}

function tile(id) {
    const item = store.data.items[id];
    const quantity = stock(id);
    const full = item.barn_limit !== false && quantity >= store.view.barn.capacity;

    return h('button', {
        type: 'button',
        class: `tile${selected[tab] === id ? ' selected' : ''}${quantity === 0 ? ' empty' : ''}${full ? ' full' : ''}`,
        title: item.name,
        'aria-pressed': selected[tab] === id ? 'true' : 'false',
        onclick: () => {
            selected[tab] = id;
            refreshSheet();
        },
    },
    h('span', { class: 'tile-emoji' }, item.emoji),
    h('span', { class: 'tile-qty' }, fmtInt(quantity)));
}

/** Card of the chosen item: stock, and the sale slider when it can be sold. */
function detail(id) {
    const item = store.data.items[id];
    const quantity = stock(id);
    const cash = store.data.currencies.cash.emoji;
    const limited = item.barn_limit !== false;

    const head = h('div', { class: 'detail-head' },
        h('span', { class: 'detail-emoji' }, item.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, item.name),
            h('div', { class: 'row-sub' }, limited
                ? `🛖 ${fmtInt(quantity)} / ${fmtInt(store.view.barn.capacity)}`
                : `🛖 ${fmtInt(quantity)} · sans limite`),
            limited ? itemMeter(quantity) : null));

    if (item.sellable === false) {
        return h('div', { class: 'item-detail' }, head,
            h('p', { class: 'row-sub' }, `Objet spécial, gratuit à l'usage${item.description ? ` · ${item.description}` : ''}`));
    }
    if (quantity === 0) {
        return h('div', { class: 'item-detail' }, head,
            h('p', { class: 'row-sub' }, `${fmtNumber(item.barn_price)} ${cash} l'unité à la grange · aucun en stock`));
    }

    // Quantity picked with the slider, kept while the panel re-renders; all of it by default.
    const chosen = Math.min(sellQuantities.get(id) ?? quantity, quantity);
    const total = (n) => fmtInt(Math.floor(n * item.barn_price));
    const amount = h('strong', {}, fmtInt(chosen));
    const gain = h('strong', {}, total(chosen));
    const button = h('button', {
        type: 'button',
        class: 'btn btn-primary sell-btn',
        onclick: () => perform('sell_barn', { item: id, quantity: Math.min(sellQuantities.get(id) ?? quantity, quantity) }),
    }, 'Vendre');

    return h('div', { class: 'item-detail' }, head,
        h('div', { class: 'sale' },
            h('span', {}, item.emoji, ' ', amount),
            h('span', { class: 'sale-arrow' }, '➜'),
            h('span', {}, cash, ' ', gain),
            h('span', { class: 'row-sub' }, `${fmtNumber(item.barn_price)} ${cash} l'unité`)),
        h('div', { class: 'sell' },
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
                    amount.textContent = fmtInt(n);
                    gain.textContent = total(n);
                },
            }),
            button));
}
