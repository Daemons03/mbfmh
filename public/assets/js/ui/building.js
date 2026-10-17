import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { buildingById, canAfford, definition, jobStatus, store } from '../store.js';
import { productionBlock } from './production.js';
import { closeSheet, refreshSheet } from './sheet.js';

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
            }, `Récolter (+${fmtInt(building.job.outputs?.[building.job.product] ?? 0)})`));
    }

    return h('p', { class: 'status' }, `${label} — `,
        h('strong', { dataset: { readyAt: status.readyAt, format: 'long', prefix: 'prêt dans ' } },
            `prêt dans ${fmtDuration(status.remaining)}`));
}

/** "Use humus" choice, kept while the panel re-renders. */
let useHumus = false;

function plantList(field) {
    const { data, state } = store;
    const crops = Object.entries(data.crops)
        .map(([id, crop]) => ({ id, crop, item: data.items[id] }))
        .sort((a, b) => a.item.unlock_level - b.item.unlock_level);
    const humusStock = state.barn.items.humus ?? 0;
    const humus = useHumus && humusStock > 0;
    const index = Math.min(field.level, 18) - 1;
    const yieldOf = (crop) => {
        const base = crop.yields[Math.min(index, crop.yields.length - 1)];
        return humus ? Math.floor(base * 1.25) : base;
    };
    const nectarOf = (crop) => crop.flower_nectar[Math.min(index, crop.flower_nectar.length - 1)] ?? 0;

    return [
        h('p', { class: 'section-title' }, 'Planter'),
        humusStock > 0 ? h('label', { class: 'checkbox' },
            h('input', {
                type: 'checkbox',
                checked: humus,
                onchange: (event) => {
                    useHumus = event.currentTarget.checked;
                    event.currentTarget.blur();
                    refreshSheet();
                },
            }),
            ` Utiliser 1 ${data.items.humus.emoji} humus : +25 % de récolte (${fmtInt(humusStock)} en grange)`) : null,
        h('div', { class: 'list' }, crops.map(({ id, crop, item }) => {
            const locked = state.level < item.unlock_level;
            const affordable = canAfford(crop.plant_cost);
            return h('div', { class: `row${locked ? ' disabled' : ''}` },
                h('span', { class: 'row-emoji' }, item.emoji),
                h('div', { class: 'row-main' },
                    h('div', { class: 'row-title' }, item.name),
                    h('div', { class: 'row-sub' },
                        `${fmtCost(crop.plant_cost, data)} · ${fmtDuration(crop.duration / state.settings.speed)} · ${fmtInt(yieldOf(crop))} ${item.emoji}`
                        + (nectarOf(crop) ? ` + ${fmtInt(nectarOf(crop))} ${data.items.flower_nectar.emoji}` : '')
                        + ` · +${crop.xp} XP`)),
                h('div', { class: 'row-actions' },
                    h('button', {
                        type: 'button',
                        class: 'btn btn-primary',
                        disabled: locked || !affordable,
                        onclick: () => perform('plant', humus ? { id: field.id, crop: id, humus: true } : { id: field.id, crop: id }),
                    }, locked ? `Niv. ${item.unlock_level}` : 'Planter')));
        })),
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
