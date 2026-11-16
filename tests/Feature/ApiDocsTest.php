<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ApiDocsTest extends TestCase
{
    public function test_swagger_ui_and_spec_are_served(): void
    {
        $this->get('/docs')->assertOk()->assertSee('swagger-ui');
        $this->get('/docs/openapi.yaml')->assertOk();
    }

    public function test_every_api_route_is_documented(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                $documented[] = strtoupper($method).' '.preg_replace('/\{[^}]+\}/', '{}', $path);
            }
        }

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            $path = preg_replace('/\{[^}]+\}/', '{}', substr($route->uri(), 3));
            $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();

            $this->assertContains("{$method} {$path}", $documented, "{$method} {$path} is missing in docs/openapi.yaml");
        }
    }
}
