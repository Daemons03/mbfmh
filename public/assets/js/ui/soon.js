import { h } from '../dom.js';

/** Placeholder for tabs delivered in later steps. extra: nodes appended below. */
export function soonPanel(title, text, extra = () => null) {
    return {
        title,
        render: () => [h('p', { class: 'muted' }, text), extra()],
    };
}
