import { perform } from '../actions.js';
import { h } from '../dom.js';
import { fmtDuration, fmtInt, resourceEmoji } from '../format.js';
import { serverNow, store } from '../store.js';

export function marketPanel() {
    return {
        title: '🏪 Marché',
        render: () => [
            h('p', { class: 'muted' }, 'Livrez des produits de la grange contre de l\'argent, de l\'XP et parfois des matériaux.'),
            h('div', { class: 'list' }, store.state.contracts.map(contractCard)),
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

    const reward = contract.reward;
    const rewards = [
        `+${fmtInt(reward.cash)} ${data.currencies.cash.emoji}`,
        `+${fmtInt(reward.xp)} XP`,
        ...Object.entries(reward.materials ?? {}).map(([id, quantity]) => `+${fmtInt(quantity)} ${resourceEmoji(id, data)}`),
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
