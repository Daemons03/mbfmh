import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { serverNow, store } from '../store.js';

const GROUPS = { first: 'Montée', podium: 'Montée', middle: 'Maintien', relegated: 'Descente' };

export function tournamentPanel() {
    return {
        title: '🏅 Tournoi',
        render: () => {
            const view = store.view.tournament;
            if (!view) return [h('p', { class: 'muted' }, 'Le tournoi démarre à la prochaine synchronisation.')];

            return [
                leagueCard(view),
                lastWeekCard(view),
                pointsCard(view),
                h('p', { class: 'section-title' }, 'Classement'),
                h('ol', { class: 'ranking' }, view.ranking.map((row, i) => rankRow(row, i + 1))),
            ];
        },
    };
}

/** Group of a 1-based rank, as on the server (tournament.groups). */
function groupOf(rank) {
    for (const [group, [from, to]] of Object.entries(store.data.tournament.groups)) {
        if (rank >= from && rank <= to) return group;
    }
    return 'relegated';
}

function ordinal(rank) {
    return rank === 1 ? '1ᵉʳ' : `${fmtInt(rank)}ᵉ`;
}

function stars(count) {
    const max = store.data.tournament.stars;
    return '★'.repeat(count) + '☆'.repeat(max - count);
}

function rewardText(rewards) {
    const { data } = store;
    const parts = Object.entries(rewards).map(([id, quantity]) => (data.items[id]
        ? `${fmtInt(quantity)} ${data.items[id].emoji}`
        : fmtCost({ [id]: quantity }, data)));
    return parts.join(' · ');
}

function leagueCard(view) {
    const remaining = view.ends_at - serverNow();
    const group = groupOf(view.rank);

    return h('div', { class: 'card league' },
        h('div', { class: 'league-head' },
            h('span', { class: 'league-emoji' }, view.emoji),
            h('div', {},
                h('div', { class: 'row-title' }, view.name),
                h('div', { class: 'stars' }, stars(view.stars)))),
        h('p', { class: 'status' }, `${ordinal(view.rank)} / ${fmtInt(view.ranking.length)} · `,
            h('strong', { class: `zone zone-${group}` }, GROUPS[group])),
        h('p', { class: 'row-sub' }, `Récompense actuelle : ${rewardText(view.rewards)}`),
        h('p', { class: 'row-sub' }, 'Fin du tournoi ',
            h('strong', { dataset: { readyAt: view.ends_at, format: 'long', prefix: 'dans ' } }, `dans ${fmtDuration(Math.max(0, remaining))}`)));
}

function lastWeekCard(view) {
    const last = view.last;
    if (!last) return null;
    const move = last.new_tier > last.tier ? `montée en ${last.new_name}` : last.new_tier < last.tier ? `descente en ${last.new_name}` : 'maintien';

    return h('div', { class: 'card' },
        h('div', { class: 'row-title' }, `Tournoi précédent : ${ordinal(last.rank)} en ${last.name}`),
        h('p', { class: 'row-sub' }, `${fmtInt(last.points)} points · ${move}`),
        h('p', { class: 'row-sub' }, `Reçu : ${rewardText(last.rewards)}`));
}

/** Bar position (0-100 %): each objective takes an equal share, filled in proportion inside it. */
function objectivesProgress(points, objectives) {
    let previous = 0;
    for (const [i, goal] of objectives.entries()) {
        if (points < goal) return ((i + (points - previous) / (goal - previous)) / objectives.length) * 100;
        previous = goal;
    }
    return 100;
}

/** Points of the week and the 6 objectives. */
function pointsCard(view) {
    const count = view.objectives.length;
    const percent = objectivesProgress(view.points, view.objectives);
    const next = view.objectives.findIndex((goal) => view.points < goal);

    return h('div', { class: 'card' },
        h('div', { class: 'row-title' }, `${fmtInt(view.points)} points`),
        h('p', { class: 'row-sub' }, 'XP de marché gagnée cette semaine (contrats, tulipes).'),
        h('div', { class: 'objectives' },
            h('div', { class: 'meter' }, h('div', { style: { width: `${percent}%` } })),
            view.objectives.map((goal, i) => h('span', {
                class: `objective${view.points >= goal ? ' done' : ''}`,
                style: { left: `${((i + 1) / count) * 100}%` },
                title: `Objectif ${i + 1} : ${fmtInt(goal)} points`,
            }, String(i + 1)))),
        h('p', { class: 'row-sub' }, next === -1
            ? 'Tous les objectifs sont atteints.'
            : `Objectif ${next + 1} : ${fmtInt(view.points)} / ${fmtInt(view.objectives[next])}`),
        h('div', { class: 'objective-rewards' }, view.objective_rewards.map((reward, i) => h('div', {
            class: `objective-reward${i < view.claimed ? ' done' : ''}`,
            title: `Objectif ${i + 1} : ${fmtInt(view.objectives[i])} points`,
        }, h('span', { class: 'objective-n' }, String(i + 1)), h('span', {}, rewardText(reward)), i < view.claimed ? h('span', { class: 'check' }, '✓') : null))));
}

function rankRow(row, rank) {
    const group = groupOf(rank);
    return h('li', { class: `rank-row zone-${group}${row.me ? ' me' : ''}` },
        h('span', { class: 'rank-number' }, String(rank)),
        h('span', { class: 'rank-name' }, row.me ? `⭐ ${row.name}` : row.name),
        h('span', { class: 'rank-points' }, fmtInt(row.points)));
}
