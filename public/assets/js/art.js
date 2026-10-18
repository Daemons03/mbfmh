/**
 * Building drawings, made for Ferme Tranquille: isometric SVG built from a few
 * primitives (boxes, gable roofs, cylinders, trees, fences, emoji props).
 *
 * Local units: 1 = half a tile width. A footprint of w x h cells is a diamond
 * whose top vertex sits at (h, top); P(x, y, z) projects a point of the
 * footprint (x in 0..w, y in 0..h, z up) onto the SVG.
 */

const NS = 'http://www.w3.org/2000/svg';

/** SVG element helper (createElementNS; children may be null). */
export function s(tag, attrs = {}, ...children) {
    const el = document.createElementNS(NS, tag);
    for (const [key, value] of Object.entries(attrs)) {
        if (value !== null && value !== undefined && value !== false) el.setAttribute(key, String(value));
    }
    for (const child of children.flat(Infinity)) {
        if (child === null || child === undefined || child === false) continue;
        el.append(typeof child === 'string' ? document.createTextNode(child) : child);
    }
    return el;
}

// ------------------------------------------------------------ colours

function hexToRgb(hex) {
    const n = Number.parseInt(hex.slice(1), 16);
    return [(n >> 16) & 255, (n >> 8) & 255, n & 255];
}

/** Lighter (f > 0) or darker (f < 0) shade of a #rrggbb colour. */
export function shade(hex, f) {
    const mix = f > 0 ? 255 : 0;
    const k = Math.abs(f);
    const [r, g, b] = hexToRgb(hex).map((c) => Math.round(c + (mix - c) * k));
    return `#${((1 << 24) | (r << 16) | (g << 8) | b).toString(16).slice(1)}`;
}

const C = {
    grass: '#8cc152', grassDark: '#6fa33a', soil: '#8b5a2b', soilDark: '#6b4220', mud: '#7a5230',
    wall: '#f3e2c0', wood: '#b9793d', woodDark: '#8a5525', stone: '#b8b2a6', water: '#5bb8e8',
    white: '#fbf7ee', outline: '#4a2e14',
    roofRed: '#c9452f', roofBlue: '#3e78c8', roofGreen: '#5b9a39', roofBrown: '#8d5a2c',
    roofOrange: '#e0832f', roofPurple: '#7c56b8', roofPink: '#d9608a', roofTeal: '#2f9c94',
    barnRed: '#c23b2a', gold: '#f2c230', leaf: '#4f9a35', leafDark: '#3c7d28',
};

// ------------------------------------------------------------ geometry

class Canvas {
    constructor(w, h, top) {
        this.w = w;
        this.h = h;
        this.top = top;
        this.nodes = [];
    }

    P(x, y, z = 0) {
        return [this.h + x - y, this.top + (x + y) / 2 - z];
    }

    pts(list) {
        return list.map(([x, y, z]) => this.P(x, y, z).map((n) => +n.toFixed(3)).join(',')).join(' ');
    }

    add(...nodes) {
        this.nodes.push(...nodes);
        return this;
    }

    poly(list, fill, extra = {}) {
        return this.add(s('polygon', { points: this.pts(list), fill, stroke: C.outline, 'stroke-width': 0.03, 'stroke-linejoin': 'round', ...extra }));
    }

    /** Ground patch over the whole footprint (inset i). */
    ground(fill, i = 0.04, extra = {}) {
        const { w, h } = this;
        return this.poly([[i, i], [w - i, i], [w - i, h - i], [i, h - i]], fill, extra);
    }

    /** Box from (x, y, z0) to (x + dx, y + dy, z1): top, lower-left and lower-right faces. */
    box(x, y, dx, dy, z0, z1, color, { top = shade(color, 0.18), left = color, right = shade(color, -0.2) } = {}) {
        this.poly([[x, y + dy, z0], [x + dx, y + dy, z0], [x + dx, y + dy, z1], [x, y + dy, z1]], left);
        this.poly([[x + dx, y, z0], [x + dx, y + dy, z0], [x + dx, y + dy, z1], [x + dx, y, z1]], right);
        return this.poly([[x, y, z1], [x + dx, y, z1], [x + dx, y + dy, z1], [x, y + dy, z1]], top);
    }

