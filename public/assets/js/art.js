/**
 * Building drawings, made for Ferme Tranquille: isometric SVG built from a few
 * primitives (textured boxes, roofs in tiles / thatch / corrugated metal,
 * cylinders, trees, fences, emoji props). Every building has 3 looks that
 * follow its level (tier 1, 2, 3): it grows and gets better materials.
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
    grass: '#8cc152', grassDark: '#6fa33a', dirt: '#b8915a', soil: '#8b5a2b', soilDry: '#a97b4a', mud: '#7a5230',
    plank: '#d9a35a', plankDark: '#b9793d', whitePlank: '#efe6d2', plaster: '#f3e2c0', stone: '#b8b0a2', water: '#5bb8e8',
    white: '#fbf7ee', outline: '#4a2e14', glass: '#9fd3ef',
    tiles: '#d4592c', thatch: '#e7bf4a', metal: '#7d8a99', slate: '#4f5a68', shingle: '#8d5a2c',
    barnRed: '#c23b2a', blue: '#3e78c8', green: '#5b9a39', pink: '#d9608a', purple: '#7c56b8', teal: '#2f9c94',
    gold: '#f2c230', leaf: '#4f9a35', blossom: '#f2a7c8',
};

/** 1, 2 or 3 depending on how far the level is in the building's levels. */
export function tierOf(level, levels) {
    if (levels <= 1) return 1;
    const r = (level - 1) / (levels - 1);
    return r < 0.3 ? 1 : r < 0.65 ? 2 : 3;
}

let uid = 0;
const nextId = () => `a${(uid++).toString(36)}`;

