<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Same guard-reset as reference-impl/server/tests/TestCase.php, same reason:
     * the auth manager caches the resolved user per guard for the lifetime of
     * the application instance — which, in tests, spans every request in a test
     * method. Without this reset the FIRST authenticated user leaks into every
     * later request regardless of the token sent, and multi-practice
     * authorization tests exercise the wrong identity. (This app's scoping test
     * caught exactly that before this override existed.)
     */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $this->app['auth']->forgetGuards();

        return parent::json($method, $uri, $data, $headers, $options);
    }
}
