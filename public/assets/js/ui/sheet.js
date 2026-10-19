import { $ } from '../dom.js';

/**
 * Bottom sheet (side panel on desktop). The body is a render function so the
 * open panel refreshes after every state change.
 */
let current = null; // { title, render }

export function openSheet(panel) {
    current = panel;
    $('#sheet').hidden = false;
    $('#sheet-backdrop').hidden = false;
    refreshSheet();
}

export function closeSheet() {
    current = null;
    $('#sheet').hidden = true;
    $('#sheet-backdrop').hidden = true;
    document.dispatchEvent(new CustomEvent('sheet:closed'));
}

export function isSheetOpen() {
    return current !== null;
}

export function refreshSheet() {
    if (!current) return;
    // Do not wipe what the player is typing (periodic sync, countdowns).
    if (isEditing($('#sheet'))) return;
    const body = current.render();
    if (body === null) {
        closeSheet(); // panel subject disappeared (e.g. demolished building)
        return;
    }
    // A title is text, or nodes (name with the level below it).
    const title = typeof current.title === 'function' ? current.title() : current.title;
    $('#sheet-title').replaceChildren(...[title].flat());
    const scroll = $('#sheet-body').scrollTop;
    $('#sheet-body').replaceChildren(...[body].flat(Infinity).filter((node) => node !== null && node !== undefined && node !== false));
    $('#sheet-body').scrollTop = scroll;
}

export function isEditing(container) {
    const active = document.activeElement;
    return active !== null && container.contains(active) && active.matches('input, select, textarea');
}

export function initSheet() {
    $('#sheet-close').addEventListener('click', closeSheet);
    $('#sheet-backdrop').addEventListener('click', closeSheet);
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && current) closeSheet();
    });
}
