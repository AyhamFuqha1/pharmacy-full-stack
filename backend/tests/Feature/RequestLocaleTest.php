<?php

namespace Tests\Feature;

use Tests\TestCase;

class RequestLocaleTest extends TestCase
{
    public function test_api_errors_default_to_english(): void
    {
        $this->getJson('/api/user', ['Accept-Language' => 'en'])
            ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_api_errors_support_arabic_accept_language(): void
    {
        $this->getJson('/api/user', ['Accept-Language' => 'ar-PS,ar;q=0.9'])
            ->assertUnauthorized()->assertJsonPath('message', 'يرجى تسجيل الدخول.');
    }

    public function test_unsupported_locale_safely_falls_back_to_english(): void
    {
        $this->getJson('/api/user', ['Accept-Language' => 'fr'])
            ->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }
}
