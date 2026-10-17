import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtInt, resourceEmoji } from '../format.js';
import { canAfford, resource, store } from '../store.js';
import { upgradeRow } from './building.js';

const MATERIALS = ['scrolls', 'ink', 'books'];

export function libraryPanel() {
    return {
        title: '📚 Bibliothèque',
        render: () => {
            const { data, state } = store;
            const view = store.view.library;
            if (!view.building_level) return [h('p', { class: 'muted' }, 'Construisez la bibliothèque (onglet Construire, hors de la ferme).')];
            const items = Object.entries(data.library.items)
                .map(([id, entry]) => ({ id, entry, item: data.items[id] }))
                .sort((a, b) => a.item.unlock_level - b.item.unlock_level || a.item.name.localeCompare(b.item.name, 'fr'));
            const building = state.buildings.find((b) => b.type === data.library.building);

            return [
                h('p', { class: 'status' }, MATERIALS.map((id) => `${resourceEmoji(id, data)} ${fmtInt(resource(id))}`).join(' · ')),
                h('p', { class: 'muted' }, `Chaque niveau ajoute des unités à chaque récolte ou production du produit. `
                    + `Un produit ne dépasse pas le niveau de la bibliothèque (niv. ${view.building_level}).`),
                h('div', { class: 'list' }, items.map(({ id, entry, item }) => itemRow(id, entry, item, view))),
                h('p', { class: 'section-title' }, 'Bâtiment'),
                building ? upgradeRow(building) : null,
            ];
        },
    };
}

function itemRow(id, entry, item, view) {
    const { data, state } = store;
    const level = state.library?.[id] ?? 0;
    const next = entry.levels[level] ?? null;
    const per = data.crops[id] ? 'par récolte' : 'par production';
    const lockedLevel = state.level < item.unlock_level;
    const lockedLibrary = next !== null && next.level > view.building_level;

    let label = 'Améliorer';
    if (!next) label = 'Max';
    else if (lockedLevel) label = `Niv. ${item.unlock_level}`;
    else if (lockedLibrary) label = `Biblio niv. ${next.level}`;

    return h('div', { class: `row${lockedLevel ? ' disabled' : ''}` },
        h('span', { class: 'row-emoji' }, item.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, `${item.name} — niv. ${level} / ${entry.levels.length}`),
            h('div', { class: 'row-sub' }, [
                level ? `+${fmtInt(view.bonus[id])} ${item.emoji} ${per}` : `Aucun bonus`,
                next ? ` · niv. ${next.level} : +${fmtInt(next.bonus)} pour ${fmtCost(next.cost, data)}` : '',
            ].join(''))),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: !next || lockedLevel || lockedLibrary || !canAfford(next.cost),
                onclick: () => perform('upgrade_library', { item: id }),
            }, label)));
}
