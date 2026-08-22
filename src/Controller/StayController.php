<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Auth\Role;
use SecondStay\Booking\BookingRepository;
use SecondStay\Core\Exception\NotFoundException;
use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\LocalContent\LocalContentService;
use SecondStay\Stay\BlockIllustrations;
use SecondStay\Stay\GuestLinkRepository;
use SecondStay\Stay\StayService;
use SecondStay\Stay\StayView;
use SecondStay\Support\QrCode;

/**
 * « Mon séjour aujourd'hui » et lien invité (SPECIFICATIONS.md §45 à §47).
 *
 * Cette page est la seule du produit conçue pour fonctionner **hors ligne** :
 * elle ne porte donc ni montant, ni document, ni action d'écriture. Ce qu'elle
 * montre — livret d'accueil, Wi-Fi, accès, déchets, sécurité, contact local —
 * est exactement ce que la spécification autorise à mettre en cache.
 */
final class StayController extends AbstractController
{
    /**
     * Séjour du client connecté.
     *
     * @param array<string, string> $params
     */
    public function show(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        if ($booking === null || ($booking->userId !== $user->id && !$user->isOperational())) {
            throw new NotFoundException('Séjour introuvable.');
        }

        $view = $this->container->get(StayService::class)->forBooking($booking, $context->locale);

        return $this->renderStay($context, $view, [
            'guest_links' => $this->container->get(GuestLinkRepository::class)->forBooking($booking->id),
            'guest_token' => $this->takeIssuedToken(),
        ]);
    }

    /**
     * Séjour derrière un lien invité : aucun compte n'est requis.
     *
     * @param array<string, string> $params
     */
    public function guest(RequestContext $context, array $params = []): Response
    {
        $view = $this->container->get(StayService::class)
            ->forGuestToken((string) ($params['token'] ?? ''), $context->locale);

        if ($view === null) {
            // Lien inconnu, expiré ou révoqué : la même réponse dans les trois
            // cas, qui n'apprend rien à qui essaie.
            throw new NotFoundException('Lien invité introuvable.');
        }

        return $this->renderStay($context, $view, ['guest_links' => [], 'guest_token' => '']);
    }

    /**
     * Délivre un lien invité pour son propre séjour.
     *
     * @param array<string, string> $params
     */
    public function issueGuestLink(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $booking = $this->container->get(BookingRepository::class)
            ->findByReference((string) ($params['reference'] ?? ''));

        if ($booking === null || $booking->userId !== $user->id) {
            throw new NotFoundException('Séjour introuvable.');
        }

        $result = $this->container->get(StayService::class)->issueGuestLink(
            $booking,
            $context->locale,
            mb_substr((string) $context->request->input('label', ''), 0, 120),
            $user,
        );

        if ($result['ok'] === false) {
            $this->flashError($result['error']);

            return $this->redirectToRoute($context, 'stay.show', ['reference' => $booking->reference]);
        }

        // Le jeton n'est montré qu'une fois, et transite par la session plutôt
        // que par l'URL, où il resterait dans l'historique.
        $this->session()->set('guest_token', $result['token']);
        $this->flashSuccess('stay.guest.created');

        return $this->redirectToRoute($context, 'stay.show', ['reference' => $booking->reference]);
    }

    /**
     * @param array<string, string> $params
     */
    public function revokeGuestLink(RequestContext $context, array $params = []): Response
    {
        $user = $this->requireRole(Role::Customer);

        $links = $this->container->get(GuestLinkRepository::class);
        $link = $links->find((int) ($params['id'] ?? 0));

        $booking = $link === null ? null : $this->container->get(BookingRepository::class)->find($link->bookingId);

        if ($link === null || $booking === null || ($booking->userId !== $user->id && !$user->isOperational())) {
            throw new NotFoundException('Lien invité introuvable.');
        }

        $result = $this->container->get(StayService::class)->revokeGuestLink($link->id, $user);
        $result['ok'] ? $this->flashSuccess('stay.guest.revoked') : $this->flashError($result['error']);

        return $this->redirectToRoute($context, 'stay.show', ['reference' => $booking->reference]);
    }

    // --- Rendu ---------------------------------------------------------------------

    /**
     * @param array<string, mixed> $extra
     */
    private function renderStay(RequestContext $context, StayView $view, array $extra): Response
    {
        $token = is_string($extra['guest_token'] ?? null) ? $extra['guest_token'] : '';

        return $this->render('stay/show.html.twig', $extra + [
            // L'état des lieux demande un compte : un invité n'y a pas accès,
            // et le propriétaire peut préférer le remplir lui-même.
            'inspection_enabled' => !$view->isGuest && $this->settings()->bool('inspection.guest_enabled'),
            // Activités locales : filtrées sur les dates exactes du séjour, et
            // visibles aussi d'un invité — ce sont des informations pratiques,
            // pas des données de réservation (SPECIFICATIONS.md §58).
            'activities' => $this->container->get(LocalContentService::class)
                ->activitiesFor($view->booking, $view->locale),
            'meta_title' => $this->trans('stay.title'),
            'stay' => $view,
            // Les illustrations sont résolues ici, une fois : un gabarit ne
            // va pas chercher un média en base.
            'illustrations' => $this->container->get(BlockIllustrations::class)
                ->forBlocks($view->visibleBlocks(), $view->locale),
            'booking' => $view->booking,
            'guest_url' => $token === '' ? '' : $this->guestUrl($context, $token),
            // Le QR est rendu en ligne plutôt que servi par une seconde
            // requête : le jeton n'apparaît ainsi dans aucune URL, et le
            // navigateur n'a rien de plus à demander (SPECIFICATIONS.md §47).
            'guest_qr' => $token === '' ? '' : QrCode::toSvg($this->guestUrl($context, $token), 4, 2),
            // La page hors ligne ne doit jamais être indexée : elle porte des
            // informations propres à un séjour.
            'meta_robots' => 'noindex, nofollow',
        ]);
    }

    private function guestUrl(RequestContext $context, string $token): string
    {
        $base = rtrim($this->settings()->string('site.public_url'), '/');
        if ($base === '') {
            $base = rtrim($context->request->baseUrl(), '/');
        }

        return $base . $this->router()->path('stay.guest', ['token' => $token], $context->locale);
    }

    private function takeIssuedToken(): string
    {
        $token = $this->session()->string('guest_token');
        if ($token !== '') {
            $this->session()->remove('guest_token');
        }

        return $token;
    }
}
