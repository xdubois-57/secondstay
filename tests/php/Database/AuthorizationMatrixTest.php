<?php

declare(strict_types=1);

namespace SecondStay\Tests\Database;

use SecondStay\Auth\Role;
use SecondStay\Core\Access;
use SecondStay\Core\Router;
use SecondStay\Core\Routes;
use SecondStay\Core\Session;
use SecondStay\Push\VapidKeyManager;
use SecondStay\Security\Csrf;
use SecondStay\Settings\SettingsService;
use SecondStay\Tests\Support\InstalledAppTestCase;

/**
 * La matrice d'autorisation : chaque route, rejouée avec chaque rôle.
 *
 * ## Ce que cette gate attrape et qu'aucune autre ne voit
 *
 * SecondStay vérifie les rôles **dans le corps de chaque action**
 * (`requireOperational()`, `requireAdministrator()`). C'est explicite et
 * lisible, mais une action ajoutée sans l'appel n'est protégée par rien, et
 * rien ne le signalait : il n'existait aucune déclaration à confronter au
 * comportement. Un test qui aurait couvert la nouvelle action l'aurait
 * couverte **connecté**, donc en passant à côté du trou.
 *
 * `Core\Access`, déclaré sur chaque route, est cette déclaration. Ce test est
 * ce qui la confronte à la réalité.
 *
 * ## Il ne lit pas le code, il interroge l'application
 *
 * Une version statique de cette gate — chercher `requireOperational()` dans la
 * source de l'action — a été écrite d'abord, et elle s'est trompée sur huit
 * routes : `InspectionController` place sa garde dans un helper privé
 * (`resolve()`), invisible à qui ne lit que le corps de l'action. C'est la
 * raison d'être de ce test : ce qui compte n'est pas ce que le code a l'air de
 * faire, c'est ce que l'application **répond**.
 *
 * ## La comparaison va dans les deux sens
 *
 * Trop permissif est un trou de sécurité. Trop strict est un défaut aussi
 * grave pour une autre raison : la table des routes ment alors sur qui accède
 * à quoi, et la prochaine personne à la lire se trompera. C'est aussi ce qui
 * rend une annotation oubliée bruyante — `Access::Public` étant la valeur par
 * défaut, une route d'administration ajoutée sans y penser refuse un visiteur
 * alors qu'elle se déclare publique, et la gate refuse.
 *
 * ## Les POST ne sont rejoués que dans le sens du refus
 *
 * Un POST autorisé **agit** : rejouer `/admin/users/{id}/delete` en
 * administrateur pour vérifier qu'il n'est pas refusé supprimerait un compte,
 * et les routes suivantes s'exécuteraient dans un monde différent. Les rôles
 * qui doivent être refusés, eux, ne produisent aucun effet — la garde tombe
 * avant toute écriture — et c'est le sens dangereux, celui qui laisse passer.
 *
 * Le sens « trop strict » reste donc couvert pour les GET seulement. Un POST
 * déclaré trop strict casse un parcours visible, que la campagne Playwright
 * traverse ; un POST déclaré trop permissif ne casse rien du tout et ne se
 * voit nulle part ailleurs.
 */
final class AuthorizationMatrixTest extends InstalledAppTestCase
{
    /**
     * Valeurs concrètes substituées aux paramètres de route.
     *
     * Une carte explicite, et non une génération à partir de la contrainte :
     * un paramètre inconnu doit faire échouer la gate plutôt que produire une
     * URL qui ne correspond à rien — une route qu'on croit avoir interrogée
     * alors qu'elle a répondu 404 avant d'atteindre sa garde est exactement le
     * vert qui ne prouve rien.
     *
     * @var array<string, string>
     */
    private const PARAMETER_VALUES = [
        'code' => 'accueil',
        'filename' => 'photo.jpg',
        'id' => '1',
        'kind' => 'checkin',
        'maskable' => 'maskable',
        'reference' => 'AB12CD34',
        'size' => '192',
        'slug' => 'accueil',
        'token' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
        'topic' => 'sejour',
        'variant' => 'thumb',
    ];

    /**
     * Un drapeau de fonctionnalité qui précède la garde rend la garde
     * inobservable : `PushController` répond 404 quand le push est désactivé,
     * **avant** de vérifier le rôle. L'ordre est le bon — une fonctionnalité
     * éteinte n'a pas à révéler qu'elle existe — mais laisser la gate lire ce
     * 404 comme « non refusée » reviendrait à ne rien vérifier sur ces routes.
     *
     * On allume donc ce qui doit l'être, plutôt que d'excuser le 404. Toute
     * fonctionnalité ajoutée avec le même motif devra rejoindre cette liste,
     * et la gate le dira en devenant rouge.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->container->get(SettingsService::class)->setMany([
            'notification.push_enabled' => '1',
        ]);
        // Le drapeau ne suffit pas : `isPushEnabled()` exige aussi un
        // fournisseur configuré, donc une paire de clés VAPID. On allume la
        // fonctionnalité pour de bon, jusqu'au bout.
        $this->container->get(VapidKeyManager::class)->ensureKeys();
    }

    public function testEveryRouteAnswersTheAccessLevelItDeclares(): void
    {
        $router = new Router();
        Routes::register($router);
        $routes = $router->routes();

        self::assertGreaterThan(150, count($routes), 'La table des routes semble incomplète.');

        $this->createUser(self::MANAGER_EMAIL, Role::LocalManager);
        $this->createUser(self::CUSTOMER_EMAIL, Role::Customer);

        /** @var array<string, ?Role> $actors */
        $actors = [
            'visiteur anonyme' => null,
            'client' => Role::Customer,
            'responsable local' => Role::LocalManager,
            'administrateur' => Role::Administrator,
        ];

