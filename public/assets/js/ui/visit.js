import { api, ApiError } from '../api.js';
import { refresh } from '../actions.js';
import { $ } from '../dom.js';
import { notify, store } from '../store.js';
import { closeSheet } from './sheet.js';
import { toast } from './toast.js';

/**
 * Looking at another farm (a real bot or another account): its map and top
 * bar instead of ours, read only, refreshed with the usual sync, until
 * "Retour". Our own state is reloaded then.
 */
export async function startVisit(login) {
    try {
        show(await api.farm(login));
    } catch (error) {
        toast(error instanceof ApiError ? error.message : 'Ferme introuvable.', 'warn');
        return;
    }
    closeSheet();
    document.body.classList.add('visiting');
}

/** Sync while visiting: the visited farm again. */
export async function refreshVisit() {
    try {
        show(await api.farm(store.visiting.login));
    } catch {
        // offline or gone: keep what is shown
    }
}

export function stopVisit() {
    store.visiting = null;
    document.body.classList.remove('visiting');
    $('#visit-banner').hidden = true;
    refresh();
}

function show(body) {
    const { farm } = body;
    store.offset = body.server_now - Date.now() / 1000;
    store.visiting = { login: farm.login, autoplay: farm.autoplay };
    store.state = farm.state;
    store.view = farm.view;
    const banner = $('#visit-banner');
    const who = farm.autoplay ? `🤖 ${farm.login} (bot ${Math.round(farm.autoplay * 100)} %)` : `👤 ${farm.login}`;
    const label = document.createElement('span');
    label.textContent = `👀 Ferme de ${who}`;
    banner.replaceChildren(label);
    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'btn btn-primary';
    back.textContent = 'Retour';
    back.addEventListener('click', stopVisit);
    banner.append(back);
    banner.hidden = false;
    notify();
}
