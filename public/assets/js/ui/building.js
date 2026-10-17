import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { buildingById, canAfford, definition, jobStatus, store } from '../store.js';
import { closeSheet } from './sheet.js';

/**
 * Context panel for one building. startMove(id) enters move mode.
 */
export function buildingPanel(id, { startMove }) {
    return {
        title: () => {
            const building = buildingById(id);
            const def = definition(building.type);
            return `${def.emoji} ${def.name} — niv. ${building.level}`;
        },
        render: () => {
            const building = buildingById(id);
            if (!building) return null;
            const def = definition(building.type);

            return [
                ...content(building, def),
                h('p', { class: 'section-title' }, 'Bâtiment'),
                h('div', { class: 'list' }, upgradeRow(building)),
                otherActions(building, def, startMove),
            ];
        },
    };
}

function content(building, def) {
    switch (def.kind) {
        case 'field':
            return building.job ? [jobStatusBlock(building)] : plantList(building);
        case 'storage':
            return [barnMeter()];
        case 'production':
            return productionBlock(building);
        default:
            return [h('p', { class: 'muted' }, 'Ses fonctionnalités arriveront dans une prochaine version.')];
    }
}

function jobStatusBlock(building) {
    const crop = store.data.items[building.job.product];
    const status = jobStatus(building);
    const label = `${crop.emoji} ${crop.name}`;

    if (status.ready) {
        return h('div', { class: 'actions' },
            h('p', { class: 'status' }, `${label} : prêt à récolter !`),
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                onclick: () => perform('harvest', { id: building.id }),
            }, `Récolter (+${fmtInt(store.data.crops[building.job.product].yield)})`));
    }

    return h('p', { class: 'status' }, `${label} — `,
        h('strong', { dataset: { readyAt: status.readyAt, format: 'long', prefix: 'prêt dans ' } },
            `prêt dans ${fmtDuration(status.remaining)}`));
}

function plantList(field) {
    const { data, state } = store;
    const crops = Object.entries(data.crops)
        .map(([id, crop]) => ({ id, crop, item: data.items[id] }))
        .sort((a, b) => a.item.unlock_level - b.item.unlock_level);

    return [
        h('p', { class: 'section-title' }, 'Planter'),
        h('div', { class: 'list' }, crops.map(({ id, crop, item }) => {
            const locked = state.level < item.unlock_level;
            const affordable = canAfford(crop.plant_cost);
            return h('div', { class: `row${locked ? ' disabled' : ''}` },
                h('span', { class: 'row-emoji' }, item.emoji),
                h('div', { class: 'row-main' },
                    h('div', { class: 'row-title' }, item.name),
                    h('div', { class: 'row-sub' },
                        `${fmtCost(crop.plant_cost, data)} · ${fmtDuration(crop.duration / state.settings.speed)} · ${crop.yield} unités · +${crop.xp} XP`)),
                h('div', { class: 'row-actions' },
                    h('button', {
                        type: 'button',
                        class: 'btn btn-primary',
                        disabled: locked || !affordable,
                        onclick: () => perform('plant', { id: field.id, crop: id }),
                    }, locked ? `Niv. ${item.unlock_level}` : 'Planter')));
        })),
    ];
}

function itemChips(quantities, { compareToBarn }) {
    return h('div', { class: 'chips' }, Object.entries(quantities).map(([id, quantity]) => {
        const item = store.data.items[id];
        if (!compareToBarn) return h('span', { class: 'chip', title: item.name }, `${item.emoji} ${fmtInt(quantity)}`);
        const have = store.state.barn.items[id] ?? 0;
        return h('span', { class: `chip${have < quantity ? ' missing' : ''}`, title: item.name },
            `${item.emoji} ${fmtInt(Math.min(have, quantity))}/${fmtInt(quantity)}`);
    }));
}

