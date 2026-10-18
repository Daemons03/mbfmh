import { perform } from '../actions.js';
import { buildingArt } from '../art.js';
import { buildingPicture } from '../sprites.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { buildingById, canAfford, definition, footprintOf, jobStatus, productEmoji, productionCost, store, workersNeeded } from '../store.js';
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
                hero(building, def),
                ...content(building, def),
                h('p', { class: 'section-title' }, 'Bâtiment'),
                h('div', { class: 'list' }, upgradeRow(building)),
                otherActions(building, def, startMove),
            ];
        },
    };
}

/** Picture of the building with its level and size. */
function hero(building, def) {
    const status = jobStatus(building);
    const product = building.job ? productEmoji(building.job.product) : null;
    let picture = buildingPicture(building.type, def, { level: building.level, rotated: building.rotated, cls: 'hero-sprite' });
    if (def.size && picture.tagName.toLowerCase() === 'svg') {
        picture = buildingArt(building, def, { working: Boolean(building.job) && !status?.ready, ready: Boolean(status?.ready), product }).svg;
        if (building.rotated) picture.classList.add('mirrored');
    }
    const [w, hh] = def.size ? footprintOf(building.type, Boolean(building.rotated)) : [0, 0];

    return h('div', { class: 'hero' },
        h('div', { class: 'hero-picture' }, picture),
        h('div', { class: 'hero-info' },
            h('div', { class: 'hero-level' }, `Niveau ${building.level} / ${def.levels.length}`),
            def.size ? h('div', { class: 'row-sub' }, `◇ ${w}×${hh}`) : null));
}

function content(building, def) {
    switch (def.kind) {
        case 'field':
            return building.job ? [jobStatusBlock(building)] : plantList(building);
        case 'storage':
            return [barnMeter()];
        case 'production':
            return productionBlock(building);
        case 'house':
        case 'decoration':
            return [buildingStats(def.levels[building.level - 1])];
        default:
            return [h('p', { class: 'muted' }, 'Ses fonctionnalités arriveront dans une prochaine version.')];
    }
}

/** "Loge 10 👷 · -35 😊" for houses, "+12 😊" for decorations. */
export function buildingStats(level) {
    const parts = [];
    if ((level.workers ?? 0) > 0) parts.push(`Loge ${fmtInt(level.workers)} 👷`);
    if ((level.workers ?? 0) < 0) parts.push(`Emploie ${fmtInt(-level.workers)} 👷`);
    if (level.happiness) parts.push(`${level.happiness > 0 ? '+' : ''}${fmtInt(level.happiness)} 😊`);
    return h('p', { class: 'status' }, parts.join(' · ') || '—');
}

function jobStatusBlock(building) {
    const crop = store.data.items[building.job.product] ?? store.data.special_crops[building.job.product];
    const status = jobStatus(building);
    const label = `${crop.emoji} ${crop.name}`;

    if (status.ready) {
        return h('div', { class: 'actions' },
            h('p', { class: 'status' }, `${label} : prêt à récolter !`),
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                onclick: () => perform('harvest', { id: building.id }),
            }, building.job.reward
                ? `Récolter (+${fmtCost(building.job.reward, store.data)})`
                : `Récolter (+${fmtInt(building.job.outputs?.[building.job.product] ?? 0)})`));
    }

    return h('p', { class: 'status' }, `${label} — `,
        h('strong', { dataset: { readyAt: status.readyAt, format: 'long', prefix: 'prêt dans ' } },
            `prêt dans ${fmtDuration(status.remaining)}`));
}

/** Humus choice ('none', 'humus', 'premium', 'instant'), kept while the panel re-renders. */
let humusChoice = 'none';

const HUMUS_BONUS = { humus: 0.25, premium: 0.5 };