// ------------------------------------------------------------ canvas

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
        return this.add(s('polygon', { points: this.pts(list), fill, stroke: C.outline, 'stroke-width': 0.025, 'stroke-linejoin': 'round', ...extra }));
    }

    line(a, b, stroke, width = 0.02, extra = {}) {
        return this.add(s('polyline', { points: this.pts([a, b]), stroke, 'stroke-width': width, fill: 'none', 'stroke-linecap': 'round', ...extra }));
    }

    /**
     * Texture lines on a quad [a, b, c, d] (a-b bottom edge, d-c top edge):
     * 'rows' run parallel to a-b, 'cols' run from a-b to d-c.
     */
    hatch([a, b, c, d], n, mode, stroke, width = 0.018, opacity = 0.55) {
        const lerp = (p, q, t) => p.map((v, i) => v + (q[i] - v) * t);
        for (let i = 1; i < n; i++) {
            const t = i / n;
            const [p, q] = mode === 'rows' ? [lerp(a, d, t), lerp(b, c, t)] : [lerp(a, b, t), lerp(d, c, t)];
            this.line(p, q, stroke, width, { opacity });
        }
        return this;
    }

    /** Ground patch over the footprint (inset i). */
    ground(fill, i = 0.04, extra = {}) {
        const { w, h } = this;
        return this.poly([[i, i], [w - i, i], [w - i, h - i], [i, h - i]], fill, { 'stroke-width': 0.02, ...extra });
    }

    /** Irregular dirt yard (rounded diamond). */
    yard(x, y, dx, dy, fill) {
        const r = 0.12;
        return this.poly([[x + r, y], [x + dx - r, y], [x + dx, y + r], [x + dx, y + dy - r], [x + dx - r, y + dy], [x + r, y + dy], [x, y + dy - r], [x, y + r]],
            fill, { stroke: shade(fill, -0.25), 'stroke-width': 0.03 });
    }

    /**
     * Box from (x, y, z0) to (x + dx, y + dy, z1).
     * wall: 'plaster' | 'planks' | 'stone' | 'logs'.
     */
    box(x, y, dx, dy, z0, z1, color, { wall = 'plaster', top = shade(color, 0.15) } = {}) {
        const left = [[x, y + dy, z0], [x + dx, y + dy, z0], [x + dx, y + dy, z1], [x, y + dy, z1]];
        const right = [[x + dx, y + dy, z0], [x + dx, y, z0], [x + dx, y, z1], [x + dx, y + dy, z1]];
        this.poly(left, color);
        this.texture(left, wall, color, z1 - z0, dx);
        this.poly(right, shade(color, -0.2));
        this.texture(right, wall, shade(color, -0.2), z1 - z0, dy);
        return this.poly([[x, y, z1], [x + dx, y, z1], [x + dx, y + dy, z1], [x, y + dy, z1]], top);
    }

    texture(quad, wall, color, height, length) {
        if (wall === 'planks' || wall === 'logs') {
            this.hatch(quad, Math.max(2, Math.round(height / (wall === 'logs' ? 0.12 : 0.08))), 'rows', shade(color, -0.35), wall === 'logs' ? 0.03 : 0.016);
        } else if (wall === 'stone') {
            const rows = Math.max(2, Math.round(height / 0.1));
            this.hatch(quad, rows, 'rows', shade(color, -0.35), 0.016);
            this.hatch(quad, Math.max(2, Math.round(length / 0.16)), 'cols', shade(color, -0.3), 0.012, 0.35);
        } else if (wall === 'boards') {
            this.hatch(quad, Math.max(2, Math.round(length / 0.1)), 'cols', shade(color, -0.3), 0.014);
        }
        return this;
    }

    /** Roof slope with its material texture; quad = [eave start, eave end, ridge end, ridge start]. */
    slope(quad, color, material) {
        this.poly(quad, color);
        const len = Math.hypot(...quad[0].map((v, i) => v - quad[1][i]));
        if (material === 'tiles') {
            this.hatch(quad, 7, 'rows', shade(color, -0.35), 0.025, 0.7);
            this.hatch(quad, Math.max(3, Math.round(len / 0.14)), 'cols', shade(color, -0.25), 0.012, 0.4);
        } else if (material === 'thatch') {
            this.hatch(quad, 6, 'rows', shade(color, -0.3), 0.03, 0.6);
            this.hatch(quad, Math.max(4, Math.round(len / 0.07)), 'cols', shade(color, 0.25), 0.012, 0.5);
        } else if (material === 'metal') {
            this.hatch(quad, Math.max(4, Math.round(len / 0.07)), 'cols', shade(color, -0.35), 0.02, 0.7);
        } else if (material === 'shingle' || material === 'slate') {
            this.hatch(quad, 6, 'rows', shade(color, -0.35), 0.02, 0.6);
        }
        return this;
    }

    /**
     * Gable roof over a box. axis 'x': ridge along x (gable on the right face);
     * axis 'y': ridge along y (gable on the left face).
     */
    roof(x, y, dx, dy, z, rise, color, { axis = 'x', wall = C.plaster, wallType = 'plaster', material = 'tiles', o = 0.12 } = {}) {
        const ze = z - o * 0.55;
        const back = shade(color, 0.12);
        const front = color;
        const side = shade(color, -0.25);
        if (axis === 'x') {
            const ym = y + dy / 2;
            const gable = [[x + dx, y + dy, z], [x + dx, y, z], [x + dx, ym, z + rise], [x + dx, ym, z + rise]];
            this.poly([[x + dx, y + dy, z], [x + dx, y, z], [x + dx, ym, z + rise]], shade(wall, -0.2));
            this.texture(gable, wallType, shade(wall, -0.2), rise, dy);
            this.slope([[x - o, y - o, ze], [x + dx + o, y - o, ze], [x + dx + o, ym, z + rise], [x - o, ym, z + rise]], back, material);
            this.slope([[x - o, y + dy + o, ze], [x + dx + o, y + dy + o, ze], [x + dx + o, ym, z + rise], [x - o, ym, z + rise]], front, material);
            return this.poly([[x + dx + o, y + dy + o, ze], [x + dx + o, ym, z + rise], [x + dx + o, ym, z + rise - 0.05], [x + dx + o, y + dy + o, ze - 0.05]], side);
        }
        const xm = x + dx / 2;
        const gable = [[x, y + dy, z], [x + dx, y + dy, z], [xm, y + dy, z + rise], [xm, y + dy, z + rise]];
        this.poly([[x, y + dy, z], [x + dx, y + dy, z], [xm, y + dy, z + rise]], wall);
        this.texture(gable, wallType, wall, rise, dx);
        this.slope([[x - o, y + dy + o, ze], [x - o, y - o, ze], [xm, y - o, z + rise], [xm, y + dy + o, z + rise]], back, material);
        this.slope([[x + dx + o, y + dy + o, ze], [x + dx + o, y - o, ze], [xm, y - o, z + rise], [xm, y + dy + o, z + rise]], shade(color, -0.12), material);
        return this.poly([[x - o, y + dy + o, ze], [x + dx + o, y + dy + o, ze], [x + dx + o, y + dy + o, ze - 0.05], [x - o, y + dy + o, ze - 0.05]], side);
    }

    /** Barn (gambrel) roof, ridge along y, two slopes per side. */
    gambrel(x, y, dx, dy, z, rise, color, wall, material = 'shingle', o = 0.1) {
        const xm = x + dx / 2;
        const k = 0.28;
        const ze = z - o * 0.5;
        const zk = z + rise * 0.62;
        const wallPts = [[x, y + dy, z], [x + dx, y + dy, z], [x + dx - dx * k, y + dy, zk], [xm, y + dy, z + rise], [x + dx * k, y + dy, zk]];
        this.poly(wallPts, wall);
        this.hatch([[x, y + dy, z], [x + dx, y + dy, z], [xm, y + dy, z + rise], [xm, y + dy, z + rise]], 6, 'cols', shade(wall, -0.3), 0.014);
        const parts = [
            [[x - o, y + dy + o, ze], [x - o, y - o, ze], [x + dx * k, y - o, zk], [x + dx * k, y + dy + o, zk]],
            [[x + dx * k, y + dy + o, zk], [x + dx * k, y - o, zk], [xm, y - o, z + rise], [xm, y + dy + o, z + rise]],
            [[x + dx + o, y + dy + o, ze], [x + dx + o, y - o, ze], [x + dx - dx * k, y - o, zk], [x + dx - dx * k, y + dy + o, zk]],
            [[x + dx - dx * k, y + dy + o, zk], [x + dx - dx * k, y - o, zk], [xm, y - o, z + rise], [xm, y + dy + o, z + rise]],
        ];
        this.slope(parts[0], shade(color, 0.12), material);
        this.slope(parts[1], shade(color, 0.2), material);
        this.slope(parts[2], shade(color, -0.15), material);
        return this.slope(parts[3], shade(color, -0.05), material);
    }

    /** Lean-to roof on posts along the lower-left face (porch). */
    porch(x, y, dx, depth, z, color, material = 'tiles', posts = C.plankDark) {
        for (const px of [x + 0.04, x + dx - 0.04]) this.box(px - 0.03, y + depth - 0.06, 0.06, 0.06, 0, z, posts, { wall: 'plaster' });
        this.poly([[x, y + depth - 0.06, 0.04], [x + dx, y + depth - 0.06, 0.04], [x + dx, y - 0.02, 0.04], [x, y - 0.02, 0.04]], C.plankDark, { 'stroke-width': 0.015 });
        return this.slope([[x - 0.04, y + depth + 0.04, z], [x + dx + 0.04, y + depth + 0.04, z], [x + dx + 0.04, y, z + 0.22], [x - 0.04, y, z + 0.22]], color, material);
    }

    /** Four-sided roof (pyramid) over a box. */
    pyramid(x, y, dx, dy, z, rise, color, material = 'shingle', o = 0.1) {
        const apex = [x + dx / 2, y + dy / 2, z + rise];
        const a = [x - o, y - o, z - o * 0.5];
        const b = [x + dx + o, y - o, z - o * 0.5];
        const c = [x + dx + o, y + dy + o, z - o * 0.5];
        const d = [x - o, y + dy + o, z - o * 0.5];
        this.slope([a, b, apex, apex], shade(color, 0.15), material);
        this.slope([d, a, apex, apex], shade(color, 0.05), material);
        this.slope([b, c, apex, apex], shade(color, -0.22), material);
        return this.slope([c, d, apex, apex], color, material);
    }

    /** Vertical cylinder centred on (cx, cy); taper < 1 narrows the top. */
    cylinder(cx, cy, r, z0, z1, color, { topColor = shade(color, 0.2), taper = 1, bands = 0, staves = 0 } = {}) {
        const [bx, by] = this.P(cx, cy, z0);
        const [tx, ty] = this.P(cx, cy, z1);
        const rx = r * Math.SQRT2;
        const ry = rx / 2;
        const trx = rx * taper;
        const id = nextId();
        this.add(s('defs', {}, s('linearGradient', { id, x1: 0, x2: 1, y1: 0, y2: 0 },
            s('stop', { offset: 0, 'stop-color': shade(color, 0.15) }),
            s('stop', { offset: 0.5, 'stop-color': color }),
            s('stop', { offset: 1, 'stop-color': shade(color, -0.35) }))));
        this.add(s('path', {
            d: `M${bx - rx},${by} A${rx},${ry} 0 0 0 ${bx + rx},${by} L${tx + trx},${ty} L${tx - trx},${ty} Z`,
            fill: `url(#${id})`, stroke: C.outline, 'stroke-width': 0.025,
        }));
        for (let i = 1; i <= bands; i++) {
            const t = i / (bands + 1);
            const y = by + (ty - by) * t;
            const rr = rx + (trx - rx) * t;
            this.add(s('path', { d: `M${bx - rr + (tx - bx) * t},${y} A${rr},${rr / 2} 0 0 0 ${bx + rr + (tx - bx) * t},${y}`, fill: 'none', stroke: shade(color, -0.4), 'stroke-width': 0.018, opacity: 0.7 }));
        }
        for (let i = 1; i < staves; i++) {
            const t = -1 + (2 * i) / staves;
            const k = Math.sign(t) * Math.sin((Math.abs(t) * Math.PI) / 2);
            this.add(s('line', { x1: bx + rx * k, y1: by + ry * Math.sqrt(1 - k * k), x2: tx + trx * k, y2: ty + (trx / 2) * Math.sqrt(1 - k * k), stroke: shade(color, -0.35), 'stroke-width': 0.012, opacity: 0.6 }));
        }
        return this.add(s('ellipse', { cx: tx, cy: ty, rx: trx, ry: trx / 2, fill: topColor, stroke: C.outline, 'stroke-width': 0.025 }));
    }

    /** Dome or cone cap on top of a cylinder. */
    cap(cx, cy, r, z, rise, color, { cone = false, material = null } = {}) {
        const [x, y] = this.P(cx, cy, z);
        const rx = r * Math.SQRT2 * 1.12;
        const d = cone
            ? `M${x - rx},${y} L${x},${y - rise} L${x + rx},${y} A${rx},${rx / 2} 0 0 1 ${x - rx},${y} Z`
            : `M${x - rx},${y} C${x - rx},${y - rise * 1.3} ${x + rx},${y - rise * 1.3} ${x + rx},${y} A${rx},${rx / 2} 0 0 1 ${x - rx},${y} Z`;
        const id = nextId();
        this.add(s('defs', {}, s('linearGradient', { id, x1: 0, x2: 1, y1: 0, y2: 0 },
            s('stop', { offset: 0, 'stop-color': shade(color, 0.2) }),
            s('stop', { offset: 1, 'stop-color': shade(color, -0.3) }))));
        this.add(s('path', { d, fill: `url(#${id})`, stroke: C.outline, 'stroke-width': 0.025 }));
        if (cone && material) {
            for (let i = 1; i < 7; i++) {
                const t = i / 7;
                this.add(s('line', { x1: x, y1: y - rise, x2: x - rx + 2 * rx * t, y2: y + (rx / 2) * Math.sin(Math.PI * t), stroke: shade(color, -0.35), 'stroke-width': 0.015, opacity: 0.6 }));
            }
        }
        return this;
    }

    /** Flat ellipse on the ground (pond, mud, shadow). */
    pool(cx, cy, r, fill, ratio = 1, edge = null) {
        const [x, y] = this.P(cx, cy, 0);
        const rx = r * Math.SQRT2 * ratio;
        if (edge) this.add(s('ellipse', { cx: x, cy: y + 0.02, rx: rx + 0.06, ry: rx / 2 + 0.04, fill: edge, stroke: shade(edge, -0.3), 'stroke-width': 0.02 }));
        this.add(s('ellipse', { cx: x, cy: y, rx, ry: rx / 2, fill, stroke: shade(fill, -0.3), 'stroke-width': 0.03 }));
        if (fill === C.water) this.add(s('ellipse', { cx: x - rx * 0.3, cy: y - rx * 0.12, rx: rx * 0.25, ry: rx * 0.06, fill: '#fff', opacity: 0.5 }));
        return this;
    }

    tree(x, y, size, crown = C.leaf, { fruit = null, blossom = false } = {}) {
        const [bx, by] = this.P(x, y, 0);
        const t = size;
        this.add(s('ellipse', { cx: bx, cy: by, rx: 0.3 * t, ry: 0.13 * t, fill: 'rgb(0 0 0 / 0.2)' }));
        this.add(s('path', { d: `M${bx - 0.06 * t},${by} L${bx - 0.03 * t},${by - 0.5 * t} L${bx + 0.03 * t},${by - 0.5 * t} L${bx + 0.06 * t},${by} Z`, fill: '#7a4a22', stroke: C.outline, 'stroke-width': 0.02 }));
        const blobs = [[0, -0.72, 0.34], [-0.24, -0.58, 0.24], [0.24, -0.6, 0.25], [-0.1, -0.95, 0.24], [0.14, -0.9, 0.22]];
        for (const [dx, dy, r] of blobs) {
            this.add(s('circle', { cx: bx + dx * t, cy: by + dy * t, r: r * t, fill: crown, stroke: shade(crown, -0.35), 'stroke-width': 0.025 }));
        }
        for (const [dx, dy, r] of [[-0.12, -0.86, 0.1], [0.1, -0.7, 0.07]]) {
            this.add(s('circle', { cx: bx + dx * t, cy: by + dy * t, r: r * t, fill: shade(crown, 0.3), opacity: 0.7 }));
        }
        const dots = fruit ?? (blossom ? C.blossom : null);
        if (dots) {
            for (const [dx, dy] of [[-0.2, -0.62], [0.16, -0.78], [0.02, -1.0], [0.26, -0.56], [-0.06, -0.72], [-0.3, -0.8]]) {
                this.add(s('circle', { cx: bx + dx * t, cy: by + dy * t, r: (fruit ? 0.06 : 0.04) * t, fill: dots, stroke: fruit ? shade(dots, -0.4) : 'none', 'stroke-width': 0.015 }));
            }
        }
        return this;
    }

    /** Small round bush. */
    bush(x, y, size = 0.3, color = C.leaf, flowers = null) {
        const [bx, by] = this.P(x, y, 0);
        this.add(s('ellipse', { cx: bx, cy: by - size * 0.35, rx: size * 0.7, ry: size * 0.45, fill: color, stroke: shade(color, -0.35), 'stroke-width': 0.02 }));
        if (flowers) {
            for (const [dx, dy] of [[-0.3, -0.45], [0.2, -0.55], [0.35, -0.3], [-0.05, -0.65]]) {
                this.add(s('circle', { cx: bx + dx * size, cy: by + dy * size, r: 0.035, fill: flowers }));
            }
        }
        return this;
    }

    /**
     * Fence along a ground segment. style: 'wood' | 'picket' (white) | 'rope' | 'mesh'.
     */
    fence(x0, y0, x1, y1, style = 'wood', height = 0.26) {
        const len = Math.hypot(x1 - x0, y1 - y0);
        const n = Math.max(1, Math.round(len / (style === 'picket' ? 0.14 : 0.35)));
        const color = style === 'picket' ? '#f7f3ea' : style === 'mesh' ? '#8a8f96' : style === 'rope' ? '#a77b46' : C.plankDark;
        if (style === 'mesh') {
            this.poly([[x0, y0, 0], [x1, y1, 0], [x1, y1, height], [x0, y0, height]], 'rgb(200 210 220 / 0.25)', { stroke: color, 'stroke-width': 0.012 });
            this.hatch([[x0, y0, 0], [x1, y1, 0], [x1, y1, height], [x0, y0, height]], Math.round(len / 0.08), 'cols', color, 0.008, 0.6);
        }
        const rails = style === 'rope' ? [height * 0.75] : style === 'picket' ? [height * 0.35, height * 0.75] : [height * 0.4, height * 0.8];
        if (style !== 'mesh') {
            for (const z of rails) this.line([x0, y0, z], [x1, y1, z], style === 'picket' ? '#d8d0c0' : shade(color, -0.1), style === 'rope' ? 0.02 : 0.045);
        }
        const posts = style === 'rope' || style === 'mesh' ? Math.max(1, Math.round(len / 0.5)) : n;
        for (let i = 0; i <= posts; i++) {
            const x = x0 + ((x1 - x0) * i) / posts;
            const y = y0 + ((y1 - y0) * i) / posts;
            this.line([x, y, 0], [x, y, height + (style === 'picket' ? 0.04 : 0)], style === 'picket' ? '#fffdf6' : color, style === 'picket' ? 0.045 : 0.06,
                style === 'picket' ? { stroke: '#fffdf6' } : {});
        }
        return this;
    }

    /** Back fences now; returns a function drawing the front ones (call it last). */
    pen(i, style = 'wood', height = 0.26) {
        const { w, h } = this;
        this.fence(i, i, w - i, i, style, height);
        this.fence(i, i, i, h - i, style, height);
        return () => {
            this.fence(i, h - i, w - i, h - i, style, height);
            this.fence(w - i, i, w - i, h - i, style, height);
        };
    }

    /** Emoji prop standing on the ground at (x, y). */
    emoji(x, y, char, size = 0.5, z = 0, extra = {}) {
        const [px, py] = this.P(x, y, z);
        return this.add(s('text', { x: px, y: py, 'font-size': size, 'text-anchor': 'middle', ...extra }, char));
    }

    /** Opening on the lower-left face (plane y = y0) between x and x + dx. */
    leftOpening(x, y, dx, z0, z1, fill = '#5a3a1c', frame = C.white) {
        this.poly([[x - 0.02, y, z0 - 0.02], [x + dx + 0.02, y, z0 - 0.02], [x + dx + 0.02, y, z1 + 0.02], [x - 0.02, y, z1 + 0.02]], frame, { 'stroke-width': 0.015 });
        return this.poly([[x, y, z0], [x + dx, y, z0], [x + dx, y, z1], [x, y, z1]], fill, { 'stroke-width': 0.015 });
    }

    /** Opening on the lower-right face (plane x = x0) between y and y + dy. */
    rightOpening(x, y, dy, z0, z1, fill = '#5a3a1c', frame = C.white) {
        this.poly([[x, y - 0.02, z0 - 0.02], [x, y + dy + 0.02, z0 - 0.02], [x, y + dy + 0.02, z1 + 0.02], [x, y - 0.02, z1 + 0.02]], frame, { 'stroke-width': 0.015 });
        return this.poly([[x, y, z0], [x, y + dy, z0], [x, y + dy, z1], [x, y, z1]], fill, { 'stroke-width': 0.015 });
    }

    svg(classes = '') {
        const width = this.w + this.h;
        const height = this.top + (this.w + this.h) / 2;
        return s('svg', { viewBox: `0 0 ${width} ${height}`, class: classes, 'aria-hidden': 'true' }, this.nodes);
    }
}