function productionBlock(building) {
    const recipe = store.view.production[building.id];

    if (building.job) {
        const status = jobStatus(building);
        if (status.ready) {
            return [
                h('p', { class: 'status' }, 'Production terminée :'),
                itemChips(building.job.outputs, { compareToBarn: false }),
                h('div', { class: 'actions' },
                    h('button', {
                        type: 'button',
                        class: 'btn btn-primary',
                        onclick: () => perform('collect', { id: building.id }),
                    }, `Récupérer (+${recipe.xp} XP)`)),
            ];
        }
        return [
            h('p', { class: 'status' }, 'En cours — ',
                h('strong', { dataset: { readyAt: status.readyAt, format: 'long', prefix: 'prêt dans ' } },
                    `prêt dans ${fmtDuration(status.remaining)}`)),
            itemChips(building.job.outputs, { compareToBarn: false }),
        ];
    }

    const missing = Object.entries(recipe.inputs).some(([id, quantity]) => (store.state.barn.items[id] ?? 0) < quantity);
    return [
        h('p', { class: 'section-title' }, 'Consomme (grange)'),
        itemChips(recipe.inputs, { compareToBarn: true }),
        h('p', { class: 'section-title' }, 'Produit'),
        itemChips(recipe.outputs, { compareToBarn: false }),
        h('p', { class: 'row-sub' }, `${fmtDuration(recipe.duration / store.state.settings.speed)} · +${recipe.xp} XP`),
        h('div', { class: 'actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: missing,
                onclick: () => perform('start_production', { id: building.id }),
            }, missing ? 'Ingrédients manquants' : 'Lancer la production')),
    ];
}

export function barnMeter() {
    const { capacity, full } = store.view.barn;
    const names = full.map((id) => `${store.data.items[id].emoji} ${store.data.items[id].name}`);

    return h('div', {},
        h('p', { class: 'status' }, 'Capacité : ', h('strong', {}, fmtInt(capacity)), ' par produit'),
        names.length ? h('p', { class: 'row-sub', style: { color: 'var(--danger)' } }, `Plein : ${names.join(', ')}`) : null);
}

/** Per-product fill bar. */
export function itemMeter(quantity) {
    const { capacity } = store.view.barn;
    const percent = capacity > 0 ? Math.min(100, (quantity / capacity) * 100) : 100;

    return h('div', { class: `meter${quantity >= capacity ? ' full' : ''}` }, h('div', { style: { width: `${percent}%` } }));
}

/** Row offering the next level, with the reason when it is not possible. */
export function upgradeRow(building) {
    const def = definition(building.type);
    const next = def.levels[building.level]; // levels[0] is level 1
    if (!next) {
        return h('p', { class: 'muted' }, 'Niveau maximum atteint.');
    }

    const required = next.unlock_level ?? 1;
    const tooLow = store.state.level < required;
    const busy = Boolean(building.job);
    let label = 'Améliorer';
    if (tooLow) label = `Niv. ${required} requis`;
    else if (busy) label = 'Occupé';

    const effects = [];
    if (next.capacity !== undefined) {
        effects.push(`${fmtInt(def.levels[building.level - 1].capacity)} → ${fmtInt(next.capacity)} places`);
    }
    if (next.outputs !== undefined) {
        effects.push(Object.entries(next.outputs).map(([id, q]) => `${q} ${store.data.items[id]?.emoji ?? id}`).join(' + ') + ' par production');
    }

    return h('div', { class: 'row' },
        h('span', { class: 'row-emoji' }, '⬆️'),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, `Niveau ${next.level}`),
            h('div', { class: 'row-sub' }, [fmtCost(next.cost, store.data), ...effects].join(' · '))),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: tooLow || busy || !canAfford(next.cost),
                onclick: () => perform('upgrade', { id: building.id }),
            }, label)));
}

function otherActions(building, def, startMove) {
    if (def.zone === 'outside') return null;

    const buttons = [
        h('button', {
            type: 'button',
            class: 'btn',
            onclick: () => {
                closeSheet();
                startMove(building.id);
            },
        }, '✋ Déplacer'),
    ];
    if (def.kind !== 'storage') {
        buttons.push(h('button', {
            type: 'button',
            class: 'btn btn-danger',
            disabled: Boolean(building.job),
            title: building.job ? 'Récoltez d\'abord' : null,
            onclick: async () => {
                if (!confirm(`Détruire ${def.name.toLowerCase()} ? Aucun remboursement.`)) return;
                if (await perform('demolish', { id: building.id })) closeSheet();
            },
        }, '🗑️ Détruire'));
    }

    return h('div', { class: 'actions' }, buttons);
}
