<?php

namespace Tests\Feature\General;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\Support\InteractsWithPortalUsers;
use Tests\TestCase;

/**
 * BUG-001 (QA round 2): a URL id that is not a number, sent to an action that
 * takes it as `int`, threw a TypeError before the action ran - a 500 instead
 * of a 404. Every such parameter must be constrained to digits at the route,
 * so a non-numeric id simply does not match (404).
 */
class IntRouteParametersAreNumericTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithPortalUsers;

    #[Test]
    public function every_int_typed_url_parameter_is_constrained_to_digits(): void
    {
        $unconstrained = [];

        /** @var Route $route */
        foreach (RouteFacade::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action);
            if (!method_exists($class, $method)) {
                continue;
            }

            $urlParameters = $route->parameterNames();
            foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
                $type = $parameter->getType();
                $name = $parameter->getName();
                if (!$type instanceof ReflectionNamedType || $type->getName() !== 'int' || !in_array($name, $urlParameters, true)) {
                    continue;
                }

                $pattern = $route->wheres[$name] ?? null;
                if ($pattern === null || preg_match('/^' . $pattern . '$/', 'abc')) {
                    $unconstrained[] = implode('|', $route->methods()) . ' ' . $route->uri() . " {{$name}}";
                }
            }
        }

        $this->assertSame([], $unconstrained, "Constrain these with ->whereNumber():\n" . implode("\n", $unconstrained));
    }

    #[Test]
    public function a_non_numeric_partner_id_is_not_found_rather_than_a_server_error(): void
    {
        $this->actingAsUser($this->makePartnerAdmin($this->makeHostPartner()));

        $this->postJson('/api/v1/admin/partners-profile-states-details-count/abc')->assertNotFound();
        $this->getJson('/api/v1/admin/partners-details/abc')->assertNotFound();
    }
}
