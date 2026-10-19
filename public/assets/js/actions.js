import { api, ApiError } from './api.js';
import { h } from './dom.js';
import { fmtInt } from './format.js';
import { applyResponse, buildingById, definition, notify, store } from './store.js';
import { openModal } from './ui/modal.js';
import { closeSheet, isSheetOpen } from './ui/sheet.js';
import { toast } from './ui/toast.js';

let onUnauthenticated = () => {};

export function setUnauthenticatedHandler(handler) {
    onUnauthenticated = handler;
}

/**
 * Sends one game action to the server and applies the answer.
 * Returns true on success. Only one request changes the farm at a time.
 */
export async function perform(type, payload = {}) {
    let refused = null;
    const done = await send(() => api.action(type, payload, store.version), (error) => {
        if (!surplusFor(type, payload, error)) return false;
        refused = error;
        return true;
    });
    // Barn full on a pick up: offer to sell what does not fit, or more.
    if (refused) {
        const sell = await askSurplusSale(surplusFor(type, payload, refused));
        if (sell) return perform(type, { ...payload, sell });
    }
    if (done && closesPanel(type, payload) && isSheetOpen()) closeSheet();
    return done;
}

/** Actions that start or pick up a job: the panel closes once they succeed. */
const CLOSING = new Set(['plant', 'plant_all', 'harvest', 'start_production', 'start_production_all', 'collect', 'start_show_animal']);

function closesPanel(type, payload) {
    if (!CLOSING.has(type)) return false;
    if (type !== 'start_production' && type !== 'start_production_all') return true;
    // Instant crafting (sewing workshop) keeps its panel open for the next item.
    const building = store.state.buildings.find((b) => b.id === payload.id);
    return definition(building.type).production?.mode !== 'instant';
}

/**
 * What a refused harvest / collect (or show animal) cannot store: one line per product that
 * does not fit, { id, item, incoming, have, min } (min = what must be sold),
 * or null when selling cannot help.
 */
function surplusFor(type, payload, error) {
    if (!(error instanceof ApiError) || error.code !== 'barn_full' || payload.sell_surplus || payload.sell) return null;
    const outputs = type === 'collect_show_animal'
        ? store.state.show?.active?.[payload.species]?.outputs
        : (type === 'harvest' || type === 'collect') && store.state.buildings.find((b) => b.id === payload.id)?.job?.outputs;
    if (!outputs) return null;

    const { items } = store.data;
    const capacity = store.view.barn.capacity;
    const lines = [];
    for (const [id, incoming] of Object.entries(outputs)) {
        if (items[id].barn_limit === false) continue;
        const have = store.state.barn.items[id] ?? 0;
        const min = incoming - Math.max(0, capacity - have);
        if (min <= 0) continue;
        if (items[id].sellable === false) return null;
        lines.push({ id, item: items[id], incoming, have, min });
    }

    return lines.length ? lines : null;
}

/**
 * Modal: one slider per product that does not fit, from what must go to
 * all of it (harvest and barn). Resolves { item: quantity } or null.
 */
