import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { serverNow, store } from '../store.js';
import { upgradeRow } from './building.js';
import { refreshSheet } from './sheet.js';

/** Open species tab and the animal shown in each, kept while the panel re-renders. */
let speciesTab = null;
const selected = {};
let seenDraw = null;

const RARITY_ORDER = ['grey', 'green', 'blue', 'purple'];

export function showPanel() {
    return {
        title: '🏆 Animaux de concours',
        render: () => {
            const config = store.data.show_animals;
            const show = store.state.show ?? { animals: {}, active: {} };
            const view = store.view.show;
            const building = store.state.buildings.find((b) => b.type === config.building);
            followDraw(config, view.last_draw);
            speciesTab = config.species[speciesTab] ? speciesTab : defaultTab(config, show, view);
            const species = config.species[speciesTab];
            const animals = RARITY_ORDER.map((rarity) => [rarity, Object.entries(config.animals)
                .filter(([, a]) => a.species === speciesTab && a.rarity === rarity)
                .sort(([, a], [, b]) => a.duration - b.duration)]);
            const ids = animals.flatMap(([, list]) => list.map(([id]) => id));
            if (!ids.includes(selected[speciesTab])) selected[speciesTab] = ids.find((id) => show.animals?.[id]) ?? ids[0];

            return [
                chestBlock(config, view),
                h('p', { class: 'status' }, 'Animaux au travail : ', h('strong', {}, `${view.active_count} / ${view.slots}`),
                    ' (un par espèce)'),
                speciesTabs(config, show, view),
                activeRow(config, show, view, speciesTab),
                detail(config, show, view, selected[speciesTab]),
                animals.map(([rarity, list]) => [
                    h('p', { class: 'section-title' }, `${config.rarities[rarity].emoji} ${config.rarities[rarity].name}`),
                    h('div', { class: 'tiles animals' }, list.map(([id, animal]) => animalTile(config, show, species, id, animal))),
                ]),
                h('p', { class: 'section-title' }, 'Bâtiment'),
                building ? upgradeRow(building) : null,
            ];
        },
    };
}

/** After a chest, show the animal it gave. */
function followDraw(config, draw) {
    const key = JSON.stringify(draw ?? null);
    if (key === seenDraw) return;
    const first = seenDraw === null;
    seenDraw = key;
    if (first || !draw?.animal) return;
    speciesTab = config.animals[draw.animal].species;
    selected[speciesTab] = draw.animal;
}

/** A species with an animal ready, else one with animals owned, else the first. */
function defaultTab(config, show, view) {
    const ids = Object.keys(config.species);
    return ids.find((id) => show.active?.[id] && view.active[id]?.ready_at <= serverNow())
        ?? ids.find((id) => Object.keys(show.animals ?? {}).some((a) => config.animals[a]?.species === id))
        ?? ids[0];
}

function speciesTabs(config, show, view) {
    return h('div', { class: 'subtabs species', role: 'tablist' }, Object.entries(config.species).map(([id, species]) => {
        const job = show.active?.[id];
        const owned = Object.keys(show.animals ?? {}).filter((a) => config.animals[a]?.species === id).length;
        const total = Object.values(config.animals).filter((a) => a.species === id).length;
        const mark = !job ? `${owned}/${total}` : view.active[id].ready_at <= serverNow() ? '✓' : '⏳';

        return h('button', {
            type: 'button',
            role: 'tab',
            class: `subtab${speciesTab === id ? ' active' : ''}${job ? ' working' : ''}`,
            title: species.name,
            'aria-label': species.name,
            'aria-selected': speciesTab === id ? 'true' : 'false',
            onclick: () => {
                speciesTab = id;
                refreshSheet();
            },
        }, h('span', { class: 'subtab-emoji' }, species.emoji), h('span', { class: 'subtab-count' }, mark));
    }));
}

function chestBlock(config, view) {
    const tokens = store.state.materials.tokens ?? 0;
    const { chest, token_pack: pack } = config;

    return h('div', { class: 'contract' },
        h('div', { class: 'row-title' }, `🎟️ ${fmtInt(tokens)} jetons`),
        h('p', { class: 'row-sub' }, `+${config.daily_tokens} jetons chaque jour. Un coffre donne une carte d'animal (ou des plans 🗺️).`),
        lastDraw(config, view.last_draw),
        h('div', { class: 'actions' },
            h('button', {
                type: 'button', class: 'btn btn-primary', disabled: tokens < chest.cost,
                onclick: () => perform('open_chest', { count: 1 }),
            }, `Ouvrir 1 coffre (${chest.cost} 🎟️)`),
            h('button', {
                type: 'button', class: 'btn btn-primary', disabled: tokens < chest.bulk_cost,
                onclick: () => perform('open_chest', { count: chest.bulk_count }),
            }, `Ouvrir ${chest.bulk_count} coffres (${chest.bulk_cost} 🎟️)`),
            h('button', {
                type: 'button', class: 'btn',
                onclick: () => perform('buy_tokens', { packs: 1 }),
            }, `+${pack.tokens} 🎟️ pour ${fmtCost(pack.cost, store.data)}`)));
}

