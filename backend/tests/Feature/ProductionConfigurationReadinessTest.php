<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProductionConfigurationReadinessTest extends TestCase
{
    public function test_production_templates_enforce_safe_non_secret_defaults(): void
    {
        $backend = file_get_contents(base_path('.env.production.example'));
        $frontend = file_get_contents(base_path('../frontend/.env.production.example'));

        foreach (['APP_ENV=production', 'APP_DEBUG=false', 'DB_CONNECTION=mysql', 'SESSION_SECURE_COOKIE=true', 'SESSION_HTTP_ONLY=true', 'BACKUP_DISK=', 'BACKUP_RETENTION_COUNT=', 'MAIL_ENCRYPTION=', 'SANCTUM_STATEFUL_DOMAINS=', 'CORS_ALLOWED_ORIGINS='] as $setting) {
            $this->assertStringContainsString($setting, $backend);
        }
        $this->assertStringContainsString('VITE_API_BASE_URL=https://', $frontend);
        $this->assertStringNotContainsString('127.0.0.1', $frontend);
        $this->assertStringNotContainsString('localhost', $frontend);
        $this->assertStringNotContainsString('CORS_ALLOWED_ORIGINS=*', $backend);
    }

    public function test_cors_and_api_errors_are_non_wildcard_and_normalized(): void
    {
        $cors = file_get_contents(config_path('cors.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString("env('CORS_ALLOWED_ORIGINS'", $cors);
        $this->assertStringContainsString("'supports_credentials' => true", $cors);
        $this->assertStringNotContainsString("'allowed_origins' => ['*']", $cors);
        $this->assertStringContainsString("__('api.server_error')", $bootstrap);
        $this->assertStringNotContainsString("'trace' =>", $bootstrap);
    }
}
