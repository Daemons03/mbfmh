/**
 * Client-side copy of the server state. The server is the authority: the
 * store is only replaced by API responses, never edited locally.
 */
export const store = {
    data: null,      // game-data.json
    state: null,     // state_json
    version: 0,
    view: null,      // derived values from the server
    offset: 0,       // server clock - local clock, in seconds
    busy: false,
    mode: null,      // { kind: 'build', type } | { kind: 'move', id }
};

const listeners = new Set();

export function subscribe(listener) {
    listeners.add(listener);
}

export function notify() {
    for (const listener of listeners) listener(store);
}

/** Takes state/version/view/server_now from any API response body. */
export function applyResponse(body) {
    if (typeof body.server_now === 'number') {
        store.offset = body.server_now - Date.now() / 1000;
    }
    if (body.state) {
        store.state = body.state;
        store.version = body.version;
        store.view = body.view;
    }
}

export function serverNow() {
    return Date.now() / 1000 + store.offset;
}

export function definition(type) {
    return store.data.buildings[type];
}

export function buildingById(id) {
    return store.state.buildings.find((b) => b.id === id) ?? null;
}

export function jobStatus(building) {
    const job = store.view.jobs[building.id];
    if (!building.job || !job) return null;
    const remaining = job.ready_at - serverNow();
    return { readyAt: job.ready_at, remaining, ready: remaining <= 0 };
}

export function resource(id) {
    if (id === 'cash' || id === 'gold') return store.state[id];
    return store.state.materials[id] ?? 0;
}

/** Cash of a production cost after the happiness effect (same rounding as the server). */
export function productionCost(cost) {
    if (!cost?.cash) return cost ?? {};
    return { ...cost, cash: Math.round(cost.cash * store.view.happiness.cost_factor) };
}

/** Workers a building level uses (> 0) or provides (< 0 returned as negative need). */
export function workersNeeded(def, fromLevel, toLevel) {
    const before = fromLevel > 0 ? (def.levels[fromLevel - 1].workers ?? 0) : 0;
    return before - (def.levels[toLevel - 1].workers ?? 0);
}

/** Client-side hint only; the server decides. */
export function canAfford(cost) {
    return Object.entries(cost ?? {}).every(([id, quantity]) => resource(id) >= quantity);
}
