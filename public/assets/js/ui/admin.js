import { performAdmin } from '../actions.js';
import { h } from '../dom.js';
import { fmtInt, fmtNumber } from '../format.js';
import { store } from '../store.js';
import { toast } from './toast.js';

const SPEED_PRESETS = [1, 10, 100, 1000];

/**
 * Admin tools, shared by the in-game tab and admin.html.
 * extra(): nodes appended at the end (logout, links).
 */
export function adminPanel(extra = () => null) {
    return {
        title: '⚙️ Admin',
        render: () => [
            speedSection(),
            levelSection(),
            jobsSection(),
            resourcesSection(),
            barnSection(),
            tournamentSection(),
            dangerSection(),
            extra(),
        ],
    };
}

// ------------------------------------------------------------ helpers

function section(title, ...children) {
    return h('section', { class: 'admin-section' }, h('p', { class: 'section-title' }, title), ...children);
}

function numberField(name, label, value, { min = 0, step = 1, max = null } = {}) {
    return h('label', { class: 'field' },
        h('span', {}, label),
        h('input', { type: 'number', name, value, min, max, step, inputmode: step === 1 ? 'numeric' : 'decimal', required: true }));
}

/** Submits through performAdmin; build(form) returns [op, payload] or null when invalid. */
function adminForm(build, submitLabel, ...fields) {
    return h('form', {
        class: 'admin-form',
        onsubmit: (event) => {
            event.preventDefault();
            const request = build(event.currentTarget);
            if (!request) return;
            document.activeElement?.blur(); // let the panel refresh with the new values
            performAdmin(...request).then((ok) => ok && toast('Modifié.'));
        },
    }, h('div', { class: 'fields' }, fields), h('button', { type: 'submit', class: 'btn btn-primary' }, submitLabel));
}

function readInt(form, name) {
    const value = Number(form.elements[name].value);
    if (!Number.isInteger(value) || value < 0) {
        toast(`Valeur invalide : ${form.elements[name].closest('label').textContent.trim()}`, 'error');
        return null;
    }
    return value;
}

function readInts(form, names) {
    const out = {};
    for (const name of names) {
        const value = readInt(form, name);
        if (value === null) return null;
        out[name] = value;
    }
    return out;
}

// ------------------------------------------------------------ sections

function speedSection() {
    const speed = store.state.settings.speed;

    return section('Vitesse du temps',
        h('p', { class: 'status' }, 'Actuelle : ', h('strong', {}, `×${fmtNumber(speed)}`)),
        h('div', { class: 'actions' }, SPEED_PRESETS.map((preset) => h('button', {
            type: 'button',
            class: `btn${preset === speed ? ' btn-primary' : ''}`,
            onclick: () => performAdmin('set_speed', { speed: preset }),
        }, `×${fmtInt(preset)}`))),
        adminForm((form) => {
            const value = Number(form.elements.speed.value);
            if (!(value >= 0.1 && value <= 1000)) {
                toast('Vitesse entre 0,1 et 1 000.', 'error');
                return null;
            }
            return ['set_speed', { speed: value }];
        }, 'Appliquer', numberField('speed', 'Vitesse personnalisée', speed, { min: 0.1, max: 1000, step: 'any' })));
}

function levelSection() {
    const max = store.data.player.max_level;

    return section('Niveau',
        adminForm((form) => {
            const level = readInt(form, 'level');
            if (level === null) return null;
            if (level < 1 || level > max) {
                toast(`Niveau entre 1 et ${max}.`, 'error');
                return null;
            }
            return ['set_level', { level }];
        }, 'Appliquer', numberField('level', `Niveau (1 à ${max})`, store.state.level, { min: 1, max })),
        h('div', { class: 'actions' },
            h('button', {
                type: 'button',
                class: 'btn',
                onclick: () => {
                    if (confirm('Passer au niveau maximum et acheter tout le terrain ?')) performAdmin('unlock_all');
                },
            }, '🔓 Tout débloquer (niveau max + terrain)')));
}