function plantList(field) {
    const { data, state } = store;
    const items = state.barn.items;
    const crops = Object.entries(data.crops)
        .map(([id, crop]) => ({ id, crop, item: data.items[id] }))
        .sort((a, b) => a.item.unlock_level - b.item.unlock_level);
    const humusItems = { humus: 'humus', premium: data.boosts?.humus.premium, instant: data.boosts?.humus.instant };
    if (humusChoice !== 'none' && !(items[humusItems[humusChoice]] > 0)) humusChoice = 'none';
    const free = humusChoice === 'premium' || humusChoice === 'instant'; // special items are free to use
    const index = Math.min(field.level, 18) - 1;
    const factor = data.buildings[field.type].yield_factor ?? 1;
    // Same rounding as the server: field factor and premium seed rounded up, humus rounded down.
    const up = (value, by) => Math.ceil(Number((value * by).toFixed(6)));
    // The library bonus comes on top, as on the server.
    const yieldOf = (id, crop, premiumSeed = false) => {
        let quantity = up(up(crop.yields[Math.min(index, crop.yields.length - 1)], factor), premiumSeed ? data.boosts.premium_seed_factor : 1);
        const bonus = HUMUS_BONUS[humusChoice];
        if (bonus) quantity = Math.floor(Number((quantity * (1 + bonus)).toFixed(6)));
        return quantity + (store.view.library.bonus[id] ?? 0);
    };
    const nectarOf = (crop) => up(crop.flower_nectar[Math.min(index, crop.flower_nectar.length - 1)] ?? 0, factor);
    const payload = (id, extra = {}) => {
        const body = { id: field.id, crop: id, ...extra };
        if (humusChoice === 'humus') body.humus = true;
        if (free) body.boost = humusChoice;
        return body;
    };

    return [
        h('p', { class: 'section-title' }, 'Planter'),
        humusOptions(humusItems),
        h('div', { class: 'list' }, crops.map(({ id, crop, item }) => {
            const locked = state.level < item.unlock_level;
            const cost = free ? {} : productionCost(crop.plant_cost);
            const affordable = canAfford(cost);
            const seed = data.boosts?.premium_seeds[id];
            const seeds = seed ? items[seed] ?? 0 : 0;
            const duration = humusChoice === 'instant' ? 'tout de suite' : fmtDuration(crop.duration / state.settings.speed);
            return h('div', { class: `row${seeds ? ' stack' : ''}${locked ? ' disabled' : ''}` },
                h('span', { class: 'row-emoji' }, item.emoji),
                h('div', { class: 'row-main' },
                    h('div', { class: 'row-title' }, item.name),
                    h('div', { class: 'row-sub' },
                        `${fmtCost(cost, data)} · ${duration} · ${fmtInt(yieldOf(id, crop))} ${item.emoji}`
                        + (nectarOf(crop) ? ` + ${fmtInt(nectarOf(crop))} ${data.items.flower_nectar.emoji}` : '')
                        + ` · +${crop.xp} XP`)),
                h('div', { class: 'row-actions' },
                    seeds && !locked ? h('button', {
                        type: 'button',
                        class: 'btn btn-go',
                        title: `Semence premium : +10 %, gratuit (${fmtInt(seeds)} en grange)`,
                        onclick: () => perform('plant', payload(id, { seed: 'premium' })),
                    }, `✨ Premium · ${fmtInt(yieldOf(id, crop, true))}`) : null,
                    h('button', {
                        type: 'button',
                        class: 'btn btn-primary',
                        disabled: locked || !affordable,
                        onclick: () => perform('plant', payload(id)),
                    }, locked ? `Niv. ${item.unlock_level}` : 'Planter')));
        })),
        specialSeeds(field),
    ];
}