    /**
     * Gable roof over a box. axis 'x': ridge along x (slopes face +y / -y,
     * gable on the right face); axis 'y': ridge along y (gable on the left face).
     */
    roof(x, y, dx, dy, z, rise, color, axis = 'x', wall = C.wall, o = 0.12) {
        const dark = shade(color, -0.22);
        const light = shade(color, 0.1);
        if (axis === 'x') {
            const ym = y + dy / 2;
            this.poly([[x + dx, y, z], [x + dx, y + dy, z], [x + dx, ym, z + rise]], shade(wall, -0.18)); // gable
            this.poly([[x - o, y - o, z - o * 0.6], [x + dx + o, y - o, z - o * 0.6], [x + dx + o, ym, z + rise], [x - o, ym, z + rise]], light);
            return this.poly([[x - o, y + dy + o, z - o * 0.6], [x + dx + o, y + dy + o, z - o * 0.6], [x + dx + o, ym, z + rise], [x - o, ym, z + rise]], color)
                .poly([[x + dx + o, y + dy + o, z - o * 0.6], [x + dx + o, ym, z + rise], [x + dx + o + 0.001, ym, z + rise - 0.06], [x + dx + o, y + dy + o, z - o * 0.6 - 0.06]], dark);
        }
        const xm = x + dx / 2;
        this.poly([[x, y + dy, z], [x + dx, y + dy, z], [xm, y + dy, z + rise]], wall); // gable
        this.poly([[x - o, y - o, z - o * 0.6], [x - o, y + dy + o, z - o * 0.6], [xm, y + dy + o, z + rise], [xm, y - o, z + rise]], light);
        return this.poly([[x + dx + o, y - o, z - o * 0.6], [x + dx + o, y + dy + o, z - o * 0.6], [xm, y + dy + o, z + rise], [xm, y - o, z + rise]], dark);
    }

    /** Four-sided roof (pyramid) over a box. */
    pyramid(x, y, dx, dy, z, rise, color, o = 0.1) {
        const apex = [x + dx / 2, y + dy / 2, z + rise];
        const a = [x - o, y - o, z - o * 0.5];
        const b = [x + dx + o, y - o, z - o * 0.5];
        const c = [x + dx + o, y + dy + o, z - o * 0.5];
        const d = [x - o, y + dy + o, z - o * 0.5];
        this.poly([a, b, apex], shade(color, 0.15));
        this.poly([d, a, apex], shade(color, 0.05));
        this.poly([b, c, apex], shade(color, -0.22));
        return this.poly([c, d, apex], color);
    }

    /** Vertical cylinder centred on (cx, cy). */
    cylinder(cx, cy, r, z0, z1, color, topColor = shade(color, 0.2), taper = 1) {
        const [bx, by] = this.P(cx, cy, z0);
        const [tx, ty] = this.P(cx, cy, z1);
        const rx = r * Math.SQRT2;
        const ry = rx / 2;
        const trx = rx * taper;
        const id = `g${Math.random().toString(36).slice(2, 8)}`;
        this.add(s('defs', {}, s('linearGradient', { id, x1: 0, x2: 1, y1: 0, y2: 0 },
            s('stop', { offset: 0, 'stop-color': shade(color, 0.12) }),
            s('stop', { offset: 0.55, 'stop-color': color }),
            s('stop', { offset: 1, 'stop-color': shade(color, -0.3) }))));
        this.add(s('path', {
            d: `M${bx - rx},${by} A${rx},${ry} 0 0 0 ${bx + rx},${by} L${tx + trx},${ty} L${tx - trx},${ty} Z`,
            fill: `url(#${id})`, stroke: C.outline, 'stroke-width': 0.03,
        }));
        return this.add(s('ellipse', { cx: tx, cy: ty, rx: trx, ry: trx / 2, fill: topColor, stroke: C.outline, 'stroke-width': 0.03 }));
    }

    /** Dome or cone cap on top of a cylinder. */
    cap(cx, cy, r, z, rise, color, cone = false) {
        const [x, y] = this.P(cx, cy, z);
        const rx = r * Math.SQRT2 * 1.08;
        const d = cone
            ? `M${x - rx},${y} L${x},${y - rise} L${x + rx},${y} A${rx},${rx / 2} 0 0 1 ${x - rx},${y} Z`
            : `M${x - rx},${y} C${x - rx},${y - rise * 1.3} ${x + rx},${y - rise * 1.3} ${x + rx},${y} A${rx},${rx / 2} 0 0 1 ${x - rx},${y} Z`;
        const id = `g${Math.random().toString(36).slice(2, 8)}`;
        this.add(s('defs', {}, s('linearGradient', { id, x1: 0, x2: 1, y1: 0, y2: 0 },
            s('stop', { offset: 0, 'stop-color': shade(color, 0.2) }),
            s('stop', { offset: 1, 'stop-color': shade(color, -0.3) }))));
        return this.add(s('path', { d, fill: `url(#${id})`, stroke: C.outline, 'stroke-width': 0.03 }));
    }

