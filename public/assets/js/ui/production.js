import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { canAfford, jobStatus, productionCost, store } from '../store.js';
import { refreshSheet } from './sheet.js';

/** Chosen number of batches per "buildingId:recipe", kept while the panel re-renders. */
const quantities = new Map();

export function itemChips(amounts, { compareToBarn = false } = {}) {
    return h('div', { class: 'chips' }, Object.entries(amounts).map(([id, quantity]) => {
        const item = store.data.items[id];
        if (!compareToBarn) return h('span', { class: 'chip', title: item.name }, `${item.emoji} ${fmtInt(quantity)}`);
        const have = store.state.barn.items[id] ?? 0;
        return h('span', { class: `chip${have < quantity ? ' missing' : ''}`, title: item.name },
            `${item.emoji} ${fmtInt(Math.min(have, quantity))}/${fmtInt(quantity)}`);
    }));
}

/** Panel content of a production building: running job, or its recipes. */
export function productionBlock(building) {
    if (building.job) return jobBlock(building);

    const { mode, recipes } = store.view.production[building.id];
    const entries = Object.entries(recipes)
        .sort(([a], [b]) => store.data.items[a].unlock_level - store.data.items[b].unlock_level);

    return [
        entries.length > 1 ? h('p', { class: 'section-title' }, 'Recettes') : null,
        h('div', { class: 'list' }, entries.map(([id, recipe]) => recipeCard(building, id, recipe, mode))),
    ];
}

function jobBlock(building) {
    const { job } = building;
    const status = jobStatus(building);
    const batches = job.quantity > 1 ? ` (${job.quantity} lots)` : '';

    if (status.ready) {
        return [
            h('p', { class: 'status' }, `Production terminée${batches} :`),
            itemChips(job.outputs),
            h('div', { class: 'actions' },
                h('button', {
                    type: 'button',
                    class: 'btn btn-primary',
                    onclick: () => perform('collect', { id: building.id }),
                }, job.xp ? `Récupérer (+${fmtInt(job.xp)} XP)` : 'Récupérer')),
        ];
    }
    return [
        h('p', { class: 'status' }, `En cours${batches} — `,
            h('strong', { dataset: { readyAt: status.readyAt, format: 'long', prefix: 'prêt dans ' } },
                `prêt dans ${fmtDuration(status.remaining)}`)),
        itemChips(job.outputs),
    ];
}

function recipeCard(building, id, recipe, mode) {
    const item = store.data.items[id];
    const key = `${building.id}:${id}`;
    const max = recipe.max_quantity;
    const quantity = Math.min(quantities.get(key) ?? 1, max);
    const times = (amounts) => Object.fromEntries(Object.entries(amounts).map(([k, v]) => [k, v * quantity]));

    const inputs = times(recipe.inputs);
    const cost = productionCost(times(recipe.cost));
    const outputs = times(recipe.outputs);
    const locked = store.state.level < item.unlock_level;
    const missing = Object.entries(inputs).some(([k, v]) => (store.state.barn.items[k] ?? 0) < v);
    const tooBig = Object.entries(outputs).find(([, v]) => v > store.view.barn.capacity);
    const hasInputs = Object.keys(inputs).length > 0;

    const ready = mode === 'instant' ? 'Fabriquer' : 'Lancer';
    let label = ready;
    if (locked) label = `Niv. ${item.unlock_level}`;
    else if (tooBig) label = 'Grange trop petite';
    else if (missing) label = 'Ingrédients manquants';
    else if (!canAfford(cost)) label = 'Pas assez d\'argent';

    const setQuantity = (value) => {
        quantities.set(key, Math.max(1, Math.min(max, value)));
        refreshSheet();
    };

    return h('div', { class: `contract recipe${locked ? ' disabled' : ''}` },
        h('div', { class: 'row-title' }, `${item.emoji} ${item.name}`),
        tooBig ? h('p', { class: 'row-sub', style: { color: 'var(--danger)' } },
            `La grange (${fmtInt(store.view.barn.capacity)} par produit) est trop petite pour ${fmtInt(tooBig[1])} `
            + `${store.data.items[tooBig[0]].emoji} : agrandissez-la d'abord.`) : null,
        hasInputs ? itemChips(inputs, { compareToBarn: true }) : h('p', { class: 'row-sub' }, 'Aucun ingrédient nécessaire.'),
        h('p', { class: 'row-sub' }, `→ `, ...Object.entries(outputs).map(([k, v], i) =>
            `${i ? ' + ' : ''}${fmtInt(v)} ${store.data.items[k].emoji}`)),
        h('p', { class: 'row-sub' }, [
            Object.keys(cost).length ? fmtCost(cost, store.data) : null,
            recipe.duration ? fmtDuration(recipe.duration * quantity / store.state.settings.speed) : 'Instantané',
            `+${fmtInt(recipe.xp * quantity)} XP`,
        ].filter(Boolean).join(' · ')),
        h('div', { class: 'contract-foot' },
            mode === 'batch' ? h('div', { class: 'stepper', 'aria-label': 'Nombre de lots' },
                h('button', { type: 'button', class: 'btn', disabled: quantity <= 1, onclick: () => setQuantity(quantity - 1) }, '−'),
                h('span', {}, `${quantity} / ${max} lot${max > 1 ? 's' : ''}`),
                h('button', { type: 'button', class: 'btn', disabled: quantity >= max, onclick: () => setQuantity(quantity + 1) }, '+'),
                h('button', { type: 'button', class: 'btn', disabled: quantity >= max, onclick: () => setQuantity(max) }, 'Max'))
                : h('span'),
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: label !== ready,
                onclick: () => perform('start_production', mode === 'batch'
                    ? { id: building.id, recipe: id, quantity }
                    : { id: building.id, recipe: id }),
            }, label)));
}
