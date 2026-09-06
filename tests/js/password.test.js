import { describe, expect, it } from 'vitest';
import {
    MIN_LENGTH,
    evaluatePassword,
    strengthLevel
} from '../../public/assets/js/modules/password.js';

describe('password strength', () => {
    it('requires at least the server minimum length', () => {
        expect(MIN_LENGTH).toBe(12);
        expect(evaluatePassword('Ab1cdefg').issues).toContain('auth.password.too_short');
    });

    it('reports an empty password', () => {
        const result = evaluatePassword('');
        expect(result.score).toBe(0);
        expect(result.level).toBe('empty');
    });

    it('detects missing character classes', () => {
        expect(evaluatePassword('abcdefghijklm').issues).toContain('auth.password.needs_uppercase');
        expect(evaluatePassword('ABCDEFGHIJKLM').issues).toContain('auth.password.needs_lowercase');
        expect(evaluatePassword('Abcdefghijklm').issues).toContain('auth.password.needs_digit');
    });

    it('penalises repetitive passwords', () => {
        const result = evaluatePassword('AAAAaaaa1111');
        expect(result.issues).toContain('auth.password.too_repetitive');
        expect(result.score).toBeLessThan(60);
    });

    it('accepts a strong password', () => {
        const result = evaluatePassword('Marée-Haute-2026!');
        expect(result.issues).toEqual([]);
        expect(result.level).toBe('strong');
        expect(result.score).toBeGreaterThanOrEqual(90);
    });

    
    it('rejects non-string input safely', () => {
        expect(evaluatePassword(null).score).toBe(0);
        expect(evaluatePassword(undefined).level).toBe('empty');
    });
});