        $problems = [];

        foreach ($actors as $label => $role) {
            $this->becomes($role);

            foreach ($routes as $route) {
                $access = $route['access'];
                $shouldBeDenied = !$access->isSatisfiedBy($role);

                // Voir l'en-tête : un POST autorisé agit, donc on ne rejoue
                // que le sens du refus.
                if ($route['method'] !== 'GET' && !$shouldBeDenied) {
                    continue;
                }

                $status = $this->replay($route);

                // Une erreur serveur n'est pas une autorisation. Sans ce
                // contrôle, une route qui plante répond 500, la gate lit
                // « non refusée », et une route cassée passe pour une route
                // correctement ouverte — sur toute la moitié de la matrice
                // qui vérifie l'accès accordé.
                if (!$shouldBeDenied && $status >= 500) {
                    $problems[] = sprintf(
                        '%s %s (%s, déclarée %s) : erreur serveur %d en %s — une panne ne prouve aucun accès',
                        $route['method'],
                        $route['pattern'],
                        $route['name'],
                        $access->value,
                        $status,
                        $label
                    );

                    continue;
                }

                $denied = $status === 403;

                if ($denied === $shouldBeDenied) {
                    continue;
                }

                $problems[] = sprintf(
                    '%s %s (%s, déclarée %s) : %s en %s — réponse %d',
                    $route['method'],
                    $route['pattern'],
                    $route['name'],
                    $access->value,
                    $shouldBeDenied ? 'aurait dû être refusée' : 'refusée à tort',
                    $label,
                    $status
                );
            }
        }

        self::assertSame([], $problems, sprintf(
            "%d route(s) ne répondent pas au niveau d'accès qu'elles déclarent :\n  - %s",
            count($problems),
            implode("\n  - ", $problems)
        ));
    }

    /**
     * Toute route non publique doit être atteignable par au moins un rôle :
     * une route que personne ne peut ouvrir est du code mort déguisé en
     * fonctionnalité.
     */
    public function testNoRouteIsUnreachableByEveryRole(): void
    {
        $router = new Router();
        Routes::register($router);

        $unreachable = [];
        foreach ($router->routes() as $route) {
            if (!$route['access']->isSatisfiedBy(Role::Administrator)) {
                $unreachable[] = $route['name'];
            }
        }

        self::assertSame([], $unreachable);
    }

    /**
     * Ouvre une session neuve et, le cas échéant, s'y authentifie.
     *
     * Une session neuve à chaque rôle, et non une déconnexion : un reste
     * d'état — un jeton CSRF, un message flash, une préférence — rendrait le
     * résultat du rôle suivant dépendant du précédent.
     */
    private function becomes(?Role $role): void
    {
        $session = new Session();
        $session->start();
        $session->regenerate();
        $this->container->instance(Session::class, $session);

        if ($role === null) {
            return;
        }

        $this->loginAs(match ($role) {
            Role::Administrator => self::ADMIN_EMAIL,
            Role::LocalManager => self::MANAGER_EMAIL,
            Role::Customer => self::CUSTOMER_EMAIL,
        });
    }

    /**
     * @param array{method: string, pattern: string, name: string, localised: bool, access: Access, handler: array{0: class-string, 1: string}} $route
     *
     * @return int code de statut HTTP
     */
    private function replay(array $route): int
    {
        $path = $this->concrete($route['pattern'], $route['name']);
        if ($route['localised']) {
            $path = '/fr' . $path;
        }

        // Le jeton CSRF est fourni : sans lui, un POST refusé le serait pour
        // CSRF et non pour son rôle, et les deux répondent 403. La gate lirait
        // alors « refusée » partout et serait verte sans rien prouver.
        $post = $route['method'] === 'GET' ? [] : [Csrf::FIELD => $this->container->get(Csrf::class)->token()];

        return $this->request($path, $route['method'], $post)->status();
    }

    private function concrete(string $pattern, string $name): string
    {
        return (string) preg_replace_callback(
            '/\{(\w+)(?::[^{}]*(?:\{[^{}]*\})?[^{}]*)?\}/',
            function (array $matches) use ($name): string {
                $parameter = $matches[1];
                if (!array_key_exists($parameter, self::PARAMETER_VALUES)) {
                    self::fail(sprintf(
                        'Paramètre de route inconnu « %s » (route %s) : ajoutez-lui une valeur concrète dans '
                        . 'PARAMETER_VALUES, sinon cette route est interrogée sur une URL qui ne lui correspond pas.',
                        $parameter,
                        $name
                    ));
                }

                return self::PARAMETER_VALUES[$parameter];
            },
            $pattern
        );
    }
}