    /** Flat ellipse on the ground (pond, mud, shadow). */
    pool(cx, cy, r, fill, ratio = 1) {
        const [x, y] = this.P(cx, cy, 0);
        return this.add(s('ellipse', { cx: x, cy: y, rx: r * Math.SQRT2 * ratio, ry: (r * Math.SQRT2) / 2, fill, stroke: shade(fill, -0.3), 'stroke-width': 0.04 }));
    }

    tree(x, y, size, crown = C.leaf, fruit = null) {
        const [bx, by] = this.P(x, y, 0);
        const t = size;
        this.add(s('ellipse', { cx: bx, cy: by, rx: 0.32 * t, ry: 0.14 * t, fill: 'rgb(0 0 0 / 0.18)' }));
        this.add(s('rect', { x: bx - 0.05 * t, y: by - 0.45 * t, width: 0.1 * t, height: 0.45 * t, rx: 0.03, fill: C.woodDark }));
        const blobs = [[0, -0.75, 0.36], [-0.22, -0.58, 0.26], [0.22, -0.58, 0.26], [0, -0.98, 0.24]];
        for (const [dx, dy, r] of blobs) {
            this.add(s('circle', { cx: bx + dx * t, cy: by + dy * t, r: r * t, fill: crown, stroke: shade(crown, -0.35), 'stroke-width': 0.03 }));
        }
        this.add(s('circle', { cx: bx - 0.1 * t, cy: by - 0.86 * t, r: 0.1 * t, fill: shade(crown, 0.3), opacity: 0.7 }));
        if (fruit) {
            for (const [dx, dy] of [[-0.18, -0.62], [0.16, -0.76], [0.02, -0.92], [0.24, -0.55]]) {
                this.add(s('circle', { cx: bx + dx * t, cy: by + dy * t, r: 0.065 * t, fill: fruit, stroke: shade(fruit, -0.4), 'stroke-width': 0.02 }));
            }
        }
        return this;
    }

    /** Wooden fence along a ground segment. */
    fence(x0, y0, x1, y1, color = C.wood, height = 0.28) {
        const n = Math.max(1, Math.round(Math.hypot(x1 - x0, y1 - y0) / 0.35));
        for (const z of [height * 0.45, height * 0.85]) {
            this.add(s('polyline', { points: this.pts([[x0, y0, z], [x1, y1, z]]), stroke: shade(color, -0.15), 'stroke-width': 0.05, fill: 'none', 'stroke-linecap': 'round' }));
        }
        for (let i = 0; i <= n; i++) {
            const x = x0 + ((x1 - x0) * i) / n;
            const y = y0 + ((y1 - y0) * i) / n;
            this.add(s('polyline', { points: this.pts([[x, y, 0], [x, y, height]]), stroke: color, 'stroke-width': 0.07, fill: 'none', 'stroke-linecap': 'round' }));
        }
        return this;
    }

    /** Back and front fences around an inset rectangle; returns a function drawing the front part. */
    pen(i, color = C.wood) {
        const { w, h } = this;
        this.fence(i, i, w - i, i, color);
        this.fence(i, i, i, h - i, color);
        return () => {
            this.fence(i, h - i, w - i, h - i, color);
            this.fence(w - i, i, w - i, h - i, color);
        };
    }

    /** Emoji prop standing on the ground at (x, y). */
    emoji(x, y, char, size = 0.5, z = 0, extra = {}) {
        const [px, py] = this.P(x, y, z);
        return this.add(s('text', { x: px, y: py, 'font-size': size, 'text-anchor': 'middle', 'dominant-baseline': 'auto', ...extra }, char));
    }

    /** Door or window drawn on the lower-left face (plane y = y0) between x and x + dx. */
    leftOpening(x, y, dx, z0, z1, fill = C.woodDark) {
        return this.poly([[x, y, z0], [x + dx, y, z0], [x + dx, y, z1], [x, y, z1]], fill, { 'stroke-width': 0.025 });
    }

    /** Door or window on the lower-right face (plane x = x0) between y and y + dy. */
    rightOpening(x, y, dy, z0, z1, fill = C.woodDark) {
        return this.poly([[x, y, z0], [x, y + dy, z0], [x, y + dy, z1], [x, y, z1]], fill, { 'stroke-width': 0.025 });
    }