async function askSurplusSale(lines) {
    if (!lines) return null;
    const cash = store.data.currencies.cash.emoji;
    const capacity = store.view.barn.capacity;
    const chosen = Object.fromEntries(lines.map((line) => [line.id, line.min]));
    const total = h('strong', {});
    const updateTotal = () => {
        total.textContent = `+${fmtInt(lines.reduce((sum, l) => sum + Math.floor(chosen[l.id] * l.item.barn_price), 0))} ${cash}`;
    };

    const rows = lines.map((line) => {
        const max = line.incoming + line.have;
        const amount = h('strong', {});
        const after = h('span', { class: 'row-sub' });
        const show = () => {
            amount.textContent = `${fmtInt(chosen[line.id])} ${line.item.emoji}`;
            after.textContent = `+${fmtInt(Math.floor(chosen[line.id] * line.item.barn_price))} ${cash} · il en restera ${fmtInt(max - chosen[line.id])} / ${fmtInt(capacity)} en grange`;
        };
        show();
        return h('div', { class: 'surplus-row' },
            h('div', { class: 'row-title' }, `${line.item.emoji} ${line.item.name} : `, h('span', { class: 'row-sub' },
                `${fmtInt(line.have)} en grange + ${fmtInt(line.incoming)} récoltés, ${fmtInt(line.min)} ne rentrent pas`)),
            h('div', { class: 'sell' },
                h('input', {
                    type: 'range',
                    min: line.min,
                    max,
                    value: line.min,
                    'aria-label': `${line.item.name} à vendre`,
                    oninput: (event) => {
                        chosen[line.id] = Number(event.target.value);
                        show();
                        updateTotal();
                    },
                }),
                amount),
            after);
    });
    updateTotal();

    const ok = await openModal({
        title: 'Grange pleine',
        body: [
            h('p', {}, 'Choisissez combien vendre au prix de la grange (au moins ce qui ne rentre pas) :'),
            rows,
            h('p', { class: 'status' }, 'Total : ', total),
        ],
        confirm: 'Vendre et récolter',
    });

    return ok ? chosen : null;
}

/** Same as perform() for admin operations. */
export function performAdmin(op, payload = {}) {
    return send(() => api.admin(op, payload, store.version));
}

/** onRefused(error) returns true when it takes care of the error itself. */
async function send(request, onRefused = () => false) {
    if (store.busy) return false;
    store.busy = true;
    try {
        applyResponse(await request());
        notify();
        return true;
    } catch (error) {
        if (!onRefused(error)) handleError(error);
        return false;
    } finally {
        store.busy = false;
    }
}

/** Reloads the authoritative state (periodic sync, tab focus, after 409). */
export async function refresh() {
    if (store.busy) return;
    try {
        applyResponse(await api.state());
        notify();
    } catch (error) {
        if (error instanceof ApiError && error.status === 0) return; // offline: keep last state, retry later
        handleError(error);
    }
}

function handleError(error) {
    if (!(error instanceof ApiError)) {
        console.error(error);
        toast('Une erreur inattendue est survenue.', 'error');
        return;
    }
    switch (error.status) {
        case 409:
            applyResponse(error.body);
            notify();
            toast(error.message, 'warn');
            break;
        case 401:
        case 403:
            onUnauthenticated();
            break;
        case 422:
            toast(error.message, 'warn');
            break;
        default:
            toast(error.message, 'error');
    }
}

/**
 * "Tout planter" / "tout produire" (plant_all, start_production_all) over the
 * idle buildings `ids`: warns when resources ran out before the last one.
 */
export async function performAll(type, payload, ids) {
    const done = await perform(type, payload);
    const left = ids.filter((id) => !buildingById(id)?.job).length;
    if (done && left) {
        const what = type === 'plant_all' ? ['planté', 'champ'] : ['lancé', 'bâtiment'];
        const plural = (n, word) => `${n} ${word}${n > 1 ? 's' : ''}`;
        toast(`${plural(ids.length - left, what[1])} ${what[0]}${ids.length - left > 1 ? 's' : ''} ; ${plural(left, what[1])} `
            + `reste${left > 1 ? 'nt' : ''} libre${left > 1 ? 's' : ''} : pas assez de ressources (cash, ingrédients, humus ou semences).`, 'warn', 6000);
    }
    return done;
}

/** Idle buildings started together with `building` by plant_all / start_production_all, itself first. */
export function idleGroup(building) {
    const def = definition(building.type);
    const base = def.variant_of ?? building.type;
    const same = def.kind === 'field'
        ? (b) => definition(b.type).kind === 'field'
        : (b) => (definition(b.type).variant_of ?? b.type) === base;
    return [building.id, ...store.state.buildings.filter((b) => b.id !== building.id && !b.job && same(b)).map((b) => b.id)];
}
