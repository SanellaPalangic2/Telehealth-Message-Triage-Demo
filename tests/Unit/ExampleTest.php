<?php

use Illuminate\Http\Client\StrayRequestException;
use Illuminate\Support\Facades\Http;

test('real HTTP requests are blocked in tests', function () {
    Http::get('https://api.anthropic.com/v1/messages');
})->throws(StrayRequestException::class);
