import { h } from '../dom.js';

/**
 * Modal dialog over the game (and the sheet). Resolves true when confirmed,
 * false when cancelled (button, Escape or a tap outside).
 * body: nodes; confirm / cancel: button labels.
 */
export function openModal({ title, body, confirm = 'Valider', cancel = 'Annuler' }) {
    return new Promise((resolve) => {
        const close = (answer) => {
            document.removeEventListener('keydown', onKey);
            backdrop.remove();
            resolve(answer);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close(false);
            }
        };
        const ok = h('button', { type: 'button', class: 'btn btn-primary', onclick: () => close(true) }, confirm);
        const dialog = h('div', { class: 'modal wood', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
            h('h2', { class: 'outlined modal-title' }, title),
            h('div', { class: 'modal-body' }, body,
                h('div', { class: 'modal-actions' },
                    h('button', { type: 'button', class: 'btn', onclick: () => close(false) }, cancel),
                    ok)));
        const backdrop = h('div', {
            class: 'modal-backdrop',
            onclick: (event) => event.target === backdrop && close(false),
        }, dialog);

        document.addEventListener('keydown', onKey, true);
        document.body.append(backdrop);
        ok.focus();
    });
}
