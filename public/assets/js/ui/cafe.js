import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtInt } from '../format.js';
import { store } from '../store.js';
import { upgradeRow } from './building.js';
import { itemChips } from './production.js';

const STAR_COUNT = 7;

export function cafePanel() {
    return {
        title: '☕ Café-boutique',
        render: () => {
            const view = store.view.cafe;
            if (!view) return [h('p', { class: 'muted' }, 'Construisez le café-boutique (onglet Construire, hors de la ferme).')];
            const { data } = store;
            const config = data.cafe;
            const recipes = Object.entries(config.recipes)
                .sort(([, a], [, b]) => a.cafe_level - b.cafe_level);
            const cafeBuilding = store.state.buildings.find((b) => b.type === config.building);

            return [
                groupBlock(view, config),
                gaugeBlock(view, config),
                h('p', { class: 'section-title' }, 'Recettes'),
                h('div', { class: 'list' }, recipes.map(([id, recipe]) => recipeCard(id, recipe, view.recipes[id]))),
                h('p', { class: 'section-title' }, 'Café'),
                cafeBuilding ? upgradeRow(cafeBuilding) : null,
            ];
        },
    };
}

function groupBlock(view, config) {
    const favourites = view.group.favourites
        .map((id) => `${config.recipes[id].emoji} ${config.recipes[id].name}`)
        .join(', ');

    return h('div', { class: 'contract' },
        h('div', { class: 'row-title' }, `Clients du jour : ${view.group.emoji} ${view.group.name}`),
        h('p', { class: 'row-sub' }, `Récompenses ×${config.favourite_factor} sur : ${favourites}`),
        h('p', { class: 'row-sub' }, 'Jauge et clients changent chaque jour à minuit.'));
}

function gaugeBlock(view, config) {
    const milestones = config.milestones;
    const next = milestones[view.rewarded] ?? null;
    const max = milestones[milestones.length - 1].points;
    const percent = Math.min(100, (view.points / max) * 100);

    return h('div', {},
        h('p', { class: 'status' }, 'Satisfaction : ', h('strong', {}, `${fmtInt(view.points)} pts`),
            next ? ` — prochain palier à ${fmtInt(next.points)}` : ' — tous les paliers atteints !'),
        h('div', { class: 'meter' }, h('div', { style: { width: `${percent}%` } })),
        h('div', { class: 'chips milestones' }, milestones.map((m, i) => h('span', {
            class: `chip${i < view.rewarded ? ' done' : ''}`,
            title: `${fmtInt(m.points)} points`,
        }, `${i < view.rewarded ? '✓ ' : ''}${fmtInt(m.points)} → ${fmtCost(m.reward, store.data)}`))));
}

function recipeCard(id, recipe, info) {
    const { data, state } = store;
    const missing = Object.entries(recipe.inputs).some(([item, q]) => (state.barn.items[item] ?? 0) < q);
    const stars = '★'.repeat(info.stars) + '☆'.repeat(STAR_COUNT - info.stars);
    const progress = info.next_star_at ? `${fmtInt(info.served)}/${fmtInt(info.next_star_at)} pour l'étoile suivante` : 'Étoiles au maximum';

    let label = 'Servir';
    if (!info.available) label = `Café niv. ${recipe.cafe_level}`;
    else if (missing) label = 'Ingrédients manquants';

    return h('div', { class: `contract recipe${info.available ? '' : ' disabled'}` },
        h('div', { class: 'row-title' }, `${recipe.emoji} ${recipe.name} `, h('span', { class: 'stars', title: progress }, stars),
            info.favourite ? h('span', { class: 'badge-x2' }, `×${data.cafe.favourite_factor}`) : null),
        h('p', { class: 'row-sub' }, progress),
        itemChips(recipe.inputs, { compareToBarn: true }),
        h('div', { class: 'contract-foot' },
            h('span', { class: 'reward' }, `+${fmtInt(info.cash)} ${data.currencies.cash.emoji} · +${fmtInt(info.points)} pts`),
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: label !== 'Servir',
                onclick: () => perform('serve_cafe', { recipe: id }),
            }, label)));
}
