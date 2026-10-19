import { api } from '../api.js';
import { performAdmin } from '../actions.js';
import { h } from '../dom.js';
import { fmtInt, fmtNumber } from '../format.js';
import { store } from '../store.js';
import { openSheet, refreshSheet } from './sheet.js';
import { toast } from './toast.js';

/** Ranking from GET /api/bots, kept a minute; one profile per bot id. */
let ranking = null; // { bots, me, world, at }
let loading = null;
const profiles = new Map();

const SORTS = {
    level: ['Niveau', (b) => [b.level, b.market_xp]],
    market: ['Marché', (b) => [b.market_xp, b.level]],
    tier: ['Palier', (b) => [b.tier, b.market_xp]],
};
let sort = 'level';
let title = 'all';
let view = 'top';

export function invalidateBots() {
    ranking = null;
    profiles.clear();
}

function load() {
    if (ranking && Date.now() - ranking.at < 60_000) return;
    loading ??= api.bots()
        .then((body) => { ranking = { ...body, at: Date.now() }; })
        .catch(() => { ranking = { bots: [], me: null, world: null, at: Date.now(), failed: true }; })
        .finally(() => { loading = null; refreshSheet(); });
}

/** Title badge of a bot: its profile (90, 70...). */
export function titleBadge(text) {
    return text ? h('span', { class: `bot-title bot-title-${text}`, title: `Profil ${text} %` }, text) : null;
}

/** Ranking of every bot and the player, sorted by level, market or tier. */
export function rankingBlock(back) {
    load();
    if (!ranking) return h('p', { class: 'muted' }, 'Chargement du classement…');
    if (ranking.failed || !ranking.bots.length) return h('p', { class: 'muted' }, 'Pas encore de bots.');

    const key = SORTS[sort][1];
    const me = { ...ranking.me, name: 'Ma ferme', me: true };
    const rows = [...ranking.bots.filter((b) => title === 'all' || b.title === title), me]
        .sort((a, b) => {
            const [a1, a2] = key(a);
            const [b1, b2] = key(b);
            return b1 - a1 || b2 - a2 || (a.me ? -1 : b.me ? 1 : 0);
        });
    const myRank = rows.findIndex((r) => r.me);
    const shown = view === 'top'
        ? rows.slice(0, 100)
        : rows.slice(Math.max(0, myRank - 10), myRank + 11);
    const firstRank = view === 'top' ? 0 : Math.max(0, myRank - 10);

    const chip = (active, label, onclick) => h('button', { type: 'button', class: `chip-btn${active ? ' active' : ''}`, onclick }, label);

    return [
        h('p', { class: 'row-sub' }, `${fmtInt(ranking.bots.length)} fermes · ma place : `, h('strong', {}, `${fmtInt(myRank + 1)}ᵉ`),
            ranking.world ? ` · bots ×${fmtNumber(ranking.world.speed)}` : ''),
        h('div', { class: 'chip-row' },
            Object.entries(SORTS).map(([id, [label]]) => chip(sort === id, label, () => { sort = id; refreshSheet(); }))),
        h('div', { class: 'chip-row' },
            chip(title === 'all', 'Tous', () => { title = 'all'; refreshSheet(); }),
            store.data.bots.profiles.map((p) => chip(title === p.title, p.title, () => { title = p.title; refreshSheet(); })),
            h('span', { class: 'chip-sep' }),
            chip(view === 'top', 'Top 100', () => { view = 'top'; refreshSheet(); }),
            chip(view === 'me', 'Autour de moi', () => { view = 'me'; refreshSheet(); })),
        h('ol', { class: 'ranking world' }, shown.map((row, i) => h('li', {
            class: `rank-row world${row.me ? ' me' : ''}`,
            onclick: row.me ? null : () => openSheet(botPanel(row.id, back)),
        },
        h('span', { class: 'rank-number' }, fmtInt(firstRank + i + 1)),
        h('span', { class: 'rank-name' }, row.me ? `⭐ ${row.name}` : row.name, ' ', titleBadge(row.title)),
        h('span', { class: 'rank-stats' }, `niv. ${fmtInt(row.level)} · 🏪 ${fmtInt(row.market_level)}`),
        h('span', { class: 'rank-tier' }, row.tier_name)))),
    ];
}

