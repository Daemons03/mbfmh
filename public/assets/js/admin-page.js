import { refresh, setUnauthenticatedHandler } from './actions.js';
import { api, ApiError } from './api.js';
import { $ } from './dom.js';
import { fmtInt } from './format.js';
import { applyResponse, notify, store, subscribe } from './store.js';
import { adminPanel } from './ui/admin.js';
import { isEditing } from './ui/sheet.js';

const panel = adminPanel();

function render() {
    if (!store.state) return;
    const { state } = store;
    $('#admin-summary').textContent = `Niveau ${state.level} · ${fmtInt(state.cash)} ${store.data.currencies.cash.emoji}`
        + ` · ${fmtInt(state.gold)} ${store.data.currencies.gold.emoji} · ${state.expansions} blocs · vitesse ×${state.settings.speed}`;
    const body = $('#admin-body');
    if (isEditing(body)) return;
    body.replaceChildren(...panel.render().flat(Infinity).filter((node) => node !== null && node !== undefined && node !== false));
}

async function boot() {
    // Not logged in: the game page has the login form.
    setUnauthenticatedHandler(() => window.location.assign('./'));
    try {
        const session = await api.session();
        if (!session.authenticated) {
            window.location.assign('./');
            return;
        }
        const [dataResponse, stateResponse] = await Promise.all([api.data(), api.state()]);
        store.data = dataResponse.data;
        applyResponse(stateResponse);
    } catch (error) {
        $('#loading').textContent = error instanceof ApiError ? error.message : 'Impossible de charger l\'admin.';
        return;
    }

    subscribe(render);
    $('#loading').hidden = true;
    $('#admin').hidden = false;
    notify();
    document.addEventListener('visibilitychange', () => !document.hidden && refresh());
}

boot();
