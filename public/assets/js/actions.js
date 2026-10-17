import { api, ApiError } from './api.js';
import { applyResponse, notify, store } from './store.js';
import { toast } from './ui/toast.js';

let onUnauthenticated = () => {};

export function setUnauthenticatedHandler(handler) {
    onUnauthenticated = handler;
}

/**
 * Sends one game action to the server and applies the answer.
 * Returns true on success. Only one request changes the farm at a time.
 */
export function perform(type, payload = {}) {
    return send(() => api.action(type, payload, store.version));
}

/** Same as perform() for admin operations. */
export function performAdmin(op, payload = {}) {
    return send(() => api.admin(op, payload, store.version));
}

async function send(request) {
    if (store.busy) return false;
    store.busy = true;
    try {
        applyResponse(await request());
        notify();
        return true;
    } catch (error) {
        handleError(error);
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
