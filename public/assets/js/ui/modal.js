import { h } from '../dom.js';

/**
 * Modal dialog over the game (and the sheet). Resolves true when confirmed,
 * false when cancelled (button, Escape or a tap outside).
 * body: nodes, or (setConfirmEnabled) => nodes when the body decides whether
 * the confirm button can be pressed (it starts disabled then).
 * danger: red confirm button (destroying, losing things).
 */
export function openModal({ title, body, confirm = 'Valider', cancel = 'Annuler', danger = false }) {
    return new Promise((resolve) => {
        const close = (answer) => {
            document.removeEventListener('keydown', onKey, true);
            backdrop.remove();
            resolve(answer);
        };
        const onKey = (event) => {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close(false);
            }
        };
        const ok = h('button', {
            type: 'button',
            class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`,
            onclick: () => !ok.disabled && close(true),
        }, confirm);
        const nodes = typeof body === 'function'
            ? (ok.disabled = true, body((enabled) => { ok.disabled = !enabled; }))
            : body;
        const dialog = h('div', { class: 'modal wood', role: 'dialog', 'aria-modal': 'true', 'aria-label': title },
            h('h2', { class: 'outlined modal-title' }, title),
            h('div', { class: 'modal-body' }, nodes,
                h('div', { class: 'modal-actions' },
                    h('button', { type: 'button', class: 'btn', onclick: () => close(false) }, cancel),
                    ok)));
        const backdrop = h('div', {
            class: 'modal-backdrop',
            onclick: (event) => event.target === backdrop && close(false),
        }, dialog);

        document.addEventListener('keydown', onKey, true);
        document.body.append(backdrop);
        (dialog.querySelector('input') ?? ok).focus();
    });
}

/** Yes / no question in a modal: text, then the two buttons. */
export function confirmModal(title, text, { confirm = 'Confirmer', cancel = 'Annuler', danger = false } = {}) {
    return openModal({ title, body: [text].flat().map((line) => h('p', {}, line)), confirm, cancel, danger });
}