// ------------------------------------------------------------ details

/** Windows on the lower-left face, with flower boxes when asked. */
function leftWindows(c, x, y, dx, z, count, { flowers = false, frame = C.white, height = 0.22 } = {}) {
    const step = dx / count;
    for (let i = 0; i < count; i++) {
        const x0 = x + step * i + step * 0.28;
        c.leftOpening(x0, y + 0.001, step * 0.44, z, z + height, C.glass, frame);
        c.line([x0 + step * 0.22, y + 0.002, z], [x0 + step * 0.22, y + 0.002, z + height], frame, 0.015);
        if (flowers) {
            c.poly([[x0 - 0.02, y + 0.06, z - 0.05], [x0 + step * 0.46, y + 0.06, z - 0.05], [x0 + step * 0.46, y + 0.06, z - 0.01], [x0 - 0.02, y + 0.06, z - 0.01]], C.plankDark, { 'stroke-width': 0.01 });
            for (let k = 0; k < 3; k++) c.emoji(x0 + step * 0.08 + k * step * 0.15, y + 0.07, '•', 0.12, z - 0.04, { fill: k % 2 ? '#ff6b8a' : '#5b8de8' });
        }
    }
}

function rightWindows(c, x, y, dy, z, count, { frame = C.white, height = 0.22 } = {}) {
    const step = dy / count;
    for (let i = 0; i < count; i++) c.rightOpening(x + 0.001, y + step * i + step * 0.28, step * 0.44, z, z + height, C.glass, frame);
}

function flowers(c, list) {
    for (const [x, y, color] of list) {
        const [px, py] = c.P(x, y, 0);
        c.add(s('circle', { cx: px, cy: py - 0.04, r: 0.05, fill: color, stroke: shade(color, -0.4), 'stroke-width': 0.012 }));
        c.add(s('circle', { cx: px + 0.06, cy: py - 0.02, r: 0.035, fill: '#ffffff' }));
    }
}

function grassTufts(c, list) {
    for (const [x, y] of list) {
        const [px, py] = c.P(x, y, 0);
        c.add(s('path', { d: `M${px - 0.06},${py} l0.03,-0.1 l0.03,0.08 l0.03,-0.12 l0.03,0.12`, fill: 'none', stroke: '#4f8a2a', 'stroke-width': 0.02 }));
    }
}

function trough(c, x, y) {
    c.box(x, y, 0.35, 0.14, 0, 0.1, C.plankDark, { wall: 'planks' });
    c.poly([[x + 0.03, y + 0.03, 0.1], [x + 0.32, y + 0.03, 0.1], [x + 0.32, y + 0.11, 0.1], [x + 0.03, y + 0.11, 0.1]], C.water, { 'stroke-width': 0.01 });
}


/** Market stall: table, four posts and a striped awning. */
function stall(c, x, y, dx, dy, color, goods = ['🍎', '🥕', '🧀']) {
    c.box(x, y, dx, dy, 0, 0.28, C.plank, { wall: 'planks' });
    for (const [px, py] of [[x, y], [x + dx - 0.05, y], [x, y + dy - 0.05], [x + dx - 0.05, y + dy - 0.05]]) c.box(px, py, 0.05, 0.05, 0.28, 0.75, C.plankDark);
    const n = 5;
    for (let i = 0; i < n; i++) {
        const x0 = x - 0.06 + ((dx + 0.12) * i) / n;
        const x1 = x - 0.06 + ((dx + 0.12) * (i + 1)) / n;
        c.poly([[x0, y + dy + 0.14, 0.62], [x1, y + dy + 0.14, 0.62], [x1, y - 0.06, 0.86], [x0, y - 0.06, 0.86]], i % 2 ? C.white : color, { 'stroke-width': 0.012 });
    }
    goods.forEach((g, i) => c.emoji(x + 0.15 + (i * (dx - 0.3)) / Math.max(1, goods.length - 1), y + dy * 0.5, g, 0.2, 0.28));
}

/** Round tent with striped walls and a cone roof. */
function tent(c, cx, cy, r, h, color) {
    c.cylinder(cx, cy, r, 0, h, color, { staves: 8, topColor: color });
    c.cap(cx, cy, r, h, h * 0.9, color, { cone: true, material: 'tiles' });
    const [x, y] = c.P(cx, cy, h + h * 0.9);
    c.add(s('path', { d: `M${x},${y} v-0.25 l0.18,0.07 l-0.18,0.07`, fill: '#ffd23f', stroke: C.outline, 'stroke-width': 0.015 }));
}

/** Bunting between two points (flags). */
function bunting(c, a, b, colors = ['#e94b3c', '#ffd23f', '#3e78c8', '#5cb531']) {
    c.line(a, b, '#6a4020', 0.012);
    const n = 8;
    for (let i = 0; i < n; i++) {
        const t = (i + 0.5) / n;
        const p = a.map((v, k) => v + (b[k] - v) * t);
        const [px, py] = c.P(...p);
        c.add(s('path', { d: `M${px - 0.05},${py} L${px + 0.05},${py} L${px},${py + 0.1} Z`, fill: colors[i % colors.length] }));
    }
}

const VARIANT_TRIM = { premium: C.gold, fertile: '#4f8a2a', free_range: '#d9a35a' };

// ------------------------------------------------------------ designs

/**
 * Each design draws on a Canvas of footprint [w, h].
 * ctx: { def, building, type, variant, tier, working, ready, product }
 */
