<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Safety net: any HTTP call that isn't matched by Http::fake() throws,
        // so a test can never reach the real Anthropic API.
        Http::preventStrayRequests();
    }
}
