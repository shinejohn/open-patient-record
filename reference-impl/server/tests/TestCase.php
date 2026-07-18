<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The auth manager caches the resolved user per guard for the lifetime of the
     * application instance — which, in tests, spans every request in a test method.
     * Without this reset, the FIRST authenticated user leaks into every later
     * request regardless of the token sent, and authorization tests silently pass
     * against the wrong identity. Forget guards before each simulated request so
     * each one authenticates exactly like a real HTTP request would.
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