/** Humus choice: only what the barn holds. */
function humusOptions(humusItems) {
    const { data, state } = store;
    const labels = {
        humus: (n) => `1 ${data.items.humus.emoji} humus : +25 % de récolte (${fmtInt(n)} en grange)`,
        premium: (n) => `1 ${data.items[humusItems.premium].emoji} humus premium : +50 %, gratuit (${fmtInt(n)})`,
        instant: (n) => `1 ${data.items[humusItems.instant].emoji} humus instantané : récolte tout de suite, gratuit (${fmtInt(n)})`,
    };
    const available = Object.keys(labels).filter((key) => (state.barn.items[humusItems[key]] ?? 0) > 0);
    if (!available.length) return null;
    const option = (key, text) => h('label', { class: 'checkbox' },
        h('input', {
            type: 'radio',
            name: 'humus',
            checked: humusChoice === key,
            onchange: (event) => {
                humusChoice = key;
                event.currentTarget.blur();
                refreshSheet();
            },
        }),
        ` ${text}`);

    return h('div', { class: 'choices' },
        option('none', 'Sans humus'),
        available.map((key) => option(key, labels[key](state.barn.items[humusItems[key]]))));
}

/** Roses, tulips and golden flowers the barn holds seeds for. */
function specialSeeds(field) {
    const { data, state } = store;
    const owned = Object.entries(data.special_crops ?? {})
        .filter(([id, crop]) => !id.startsWith('_') && (state.barn.items[crop.seed] ?? 0) > 0);
    if (!owned.length) return null;
    const effect = (crop) => {
        if (crop.reward?.xp) return `+${fmtInt(crop.reward.xp)} XP tout de suite`;
        if (crop.market_xp_by_field_level) return `+${fmtInt(tulipMarketXp(crop.market_xp_by_field_level, field.level))} XP de marché tout de suite`;
        return `${fmtDuration(crop.duration / state.settings.speed)} → ${fmtCost(crop.reward, data)}`;
    };

    return [
        h('p', { class: 'section-title' }, 'Semences spéciales'),
        h('div', { class: 'list' }, owned.map(([id, crop]) => h('div', { class: 'row' },
            h('span', { class: 'row-emoji' }, crop.emoji),
            h('div', { class: 'row-main' },
                h('div', { class: 'row-title' }, crop.name),
                h('div', { class: 'row-sub' }, `${effect(crop)} · ${fmtInt(state.barn.items[crop.seed])} en grange · gratuit`)),
            h('div', { class: 'row-actions' },
                h('button', { type: 'button', class: 'btn btn-go', onclick: () => perform('plant', { id: field.id, crop: id }) }, 'Planter'))))),
    ];
}

/** Same interpolation as the server (Boosts::marketXpByFieldLevel). */
function tulipMarketXp(points, level) {
    let [lastLevel, lastXp] = points[0];
    if (level <= lastLevel) return lastXp;
    for (const [pointLevel, pointXp] of points) {
        if (level <= pointLevel) return Math.round(lastXp + ((pointXp - lastXp) * (level - lastLevel)) / (pointLevel - lastLevel));
        [lastLevel, lastXp] = [pointLevel, pointXp];
    }
    return lastXp;
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
    const extraWorkers = workersNeeded(def, building.level, building.level + 1);
    const noWorkers = extraWorkers > store.view.workers.free;
    let label = 'Améliorer';
    if (tooLow) label = `Niv. ${required} requis`;
    else if (busy) label = 'Occupé';
    else if (noWorkers) label = '👷 insuffisants';

    const effects = [];
    if (next.capacity !== undefined) {
        effects.push(`${fmtInt(def.levels[building.level - 1].capacity)} → ${fmtInt(next.capacity)} places`);
    }
    if (extraWorkers > 0) effects.push(`${fmtInt(extraWorkers)} 👷 de plus`);
    if (extraWorkers < 0) effects.push(`+${fmtInt(-extraWorkers)} 👷 logés`);
    if (next.happiness !== undefined) {
        const current = def.levels[building.level - 1].happiness ?? 0;
        const delta = next.happiness - current;
        if (delta) effects.push(`${delta > 0 ? '+' : ''}${fmtInt(delta)} 😊`);
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
                disabled: tooLow || busy || noWorkers || !canAfford(next.cost),
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
        h('button', {
            type: 'button',
            class: 'btn',
            title: 'Pivoter d\'un quart de tour sur place',
            onclick: () => perform('rotate', { id: building.id }),
        }, '↻ Pivoter'),
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
