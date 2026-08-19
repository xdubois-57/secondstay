<?php

declare(strict_types=1);

namespace SecondStay\Core;

use SecondStay\Core\Http\Request;

final class RequestContext
{
    public function __construct(
        public readonly Request $request,
        public readonly string $locale,
        public readonly bool $localePrefixPresent,
        public readonly string $routePath,
    ) {
    }
}
