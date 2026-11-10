import { h } from '../dom.js';
import { fmtCost, fmtDuration, fmtInt } from '../format.js';
import { serverNow, store } from '../store.js';
import { botPanel, rankingBlock, titleBadge } from './bots.js';
import { openSheet, refreshSheet } from './sheet.js';
import { startVisit } from './visit.js';

const LOTS = { first: 'Lot A', podium: 'Lot B', middle: 'Lot C', relegated: 'Lot D' };

/** Open tab: the player's league or the ranking of every farm. */
let tab = 'league';

export function tournamentPanel() {
    return {
        title: '🏅 Tournoi',
        render: () => {
            const view = store.view.tournament;
            if (!view) return [h('p', { class: 'muted' }, 'Le tournoi démarre à la prochaine synchronisation.')];
            const tabButton = (id, label) => h('button', {
                type: 'button',
                role: 'tab',
                class: `subtab${tab === id ? ' active' : ''}`,
                'aria-selected': tab === id ? 'true' : 'false',
                onclick: () => {
                    tab = id;
                    refreshSheet();
                },
            }, label);

            return [
                h('div', { class: 'subtabs', role: 'tablist' }, tabButton('league', '🏅 Ma ligue'), tabButton('world', '🌍 Classement')),
                tab === 'world' ? rankingBlock(tournamentPanel) : [
                    leagueCard(view),
                    lastWeekCard(view),
                    pointsCard(view),
                    h('p', { class: 'section-title' }, 'Ma ligue'),
                    h('ol', { class: 'ranking' }, view.ranking.flatMap((row, i) => {
                        // A header where each lot starts.
                        const group = groupOf(view, i + 1);
                        const head = i === 0 || groupOf(view, i) !== group
                            ? h('li', { class: `rank-lot zone-${group}` }, lotLabel(view, group))
                            : null;
                        return [head, rankRow(view, row, i + 1)];
                    })),
                ],
            ];
        },
    };
}

/** Lot of a 1-based rank, as on the server (the league's lots; to = null: the end). */
function groupOf(view, rank) {
    for (const [group, [from, to]] of Object.entries(view.lots)) {
        if (rank >= from && (to === null || rank <= to)) return group;
    }
    return 'relegated';
}

/** What a lot does at the end of the week in this tier: A and B go up, D goes down. */
function moveOf(view, group) {
    if ((group === 'first' || group === 'podium') && view.tier < view.top) return 'montée';
    if (group === 'relegated' && view.tier > 0) return 'descente';
    return 'maintien';
}

function lotLabel(view, group) {
    return `${LOTS[group]} · ${moveOf(view, group)}`;
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
    const group = view.group;

    return h('div', { class: 'card league' },
        h('div', { class: 'league-head' },
            h('span', { class: 'league-emoji' }, view.emoji),
            h('div', {},
                h('div', { class: 'row-title' }, view.name),
                h('div', { class: 'stars' }, stars(view.stars)))),
        h('p', { class: 'status' }, `${ordinal(view.rank)} / ${fmtInt(view.ranking.length)} · `,
            h('strong', { class: `zone zone-${group}` }, lotLabel(view, group))),
        h('p', { class: 'row-sub' }, `Récompense actuelle : ${rewardText(view.rewards)}`),
        h('p', { class: 'row-sub' }, 'Fin du tournoi ',
            h('strong', { dataset: { readyAt: view.ends_at, format: 'long', prefix: 'dans ' } }, `dans ${fmtDuration(Math.max(0, remaining))}`)));
}

function lastWeekCard(view) {
    const last = view.last;
    if (!last) return null;
    const move = last.new_tier > last.tier ? `montée en ${last.new_name}` : last.new_tier < last.tier ? `descente en ${last.new_name}` : 'maintien';

    return h('div', { class: 'card' },
        h('div', { class: 'row-title' }, `Tournoi précédent : ${ordinal(last.rank)}${last.size ? ` / ${fmtInt(last.size)}` : ''} en ${last.name}`
            + (last.group ? ` · ${LOTS[last.group]}` : '')),
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

function rankRow(view, row, rank) {
    const group = groupOf(view, rank);
    const bot = !row.me && row.id;
    let open = null;
    if (bot) open = () => openSheet(botPanel(row.id, tournamentPanel));
    else if (row.player) open = () => startVisit(row.name);
    return h('li', {
        class: `rank-row zone-${group}${row.me ? ' me' : ''}${row.player ? ' player' : ''}${open ? ' clickable' : ''}`,
        title: row.player ? `Voir la ferme de ${row.name}` : null,
        onclick: open,
    },
    h('span', { class: 'rank-number' }, fmtInt(rank)),
    h('span', { class: 'rank-name' }, row.me ? `⭐ ${row.name}` : row.player ? `${row.title ? '🤖' : '👤'} ${row.name}` : row.name, ' ', titleBadge(row.title)),
    h('span', { class: 'rank-points' }, fmtInt(row.points)));
}