    svg(classes = '') {
        const width = this.w + this.h;
        const height = this.top + (this.w + this.h) / 2;
        return s('svg', { viewBox: `0 0 ${width} ${height}`, class: classes, 'aria-hidden': 'true' }, this.nodes);
    }
}

// ------------------------------------------------------------ helpers

function windows(c, x, y, dy, z, count, fill = '#9fd3ef') {
    const step = dy / count;
    for (let i = 0; i < count; i++) {
        c.rightOpening(x, y + step * i + step * 0.25, step * 0.5, z, z + 0.22, fill);
    }
}

function leftWindows(c, x, y, dx, z, count, fill = '#9fd3ef') {
    const step = dx / count;
    for (let i = 0; i < count; i++) {
        c.leftOpening(x + step * i + step * 0.25, y, step * 0.5, z, z + 0.22, fill);
    }
}

function sign(c, x, y, z, char, size = 0.42) {
    const [px, py] = c.P(x, y, z);
    c.add(s('circle', { cx: px, cy: py - size * 0.35, r: size * 0.62, fill: C.white, stroke: C.outline, 'stroke-width': 0.04 }));
    return c.emoji(x, y, char, size, z + size * 0.02 - size * 0.02, { dy: size * 0.02 });
}

function flowers(c, list) {
    for (const [x, y, color] of list) {
        const [px, py] = c.P(x, y, 0);
        c.add(s('circle', { cx: px, cy: py - 0.04, r: 0.06, fill: color, stroke: shade(color, -0.4), 'stroke-width': 0.015 }));
    }
}

const VARIANT_TRIM = { premium: C.gold, fertile: '#4f8a2a', free_range: '#d9a35a' };

// ------------------------------------------------------------ designs

/**
 * Each design draws on a Canvas for a footprint of size [w, h].
 * ctx: { def, building, variant, working, ready, product (item emoji or null), data }
 */
