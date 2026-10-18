import { api, ApiError } from './api.js';
import { fmtInt } from './format.js';
import { applyResponse, definition, notify, store } from './store.js';
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
    // Barn full on a pick up: offer to sell what does not fit instead.
    if (refused) {
        const { lines, cash } = surplusFor(type, payload, refused);
        const emoji = store.data.currencies.cash.emoji;
        if (confirm(`Grange pleine, ne rentre pas :\n${lines.join('\n')}\n\nVendre ce surplus directement (+${fmtInt(cash)} ${emoji}) ?`)) {
            return perform(type, { ...payload, sell_surplus: true });
        }
    }
    if (done && closesPanel(type, payload) && isSheetOpen()) closeSheet();
    return done;
}

/** Actions that start or pick up a job: the panel closes once they succeed. */
const CLOSING = new Set(['plant', 'harvest', 'start_production', 'collect', 'start_show_animal']);

function closesPanel(type, payload) {
    if (!CLOSING.has(type)) return false;
    if (type !== 'start_production') return true;
    // Instant crafting (sewing workshop) keeps its panel open for the next item.
    const building = store.state.buildings.find((b) => b.id === payload.id);
    return definition(building.type).production?.mode !== 'instant';
}

/**
 * What a refused harvest / collect would sell to fit the barn:
 * { lines: ['12 🌽 Maïs'], cash }, or null when selling cannot help.
 */
function surplusFor(type, payload, error) {
    if (!(error instanceof ApiError) || error.code !== 'barn_full' || payload.sell_surplus) return null;
    if (type !== 'harvest' && type !== 'collect') return null;
    const outputs = store.state.buildings.find((b) => b.id === payload.id)?.job?.outputs;
    if (!outputs) return null;

    const { items } = store.data;
    const capacity = store.view.barn.capacity;
    const lines = [];
    let cash = 0;
    for (const [id, quantity] of Object.entries(outputs)) {
        if (items[id].barn_limit === false) continue;
        const surplus = quantity - Math.max(0, capacity - (store.state.barn.items[id] ?? 0));
        if (surplus <= 0) continue;
        if (items[id].sellable === false) return null;
        lines.push(`${fmtInt(surplus)} ${items[id].emoji} ${items[id].name}`);
        cash += Math.floor(surplus * items[id].barn_price);
    }

    return lines.length ? { lines, cash } : null;
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
