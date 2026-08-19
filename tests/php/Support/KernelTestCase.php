<?php

declare(strict_types=1);

namespace SecondStay\Tests\Support;

use PHPUnit\Framework\TestCase;
use SecondStay\Core\Http\Request;
use SecondStay\Core\Http\Response;
use SecondStay\Core\Kernel;

abstract class KernelTestCase extends TestCase
{
    protected function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    protected function kernel(): Kernel
    {
        return new Kernel($this->projectRoot());
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     * @param array<string, mixed>  $post
     */
    protected function request(
        string $path,
        string $method = 'GET',
        array $server = [],
        array $cookies = [],
        array $post = [],
    ): Request {
        return new Request($method, $path, [], $post, $server, $cookies);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $cookies
     */
    protected function get(string $path, array $server = [], array $cookies = []): Response
    {
        return $this->kernel()->handle($this->request($path, 'GET', $server, $cookies));
    }
}
