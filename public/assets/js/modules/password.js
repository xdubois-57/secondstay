/**
 * Évaluation de la robustesse d'un mot de passe côté client.
 *
 * La règle miroir côté serveur (`SecondStay\Auth\PasswordHasher::evaluate`)
 * reste l'autorité : cet indicateur est uniquement une aide à la saisie.
 */

export const MIN_LENGTH = 12;

export function evaluatePassword(password) {
    if (typeof password !== 'string' || password === '') {
        return { score: 0, level: 'empty', issues: ['auth.password.too_short'] };
    }

    const issues = [];
    const length = [...password].length;

    if (length < MIN_LENGTH) {
        issues.push('auth.password.too_short');
    }
    if (!/\p{Lu}/u.test(password)) {
        issues.push('auth.password.needs_uppercase');
    }
    if (!/\p{Ll}/u.test(password)) {
        issues.push('auth.password.needs_lowercase');
    }
    if (!/\d/.test(password)) {
        issues.push('auth.password.needs_digit');
    }

    let score = Math.min(40, Math.floor(length * 2.5));
    score += /\p{Lu}/u.test(password) ? 15 : 0;
    score += /\p{Ll}/u.test(password) ? 15 : 0;
    score += /\d/.test(password) ? 15 : 0;
    score += /[^\p{L}\d]/u.test(password) ? 15 : 0;

    const unique = new Set(password).size;
    if (unique < 5) {
        score = Math.round(score / 2);
        issues.push('auth.password.too_repetitive');
    }

    score = Math.min(100, score);

    return { score, level: strengthLevel(score), issues };
}

export function strengthLevel(score) {
    if (score < 40) {
        return 'weak';
    }
    if (score < 70) {
        return 'fair';
    }
    if (score < 90) {
        return 'good';
    }
    return 'strong';
}

