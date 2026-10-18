import { buildingIcon, tierOf } from './art.js';
import { h } from './dom.js';
import { store } from './store.js';

/**
 * Building pictures (public/assets/img/buildings, see scripts/import-sprites.php).
 * manifest.json gives, per type and tier, the picture size and the left,
 * right and bottom corners of its ground diamond, so it can be laid exactly
 * on a footprint. Missing tiers fall back to the one below.
 */

const BASE = 'assets/img/buildings/';

export async function loadSprites() {
    try {
        const response = await fetch(`${BASE}manifest.json`, { cache: 'no-cache' });
        store.sprites = response.ok ? await response.json() : {};
    } catch {
        store.sprites = {};
    }
}

/** Picture for a type at a tier (1-3), or the closest lower tier; null when none. */
export function spriteFor(type, tier) {
    const tiers = store.sprites?.[type];
    if (!tiers) return null;
    for (let t = tier; t >= 1; t--) {
        if (tiers[t]) return tiers[t];
    }
    return null;
}

/**
 * Should the picture be mirrored on a w x h footprint? Pictures show one
 * orientation (guessed from where their bottom corner is); the other one is
 * the mirror image.
 */
export function needsMirror(sprite, size, rotated) {
    const [sw, sh] = size;
    let nativeRotated = false;
    if (sw !== sh) {
        const frac = (sprite.bottom[0] - sprite.left[0]) / (sprite.right[0] - sprite.left[0]);
        nativeRotated = Math.abs(frac - sh / (sw + sh)) < Math.abs(frac - sw / (sw + sh));
    }
    return Boolean(rotated) !== nativeRotated;
}

/**
 * Box of the picture in units, in the footprint's own frame (top corner of
 * the w x h diamond at (h, 0)).
 */
export function spriteBox(sprite, w, h, mirrored) {
    const { width, height } = sprite;
    const left = mirrored ? width - sprite.right[0] : sprite.left[0];
    const right = mirrored ? width - sprite.left[0] : sprite.right[0];
    const scale = (w + h) / (right - left);

    return {
        left: -left * scale,
        top: (w + h) / 2 - sprite.bottom[1] * scale,
        width: width * scale,
        height: height * scale,
    };
}

export function spriteUrl(sprite) {
    return BASE + sprite.file;
}

/** <img> of a building picture for lists and cards. */
export function spriteImg(sprite, { mirrored = false, cls = 'sprite-icon' } = {}) {
    return h('img', { src: spriteUrl(sprite), alt: '', class: `${cls}${mirrored ? ' mirrored' : ''}`, draggable: 'false', loading: 'lazy' });
}

/** Picture of a building type for lists and cards: its image when there is one, else the drawing. */
export function buildingPicture(type, def, { level = 1, rotated = false, cls = 'sprite-icon' } = {}) {
    const sprite = spriteFor(type, tierOf(level, def.levels?.length ?? 1));
    if (!sprite) return buildingIcon(type, def, level);
    const mirrored = def.size ? needsMirror(sprite, def.size, rotated) : false;
    return spriteImg(sprite, { mirrored, cls });
}