const DESIGNS = {
    field(c, ctx) {
        const { w, h } = c;
        const t = ctx.tier;
        const fertile = ctx.variant === 'fertile';
        const level = ctx.building.level ?? 1;
        const soil = fertile ? '#5e3a1a' : level < 4 ? C.soilDry : C.soil;
        if (!ctx.overlay) DESIGNS.fieldGround(c, ctx, soil, level);
        if (ctx.building.job) {
            const char = ctx.ready ? ctx.product : '🌱';
            const size = ctx.ready ? 0.42 : 0.28;
            // 5 plants along the long side, 3 across (a turned field is h x w)
            const [cols, rows] = w >= h ? [5, 3] : [3, 5];
            for (let row = 0; row < rows; row++) {
                for (let col = 0; col < cols; col++) {
                    const x = 0.35 + ((w - 0.7) * col) / (cols - 1);
                    const y = 0.3 + ((h - 0.6) * row) / (rows - 1);
                    c.emoji(x, y, char, size, 0, { class: ctx.ready ? 'sway' : null });
                }
            }
            if (!ctx.ready && t >= 2) {
                // crop sign, as farmers do
                c.box(w * 0.5 - 0.03, h - 0.3, 0.06, 0.06, 0, 0.45, C.plankDark);
                c.poly([[w * 0.5 - 0.25, h - 0.27, 0.42], [w * 0.5 + 0.25, h - 0.27, 0.42], [w * 0.5 + 0.25, h - 0.27, 0.78], [w * 0.5 - 0.25, h - 0.27, 0.78]], C.plank, { 'stroke-width': 0.02 });
                c.emoji(w * 0.5, h - 0.26, ctx.product ?? '🌱', 0.3, 0.47);
            }
        }
    },

    fieldGround(c, ctx, soil, level) {
        const { w, h } = c;
        c.ground(ctx.variant ? VARIANT_TRIM[ctx.variant] : '#7fb447', 0.01);
        c.ground(soil, 0.1, { stroke: shade(soil, -0.3) });
        if (level < 4) {
            // dry earth: a few cracks
            for (const [a, b] of [[[0.5, 0.4], [0.9, 0.55]], [[1.4, 1.2], [1.9, 1.3]], [[2.1, 0.5], [2.4, 0.8]]]) c.line(a, b, shade(soil, -0.3), 0.02);
        } else {
            for (let i = 1; i < 7; i++) {
                const y = 0.1 + ((h - 0.2) * i) / 7;
                c.line([0.15, y], [w - 0.15, y], shade(soil, -0.35), 0.05);
                c.line([0.15, y - 0.06], [w - 0.15, y - 0.06], shade(soil, 0.12), 0.02);
            }
        }
        if (level >= 8) {
            // stone edging and irrigation pipes
            const stones = [];
            for (let i = 0; i <= 12; i++) stones.push([0.06 + ((w - 0.12) * i) / 12, h - 0.05], [0.06 + ((w - 0.12) * i) / 12, 0.05]);
            for (let i = 0; i <= 8; i++) stones.push([0.05, 0.06 + ((h - 0.12) * i) / 8], [w - 0.05, 0.06 + ((h - 0.12) * i) / 8]);
            for (const [x, y] of stones) {
                const [px, py] = c.P(x, y, 0);
                c.add(s('ellipse', { cx: px, cy: py, rx: 0.07, ry: 0.04, fill: '#c9c2b4', stroke: '#8a8478', 'stroke-width': 0.012 }));
            }
            for (const y of [h * 0.33, h * 0.66]) {
                c.line([0.1, y, 0.02], [w - 0.1, y, 0.02], '#2f8fd0', 0.05);
                c.line([0.1, y, 0.035], [w - 0.1, y, 0.035], '#9fd8f5', 0.015);
            }
        }
        if (ctx.variant === 'premium') c.line([0.06, h - 0.06], [w - 0.06, h - 0.06], C.water, 0.09);
    },

    barn(c, ctx) {
        const t = ctx.tier;
        c.ground(C.grass);
        c.yard(0.1, 1.4, 1.8, 0.5, C.dirt);
        const z = 0.75 + t * 0.12;
        c.box(0.25, 0.2, 1.5, 1.45, 0, z, C.barnRed, { wall: 'boards' });
        c.leftOpening(0.62, 1.651, 0.76, 0, 0.62, '#5a2a1a', C.white);
        c.line([0.62, 1.652, 0], [1.38, 1.652, 0.62], C.white, 0.04);
        c.line([1.38, 1.652, 0], [0.62, 1.652, 0.62], C.white, 0.04);
        rightWindows(c, 1.75, 0.2, 1.45, z * 0.55, t >= 2 ? 3 : 2);
        c.gambrel(0.25, 0.2, 1.5, 1.45, z, 0.85, C.shingle, C.barnRed, 'shingle');
        c.leftOpening(0.88, 1.652, 0.24, z + 0.12, z + 0.38, '#5a2a1a', C.white);
        if (t >= 2) {
            c.box(1.78, 1.55, 0.18, 0.18, 0, 0.18, C.plankDark, { wall: 'planks' }); // crate
            c.emoji(0.12, 1.6, '🌾', 0.35);
        }
        if (t >= 3) {
            c.box(0.05, 0.25, 0.18, 0.5, 0, 0.12, C.plankDark, { wall: 'planks' });
            c.emoji(0.14, 0.5, '🎃', 0.22, 0.12);
        }
    },

    farmhouse(c, ctx) {
        const t = ctx.tier;
        c.ground(C.grass);
        flowers(c, [[0.12, 1.5, '#ff6b8a'], [0.15, 1.8, '#ffd23f'], [1.85, 0.15, '#ff6b8a'], [1.88, 0.4, '#b06bff']]);
        c.pool(1.75, 1.65, 0.18, C.water, 1, '#a9a297');
        const z = 0.75 + (t - 1) * 0.15;
        c.box(0.2, 0.25, 1.3, 1.25, 0, z, C.whitePlank, { wall: 'planks' });
        c.box(0.45, 0.4, 0.16, 0.16, z, z + 0.85, '#9a5a3a', { wall: 'stone' });
        leftWindows(c, 0.2, 1.5, 1.3, z * 0.45, 3, { flowers: t >= 2 });
        rightWindows(c, 1.5, 0.25, 1.25, z * 0.45, 2);
        c.leftOpening(0.75, 1.501, 0.22, 0, 0.42, '#7a3a1a', C.white);
        c.porch(0.5, 1.5, 0.75, 0.3, 0.42, C.shingle, 'shingle');
        c.roof(0.2, 0.25, 1.3, 1.25, z, 0.6, C.shingle, { axis: 'x', wall: C.whitePlank, wallType: 'planks', material: 'shingle' });
        if (t >= 3) c.bush(1.8, 0.8, 0.35, C.leaf, '#ff6b8a');
    },

    house(c, ctx) {
        const t = ctx.tier;
        const premium = ctx.variant === 'premium';
        const wallColor = premium ? '#c9b49a' : C.plank;
        const wallType = premium ? 'stone' : 'planks';
        const roofColor = premium ? '#3f8fbf' : C.tiles;
        c.ground(C.grass);
        flowers(c, [[0.15, 1.55, '#ff6b8a'], [0.25, 1.85, '#ffd23f'], [1.85, 0.2, '#5bb8e8']]);
        grassTufts(c, [[1.8, 1.6], [0.2, 0.3]]);
        const floors = t;
        const z = 0.62 + (floors - 1) * 0.42;
        const x = 0.3;
        const dx = t >= 2 ? 1.25 : 1.1;
        c.box(x, 0.35, dx, 1.05, 0, z, wallColor, { wall: wallType });
        for (let f = 0; f < floors; f++) leftWindows(c, x, 1.4, dx, 0.2 + f * 0.42, 3, { flowers: true });
        rightWindows(c, x + dx, 0.35, 1.05, 0.2, 2);
        if (floors >= 2) rightWindows(c, x + dx, 0.35, 1.05, 0.62, 2);
        c.leftOpening(x + dx * 0.15, 1.401, 0.2, 0, 0.4, '#7a3a1a', C.white);
        c.porch(x, 1.4, dx * 0.55, 0.32, 0.4, roofColor, 'tiles');
        if (t >= 3) {
            // balcony on the upper floor
            c.poly([[x, 1.4, 0.84], [x + dx, 1.4, 0.84], [x + dx, 1.62, 0.84], [x, 1.62, 0.84]], C.plankDark, { 'stroke-width': 0.015 });
            c.fence(x, 1.62, x + dx, 1.62, premium ? 'picket' : 'wood', 0.18);
            c.line([x, 1.62, 1.02], [x + dx, 1.62, 1.02], C.plankDark, 0.03);
        }
        c.roof(x, 0.35, dx, 1.05, z, 0.55, roofColor, { axis: 'y', wall: wallColor, wallType, material: 'tiles' });
        c.box(x + dx * 0.7, 0.45, 0.12, 0.12, z + 0.2, z + 0.62, '#8a7a6a', { wall: 'stone' });
        if (t >= 2) {
            // side wing
            c.box(1.6, 1.0, 0.32, 0.6, 0, 0.5, wallColor, { wall: wallType });
            rightWindows(c, 1.92, 1.0, 0.6, 0.18, 1);
            c.roof(1.6, 1.0, 0.32, 0.6, 0.5, 0.25, roofColor, { axis: 'x', wall: wallColor, wallType, material: 'tiles', o: 0.06 });
        }
    },

    windmill(c, ctx) {
        const t = ctx.tier;
        const premium = ctx.variant === 'premium';
        c.ground(C.grass);
        c.yard(0.1, 1.2, 1.0, 0.7, C.dirt);
        let hub;
        if (t === 1) {
            // wooden house with a thatched roof and small sails on the gable
            c.box(0.35, 0.35, 1.2, 1.1, 0, 0.7, C.plank, { wall: 'planks' });
            leftWindows(c, 0.35, 1.45, 1.2, 0.3, 2);
            c.leftOpening(0.6, 1.451, 0.25, 0, 0.42, '#6a3a1a', C.plankDark);
            c.roof(0.35, 0.35, 1.2, 1.1, 0.7, 0.6, C.thatch, { axis: 'y', wall: C.plank, wallType: 'planks', material: 'thatch', o: 0.16 });
            hub = c.P(0.95, 1.5, 1.05);
        } else {
            const stone = t === 3;
            const top = stone ? 2.1 : 1.6;
            if (stone) {
                c.box(0.3, 0.3, 1.25, 1.25, 0, top, premium ? '#d8d0e6' : '#c9bfae', { wall: 'stone' });
                for (const z of [0.4, 0.95, 1.5]) leftWindows(c, 0.3, 1.55, 1.25, z, 2, { frame: C.plankDark });
                rightWindows(c, 1.55, 0.3, 1.25, 0.95, 2, { frame: C.plankDark });
                c.poly([[0.3, 1.55, 1.25], [1.55, 1.55, 1.25], [1.55, 1.55, 1.32], [0.3, 1.55, 1.32]], C.plankDark);
            } else {
                c.box(0.4, 0.4, 1.1, 1.1, 0, top, C.plank, { wall: 'planks' });
                leftWindows(c, 0.4, 1.5, 1.1, 0.8, 2, { frame: C.plankDark });
            }
            c.leftOpening(0.7, stone ? 1.551 : 1.501, 0.3, 0, 0.48, '#6a3a1a', C.plankDark);
            const [x, y, d] = stone ? [0.3, 0.3, 1.25] : [0.4, 0.4, 1.1];
            c.pyramid(x, y, d, d, top, 0.7, premium ? C.blue : C.thatch, premium ? 'slate' : 'thatch', 0.14);
            hub = c.P(x + d / 2 + 0.05, y + d + 0.05, top - 0.15);
            if (stone) {
                c.box(1.6, 1.2, 0.3, 0.3, 0, 0.25, C.plankDark, { wall: 'planks' });
                c.emoji(1.45, 1.85, '🌾', 0.35);
            }
        }
        const [hx, hy] = hub;
        const len = t === 1 ? 0.75 : t === 2 ? 1.05 : 1.35;
        const blade = (angle) => s('g', { transform: `rotate(${angle} ${hx} ${hy})` },
            s('line', { x1: hx, y1: hy, x2: hx, y2: hy - len, stroke: '#6a4020', 'stroke-width': 0.05 }),
            s('rect', { x: hx - 0.01, y: hy - len, width: len * 0.2, height: len * 0.82, fill: 'rgb(255 250 235 / 0.9)', stroke: '#6a4020', 'stroke-width': 0.02 }),
            s('path', { d: `M${hx},${hy - len * 0.25} h${len * 0.2} M${hx},${hy - len * 0.5} h${len * 0.2} M${hx},${hy - len * 0.75} h${len * 0.2}`, stroke: '#6a4020', 'stroke-width': 0.012 }));
        c.add(s('g', { class: `blades${ctx.working ? ' spin' : ''}`, style: `transform-origin: ${hx}px ${hy}px` },
            blade(15), blade(105), blade(195), blade(285)));
        c.add(s('circle', { cx: hx, cy: hy, r: 0.07, fill: '#6a4020', stroke: C.outline, 'stroke-width': 0.02 }));
    },

    /**
     * Animal enclosures. opts: animal, coop (house colour), roof colour/material,
     * yard colour, pond / mud, wide (3x2), stilts.
     */
    coop(c, ctx, opts) {
        const { w, h } = c;
        const t = ctx.tier;
        const freeRange = ctx.variant === 'free_range';
        const premium = ctx.variant === 'premium';
        const { animal, wallColor = C.plank, roofColor = C.slate, roofMaterial = 'slate', yardColor = C.dirt, pond = false, mud = false, stilts = false } = opts;

        c.ground(freeRange ? '#7cbf4a' : C.grass);
        c.yard(0.12, 0.12, w - 0.24, h - 0.24, yardColor);
        grassTufts(c, [[w - 0.25, 0.3], [0.3, h - 0.25], [w * 0.5, h - 0.2]]);
        if (pond) c.pool(w * 0.62, h * 0.62, 0.38 + t * 0.04, C.water, 1.3, t >= 2 ? '#b3ab9c' : null);
        if (mud) c.pool(w * 0.62, h * 0.62, 0.42, C.mud, 1.4);
        const style = premium || t >= 3 ? 'picket' : t === 2 ? 'wood' : opts.fence ?? 'mesh';
        const front = c.pen(0.1, style);

        // shelter grows with the tier: open shed -> cabin -> two-floor house
        const bw = Math.min(w - 0.5, (w > 2 ? 1.1 : 0.75) + (t - 1) * 0.3);
        const bd = Math.min(h - 0.6, 0.65 + (t - 1) * 0.15);
        const z = t === 3 ? 0.95 : 0.6;
        const base = stilts && t === 1 ? 0.22 : 0;
        if (t === 1 && !stilts) {
            // open shed: four posts and a roof
            for (const [x, y] of [[0.25, 0.25], [0.25 + bw - 0.06, 0.25], [0.25, 0.25 + bd - 0.06], [0.25 + bw - 0.06, 0.25 + bd - 0.06]]) {
                c.box(x, y, 0.06, 0.06, 0, z, C.plankDark);
            }
            c.poly([[0.25, 0.25, 0.02], [0.25 + bw, 0.25, 0.02], [0.25 + bw, 0.25 + bd, 0.02], [0.25, 0.25 + bd, 0.02]], '#e2c46a', { 'stroke-width': 0.01 });
        } else {
            if (base) for (const [x, y] of [[0.27, 0.27], [0.2 + bw, 0.27], [0.27, 0.2 + bd], [0.2 + bw, 0.2 + bd]]) c.box(x, y, 0.05, 0.05, 0, base, C.plankDark);
            c.box(0.25, 0.25, bw, bd, base, base + z, wallColor, { wall: 'planks' });
            c.leftOpening(0.25 + bw * 0.4, 0.25 + bd + 0.001, bw * 0.22, base, base + 0.32, '#5a3a1c', C.plankDark);
            if (t >= 2) leftWindows(c, 0.25 + bw * 0.62, 0.25 + bd, bw * 0.38, base + 0.3, 1);
            if (t === 3) leftWindows(c, 0.25, 0.25 + bd, bw, base + 0.62, 3);
            rightWindows(c, 0.25 + bw, 0.25, bd, base + 0.3, 1);
            if (base) c.poly([[0.25 + bw * 0.4, 0.25 + bd, base], [0.25 + bw * 0.62, 0.25 + bd + 0.35, 0], [0.25 + bw * 0.62, 0.25 + bd + 0.35, 0.02], [0.25 + bw * 0.4, 0.25 + bd, base + 0.02]], C.plankDark);
        }
        c.roof(0.25, 0.25, bw, bd, base + z, 0.35 + t * 0.05, premium ? C.blue : roofColor,
            { axis: 'x', wall: wallColor, wallType: 'planks', material: roofMaterial, o: 0.1 });
        if (t >= 2) trough(c, w - 0.6, 0.3);
        if (t >= 3) c.bush(w - 0.3, h * 0.45, 0.28, C.leaf, '#ff6b8a');

        const spots = [[w * 0.68, h * 0.42], [w * 0.4, h * 0.76], [w * 0.82, h * 0.78], [w * 0.55, h * 0.58], [w * 0.28, h * 0.62]];
        for (const [x, y] of spots.slice(0, 1 + t)) c.emoji(x, y, animal, 0.42, 0, { class: ctx.working ? 'peck' : null });
        if (freeRange) flowers(c, [[w - 0.3, 0.3, '#ffd23f'], [0.3, h - 0.3, '#ff6b8a'], [w * 0.5, 0.2, '#b06bff']]);
        front();
    },

    orchard(c, ctx, { crown = C.leaf, fruit = '#e23b3b', blossom = false } = {}) {
        const { w, h } = c;
        const t = ctx.tier;
        const variant = ctx.variant;
        c.ground(variant === 'fertile' ? '#5f9c34' : C.grass);
        if (t === 1) c.yard(0.15, 0.15, w - 0.3, h - 0.3, C.dirt);
        if (variant === 'premium' || t >= 3) {
            c.line([0.1, h * 0.58], [w * 0.45, h * 0.42], C.water, 0.14);
            c.line([w * 0.45, h * 0.42], [w - 0.1, h * 0.62], C.water, 0.14);
        }
        const style = variant === 'free_range' ? 'wood' : t === 1 ? 'rope' : t === 2 ? 'wood' : 'picket';
        const front = c.pen(0.08, style, t === 1 ? 0.18 : 0.26);
        const size = t === 1 ? 0.55 : t === 2 ? 0.8 : 1.0;
        const trees = [];
        for (let row = 0; row < 2; row++) {
            for (let col = 0; col < 3; col++) trees.push([0.5 + col * ((w - 1.0) / 2), 0.5 + row * (h - 1.0)]);
        }
        trees.sort((a, b) => a[0] + a[1] - (b[0] + b[1]));
        const showFruit = ctx.ready || ctx.working;
        for (const [x, y] of trees) c.tree(x, y, size, crown, { fruit: showFruit ? fruit : null, blossom: !showFruit && (blossom || t >= 3) });
        if (t === 1 && showFruit) c.emoji(w * 0.5, h * 0.5, '🧺', 0.3);
        front();
    },

    meadow(c, ctx) {
        const { w, h } = c;
        const t = ctx.tier;
        if (!ctx.overlay) {
            c.ground(ctx.variant === 'premium' ? C.gold : C.grassDark, 0.02);
            c.ground('#9ccf62', 0.1);
            const colors = ['#9b7fd6', '#c79bf0'];
            for (let i = 0; i < 5; i++) {
                const y = 0.3 + ((h - 0.6) * i) / 4;
                c.line([0.3, y, 0.05], [w - 0.3, y, 0.05], colors[i % 2], 0.14 + t * 0.02);
            }
            if (t >= 2) c.pen(0.06, t === 3 ? 'picket' : 'wood', 0.18)();
        }
        if (ctx.building.job) {
            for (const [x, y] of [[0.7, 0.45], [1.5, 0.9], [2.3, 1.45], [1.0, 1.4]]) {
                const [px, py] = w >= h ? [x, y] : [y, x]; // turned meadow
                c.emoji(px, py, ctx.product ?? '🌸', 0.38, 0.05, { class: ctx.ready ? 'sway' : null });
            }
        }
    },

    silo(c, ctx) {
        const t = ctx.tier;
        c.ground(C.grass);
        if (t === 1) {
            // wooden tower with cross braces and a green cone
            c.cylinder(0.5, 1.0, 0.36, 0, 1.05, C.plank, { staves: 9, bands: 2 });
            const [x, y] = c.P(0.5, 1.36, 0);
            c.add(s('path', { d: `M${x - 0.45},${y - 0.1} L${x + 0.45},${y - 0.95} M${x + 0.45},${y - 0.1} L${x - 0.45},${y - 0.95}`, stroke: C.plankDark, 'stroke-width': 0.04, opacity: 0.8 }));
            c.cap(0.5, 1.0, 0.36, 1.05, 0.5, '#4f8a5a', { cone: true, material: 'metal' });
            for (const [dx, dy] of [[0.15, 1.6], [0.85, 1.5]]) c.emoji(dx, dy, '🪨', 0.18);
        } else {
            const top = t === 2 ? 1.4 : 1.8;
            c.cylinder(0.5, 1.0, 0.38, 0, top, ctx.variant === 'premium' ? '#d8dfe8' : '#bfc6cf', { bands: t === 2 ? 4 : 6, topColor: '#e4e7ea' });
            c.cap(0.5, 1.0, 0.38, top, 0.35, t === 3 ? C.barnRed : '#7d8a99');
            const [x, y] = c.P(0.5, 1.38, 0);
            c.add(s('rect', { x: x + 0.12, y: y - top + 0.05, width: 0.1, height: top - 0.1, fill: 'none', stroke: '#5b6068', 'stroke-width': 0.02, 'stroke-dasharray': '0.03 0.07' }));
        }
    },

    composter(c, ctx) {
        const t = ctx.tier;
        c.ground(C.grass);
        const crates = t === 1 ? [0.55] : t === 2 ? [0.25, 0.95] : [0.15, 0.75, 1.35];
        for (const y of crates) {
            c.box(0.15, y, 0.7, 0.55, 0, 0.32, C.plankDark, { wall: 'planks' });
            c.poly([[0.2, y + 0.05, 0.3], [0.8, y + 0.05, 0.3], [0.8, y + 0.5, 0.3], [0.2, y + 0.5, 0.3]], '#6a4a22', { 'stroke-width': 0.01 });
            for (const [dx, dy, col] of [[0.3, 0.15, '#d9822b'], [0.55, 0.3, '#c8442f'], [0.4, 0.4, '#e3b23c'], [0.65, 0.12, '#7aa53a']]) {
                c.emoji(dx, y + dy, '◆', 0.12, 0.3, { fill: col });
            }
        }
        c.line([0.85, 1.85, 0], [0.55, 1.6, 0.55], '#7a4a22', 0.035); // pitchfork
        c.line([0.5, 1.56, 0.55], [0.6, 1.64, 0.62], '#8a8f96', 0.03);
    },

    beehive(c, ctx) {
        const t = ctx.tier;
        c.ground(C.grass);
        flowers(c, [[0.15, 0.3, '#ffd23f'], [0.85, 0.5, '#ff6b8a'], [0.2, 1.6, '#ffd23f'], [0.8, 1.85, '#b06bff'], [0.5, 0.15, '#ff6b8a']]);
        const roof = ctx.variant === 'premium' ? C.blue : '#c8742f';
        const hives = t === 1 ? [1.0] : t === 2 ? [0.45, 1.2] : [0.25, 0.85, 1.45];
        for (const y of hives) {
            c.box(0.22, y, 0.5, 0.42, 0, 0.08, C.plankDark);
            c.box(0.24, y + 0.02, 0.46, 0.38, 0.08, 0.26, '#f5d36b', { wall: 'planks' });
            c.box(0.24, y + 0.02, 0.46, 0.38, 0.26, 0.42, '#f2c94c', { wall: 'planks' });
            c.pyramid(0.24, y + 0.02, 0.46, 0.38, 0.42, 0.16, roof, 'shingle', 0.05);
        }
        if (ctx.working || ctx.ready) c.emoji(0.55, 0.9, '🐝', 0.28, 0.75, { class: 'buzz' });
    },

    workshop(c, ctx, { wall = C.plaster, wallType = 'plaster', roof = C.tiles, material = 'tiles', signChar = '🏺', chimney = false, awning = null }) {
        const { w, h } = c;
        const t = ctx.tier;
        c.ground(C.grass);
        c.yard(0.1, h - 0.45, w - 0.2, 0.35, '#cbbd9e');
        const dx = w - 0.55;
        const dy = h - 0.75;
        const z = 0.7 + (t - 1) * 0.3;
        c.box(0.25, 0.25, dx, dy, 0, z, wall, { wall: wallType });
        if (chimney || t >= 2) c.box(0.4, 0.35, 0.18, 0.18, z, z + 0.75, '#9a5a3a', { wall: 'stone' });
        leftWindows(c, 0.25, 0.25 + dy, dx, 0.3, w > 2 ? 4 : 2, { flowers: t >= 2 });
        if (t >= 2) leftWindows(c, 0.25, 0.25 + dy, dx, 0.3 + 0.38, w > 2 ? 4 : 2);
        c.leftOpening(0.25 + dx * 0.42, 0.25 + dy + 0.001, 0.26, 0, 0.42, '#6a3a1a', C.white);
        rightWindows(c, 0.25 + dx, 0.25, dy, 0.3, 2);
        if (awning) {
            for (let i = 0; i < 6; i++) {
                const x0 = 0.25 + (dx * i) / 6;
                c.poly([[x0, 0.25 + dy, 0.6], [x0 + dx / 6, 0.25 + dy, 0.6], [x0 + dx / 6, 0.25 + dy + 0.25, 0.48], [x0, 0.25 + dy + 0.25, 0.48]], i % 2 ? C.white : awning, { 'stroke-width': 0.012 });
            }
        }
        c.roof(0.25, 0.25, dx, dy, z, 0.5, roof, { axis: 'x', wall, wallType, material });
        // hanging sign
        const [sx, sy] = c.P(0.25 + dx, 0.25 + dy * 0.5, z + 0.05);
        c.add(s('rect', { x: sx - 0.2, y: sy - 0.08, width: 0.4, height: 0.34, rx: 0.05, fill: C.plank, stroke: C.outline, 'stroke-width': 0.025 }));
        c.add(s('text', { x: sx, y: sy + 0.18, 'font-size': 0.26, 'text-anchor': 'middle' }, signChar));
        if (t >= 3) c.bush(w - 0.25, h - 0.3, 0.3, C.leaf, '#ff6b8a');
    },

    // ---------------------------------------------------------- outside the farm (lots of 4 x 3)

    market(c, ctx) {
        const t = ctx.tier;
        c.ground('#8fc35a', 0.02);
        c.yard(0.15, 0.15, 3.7, 2.7, '#c9b48a');
        if (t >= 3) {
            // covered market hall at the back
            c.box(0.35, 0.3, 2.6, 0.9, 0, 0.75, C.white, { wall: 'boards' });
            leftWindows(c, 0.35, 1.2, 2.6, 0.32, 4);
            c.roof(0.35, 0.3, 2.6, 0.9, 0.75, 0.45, '#4f9a5a', { axis: 'x', wall: C.white, material: 'metal' });
            bunting(c, [0.3, 1.5, 0.9], [3.5, 1.5, 0.9]);
        }
        const stalls = t === 1 ? [[0.5, 1.5, '#e94b3c'], [1.9, 1.7, '#3e9ad8']]
            : t === 2 ? [[0.4, 0.5, '#e94b3c'], [1.7, 0.6, '#3e9ad8'], [0.9, 1.75, '#f2a23a']]
                : [[0.5, 1.7, '#e94b3c'], [1.8, 1.8, '#3e9ad8'], [3.1, 1.5, '#f2a23a']];
        const goods = [['🍎', '🍐', '🥕'], ['🧀', '🥛', '🥚'], ['🍯', '🫙', '🌽']];
        stalls.forEach(([x, y, color], i) => stall(c, x, y, 0.75, 0.45, color, goods[i % 3]));
        c.emoji(3.25, 2.55, '🛻', 0.75);
        if (t >= 2) {
            c.cylinder(3.3, 0.6, 0.13, 0, 0.3, C.plankDark, { bands: 2 });
            c.emoji(0.4, 2.5, '🧑‍🌾', 0.45);
        }
    },

    show_animal(c) {
        c.ground('#8fc35a', 0.02);
        c.yard(0.25, 0.25, 3.5, 2.5, '#9fcf62');
        const front = c.pen(0.2, 'wood');
        c.poly([[0.6, 1.4, 0.04], [2.0, 1.4, 0.04], [2.0, 2.4, 0.04], [0.6, 2.4, 0.04]], '#e0b46a', { 'stroke-width': 0.015 }); // stage
        c.box(2.0, 0.4, 1.5, 1.3, 0, 0.85, '#b5523b', { wall: 'stone' });
        for (const yy of [0.55, 1.1]) c.rightOpening(3.5, yy, 0.38, 0, 0.55, '#5a2a1a', C.white);
        c.leftOpening(2.55, 1.701, 0.4, 0, 0.55, '#5a2a1a', C.white);
        c.roof(2.0, 0.4, 1.5, 1.3, 0.85, 0.75, C.slate, { axis: 'y', wall: '#b5523b', wallType: 'stone', material: 'slate' });
        tent(c, 0.95, 0.9, 0.45, 0.55, '#e23b3b');
        c.emoji(1.35, 1.9, '🐐', 0.5);
        c.emoji(0.4, 2.0, '🎈', 0.4, 0.4);
        c.emoji(3.6, 2.2, '🎈', 0.35, 0.3);
        c.tree(3.6, 0.5, 0.7);
        front();
    },

    library(c, ctx) {
        const t = ctx.tier;
        c.ground('#8fc35a', 0.02);
        c.yard(0.2, 2.2, 3.6, 0.65, '#cbbd9e');
        const brick = '#b4643f';
        if (t === 1) {
            c.box(1.0, 0.6, 1.7, 1.4, 0, 0.8, brick, { wall: 'stone' });
            leftWindows(c, 1.0, 2.0, 1.7, 0.32, 3, { frame: C.white });
            c.leftOpening(1.7, 2.001, 0.3, 0, 0.5, '#4a2a1a', C.white);
            c.roof(1.0, 0.6, 1.7, 1.4, 0.8, 0.7, C.slate, { axis: 'y', wall: brick, wallType: 'stone', material: 'slate' });
        } else {
            const dx = t === 2 ? 2.2 : 3.1;
            const z = t === 2 ? 1.2 : 1.35;
            c.box(0.45, 0.45, dx, 1.55, 0, z, brick, { wall: 'stone' });
            for (const zz of [0.3, 0.8]) leftWindows(c, 0.45, 2.0, dx, zz, t === 2 ? 4 : 6, { frame: '#f3e2c0' });
            rightWindows(c, 0.45 + dx, 0.45, 1.55, 0.3, 2, { frame: '#f3e2c0' });
            c.leftOpening(0.45 + dx * 0.45, 2.001, 0.34, 0, 0.55, '#4a2a1a', '#f3e2c0');
            c.roof(0.45, 0.45, dx, 1.55, z, 0.75, '#5a5048', { axis: 'x', wall: brick, wallType: 'stone', material: 'slate' });
            if (t === 3) {
                // yellow skylights along the front slope
                for (let i = 0; i < 5; i++) {
                    const x0 = 0.75 + i * 0.55;
                    c.poly([[x0, 1.9, z + 0.12], [x0 + 0.32, 1.9, z + 0.12], [x0 + 0.32, 1.55, z + 0.45], [x0, 1.55, z + 0.45]], '#ffd84a', { 'stroke-width': 0.015 });
                }
                c.cylinder(3.75, 1.2, 0.22, 0, 1.0, '#e2b84a', { bands: 3 });
            }
            const [cx, cy] = c.P(0.45 + dx * 0.62, 2.0, 0.62);
            c.add(s('circle', { cx, cy, r: 0.1, fill: '#fff', stroke: C.outline, 'stroke-width': 0.02 }));
        }
        c.bush(0.35, 1.9, 0.3, C.leaf, '#ff6b8a');
        c.emoji(3.4, 2.6, '📚', 0.35);
    },

    external_barn(c, ctx) {
        const t = ctx.tier;
        c.ground('#8fc35a', 0.02);
        c.yard(0.2, 0.2, 3.6, 2.6, '#c2a679');
        // pergola with crates at every tier
        for (const [x, y] of [[0.4, 1.6], [1.4, 1.6], [0.4, 2.4], [1.4, 2.4]]) c.box(x, y, 0.06, 0.06, 0, 0.6, C.plankDark);
        for (let i = 0; i < 6; i++) c.line([0.35 + i * 0.22, 1.55, 0.62], [0.35 + i * 0.22, 2.5, 0.62], C.plank, 0.05);
        c.box(0.6, 1.8, 0.3, 0.3, 0, 0.25, C.plank, { wall: 'planks' });
        c.cylinder(1.2, 2.1, 0.13, 0, 0.32, C.plankDark, { bands: 2 });
        if (t >= 2) {
            const base = t === 3 ? 0.35 : 0;
            if (base) for (const [x, y] of [[2.0, 0.4], [3.4, 0.4], [2.0, 1.6], [3.4, 1.6]]) c.box(x, y, 0.07, 0.07, 0, base, C.plankDark);
            const z = t === 3 ? 1.2 : 0.7;
            c.box(2.0, 0.4, 1.45, 1.25, base, base + z, C.plank, { wall: 'planks' });
            c.leftOpening(2.45, 1.651, 0.45, base, base + 0.5, '#5a3a1c', C.plankDark);
            if (t === 3) leftWindows(c, 2.0, 1.65, 1.45, base + 0.75, 2, { frame: C.plankDark });
            c.roof(2.0, 0.4, 1.45, 1.25, base + z, 0.5, t === 3 ? '#4f8a5a' : C.metal, { axis: 'x', wall: C.plank, wallType: 'planks', material: 'metal' });
            if (base) c.poly([[2.45, 1.65, base], [2.9, 2.3, 0], [2.9, 2.3, 0.02], [2.45, 1.65, base + 0.02]], C.plankDark);
        }
        c.emoji(3.3, 2.6, '📦', 0.3);
        c.emoji(1.9, 2.65, '🛒', 0.35);
    },

    cafe(c, ctx) {
        const t = ctx.tier;
        c.ground('#8fc35a', 0.02);
        c.yard(0.15, 1.8, 3.7, 1.0, '#d9c9a8');
        const dx = t === 1 ? 1.4 : t === 2 ? 2.0 : 2.6;
        c.box(0.4, 0.4, dx, 1.2, 0, 0.8, '#f6e6c8', { wall: 'boards' });
        leftWindows(c, 0.4, 1.6, dx, 0.3, Math.round(dx * 2), { flowers: true });
        for (let i = 0; i < 6; i++) {
            const x0 = 0.4 + (dx * i) / 6;
            c.poly([[x0, 1.6, 0.62], [x0 + dx / 6, 1.6, 0.62], [x0 + dx / 6, 1.85, 0.5], [x0, 1.85, 0.5]], i % 2 ? C.white : C.teal, { 'stroke-width': 0.012 });
        }
        c.roof(0.4, 0.4, dx, 1.2, 0.8, 0.55, C.teal, { axis: 'x', wall: '#f6e6c8', wallType: 'boards', material: 'tiles' });
        const tables = t === 1 ? [[2.4, 2.2]] : t === 2 ? [[1.0, 2.3], [2.6, 2.2]] : [[0.8, 2.3], [2.0, 2.4], [3.3, 2.2]];
        for (const [x, y] of tables) {
            c.cylinder(x, y, 0.16, 0, 0.22, C.white);
            c.cap(x, y, 0.32, 0.7, 0.2, '#2fb7a8', { cone: true });
            c.line([x, y, 0.22], [x, y, 0.7], '#555', 0.02);
        }
        c.emoji(0.4 + dx * 0.5, 1.62, '☕', 0.3, 0.95);
    },

    lot(c, ctx) {
        c.ground('#8fc35a', 0.02);
        c.pen(0.3, 'rope', 0.16)();
        c.box(1.85, 1.4, 0.06, 0.06, 0, 0.55, C.plankDark);
        c.poly([[1.5, 1.47, 0.5], [2.3, 1.47, 0.5], [2.3, 1.47, 0.95], [1.5, 1.47, 0.95]], C.plank, { 'stroke-width': 0.02 });
        c.emoji(1.9, 1.48, ctx.def.emoji, 0.32, 0.6);
        c.emoji(2.6, 2.3, '🔨', 0.3);
    },

    decoration(c, ctx) {
        c.ground(C.grass, 0.06);
        const type = ctx.type;
        if (type === 'ornamental_tree') return c.tree(0.5, 0.5, 1.0, '#3f9a4a', { blossom: true });
        if (type === 'hay_bale') {
            c.cylinder(0.5, 0.5, 0.3, 0, 0.36, '#e9c860', { bands: 2, topColor: '#f3d77a' });
            return c.emoji(0.5, 0.5, '🌾', 0.24, 0.36);
        }
        if (type === 'well') {
            c.cylinder(0.5, 0.5, 0.3, 0, 0.3, C.stone, { topColor: '#3d8fc4', bands: 2 });
            c.box(0.18, 0.47, 0.05, 0.05, 0.3, 0.8, C.plankDark);
            c.box(0.78, 0.47, 0.05, 0.05, 0.3, 0.8, C.plankDark);
            return c.roof(0.12, 0.3, 0.76, 0.4, 0.8, 0.25, C.tiles, { axis: 'x', wall: C.plank, material: 'tiles', o: 0.04 });
        }
        if (type === 'spring_fountain') {
            c.cylinder(0.5, 0.5, 0.42, 0, 0.16, C.stone, { topColor: C.water });
            c.cylinder(0.5, 0.5, 0.08, 0.16, 0.5, C.stone);
            c.cylinder(0.5, 0.5, 0.2, 0.5, 0.56, C.stone, { topColor: C.water });
            return c.emoji(0.5, 0.5, '💧', 0.22, 0.6);
        }
        if (type === 'pavilion') {
            for (const [x, y] of [[0.2, 0.2], [0.75, 0.2], [0.2, 0.75], [0.75, 0.75]]) c.box(x, y, 0.06, 0.06, 0, 0.55, C.white);
            return c.pyramid(0.2, 0.2, 0.61, 0.61, 0.55, 0.4, C.teal, 'slate', 0.08);
        }
        if (type === 'clothesline') {
            c.box(0.15, 0.48, 0.05, 0.05, 0, 0.6, C.plankDark);
            c.box(0.8, 0.48, 0.05, 0.05, 0, 0.6, C.plankDark);
            c.line([0.17, 0.5, 0.58], [0.82, 0.5, 0.58], '#555', 0.02);
            for (const [x, color] of [[0.32, '#5bb8e8'], [0.5, '#ff6b8a'], [0.68, '#ffd23f']]) {
                c.poly([[x - 0.06, 0.5, 0.58], [x + 0.06, 0.5, 0.58], [x + 0.06, 0.5, 0.38], [x - 0.06, 0.5, 0.38]], color, { 'stroke-width': 0.015 });
            }
            return c;
        }
        c.ground('#7a5230', 0.16);
        c.bush(0.35, 0.4, 0.2, C.leaf, '#ff6b8a');
        c.bush(0.65, 0.55, 0.2, C.leaf, '#ffd23f');
        return flowers(c, [[0.3, 0.7, '#b06bff'], [0.7, 0.3, '#ff6b8a']]);
    },

    generic(c, ctx) {
        c.ground(C.grass);
        c.box(0.25, 0.25, c.w - 0.5, c.h - 0.5, 0, 0.7, C.plaster);
        c.roof(0.25, 0.25, c.w - 0.5, c.h - 0.5, 0.7, 0.5, C.shingle, { material: 'shingle' });
        c.emoji(c.w - 0.25, c.h / 2, ctx.def.emoji, 0.4, 0.8);
    },
};