/** Profile of one bot: level, market, tier, land and buildings. back(): panel to return to. */
export function botPanel(id, back) {
    return {
        title: () => {
            const bot = profiles.get(id);
            return bot ? [h('span', { class: 'title-name' }, `🤖 ${bot.name}`), h('span', { class: 'title-sub' }, `Profil ${bot.title} %`)] : 'Ferme';
        },
        render: () => {
            if (!profiles.has(id)) {
                profiles.set(id, null);
                api.bot(id).then((body) => profiles.set(id, body.bot)).catch(() => profiles.delete(id)).finally(refreshSheet);
            }
            const bot = profiles.get(id);
            const backButton = back ? h('button', { type: 'button', class: 'btn', onclick: () => openSheet(back()) }, '← Retour') : null;
            if (!bot) return [backButton, h('p', { class: 'muted' }, 'Chargement…')];
            const { buildings } = store.data;

            return [
                backButton,
                h('div', { class: 'card' },
                    h('div', { class: 'row-title' }, bot.name, ' ', titleBadge(bot.title)),
                    h('p', { class: 'status' }, `Niveau ${fmtInt(bot.level)} · marché niv. ${fmtInt(bot.market_level)} · ${bot.tier_name}`),
                    h('p', { class: 'row-sub' }, `${fmtInt(bot.cash)} ${store.data.currencies.cash.emoji} · ${fmtInt(bot.expansions)} extensions de terrain`)),
                h('p', { class: 'section-title' }, 'Bâtiments'),
                h('div', { class: 'list' }, bot.buildings
                    .sort((a, b) => (buildings[a.type]?.levels[0].unlock_level ?? 0) - (buildings[b.type]?.levels[0].unlock_level ?? 0))
                    .map(({ type, levels }) => h('div', { class: 'row' },
                        h('span', { class: 'row-emoji' }, buildings[type]?.emoji ?? '🏠'),
                        h('div', { class: 'row-main' },
                            h('div', { class: 'row-title' }, `${levels.length} × ${buildings[type]?.name ?? type}`),
                            h('div', { class: 'row-sub' }, `niveau${levels.length > 1 ? 'x' : ''} ${levels.sort((a, b) => b - a).join(', ')}`)),
                        h('span')))),
            ];
        },
    };
}

/** Admin: bot speed, a few days at once, a new world. */
export function botAdminSection(section, numberField) {
    load();
    const speed = ranking?.world?.speed ?? 1;
    const done = (ok) => {
        if (!ok) return;
        invalidateBots();
        load();
        toast('Bots modifiés.');
    };

    return section('Bots',
        h('p', { class: 'status' }, 'Vitesse des bots : ', h('strong', {}, `×${fmtNumber(speed)}`),
            ' (jours de jeu par jour réel, indépendante de la vôtre)'),
        h('div', { class: 'actions' }, [0.5, 1, 2, 5].map((preset) => h('button', {
            type: 'button',
            class: `btn${preset === speed ? ' btn-primary' : ''}`,
            onclick: () => performAdmin('set_bot_speed', { speed: preset }).then(done),
        }, `🤖 ×${fmtNumber(preset)}`))),
        h('form', {
            class: 'admin-form',
            onsubmit: (event) => {
                event.preventDefault();
                const days = Number(event.currentTarget.elements.bot_days.value);
                if (!Number.isInteger(days) || days < 1 || days > 60) {
                    toast('Entre 1 et 60 jours.', 'error');
                    return;
                }
                document.activeElement?.blur();
                performAdmin('advance_bots', { days }).then(done);
            },
        }, h('div', { class: 'fields' }, numberField('bot_days', 'Faire jouer les bots (jours)', 1, { min: 1, max: 60 })),
        h('button', { type: 'submit', class: 'btn btn-primary' }, 'Avancer')),
        h('div', { class: 'actions' }, h('button', {
            type: 'button',
            class: 'btn btn-danger',
            onclick: () => {
                if (confirm('Recréer les 1 000 bots au niveau 1 ?')) performAdmin('reset_bots').then(done);
            },
        }, 'Réinitialiser les bots')));
}
