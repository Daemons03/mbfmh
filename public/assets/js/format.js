const integer = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
const decimal = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 });

export function fmtInt(n) {
    return integer.format(n);
}

export function fmtNumber(n) {
    return decimal.format(n);
}

/** 45 s · 4 min 05 s · 1 h 05 min · 2 j 3 h */
export function fmtDuration(seconds) {
    const s = Math.max(0, Math.ceil(seconds));
    if (s < 60) return `${s} s`;
    if (s < 3600) {
        const m = Math.floor(s / 60);
        const rest = s % 60;
        return rest ? `${m} min ${String(rest).padStart(2, '0')} s` : `${m} min`;
    }
    if (s < 86400) {
        const hours = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return m ? `${hours} h ${String(m).padStart(2, '0')} min` : `${hours} h`;
    }
    const d = Math.floor(s / 86400);
    const hours = Math.floor((s % 86400) / 3600);
    return hours ? `${d} j ${hours} h` : `${d} j`;
}

/** Compact countdown for grid badges: 4:05, 1h05, 2j3h */
export function fmtShort(seconds) {
    const s = Math.max(0, Math.ceil(seconds));
    if (s < 3600) return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
    if (s < 86400) return `${Math.floor(s / 3600)}h${String(Math.floor((s % 3600) / 60)).padStart(2, '0')}`;
    return `${Math.floor(s / 86400)}j${Math.floor((s % 86400) / 3600)}h`;
}

/** "250 💵 · 5 🪵" from a cost object, using data emojis. */
export function fmtCost(cost, data) {
    const parts = Object.entries(cost ?? {})
        .filter(([, quantity]) => quantity > 0)
        .map(([resource, quantity]) => `${fmtInt(quantity)} ${resourceEmoji(resource, data)}`);
    return parts.length ? parts.join(' · ') : 'Gratuit';
}

export function resourceEmoji(resource, data) {
    return data.currencies[resource]?.emoji ?? data.materials[resource]?.emoji ?? resource;
}