function jobsSection() {
    const running = store.state.buildings.filter((b) => b.job).length;
    const waiting = store.state.contracts.filter((c) => c.wait).length;

    return section('Productions',
        h('p', { class: 'row-sub' }, `${running} production(s) en cours ou à récupérer, ${waiting} contrat(s) en attente.`),
        h('div', { class: 'actions' },
            h('button', {
                type: 'button',
                class: 'btn',
                disabled: running + waiting === 0,
                onclick: () => performAdmin('finish_all_jobs'),
            }, '⏩ Tout terminer maintenant')));
}

function resourcesSection() {
    const { data, state } = store;
    const materialIds = Object.keys(data.materials);

    return section('Ressources',
        adminForm((form) => {
            const currencies = readInts(form, ['cash', 'gold']);
            const materials = currencies && readInts(form, materialIds);
            return materials ? ['set_resources', { ...currencies, materials }] : null;
        }, 'Enregistrer les ressources',
        numberField('cash', `${data.currencies.cash.emoji} ${data.currencies.cash.name}`, state.cash),
        numberField('gold', `${data.currencies.gold.emoji} ${data.currencies.gold.name}`, state.gold),
        materialIds.map((id) => numberField(id, `${data.materials[id].emoji} ${data.materials[id].name}`, state.materials[id] ?? 0))));
}

function barnSection() {
    const { data, state } = store;
    const itemIds = Object.keys(data.items);

    return section('Grange (peut dépasser la capacité)',
        adminForm((form) => {
            const barn = readInts(form, itemIds);
            return barn ? ['set_resources', { barn }] : null;
        }, 'Enregistrer la grange',
        itemIds.map((id) => numberField(id, `${data.items[id].emoji} ${data.items[id].name}`, state.barn.items[id] ?? 0))));
}

const CURVE_GROUPS = { first: '1ᵉʳ', podium: 'Podium (2ᵉ–3ᵉ)', middle: 'Milieu de tableau', relegated: 'Relégués' };
const CURVE_PERCENTS = Array.from({ length: 21 }, (_, i) => i * 5); // 0 to 100 %

/** Invented farms of the tournament: group -> objective + percent above it. */
function tournamentSection() {
    const view = store.view.tournament;
    if (!view) return null;
    const select = (name, options, value, label) => h('label', { class: 'field' },
        h('span', {}, label),
        h('select', { name }, options.map(([v, text]) => h('option', { value: v, selected: Number(v) === value }, text))));

    return section('Tournoi',
        h('p', { class: 'row-sub' }, 'Score de fin de semaine des fermes fictives : objectif de points + pourcentage (chaque ferme tire entre −5 % et ce réglage).'),
        adminForm((form) => {
            const curve = {};
            for (const group of Object.keys(CURVE_GROUPS)) {
                curve[group] = { objective: Number(form.elements[`${group}-objective`].value), percent: Number(form.elements[`${group}-percent`].value) };
            }
            return ['set_tournament_curve', { curve }];
        }, 'Enregistrer la courbe',
        Object.entries(CURVE_GROUPS).map(([group, label]) => h('div', { class: 'curve-row' },
            h('strong', {}, label),
            select(`${group}-objective`, [1, 2, 3, 4, 5, 6].map((n) => [n, `Objectif ${n}`]), view.curve[group].objective, 'Objectif'),
            select(`${group}-percent`, CURVE_PERCENTS.map((n) => [n, `+${n} %`]), view.curve[group].percent, 'Au-dessus')))),
        h('div', { class: 'actions' },
            h('button', {
                type: 'button',
                class: 'btn',
                onclick: () => {
                    if (confirm('Terminer le tournoi maintenant (classement, récompenses, montée / descente) ?')) performAdmin('finish_tournament_week');
                },
            }, '🏁 Terminer le tournoi maintenant')));
}

function dangerSection() {
    return section('Zone dangereuse',
        h('div', { class: 'actions' },
            h('button', {
                type: 'button',
                class: 'btn btn-danger',
                onclick: () => {
                    const answer = prompt('La ferme repartira de zéro. Tapez RESET pour confirmer.');
                    if (answer === 'RESET') performAdmin('reset_farm');
                },
            }, '🗑️ Réinitialiser la ferme')));
}