function lastDraw(config, draw) {
    if (!draw) return null;
    if (draw.blueprints) return h('p', { class: 'status' }, `🎉 +${fmtInt(draw.blueprints)} plans 🗺️`);
    const animal = config.animals[draw.animal];
    const species = config.species[animal.species];
    const rarity = config.rarities[animal.rarity];
    const what = draw.new ? 'Nouvel animal' : 'Carte';

    return h('p', { class: 'status' }, `🎉 ${what} : ${species.emoji} ${animal.name} ${rarity.emoji}`
        + (draw.count > 1 ? ` ×${draw.count}` : ''));
}

/** The animal of this species at work, if any: countdown, cancel, collect. */
function activeRow(config, show, view, speciesId) {
    const job = show.active?.[speciesId];
    if (!job) return null;
    const animal = config.animals[job.animal];
    const status = view.active[speciesId];
    const remaining = status.ready_at - serverNow();
    const outputs = Object.entries(job.outputs).map(([k, q]) => `${fmtInt(q)} ${store.data.items[k].emoji}`).join(' + ');

    return h('div', { class: 'row stack active-animal' },
        h('span', { class: 'row-emoji' }, config.species[speciesId].emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, `${animal.name} ${config.rarities[animal.rarity].emoji} au travail`),
            h('div', { class: 'row-sub' }, remaining > 0
                ? h('span', {}, `${outputs} — `, h('strong', { dataset: { readyAt: status.ready_at, format: 'long', prefix: 'prêt dans ' } },
                    `prêt dans ${fmtDuration(remaining)}`))
                : `${outputs} — prêt !`)),
        h('div', { class: 'row-actions' },
            remaining > 0 ? h('button', {
                type: 'button', class: 'btn',
                onclick: () => {
                    if (confirm(`Arrêter ${animal.name} ? Sa production est perdue.`)) perform('cancel_show_animal', { species: speciesId });
                },
            }, 'Annuler') : null,
            h('button', {
                type: 'button', class: 'btn btn-primary', disabled: remaining > 0,
                onclick: () => perform('collect_show_animal', { species: speciesId }),
            }, 'Récupérer')));
}

/** Tile framed by rarity: the species when discovered (with its level), its fixed duration. */
function animalTile(config, show, species, id, animal) {
    const owned = show.animals?.[id];
    const working = show.active?.[animal.species]?.animal === id;

    return h('button', {
        type: 'button',
        class: `tile animal rarity-${animal.rarity}${selected[speciesTab] === id ? ' selected' : ''}${owned ? '' : ' empty'}`,
        title: animal.name,
        'aria-pressed': selected[speciesTab] === id ? 'true' : 'false',
        onclick: () => {
            selected[speciesTab] = id;
            refreshSheet();
        },
    },
    working ? h('span', { class: 'tile-badge' }, '⏳') : null,
    h('span', { class: 'tile-emoji' }, owned ? species.emoji : '❔'),
    h('span', { class: 'tile-name' }, owned ? animal.name : '???'),
    h('span', { class: 'tile-qty' }, owned ? `niv. ${owned.level}` : '🔒'),
    h('span', { class: 'tile-time' }, `⏱ ${fmtDuration(species.durations[animal.duration] / store.state.settings.speed)}`));
}

/** Card of the chosen animal: level, cards, what it makes, and the start button. */
function detail(config, show, view, id) {
    const animal = config.animals[id];
    const species = config.species[animal.species];
    const rarity = config.rarities[animal.rarity];
    const owned = show.animals?.[id];
    const level = owned?.level ?? 1;
    const product = store.data.items[species.product];
    const quantity = species.quantities[animal.rarity][level - 1][animal.duration];
    const duration = species.durations[animal.duration] / store.state.settings.speed;
    const busy = Boolean(show.active?.[animal.species]);
    const full = view.active_count >= view.slots;
    const next = owned && level < 5 ? config.card_levels[level - 1] : null;

    const head = h('div', { class: 'detail-head' },
        h('span', { class: `detail-emoji rarity rarity-${animal.rarity}` }, owned ? species.emoji : '❔'),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, `${animal.name} ${rarity.emoji}`),
            h('div', { class: 'row-sub' }, owned
                ? `${rarity.name} · niveau ${level}${next ? ` · ${fmtInt(owned.cards)} / ${fmtInt(next)} cartes` : ' · niveau maximum'}`
                : `${rarity.name} · pas encore découvert`),
            next ? h('div', { class: 'meter' }, h('div', { style: { width: `${Math.min(100, (owned.cards / next) * 100)}%` } })) : null));

    const reason = !owned ? 'Ouvrez des coffres pour obtenir sa carte.'
        : busy ? `Un animal de cette espèce (${species.name.toLowerCase()}) travaille déjà.`
            : full ? 'Aucun emplacement libre : améliorez le bâtiment.' : null;

    return h('div', { class: 'item-detail' }, head,
        h('div', { class: 'sale' },
            h('span', {}, `⏱ ${fmtDuration(duration)}`),
            h('span', { class: 'sale-arrow' }, '➜'),
            h('span', {}, product.emoji, ' ', h('strong', {}, fmtInt(quantity))),
            h('span', { class: 'row-sub' }, 'gratuit')),
        reason ? h('p', { class: 'row-sub' }, reason) : null,
        owned ? h('button', {
            type: 'button',
            class: 'btn btn-go',
            disabled: busy || full,
            onclick: () => perform('start_show_animal', { animal: id }),
        }, 'Lancer') : null);
}