const DESIGNS = {
    field(c, ctx) {
        const { w, h } = c;
        const fertile = ctx.variant === 'fertile';
        const soil = fertile ? '#5e3a1a' : C.soil;
        c.ground(ctx.variant ? VARIANT_TRIM[ctx.variant] : C.grassDark, 0.02);
        c.ground(soil, 0.14);
        for (let i = 1; i < 6; i++) {
            const y = 0.14 + ((h - 0.28) * i) / 6;
            c.add(s('polyline', { points: c.pts([[0.2, y], [w - 0.2, y]]), stroke: shade(soil, -0.3), 'stroke-width': 0.05, fill: 'none', 'stroke-linecap': 'round' }));
        }
        if (ctx.variant === 'premium') {
            c.add(s('polyline', { points: c.pts([[0.08, h - 0.08], [w - 0.08, h - 0.08]]), stroke: C.water, 'stroke-width': 0.09, fill: 'none', 'stroke-linecap': 'round' }));
        }
        if (ctx.building.job) {
            const char = ctx.ready ? ctx.product : '🌱';
            const size = ctx.ready ? 0.42 : 0.3;
            for (let row = 0; row < 3; row++) {
                for (let col = 0; col < 4; col++) {
                    const x = 0.45 + ((w - 0.9) * col) / 3;
                    const y = 0.35 + ((h - 0.7) * row) / 2;
                    c.emoji(x, y, char, size, 0, { class: ctx.ready ? 'sway' : null });
                }
            }
        }
    },

    barn(c) {
        c.ground(C.grass);
        c.pool(1.0, 1.7, 0.45, '#d9c28a', 1.3);
        c.box(0.25, 0.2, 1.5, 1.5, 0, 0.85, C.barnRed);
        // white trim and big door on the left face
        c.leftOpening(0.65, 1.7, 0.7, 0, 0.62, C.white);
        c.leftOpening(0.72, 1.7, 0.56, 0, 0.56, C.woodDark);
        c.add(s('polyline', { points: c.pts([[0.72, 1.7, 0], [1.28, 1.7, 0.56]]), stroke: C.white, 'stroke-width': 0.05, fill: 'none' }));
        c.add(s('polyline', { points: c.pts([[1.28, 1.7, 0], [0.72, 1.7, 0.56]]), stroke: C.white, 'stroke-width': 0.05, fill: 'none' }));
        windows(c, 1.75, 0.2, 1.5, 0.45, 2, C.white);
        c.roof(0.25, 0.2, 1.5, 1.5, 0.85, 0.75, '#7b4a2a', 'y', C.barnRed);
        c.leftOpening(0.88, 1.7, 0.24, 1.0, 1.25, C.white);
    },

    farmhouse(c) {
        c.ground(C.grass);
        flowers(c, [[0.15, 1.5, '#ff6b8a'], [0.2, 1.75, '#ffd23f'], [1.8, 0.2, '#ff6b8a'], [1.85, 0.45, '#b06bff']]);
        c.box(0.2, 0.25, 1.1, 1.4, 0, 0.8, C.wall);
        c.box(1.3, 0.55, 0.5, 0.9, 0, 0.55, C.wall);
        c.box(0.45, 0.4, 0.18, 0.18, 0.8, 1.55, '#a85a3a');
        leftWindows(c, 0.2, 1.65, 1.1, 0.35, 2);
        c.leftOpening(1.4, 1.45, 0.25, 0, 0.42, C.woodDark);
        windows(c, 1.8, 0.55, 0.9, 0.2, 2);
        c.roof(1.3, 0.55, 0.5, 0.9, 0.55, 0.35, C.roofBlue, 'y');
        c.roof(0.2, 0.25, 1.1, 1.4, 0.8, 0.65, C.roofBlue, 'x');
    },

    house(c, ctx) {
        c.ground(C.grass);
        const premium = ctx.variant === 'premium';
        const roof = premium ? C.roofPurple : C.roofOrange;
        flowers(c, [[0.2, 1.6, '#ff6b8a'], [0.35, 1.8, '#ffd23f'], [1.75, 0.3, '#5bb8e8']]);
        c.box(0.35, 0.35, 1.3, 1.25, 0, premium ? 1.1 : 0.75, premium ? '#e9d3b0' : C.wall);
        leftWindows(c, 0.35, 1.6, 1.3, 0.38, premium ? 3 : 2);
        if (premium) leftWindows(c, 0.35, 1.6, 1.3, 0.75, 3);
        windows(c, 1.65, 0.35, 1.25, 0.38, 2);
        c.leftOpening(0.85, 1.6, 0.3, 0, 0.4, C.woodDark);
        c.roof(0.35, 0.35, 1.3, 1.25, premium ? 1.1 : 0.75, premium ? 0.85 : 0.6, roof, 'y', premium ? '#e9d3b0' : C.wall);
        if (premium) c.box(1.2, 0.5, 0.16, 0.16, 1.2, 1.85, '#7a6a5a');
    },

    windmill(c, ctx) {
        c.ground(C.grass);
        c.box(0.3, 1.3, 0.55, 0.45, 0, 0.35, C.wood);
        c.pyramid(0.3, 1.3, 0.55, 0.45, 0.35, 0.22, C.roofRed);
        c.cylinder(1.05, 0.95, 0.55, 0, 1.6, ctx.variant === 'premium' ? '#e8e0f0' : '#e6dccb', '#d6c9b0', 0.72);
        c.leftOpening(0.85, 1.42, 0.3, 0, 0.42, C.woodDark);
        c.cap(1.05, 0.95, 0.4, 1.6, 0.55, ctx.variant === 'premium' ? C.roofBlue : C.roofRed, true);
        const [hx, hy] = c.P(1.05, 1.32, 1.5);
        const blade = (angle) => s('g', { transform: `rotate(${angle} ${hx} ${hy})` },
            s('rect', { x: hx - 0.07, y: hy - 1.05, width: 0.14, height: 1.0, fill: '#f7f0e0', stroke: C.outline, 'stroke-width': 0.025 }),
            s('line', { x1: hx, y1: hy, x2: hx, y2: hy - 1.05, stroke: C.woodDark, 'stroke-width': 0.04 }));
        c.add(s('g', { class: `blades${ctx.working ? ' spin' : ''}`, style: `transform-origin: ${hx}px ${hy}px` },
            blade(10), blade(100), blade(190), blade(280)));
        c.add(s('circle', { cx: hx, cy: hy, r: 0.09, fill: C.woodDark, stroke: C.outline, 'stroke-width': 0.03 }));
    },

    coop(c, ctx, { house = C.wood, roof = C.roofRed, animal = '🐔', count = 3, ground = '#c9b27a', pond = false, mud = false, big = false } = {}) {
        const { w, h } = c;
        const freeRange = ctx.variant === 'free_range';
        c.ground(freeRange ? '#7cbf4a' : C.grass);
        c.ground(ground, 0.18);
        if (pond) c.pool(w * 0.62, h * 0.62, 0.42, C.water, 1.25);
        if (mud) c.pool(w * 0.6, h * 0.6, 0.45, C.mud, 1.3);
        const front = c.pen(0.12, ctx.variant === 'premium' ? C.white : C.wood);
        const bw = big ? 1.3 : 0.8;
        c.box(0.2, 0.2, bw, 0.75, 0, big ? 0.7 : 0.55, house);
        c.leftOpening(0.2 + bw * 0.35, 0.95, bw * 0.3, 0, 0.35, shade(house, -0.45));
        c.roof(0.2, 0.2, bw, 0.75, big ? 0.7 : 0.55, big ? 0.45 : 0.35, ctx.variant === 'premium' ? C.roofBlue : roof, 'x', house);
        const spots = [[w * 0.7, h * 0.35], [w * 0.4, h * 0.75], [w * 0.8, h * 0.8], [w * 0.55, h * 0.5]];
        for (const [x, y] of spots.slice(0, count)) c.emoji(x, y, animal, 0.42, 0, { class: ctx.working ? 'peck' : null });
        if (freeRange) flowers(c, [[w - 0.3, 0.3, '#ffd23f'], [0.3, h - 0.3, '#ff6b8a']]);
        front();
    },

    orchard(c, ctx, { crown = C.leaf, fruit = '#e23b3b' } = {}) {
        const { w, h } = c;
        const variant = ctx.variant;
        c.ground(variant === 'fertile' ? '#5f9c34' : C.grass);
        if (variant === 'premium') {
            c.add(s('polyline', { points: c.pts([[0.1, h * 0.55], [w * 0.5, h * 0.45], [w - 0.1, h * 0.6]]), stroke: C.water, 'stroke-width': 0.14, fill: 'none', 'stroke-linecap': 'round' }));
        }
        const front = variant === 'free_range' ? c.pen(0.08) : null;
        const trees = [];
        for (let row = 0; row < 2; row++) {
            for (let col = 0; col < 3; col++) trees.push([0.55 + col * ((w - 1.1) / 2), 0.5 + row * (h - 1.0)]);
        }
        trees.sort((a, b) => a[0] + a[1] - (b[0] + b[1]));
        for (const [x, y] of trees) c.tree(x, y, variant === 'free_range' ? 1.05 : 0.9, crown, ctx.ready || ctx.working ? fruit : null);
        if (front) front();
    },

    meadow(c, ctx) {
        const { w, h } = c;
        c.ground(ctx.variant === 'premium' ? C.gold : C.grassDark, 0.02);
        c.ground('#9ccf62', 0.12);
        const colors = ['#9b7fd6', '#c79bf0', '#9b7fd6', '#c79bf0'];
        for (let i = 0; i < 4; i++) {
            const y = 0.35 + ((h - 0.7) * i) / 3;
            c.add(s('polyline', { points: c.pts([[0.3, y, 0.05], [w - 0.3, y, 0.05]]), stroke: colors[i], 'stroke-width': 0.16, fill: 'none', 'stroke-linecap': 'round' }));
        }
        if (ctx.building.job) {
            for (const [x, y] of [[0.8, 0.5], [1.6, 1.0], [2.3, 1.5]]) c.emoji(x, y, ctx.product ?? '🌸', 0.4, 0.05, { class: ctx.ready ? 'sway' : null });
        }
    },

    silo(c, ctx) {
        c.ground(C.grass);
        c.cylinder(0.5, 1.0, 0.36, 0, 1.5, ctx.variant === 'premium' ? '#d8dfe8' : '#c8ccd2', '#e4e7ea');
        c.cap(0.5, 1.0, 0.36, 1.5, 0.35, C.roofRed);
        const [x, y] = c.P(0.5, 1.36, 0);
        c.add(s('rect', { x: x - 0.06, y: y - 1.45, width: 0.12, height: 1.4, fill: 'none', stroke: '#6b6f75', 'stroke-width': 0.025, 'stroke-dasharray': '0.04 0.08' }));
    },

    composter(c) {
        c.ground(C.grass);
        for (const [i, y] of [[0, 0.2], [1, 0.8], [2, 1.4]].map(([k, v]) => [k, v])) {
            c.box(0.18, y, 0.64, 0.5, 0, 0.35, C.wood);
            c.poly([[0.24, y + 0.06, 0.33], [0.76, y + 0.06, 0.33], [0.76, y + 0.44, 0.33], [0.24, y + 0.44, 0.33]], i === 1 ? '#5a3a1a' : '#6f8f2a');
        }
    },

    beehive(c, ctx) {
        c.ground(C.grass);
        flowers(c, [[0.15, 0.3, '#ffd23f'], [0.85, 0.5, '#ff6b8a'], [0.2, 1.6, '#ffd23f'], [0.8, 1.85, '#b06bff']]);
        const roof = ctx.variant === 'premium' ? C.roofBlue : C.roofOrange;
        for (const y of [0.25, 1.05]) {
            c.box(0.22, y, 0.56, 0.55, 0, 0.22, '#f5d36b');
            c.box(0.22, y, 0.56, 0.55, 0.22, 0.44, '#f2c94c');
            c.pyramid(0.22, y, 0.56, 0.55, 0.44, 0.18, roof, 0.05);
        }
        if (ctx.working || ctx.ready) c.emoji(0.5, 1.0, '🐝', 0.3, 0.9, { class: 'buzz' });
    },

    workshop(c, ctx, { wall = C.wall, roof = C.roofRed, signChar = '🏺', chimney = false, awning = null, wide = false } = {}) {
        const { w, h } = c;
        c.ground(C.grass);
        const dx = wide ? w - 0.6 : w - 0.5;
        c.box(0.25, 0.25, dx, h - 0.6, 0, 0.8, wall);
        if (chimney) c.box(0.45, 0.4, 0.2, 0.2, 0.8, 1.6, '#9a5a3a');
        leftWindows(c, 0.25, h - 0.35, dx, 0.38, wide ? 4 : 2);
        c.leftOpening(0.25 + dx * 0.45, h - 0.35, 0.3, 0, 0.42, C.woodDark);
        windows(c, 0.25 + dx, 0.25, h - 0.6, 0.38, 2);
        if (awning) {
            for (let i = 0; i < 6; i++) {
                const x0 = 0.25 + (dx * i) / 6;
                c.poly([[x0, h - 0.35, 0.62], [x0 + dx / 6, h - 0.35, 0.62], [x0 + dx / 6, h - 0.1, 0.5], [x0, h - 0.1, 0.5]], i % 2 ? C.white : awning);
            }
        }
        c.roof(0.25, 0.25, dx, h - 0.6, 0.8, 0.55, roof, 'x', wall);
        sign(c, 0.25 + dx, h / 2 - 0.05, 0.95, signChar, 0.4);
    },

    decoration(c, ctx) {
        c.ground(C.grass, 0.06);
        const type = ctx.def.base ?? ctx.type;
        if (type === 'ornamental_tree') return c.tree(0.5, 0.5, 1.0, '#3f9a4a');
        if (type === 'hay_bale') {
            c.box(0.2, 0.25, 0.6, 0.5, 0, 0.38, '#e9c860');
            return c.emoji(0.5, 0.5, '🌾', 0.3, 0.38);
        }
        if (type === 'well') {
            c.cylinder(0.5, 0.5, 0.3, 0, 0.3, C.stone, C.water);
            c.box(0.18, 0.47, 0.05, 0.05, 0.3, 0.8, C.woodDark);
            c.box(0.78, 0.47, 0.05, 0.05, 0.3, 0.8, C.woodDark);
            return c.roof(0.12, 0.3, 0.76, 0.4, 0.8, 0.25, C.roofRed, 'x', C.wood, 0.04);
        }
        if (type === 'spring_fountain') {
            c.cylinder(0.5, 0.5, 0.4, 0, 0.18, C.stone, C.water);
            c.cylinder(0.5, 0.5, 0.08, 0.18, 0.5, C.stone);
            return c.emoji(0.5, 0.5, '💧', 0.28, 0.55);
        }
        if (type === 'pavilion') {
            for (const [x, y] of [[0.2, 0.2], [0.75, 0.2], [0.2, 0.75], [0.75, 0.75]]) c.box(x, y, 0.06, 0.06, 0, 0.55, C.white);
            return c.pyramid(0.2, 0.2, 0.61, 0.61, 0.55, 0.4, C.roofTeal, 0.08);
        }
        if (type === 'clothesline') {
            c.box(0.15, 0.48, 0.05, 0.05, 0, 0.6, C.woodDark);
            c.box(0.8, 0.48, 0.05, 0.05, 0, 0.6, C.woodDark);
            c.add(s('polyline', { points: c.pts([[0.17, 0.5, 0.58], [0.82, 0.5, 0.58]]), stroke: '#555', 'stroke-width': 0.02, fill: 'none' }));
            for (const [x, color] of [[0.32, '#5bb8e8'], [0.5, '#ff6b8a'], [0.68, '#ffd23f']]) {
                c.poly([[x - 0.06, 0.5, 0.58], [x + 0.06, 0.5, 0.58], [x + 0.06, 0.5, 0.38], [x - 0.06, 0.5, 0.38]], color, { 'stroke-width': 0.015 });
            }
            return c;
        }
        // flowerbed and anything else: soil with flowers
        c.ground('#7a5230', 0.18);
        return flowers(c, [[0.35, 0.35, '#ff6b8a'], [0.6, 0.35, '#ffd23f'], [0.35, 0.62, '#b06bff'], [0.62, 0.62, '#ff6b8a'], [0.5, 0.5, '#fff']]);
    },

    generic(c, ctx) {
        c.ground(C.grass);
        c.box(0.25, 0.25, c.w - 0.5, c.h - 0.5, 0, 0.7, C.wall);
        c.roof(0.25, 0.25, c.w - 0.5, c.h - 0.5, 0.7, 0.5, C.roofBrown, 'x');
        sign(c, c.w - 0.25, c.h / 2, 0.9, ctx.def.emoji);
    },
};

