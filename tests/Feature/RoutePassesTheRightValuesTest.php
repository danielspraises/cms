<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use ReflectionException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Laravel hands a controller method the values from the URL in the order they
 * appear, one after another. A method that skips one (for example takes the
 * loan type but not the society before it) silently receives the wrong value.
 * This checks every route in the app.
 */
class RoutePassesTheRightValuesTest extends TestCase
{
    public function test_every_controller_method_lines_up_with_the_values_in_its_url(): void
    {
        $problems = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();
            if (!str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action);
            $urlValues = $route->parameterNames();
            if ($urlValues === []) {
                continue;
            }

            try {
                $reflection = new ReflectionMethod($class, $method);
            } catch (ReflectionException) {
                continue;
            }

            $declared = [];
            foreach ($reflection->getParameters() as $parameter) {
                if (in_array($parameter->getName(), $urlValues, true)) {
                    $declared[] = $parameter->getName();
                }
            }

            // A method that never names any URL value just reads the request, which is fine.
            if ($declared !== [] && $declared !== array_slice($urlValues, 0, count($declared))) {
                $problems[] = $route->methods()[0] . ' /' . $route->uri()
                    . '  ->  ' . class_basename($class) . '::' . $method
                    . '(' . implode(', ', $declared) . ') but the URL gives (' . implode(', ', $urlValues) . ')';
            }
        }

        $this->assertSame(
            [],
            $problems,
            "These routes would hand a controller the wrong value from the URL:\n" . implode("\n", $problems)
        );
    }
}