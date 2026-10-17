import { h, $ } from '../dom.js';

/** kind: 'info' | 'warn' | 'error' */
export function toast(message, kind = 'info', duration = 3500) {
    const el = h('div', { class: `toast ${kind}`, role: kind === 'error' ? 'alert' : 'status' }, message);
    $('#toasts').append(el);
    setTimeout(() => el.remove(), duration);
}