/** How each type is drawn: design, options, room needed above the ground. */
const TYPES = {
    field: ['field', {}, 0.9],
    barn: ['barn', {}, 2.3],
    farmhouse: ['farmhouse', {}, 2.2],
    house: ['house', {}, 2.6],
    windmill: ['windmill', {}, 3.7],
    chicken_coop: ['coop', { animal: '🐔', roofColor: C.slate, roofMaterial: 'slate', stilts: true }, 1.9],
    pigsty: ['coop', { animal: '🐖', mud: true, roofColor: C.metal, roofMaterial: 'metal', yardColor: '#a07a4a', fence: 'rope' }, 1.9],
    cowshed: ['coop', { animal: '🐄', wallColor: C.barnRed, roofColor: C.shingle, roofMaterial: 'shingle', yardColor: '#b9c97a', fence: 'wood' }, 1.9],
    goat_stable: ['coop', { animal: '🐐', wallColor: '#c9bfae', roofColor: C.tiles, roofMaterial: 'tiles', yardColor: '#c4b99a', fence: 'wood' }, 1.9],
    duck_coop: ['coop', { animal: '🦆', pond: true, roofColor: C.thatch, roofMaterial: 'thatch', yardColor: '#a9cf7a', fence: 'rope' }, 1.9],
    rabbit_hutch: ['coop', { animal: '🐇', roofColor: C.metal, roofMaterial: 'metal', yardColor: '#b9d98a', stilts: true }, 1.9],
    sheep_pen: ['coop', { animal: '🐑', roofColor: C.shingle, roofMaterial: 'shingle', yardColor: '#b9d98a', fence: 'wood' }, 1.9],
    apple_orchard: ['orchard', { fruit: '#e23b3b' }, 1.8],
    cherry_orchard: ['orchard', { fruit: '#a3132e', crown: '#4c9440', blossom: true }, 1.8],
    almond_orchard: ['orchard', { fruit: '#c79a5c', crown: '#6aa34a' }, 1.8],
    peach_orchard: ['orchard', { fruit: '#ffa36b', crown: '#57a03d' }, 1.8],
    meadow: ['meadow', {}, 0.8],
    silo: ['silo', {}, 2.7],
    composter: ['composter', {}, 1.2],
    beehive: ['beehive', {}, 1.6],
    dairy: ['workshop', { wall: C.white, wallType: 'boards', roof: C.blue, material: 'slate', signChar: '🥛' }, 2.6],
    jam_kitchen: ['workshop', { wall: '#f6dfc4', roof: C.tiles, material: 'tiles', signChar: '🫙', chimney: true }, 2.8],
    manufactory: ['workshop', { wall: '#c9bfae', wallType: 'stone', roof: C.purple, material: 'slate', signChar: '🏺', chimney: true }, 2.8],
    sewing_workshop: ['workshop', { wall: '#fbe8ef', wallType: 'boards', roof: C.pink, material: 'tiles', signChar: '🧵', awning: '#d9608a' }, 2.6],
    market: ['market', {}, 1.8],
    show_animal: ['show_animal', {}, 2.3],
    library: ['library', {}, 2.6],
    external_barn: ['external_barn', {}, 2.2],
    cafe: ['cafe', {}, 1.9],
    lot: ['lot', {}, 1.3],
};

