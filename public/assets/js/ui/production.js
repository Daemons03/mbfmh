import { idleGroup, perform, performAll } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { canAfford, jobStatus, productionCost, speedUpCost, store } from '../store.js';
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
        jobControls(building, status),
    ];
}

/**
 * Buttons under a running job (field or production building): finish it now
 * for gold (price follows the countdown), or cancel it and lose what it took.
 */
export function jobControls(building, status) {
    const gold = store.data.currencies.gold.emoji;
    const cost = speedUpCost(status.remaining);

    return h('div', { class: 'actions' },
        h('button', {
            type: 'button',
            class: 'btn btn-primary',
            disabled: store.state.gold < cost,
            title: store.state.gold < cost ? "Pas assez d'or" : null,
            onclick: () => perform('speed_up', { id: building.id }),
        }, '⚡ Accélérer (',
        h('span', { dataset: { readyAt: status.readyAt, format: 'speed-up' } }, fmtInt(cost)), ` ${gold})`),
        h('button', {
            type: 'button',
            class: 'btn',
            onclick: () => {
                if (confirm('Annuler cette production ? Tout ce qui a été engagé (cash, semences, humus, nourriture, engrais, ingrédients) est perdu.')) {
                    perform('cancel_job', { id: building.id });
                }
            },
        }, 'Annuler'));
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
    const tooBig = Object.entries(outputs).find(([k, v]) => v > store.view.barn.capacity && store.data.items[k].barn_limit !== false);
    const hasInputs = Object.keys(inputs).length > 0;

    const ready = mode === 'instant' ? 'Fabriquer' : 'Lancer';
    let label = ready;
    if (locked) label = `Niv. ${item.unlock_level}`;
    else if (tooBig) label = 'Grange trop petite';
    else if (missing) label = 'Ingrédients manquants';
    else if (!canAfford(cost)) label = 'Pas assez d\'argent';

    const body = mode === 'batch' ? { id: building.id, recipe: id, quantity } : { id: building.id, recipe: id };
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
            h('div', { class: 'row-actions' },
                boostButtons(building, id, recipe, locked || Boolean(tooBig)),
                h('button', {
                    type: 'button',
                    class: 'btn btn-primary',
                    disabled: label !== ready,
                    onclick: () => perform('start_production', body),
                }, label),
                mode === 'instant' ? null : allButton(building, body, label !== ready, 'Tout'))));
}

/** Buildings without "Tout": silos are started one by one. */
const NO_START_ALL = new Set(['silo']);

/** "Tout (N)": the same start on every idle building of this kind (variants included). */
function allButton(building, body, disabled, text) {
    if (NO_START_ALL.has(store.data.buildings[building.type].variant_of ?? building.type)) return null;
    const group = idleGroup(building);
    if (group.length < 2) return null;
    return h('button', {
        type: 'button',
        class: 'btn btn-all',
        disabled,
        title: `Lancer aussi les ${group.length - 1} autres bâtiments libres`,
        onclick: () => performAll('start_production_all', body, group),
    }, `${text} (${group.length})`);
}

/** Which special items a recipe can take instead of its feed / fertilizer. */
function boostKind(inputs) {
    const boosts = store.data.boosts;
    if (!boosts) return null;
    return ['feed', 'fertilizer'].find((kind) => Object.keys(inputs).some((k) => boosts[kind].inputs.includes(k))) ?? null;
}

/** "⚡ Instantané" / "✨ Premium" buttons when the barn holds the special feed or fertilizer. */
function boostButtons(building, id, recipe, disabled) {
    const kind = boostKind(recipe.inputs);
    if (!kind) return null;
    const { data, state } = store;
    const main = recipe.outputs[id];
    const variants = [
        ['instant', '⚡ Instantané', 'prêt tout de suite'],
        ['premium', `✨ Premium · ${fmtInt(Math.ceil(Number((main * data.boosts.premium_factor).toFixed(6))))}`, '+50 %'],
    ];

    return variants.map(([variant, text, effect]) => {
        const item = data.boosts[kind][variant];
        const have = state.barn.items[item] ?? 0;
        if (!have) return null;
        const body = { id: building.id, recipe: id, boost: variant };
        return [
            h('button', {
                type: 'button',
                class: 'btn btn-go',
                disabled,
                title: `1 ${data.items[item].emoji} ${data.items[item].name} à la place : ${effect}, gratuit (${fmtInt(have)} en grange)`,
                onclick: () => perform('start_production', body),
            }, text),
            allButton(building, body, disabled, variant === 'instant' ? '⚡ Tout' : '✨ Tout'),
        ];
    });
}
