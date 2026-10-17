/**
 * Tiny DOM builder: h('div', { class: 'row', onclick: fn }, 'text', child).
 * Text always goes through text nodes, never innerHTML.
 */
export function h(tag, props = {}, ...children) {
    const el = document.createElement(tag);
    for (const [key, value] of Object.entries(props ?? {})) {
        if (value === null || value === undefined || value === false) continue;
        if (key === 'class') el.className = value;
        else if (key === 'dataset') Object.assign(el.dataset, value);
        else if (key === 'style') {
            for (const [prop, v] of Object.entries(value)) el.style.setProperty(prop, v);
        } else if (key.startsWith('on')) el.addEventListener(key.slice(2), value);
        else if (value === true) el.setAttribute(key, '');
        else el.setAttribute(key, String(value));
    }
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        el.append(child instanceof Node ? child : String(child));
    }
    return el;
}

export function $(selector) {
    return document.querySelector(selector);
}