/**
 * <svg> for a building on the map. Returns { svg, top } where top is the room
 * above the footprint (in local units) so the caller can position it.
 * `rotated` draws on the turned footprint (h x w): overlays only, as a whole
 * drawing is mirrored instead.
 */
export function buildingArt(building, def, { working = false, ready = false, product = null, overlay = false, rotated = false, top: forcedTop = null } = {}) {
    const base = def.variant_of ?? building.type;
    const [w, h] = rotated ? [def.size[1], def.size[0]] : def.size;
    const [design, options, defaultTop] = TYPES[base] ?? (def.kind === 'decoration' ? ['decoration', {}, 1.3] : ['generic', {}, 1.8]);
    const top = forcedTop ?? defaultTop;
    const c = new Canvas(w, h, top);
    const ctx = {
        def, building, type: base, variant: def.variant ?? null,
        tier: tierOf(building.level ?? 1, def.levels?.length ?? 1), working, ready, product, overlay,
    };
    if (!overlay || OVERLAYS.has(design)) DESIGNS[design](c, ctx, options);

    return { svg: c.svg(), top };
}

/** Designs that still draw something on top of a picture (crops on fields, flowers on meadows). */
const OVERLAYS = new Set(['field', 'meadow']);

/** Does this type draw an overlay above its picture? */
export function hasOverlay(type, def) {
    return OVERLAYS.has((TYPES[def.variant_of ?? type] ?? [])[0]);
}

