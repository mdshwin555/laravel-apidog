<?php

namespace Hawasly\ApiSpec;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Reads one route and everything Laravel already knows about it: the auth it
 * needs, the permission and rate limit on its middleware, its path parameters,
 * and the body it accepts according to its FormRequest.
 */
class RouteInspector
{
    public function __construct(private array $config)
    {
    }

    public function inspect(Route $route): array
    {
        $middleware = $route->gatherMiddleware();

        return [
            'method' => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first(),
            'methods' => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->values()->all(),
            'uri' => $route->uri(),
            'name' => $route->getName(),
            'action' => $route->getActionName(),
            'requires_auth' => $this->requiresAuth($middleware),
            'permission' => $this->permission($middleware),
            'throttle' => $this->throttle($middleware),
            'path_params' => $this->pathParams($route),
            'body' => $this->body($route),
            'has_files' => $this->hasFiles($route),
        ];
    }

    private function requiresAuth(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && (str_starts_with($m, 'auth:') || $m === 'auth')) {
                return true;
            }
        }

        return false;
    }

    private function permission(array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'permission:')) {
                return Str::after($m, 'permission:');
            }
        }

        return null;
    }

    private function throttle(array $middleware): ?string
    {
        foreach ($middleware as $m) {
            if (is_string($m) && str_starts_with($m, 'throttle:')) {
                $value = Str::after($m, 'throttle:');
                [$max, $minutes] = array_pad(explode(',', $value), 2, '1');

                return is_numeric($max) ? "{$max}/{$minutes}min" : $value;
            }
        }

        return null;
    }

    /**
     * @return array<int,array{name:string,variable:string}>
     */
    private function pathParams(Route $route): array
    {
        $names = $route->parameterNames();

        // One parameter is `id`, which is what a reader expects and what the
        // create request stores. Two or more keep their own names, because
        // two `{{id}}` in one URL would collide.
        if (count($names) === 1) {
            return [['name' => $names[0], 'variable' => 'id']];
        }

        return collect($names)
            ->map(fn ($p) => ['name' => $p, 'variable' => Str::snake($p).'_id'])
            ->all();
    }

    private function hasFiles(Route $route): bool
    {
        foreach ($this->body($route) as $field) {
            if (in_array($field['type'], ['file', 'image'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Body fields from the route's FormRequest. Laravel already carries the
     * whole contract there, so nothing is guessed: required, type, limits and
     * allowed values all come out of the rules.
     *
     * @return array<int,array<string,mixed>>
     */
    public function body(Route $route): array
    {
        $rules = $this->rulesFor($route);

        if (! $rules) {
            return [];
        }

        $fields = [];

        foreach ($rules as $key => $rule) {
            if (str_contains($key, '*')) {
                continue;
            }

            $parts = $this->normaliseRule($rule);

            $type = $this->typeOf($parts);

            if ($type === 'string' && str_ends_with($key, '_id')) {
                $type = 'integer';
            }

            $fields[] = [
                'name' => $key,
                'rules' => $parts,
                'required' => in_array('required', $parts, true) || $this->hasConditionalRequired($parts),
                'type' => $type,
                'in' => $this->allowedValues($parts),
                'max' => $this->limit($parts, 'max'),
                'min' => $this->limit($parts, 'min'),
                'example' => null,
            ];
        }

        foreach ($fields as $i => $field) {
            $fields[$i]['example'] = $this->exampleFor($field);
        }

        return $fields;
    }

    /**
     * Rules from the route's FormRequest, or read out of an inline
     * $request->validate([...]) call when the controller validates in place.
     * Both are common, and only reading the first would leave most inline
     * controllers with an empty body.
     */
    private function rulesFor(Route $route): array
    {
        $class = $this->formRequestClass($route);

        if ($class) {
            try {
                return (new $class)->rules();
            } catch (\Throwable) {
                return [];
            }
        }

        return $this->inlineRules($route);
    }

    private function inlineRules(Route $route): array
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return [];
        }

        [$controller, $method] = explode('@', $action);

        if (! class_exists($controller) || ! method_exists($controller, $method)) {
            return [];
        }

        try {
            $reflection = new ReflectionMethod($controller, $method);
            $file = file($reflection->getFileName());
            $source = implode('', array_slice(
                $file,
                $reflection->getStartLine() - 1,
                $reflection->getEndLine() - $reflection->getStartLine() + 1,
            ));
        } catch (\Throwable) {
            return [];
        }

        if (! preg_match('/->validate\(\s*\[(.*?)\]\s*\)/s', $source, $m)) {
            return [];
        }

        $rules = [];

        preg_match_all("/'([^']+)'\s*=>\s*(?:'([^']*)'|\[(.*?)\])/s", $m[1], $pairs, PREG_SET_ORDER);

        foreach ($pairs as $pair) {
            $key = $pair[1];

            if ($pair[2] !== '') {
                $rules[$key] = $pair[2];

                continue;
            }

            preg_match_all("/'([^']*)'/", $pair[3] ?? '', $inner);
            $rules[$key] = $inner[1] ?? [];
        }

        return $rules;
    }

    private function formRequestClass(Route $route): ?string
    {
        $action = $route->getAction('uses');

        if (! is_string($action) || ! str_contains($action, '@')) {
            return null;
        }

        [$controller, $method] = explode('@', $action);

        if (! class_exists($controller) || ! method_exists($controller, $method)) {
            return null;
        }

        foreach ((new ReflectionMethod($controller, $method))->getParameters() as $param) {
            $type = $param->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            $class = $type->getName();

            if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
                return $class;
            }
        }

        return null;
    }

    private function normaliseRule(mixed $rule): array
    {
        if (is_string($rule)) {
            return explode('|', $rule);
        }

        if (! is_array($rule)) {
            return [];
        }

        // Rule::in(...) is an object; casting it back to a string is what
        // recovers the allowed values, which class_basename alone loses.
        return collect($rule)
            ->map(function ($r) {
                if (is_string($r)) {
                    return $r;
                }

                if (is_object($r) && method_exists($r, '__toString')) {
                    return (string) $r;
                }

                return is_object($r) ? class_basename($r) : '';
            })
            ->filter()
            ->flatMap(fn ($r) => explode('|', $r))
            ->all();
    }

    private function hasConditionalRequired(array $parts): bool
    {
        foreach ($parts as $p) {
            if (str_starts_with($p, 'required_if') || str_starts_with($p, 'required_with')) {
                return true;
            }
        }

        return false;
    }

    private function typeOf(array $parts): string
    {
        $map = [
            'file' => 'file', 'image' => 'image', 'boolean' => 'boolean',
            'integer' => 'integer', 'numeric' => 'number', 'array' => 'array',
            'date' => 'date', 'email' => 'email',
        ];

        foreach ($parts as $p) {
            $head = Str::before($p, ':');

            if (isset($map[$head])) {
                return $map[$head];
            }
        }

        // A foreign key is a number even when the rule only says exists:.
        foreach ($parts as $p) {
            if (str_starts_with($p, 'exists:')) {
                return 'integer';
            }
        }

        return 'string';
    }

    private function allowedValues(array $parts): array
    {
        foreach ($parts as $p) {
            if (str_starts_with($p, 'in:')) {
                return array_map(
                    fn ($v) => trim(trim($v), '"'),
                    explode(',', Str::after($p, 'in:')),
                );
            }
        }

        return [];
    }

    private function limit(array $parts, string $which): ?string
    {
        foreach ($parts as $p) {
            if (str_starts_with($p, $which.':')) {
                return Str::after($p, $which.':');
            }
        }

        return null;
    }

    /**
     * A value a developer can send as-is. "string" in every field is why an
     * imported collection usually has to be filled in by hand before it runs.
     */
    /**
     * A value the field's own rules accept, so a request can be sent as it
     * stands. Derived rather than looked up: a table of field names only fits
     * the project it was written for, and this has to work on any of them.
     */
    private function exampleFor(array $field): mixed
    {
        foreach ($this->config['examples'] ?? [] as $pattern => $value) {
            if (Str::is($pattern, $field['name'])) {
                return $value;
            }
        }

        if ($field['in']) {
            return $field['in'][0];
        }

        $min = is_numeric($field['min']) ? (int) $field['min'] : null;
        $max = is_numeric($field['max']) ? (int) $field['max'] : null;

        return match ($field['type']) {
            'boolean' => true,
            'integer' => $min ?: 1,
            'number' => $min ?: 10,
            'date' => now()->toDateString(),
            'email' => 'user@example.com',
            'array' => [],
            'file', 'image' => null,
            default => $this->stringExample($field, $min, $max),
        };
    }

    /**
     * The rules describe the shape a string has to take, so the example is
     * read off them: a confirmed field is a password, a url rule wants a url,
     * a length floor has to be cleared.
     */
    private function stringExample(array $field, ?int $min, ?int $max): string
    {
        $rules = $field['rules'] ?? [];

        foreach (['url' => 'https://example.com', 'uuid' => '550e8400-e29b-41d4-a716-446655440000',
                  'ip' => '127.0.0.1', 'json' => '{}', 'timezone' => 'UTC',
                  'date_format' => now()->toDateTimeString(), 'confirmed' => 'Passw0rd!'] as $rule => $value) {
            foreach ($rules as $r) {
                if (Str::before($r, ':') === $rule) {
                    return $value;
                }
            }
        }

        $value = Str::headline(Str::replaceLast('_id', '', $field['name']));

        if ($min && strlen($value) < $min) {
            $value = str_pad($value, $min, 'x');
        }

        if ($max && strlen($value) > $max) {
            $value = substr($value, 0, $max);
        }

        return $value;
    }
}
