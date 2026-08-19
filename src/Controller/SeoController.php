<?php

declare(strict_types=1);

namespace SecondStay\Controller;

use SecondStay\Core\Http\Response;
use SecondStay\Core\RequestContext;
use SecondStay\Seo\SeoBuilder;

final class SeoController extends AbstractController
{
    /**
     * @param array<string, string> $params
     */
    public function sitemap(RequestContext $context, array $params = []): Response
    {
        return new Response(
            $this->container->get(SeoBuilder::class)->sitemap(),
            200,
            ['Content-Type' => 'application/xml; charset=UTF-8']
        );
    }

    /**
     * @param array<string, string> $params
     */
    public function robots(RequestContext $context, array $params = []): Response
    {
        return Response::text($this->container->get(SeoBuilder::class)->robots());
    }
}
