/**
 * Isometric map geometry. Unit u = half a tile width (CSS var --u); a cell
 * is a diamond 2u wide and 1u high.
 *
 * A layout L = { side, out, x1 }: the farm is cells 0..side-1 on both axes;
 * the outside strip (market, library...) lies along the farm's top-right edge
 * at y = -out..-1, for x = 0..x1. Point (x, y) is drawn at
 * (side + x - y, TOP + (x + y + out) / 2) units, TOP leaving room above.
 */

export const TOP = 3.4;
export const DEPTH = 0.5; // earth edge under the land
export const OUT_ROWS = 5; // depth of the outside strip (4 for lots + 1 for the path)
export const LOT = [4, 3]; // footprint of an outside building lot

export function layout(side, outsideLots) {
    return { side, out: OUT_ROWS, x1: Math.max(side, outsideLots * LOT[0]) };
}

export function mapSize(L) {
    return { width: L.side + L.x1 + L.out, height: TOP + (L.x1 + L.side + L.out) / 2 + DEPTH };
}

/** Top vertex of point / cell (x, y), in units. */
export function cellTop(L, x, y) {
    return [L.side + x - y, TOP + (x + y + L.out) / 2];
}

/** Diamond of a w x h footprint at (x, y), as SVG points (units). */
export function footprintPoints(L, x, y, w, h) {
    const corners = [[x, y], [x + w, y], [x + w, y + h], [x, y + h]];
    return corners.map(([cx, cy]) => cellTop(L, cx, cy).map((n) => +n.toFixed(3)).join(',')).join(' ');
}

/** Point (in cells, may be fractional) under a screen point given in units. */
export function pointAt(L, ux, uy) {
    const a = ux - L.side; // x - y
    const b = 2 * (uy - TOP) - L.out; // x + y
    return { x: (a + b) / 2, y: (b - a) / 2 };
}

/** Farm cell under a point given in units, or null outside the farm. */
export function cellAt(L, ux, uy) {
    const p = pointAt(L, ux, uy);
    const x = Math.floor(p.x);
    const y = Math.floor(p.y);
    if (x < 0 || y < 0 || x >= L.side || y >= L.side) return null;

    return { x, y };
}

/** Where to put a building element: box in units (left, top, width, height). */
export function buildingBox(L, x, y, w, h, top) {
    const [vx, vy] = cellTop(L, x, y);

    return { left: vx - h, top: vy - top, width: w + h, height: top + (w + h) / 2 };
}

/** Lot of the n-th outside building (0 = nearest the farm's top corner). */
export function outsideLot(n) {
    return { x: n * LOT[0], y: -OUT_ROWS, w: LOT[0], h: LOT[1] };
}
