<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * personal_access_tokens was dropped with the tdh_user integration, so no
 * route may authenticate via auth:sanctum (a bearer token would query it).
 */
class ApiRoutesTest extends TestCase
{
    public function test_the_sanctum_user_endpoint_no_longer_exists(): void
    {
        $this->withToken('1|anything')->getJson('/api/user')->assertNotFound();
    }
}
