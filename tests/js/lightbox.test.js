import { describe, expect, it } from 'vitest';
import {
    close,
    createLightboxState,
    current,
    next,
    openAt,
    previous
} from '../../public/assets/js/modules/lightbox.js';

const items = [
    { src: '/media/large/a.jpg', caption: 'A' },
    { src: '/media/large/b.jpg', caption: 'B' },
    { src: '/media/large/c.jpg', caption: 'C' }
];

describe('lightbox', () => {
    it('starts closed on the first item', () => {
        const state = createLightboxState(items);
        expect(state.open).toBe(false);
        expect(state.index).toBe(0);
        expect(current(state).caption).toBe('A');
    });

    it('opens at the requested index', () => {
        const state = openAt(createLightboxState(items), 2);
        expect(state.open).toBe(true);
        expect(current(state).caption).toBe('C');
    });

    it('clamps an out-of-range index', () => {
        expect(openAt(createLightboxState(items), 99).index).toBe(2);
        expect(openAt(createLightboxState(items), -5).index).toBe(0);
        expect(openAt(createLightboxState(items), 'x').index).toBe(0);
    });

    it('cycles forwards and backwards', () => {
        let state = openAt(createLightboxState(items), 2);
        state = next(state);
        expect(current(state).caption).toBe('A');
        state = previous(state);
        expect(current(state).caption).toBe('C');
    });

    it('closes without losing the position', () => {
        const state = close(openAt(createLightboxState(items), 1));
        expect(state.open).toBe(false);
        expect(state.index).toBe(1);
    });

    it('tolerates an empty gallery', () => {
        const empty = createLightboxState([]);
        expect(current(empty)).toBeNull();
        expect(openAt(empty, 3).open).toBe(false);
        expect(next(empty).index).toBe(0);
        expect(previous(empty).index).toBe(0);
        expect(createLightboxState(null).items).toEqual([]);
    });
});
