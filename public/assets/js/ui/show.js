import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt, fmtShort } from '../format.js';
import { serverNow, store } from '../store.js';
import { upgradeRow } from './building.js';

export function showPanel() {
    return {
        title: '🏆 Animaux de concours',
        render: () => {
            const config = store.data.show_animals;
            const show = store.state.show ?? { animals: {}, active: {} };
            const view = store.view.show;
            const building = store.state.buildings.find((b) => b.type === config.building);

            return [
                chestBlock(config, view),
                h('p', { class: 'status' }, 'Animaux au travail : ', h('strong', {}, `${view.active_count} / ${view.slots}`),
                    ' (un par espèce)'),
                activeBlock(config, show, view),
                ...Object.entries(config.species).map(([id, species]) => speciesBlock(id, species, config, show, view)),
                h('p', { class: 'section-title' }, 'Bâtiment'),
                building ? upgradeRow(building) : null,
            ];
        },
    };
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

function activeBlock(config, show, view) {
    const entries = Object.entries(show.active ?? {});
    if (!entries.length) return null;

    return h('div', { class: 'list' }, entries.map(([speciesId, job]) => {
        const animal = config.animals[job.animal];
        const species = config.species[speciesId];
        const status = view.active[speciesId];
        const remaining = status.ready_at - serverNow();
        const outputs = Object.entries(job.outputs).map(([k, q]) => `${fmtInt(q)} ${store.data.items[k].emoji}`).join(' + ');

        return h('div', { class: 'row' },
            h('span', { class: 'row-emoji' }, species.emoji),
            h('div', { class: 'row-main' },
                h('div', { class: 'row-title' }, `${animal.name} ${config.rarities[animal.rarity].emoji}`),
                h('div', { class: 'row-sub' }, remaining > 0
                    ? h('span', {}, `${outputs} — `, h('strong', { dataset: { readyAt: status.ready_at, format: 'long', prefix: 'prêt dans ' } },
                        `prêt dans ${fmtDuration(remaining)}`))
                    : `${outputs} — prêt !`)),
            h('div', { class: 'row-actions' },
                h('button', {
                    type: 'button', class: 'btn btn-primary', disabled: remaining > 0,
                    onclick: () => perform('collect_show_animal', { species: speciesId }),
                }, 'Récupérer')));
    }));
}

function speciesBlock(speciesId, species, config, show, view) {
    const animals = Object.entries(config.animals).filter(([, a]) => a.species === speciesId);
    const owned = animals.filter(([id]) => show.animals?.[id]);
    const busy = Boolean(show.active?.[speciesId]);
    const full = view.active_count >= view.slots;
    const product = store.data.items[species.product].emoji;

    return h('div', {},
        h('p', { class: 'section-title' }, `${species.emoji} ${species.name} — ${owned.length} / ${animals.length} découverts`),
        owned.length ? h('div', { class: 'list' }, owned.map(([id, animal]) => {
            const { level, cards } = show.animals[id];
            const next = level < 5 ? config.card_levels[level - 1] : null;
            return h('div', { class: `contract recipe rarity rarity-${animal.rarity}` },
                h('div', { class: 'row-title' }, `${config.rarities[animal.rarity].emoji} ${animal.name} — niv. ${level}`),
                h('p', { class: 'row-sub' }, next ? `${fmtInt(cards)} / ${fmtInt(next)} cartes pour le niveau suivant` : 'Niveau maximum'),
                h('div', { class: 'actions' }, species.durations.map((duration, i) => h('button', {
                    type: 'button', class: 'btn',
                    disabled: busy || full,
                    title: busy ? 'Un animal de cette espèce travaille déjà' : (full ? 'Aucun emplacement libre' : null),
                    onclick: () => perform('start_show_animal', { animal: id, duration: i }),
                }, `${fmtShort(duration / store.state.settings.speed)} → ${fmtInt(species.quantities[animal.rarity][level - 1][i])} ${product}`))));
        })) : h('p', { class: 'row-sub' }, 'Aucun animal découvert : ouvrez des coffres.'));
}
