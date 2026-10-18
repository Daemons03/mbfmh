/**
 * Isometric map geometry. Unit u = half a tile width (CSS var --u); a cell
 * is a diamond 2u wide and 1u high. Cell (x, y) has its top vertex at
 * (side + x - y, TOP + (x + y) / 2) in units, TOP leaving room for tall
 * buildings at the back of the map.
 */

export const TOP = 3.4;
export const DEPTH = 0.5; // earth edge under the land

export function mapSize(side) {
    return { width: 2 * side, height: TOP + side + DEPTH };
}

/** Top vertex of cell (x, y), in units. */
export function cellTop(side, x, y) {
    return [side + x - y, TOP + (x + y) / 2];
}

/** Diamond of a w x h footprint at (x, y), as SVG points (units). */
export function footprintPoints(side, x, y, w, h) {
    const corners = [[x, y], [x + w, y], [x + w, y + h], [x, y + h]];
    return corners.map(([cx, cy]) => cellTop(side, cx, cy).map((n) => +n.toFixed(3)).join(',')).join(' ');
}

/** Cell under a point given in units from the map's top-left corner, or null outside. */
export function cellAt(side, ux, uy) {
    const a = ux - side; // x - y
    const b = 2 * (uy - TOP); // x + y
    const x = Math.floor((a + b) / 2);
    const y = Math.floor((b - a) / 2);
    if (x < 0 || y < 0 || x >= side || y >= side) return null;

    return { x, y };
}

/** Where to put a building element: box in units (left, top, width, height). */
export function buildingBox(side, x, y, w, h, top) {
    const [vx, vy] = cellTop(side, x, y);

    return { left: vx - h, top: vy - top, width: w + h, height: top + (w + h) / 2 };
}

/** Screen centre (units) of a footprint, for scrolling and badges. */
export function footprintCenter(side, x, y, w, h) {
    const [vx, vy] = cellTop(side, x + w / 2, y + h / 2);

    return [vx, vy];
}