/** How each type is drawn: design name, options and how much room it needs above the ground. */
const TYPES = {
    field: ['field', {}, 0.6],
    barn: ['barn', {}, 2.0],
    farmhouse: ['farmhouse', {}, 1.9],
    house: ['house', {}, 2.2],
    windmill: ['windmill', {}, 3.2],
    chicken_coop: ['coop', { animal: '🐔', ground: '#d8c08a' }, 1.4],
    pigsty: ['coop', { animal: '🐖', mud: true, roof: C.roofBrown, ground: '#a98a5a', big: true }, 1.5],
    cowshed: ['coop', { animal: '🐄', roof: C.roofGreen, house: C.barnRed, ground: '#b9c97a', big: true, count: 2 }, 1.5],
    goat_stable: ['coop', { animal: '🐐', roof: '#6b6f75', house: C.stone, ground: '#c4b99a', count: 2 }, 1.4],
    duck_coop: ['coop', { animal: '🦆', pond: true, roof: C.roofTeal, ground: '#a9cf7a', big: true }, 1.5],
    rabbit_hutch: ['coop', { animal: '🐇', roof: C.roofOrange, ground: '#b9d98a' }, 1.4],
    sheep_pen: ['coop', { animal: '🐑', roof: C.roofBrown, ground: '#b9d98a', big: true }, 1.5],
    apple_orchard: ['orchard', { fruit: '#e23b3b' }, 1.5],
    cherry_orchard: ['orchard', { fruit: '#a3132e', crown: '#4c9440' }, 1.5],
    almond_orchard: ['orchard', { fruit: '#c79a5c', crown: '#6aa34a' }, 1.5],
    peach_orchard: ['orchard', { fruit: '#ffa36b', crown: '#57a03d' }, 1.5],
    meadow: ['meadow', {}, 0.7],
    silo: ['silo', {}, 2.5],
    composter: ['composter', {}, 0.9],
    beehive: ['beehive', {}, 1.6],
    dairy: ['workshop', { wall: C.white, roof: C.roofBlue, signChar: '🥛', wide: true }, 2.0],
    jam_kitchen: ['workshop', { wall: '#f6dfc4', roof: C.roofPink, signChar: '🫙', wide: true, chimney: true }, 2.2],
    manufactory: ['workshop', { wall: '#e8d6b8', roof: C.roofPurple, signChar: '🏺', chimney: true }, 2.2],
    sewing_workshop: ['workshop', { wall: '#fbe8ef', roof: C.roofPink, signChar: '🧵', awning: '#d9608a' }, 2.0],
};

/**
 * <svg> for a building on the map. Returns { svg, top } where top is the room
 * above the footprint (in local units) so the caller can position it.
 */
export function buildingArt(building, def, { working = false, ready = false, product = null } = {}) {
    const base = def.variant_of ?? building.type;
    const [w, h] = def.size;
    const [design, options, top] = TYPES[base] ?? (def.kind === 'decoration' ? ['decoration', {}, 1.2] : ['generic', {}, 1.8]);
    const c = new Canvas(w, h, top);
    const ctx = { def, building, type: building.type, variant: def.variant ?? null, working, ready, product };
    DESIGNS[design](c, ctx, options);

    return { svg: c.svg(), top };
}

/** Small picture of a building type for lists (build panel). */
export function buildingIcon(type, def) {
    if (!def.size) return null;
    const { svg } = buildingArt({ type, level: 1 }, def);
    svg.setAttribute('class', 'icon');
    return svg;
}
