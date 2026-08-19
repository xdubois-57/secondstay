<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;

final class HomeController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function index(RequestContext $context, array $params = []): Response
    {
        // L'URL canonique du site public porte toujours un préfixe de langue.
        if (!$context->localePrefixPresent) {
            return $this->redirectToRoute($context, 'home');
        }

        return $this->render('public/home.html.twig', [
            'meta_title' => $this->trans('meta.home.title'),
            'meta_description' => $this->trans('meta.home.description'),
        ]);
    }
}
