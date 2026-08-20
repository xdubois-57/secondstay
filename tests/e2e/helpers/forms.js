/**
 * Saisie fiable d'un champ de formulaire natif.
 *
 * WebKit mobile n'applique pas toujours `fill()` à un `input[type=date]` :
 * le champ reste vide, le formulaire part sans dates et le serveur répond —
 * à juste titre — par une erreur de validation. Le test échouerait alors sans
 * qu'aucun défaut du produit soit en cause.
 *
 * Le remplissage est donc vérifié, et repris par le DOM avec les événements
 * que le sélecteur natif émet lui-même si la première tentative n'a pas pris.
 *
 * Le champ est ensuite **quitté**. Un utilisateur referme le sélecteur natif
 * avant de toucher le bouton suivant ; laissé ouvert sous WebKit mobile, il
 * absorbe le clic suivant, qui ne fait alors que le refermer — le formulaire
 * ne part pas, et l'échec ressemble à un défaut du produit.
 */
export async function fillDate(page, selector, value) {
    const field = page.locator(selector);

    await field.fill(value);
    if ((await field.inputValue()) !== value) {
        await field.evaluate((element, wanted) => {
            element.value = wanted;
            element.dispatchEvent(new Event('input', { bubbles: true }));
            element.dispatchEvent(new Event('change', { bubbles: true }));
        }, value);
    }

    const applied = await field.inputValue();
    if (applied !== value) {
        throw new Error(`Le champ ${selector} vaut « ${applied} » au lieu de « ${value} ».`);
    }

    await field.evaluate((element) => element.blur());
}
