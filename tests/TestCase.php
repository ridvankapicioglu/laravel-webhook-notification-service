<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Tests must never reach a real provider (e.g. use up the webhook.site request limit).
        // Any HTTP call that is not faked fails the test instead of leaving the machine.
        Http::preventStrayRequests();
    }

    /**
     * RefreshDatabase (set up in here) drops every table. Make sure that can only ever happen
     * to the test database, never to the application's real one.
     */
    protected function setUpTraits()
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, '_test')) {
            throw new RuntimeException(
                "Refusing to run tests against the [{$database}] database. Tests must use a database whose name ends in _test."
            );
        }

        return parent::setUpTraits();
    }
}