/**
 * Picture of a building outside the farm (market, library...) on its 4 x 3
 * lot; an empty lot with a sign when it is not built yet.
 */
export function outsideArt(type, def, { level = 1, levels = def.levels?.length ?? 1, built = true } = {}) {
    const [design, options, top] = TYPES[built ? type : 'lot'] ?? TYPES.lot;
    const c = new Canvas(4, 3, top);
    const ctx = { def, building: { type, level }, type, variant: null, tier: tierOf(level, levels), working: false, ready: false, product: null };
    DESIGNS[design](c, ctx, options);

    return { svg: c.svg(), top };
}

/** Small picture of a building type for lists (build panel). */
export function buildingIcon(type, def, level = 1) {
    if (!def.size) {
        if (!TYPES[type]) return null;
        const { svg } = outsideArt(type, def, { level });
        svg.setAttribute('class', 'icon');
        return svg;
    }
    const { svg } = buildingArt({ type, level }, def);
    svg.setAttribute('class', 'icon');
    return svg;
}

/**
 * Banner above a building: 'idle' (pale blue, product + zZ), 'ready' (yellow,
 * product) or 'collect-all' (yellow, barn + arrow).
 */
export function pennant(kind, char) {
    const fill = kind === 'idle' ? 'rgb(190 232 250 / 0.82)' : '#ffd84a';
    const stroke = kind === 'idle' ? '#7cc4e4' : '#e0a91c';
    return s('svg', { class: `pennant ${kind}`, viewBox: '0 0 10 12', 'aria-hidden': 'true' },
        s('path', { d: 'M1,0.6 H9 Q9.5,0.6 9.5,1.1 V8 L5,11.4 L0.5,8 V1.1 Q0.5,0.6 1,0.6 Z', fill, stroke, 'stroke-width': 0.5, 'stroke-linejoin': 'round' }),
        s('path', { d: 'M1.4,1.4 H8.6 V3 Q5,2.2 1.4,3.6 Z', fill: '#fff', opacity: 0.35 }),
        s('text', { x: 5, y: 7.1, 'font-size': 5, 'text-anchor': 'middle' }, char),
        kind === 'idle' ? s('text', { x: 8.6, y: 1.8, 'font-size': 3.2, 'font-weight': 700, fill: '#3a8fd8', stroke: '#fff', 'stroke-width': 0.35, 'paint-order': 'stroke', class: 'zz' }, 'zZ') : null,
        kind === 'collect-all' ? s('g', {},
            s('circle', { cx: 7.6, cy: 7.2, r: 1.8, fill: '#3fa34a', stroke: '#fff', 'stroke-width': 0.4 }),
            s('path', { d: 'M6.8,7.4 l0.8,0.8 l1.4,-1.6', stroke: '#fff', 'stroke-width': 0.5, fill: 'none', 'stroke-linecap': 'round' })) : null);
}
