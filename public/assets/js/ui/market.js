import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt, resourceEmoji } from '../format.js';
import { canAfford, serverNow, store } from '../store.js';

export function marketPanel() {
    return {
        title: '🏪 Marché',
        render: () => [
            marketLevelBlock(),
            h('p', { class: 'muted' }, 'Livrez des produits de la grange contre de l\'argent, de l\'XP et parfois des matériaux.'),
            h('div', { class: 'list' }, store.state.contracts.map(contractCard)),
            h('p', { class: 'section-title' }, 'Améliorations du marché'),
            h('p', { class: 'muted' }, 'Chaque contrat livré rapporte en plus le bonus de ses améliorations.'),
            h('div', { class: 'list' }, Object.entries(store.data.market_upgrades.tracks).map(([id, track]) => upgradeTrackRow(id, track))),
        ],
    };
}

/** Seconds before a discarded contract's replacement is usable, or 0. */
export function waitingFor(contract) {
    const availableAt = store.view.contracts[contract.id]?.available_at;
    return availableAt === undefined ? 0 : Math.max(0, availableAt - serverNow());
}

export function canFulfill(contract) {
    return waitingFor(contract) === 0
        && Object.entries(contract.items).every(([item, quantity]) => (store.state.barn.items[item] ?? 0) >= quantity);
}

function contractCard(contract) {
    const waiting = waitingFor(contract);
    if (waiting > 0) {
        return h('div', { class: 'contract waiting' },
            h('span', { class: 'muted' }, '🕒 Nouveau contrat ',
                h('strong', { dataset: { readyAt: store.view.contracts[contract.id].available_at, format: 'long', prefix: 'dans ' } },
                    `dans ${fmtDuration(waiting)}`)));
    }

    const { data, state } = store;
    const items = Object.entries(contract.items).map(([item, quantity]) => {
        const have = state.barn.items[item] ?? 0;
        return h('span', { class: `chip${have < quantity ? ' missing' : ''}`, title: data.items[item].name },
            `${data.items[item].emoji} ${fmtInt(Math.min(have, quantity))}/${fmtInt(quantity)}`);
    });

    // Contract reward plus the market upgrades bonus, as the server pays it.
    const { reward } = contract;
    const bonus = store.view.market.bonus;
    const materials = { ...reward.materials };
    for (const [id, quantity] of Object.entries(bonus.materials)) materials[id] = (materials[id] ?? 0) + quantity;
    const rewards = [
        `+${fmtInt(reward.cash + bonus.cash)} ${data.currencies.cash.emoji}`,
        `+${fmtInt(reward.xp + bonus.xp)} XP`,
        ...Object.entries(materials).map(([id, quantity]) => `+${fmtInt(quantity)} ${resourceEmoji(id, data)}`),
    ];

    return h('div', { class: 'contract' },
        h('div', { class: 'chips' }, items),
        h('div', { class: 'contract-foot' },
            h('span', { class: 'reward' }, rewards.join(' · ')),
            h('div', { class: 'row-actions' },
                h('button', {
                    type: 'button',
                    class: 'btn',
                    onclick: () => {
                        const delay = fmtDuration(data.market.replace_delay / state.settings.speed);
                        if (confirm(`Jeter ce contrat ? Un nouveau arrivera dans ${delay}.`)) {
                            perform('discard_contract', { id: contract.id });
                        }
                    },
                }, 'Jeter'),
                h('button', {
                    type: 'button',
                    class: 'btn btn-primary',
                    disabled: !canFulfill(contract),
                    onclick: () => perform('fulfill_contract', { id: contract.id }),
                }, 'Livrer'))));
}

function effectText(effect, bonus) {
    const { data } = store;
    switch (effect.type) {
        case 'slots': return `+${fmtInt(bonus)} contrat(s) au marché`;
        case 'cash': return `+${fmtInt(bonus)} ${data.currencies.cash.emoji} par contrat`;
        case 'xp': return `+${fmtInt(bonus)} XP par contrat`;
        case 'market_xp': return `+${fmtInt(bonus)} XP de marché par contrat`;
        default: return `+${fmtInt(bonus)} ${resourceEmoji(effect.material, data)} par contrat`;
    }
}

function upgradeTrackRow(id, track) {
    const level = store.state.market_upgrades?.[id] ?? 0;
    const next = track.levels[level] ?? null;
    const current = level ? effectText(track.effect, track.levels[level - 1].bonus) : 'Aucun bonus';

    return h('div', { class: 'row' },
        h('span', { class: 'row-emoji' }, track.emoji),
        h('div', { class: 'row-main' },
            h('div', { class: 'row-title' }, `${track.name} — niv. ${level} / ${track.levels.length}`),
            h('div', { class: 'row-sub' }, current
                + (next ? ` · niv. ${next.level} : ${effectText(track.effect, next.bonus)} pour ${fmtCost(next.cost, store.data)}` : ''))),
        h('div', { class: 'row-actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-primary',
                disabled: !next || !canAfford(next.cost),
                onclick: () => perform('upgrade_market', { track: id }),
            }, next ? 'Améliorer' : 'Max')));
}

/** Market level: grows with the market XP of delivered contracts; the market looks bigger. */
function marketLevelBlock() {
    const { level, xp, needed } = store.view.market.level;
    const percent = needed ? Math.min(100, (xp / needed) * 100) : 100;

    return h('div', { class: 'card' },
        h('div', { class: 'row-title' }, `🏪 Niveau de marché ${level}`),
        h('div', { class: 'meter' }, h('div', { style: { width: `${percent}%` } })),
        h('p', { class: 'row-sub' }, needed
            ? `${fmtInt(xp)} / ${fmtInt(needed)} XP de marché · +${fmtInt(store.view.market.xp_per_contract)} par contrat livré`
            : 'Niveau de marché maximum'));
}
