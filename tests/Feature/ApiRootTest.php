<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Smoke test: CivicRide's backend is API-only, so the web root has no route
 * and must answer with a JSON 404 instead of an HTML error page.
 */
class ApiRootTest extends TestCase
{
    public function test_the_api_only_application_answers_unknown_routes_with_json(): void
    {
        $this->getJson('/')
            ->assertStatus(404)
            ->assertHeader('content-type', 'application/json');
    }
}
