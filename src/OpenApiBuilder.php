<?php

namespace Hawasly\ApiSpec;

use Illuminate\Support\Str;

/**
 * Turns inspected routes into an OpenAPI 3.1 document.
 *
 * Apidog, Swagger UI and Redoc all read this natively, and unlike a Postman
 * collection it can state the security scheme, the schema of every body and
 * the shape of the responses rather than only an example.
 */
class OpenApiBuilder
{
    private RoleResolver $roles;

    private ExampleFactory $examples;

    private FieldDescriber $describer;

    public function __construct(private array $config, ?RoleResolver $roles = null)
    {
        $this->roles = $roles ?? new RoleResolver($config);
        $this->examples = new ExampleFactory($config);
        $this->describer = new FieldDescriber;
    }

    public function build(array $routes, array $spec = []): array
    {
        $paths = [];
        $tags = [];

        foreach ($routes as $route) {
            $path = '/'.ltrim($route['uri'], '/');

            foreach ($route['path_params'] as $param) {
                $path = str_replace('{'.$param['name'].'?}', '{'.$param['name'].'}', $path);
            }

            $tag = $this->tagFor($route);
            $tags[$tag] = true;

            $method = strtolower($route['method']);
            $paths[$path][$method] = $this->operation($route, $tag, $spec);
        }

        ksort($paths);

        return array_filter([
            'openapi' => '3.1.0',
            'info' => [
                'title' => $this->config['name'],
                'version' => '1.0.0',
                'description' => $this->info(),
            ],
            'servers' => [['url' => $this->config['base_url'], 'description' => 'Base URL']],
            'tags' => collect(array_keys($tags))->sort()->map(fn ($t) => ['name' => $t])->values()->all(),
            'security' => [['bearerAuth' => []]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => 'The token returned by the login endpoint.',
                    ],
                ],
                'schemas' => $this->schemas(),
            ],
            'paths' => $paths,
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function info(): string
    {
        return implode("\n", array_filter([
            $this->config['description'] ?? '',
            '',
            'Generated '.now()->toDateTimeString().' from the application routes.',
            '',
            '## Response envelope',
            '',
            '```json',
            '{ "data": ..., "message": "...", "status_code": 1 }',
            '```',
            '',
            '`status_code` is the application result (1 success / 0 failure) and is **not** the HTTP status.',
        ], fn ($l) => $l !== null));
    }

    private function tagFor(array $route): string
    {
        return implode(' / ', $this->roles->folders($route));
    }

    private function operation(array $route, string $tag, array $spec): array
    {
        $key = $route['method'].' /'.ltrim(Str::after($route['uri'], $this->config['prefix']), '/');
        $meta = $spec[$key] ?? $spec[$route['name']] ?? [];

        $operation = array_filter([
            'tags' => [$tag],
            'summary' => $meta['name'] ?? $meta['summary'] ?? $this->summary($route),
            'description' => $this->description($route, $meta),
            'operationId' => $route['name'] ?: Str::camel($route['method'].' '.str_replace(['/', '{', '}'], ' ', $route['uri'])),
            'parameters' => $this->parameters($route),
            'requestBody' => $this->requestBody($route),
            'responses' => $this->responses($route),
            'security' => $route['requires_auth'] ? [['bearerAuth' => []]] : [],
        ], fn ($v) => $v !== null && $v !== []);

        if (! $route['requires_auth']) {
            $operation['security'] = [];
        }

        return $operation;
    }

    private function summary(array $route): string
    {
        if ($route['name']) {
            return Str::headline(Str::afterLast($route['name'], '.'));
        }

        return $route['method'].' '.$route['uri'];
    }

    private function description(array $route, array $meta): string
    {
        $lines = [];

        if (! empty($meta['notes'])) {
            $lines[] = $meta['notes'];
            $lines[] = '';
        }

        $tags = [$route['requires_auth'] ? 'Requires a token' : 'Public'];

        if ($route['permission']) {
            $tags[] = 'Permission: `'.$route['permission'].'`';
        }

        if ($route['throttle']) {
            $tags[] = 'Rate limit: '.$route['throttle'];
        }

        $lines[] = implode(' · ', $tags);

        if ($route['name']) {
            $lines[] = '';
            $lines[] = 'Route: `'.$route['name'].'`';
        }

        return implode("\n", $lines);
    }

    private function parameters(array $route): array
    {
        $params = [];

        foreach ($route['path_params'] as $param) {
            $params[] = [
                'name' => $param['name'],
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'integer'],
                'description' => 'Identifier of the '.Str::headline($param['name']).'.',
            ];
        }

        if ($route['method'] === 'GET' && $route['name'] && str_ends_with($route['name'], '.index')) {
            $params[] = ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 15]];
            $params[] = ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'default' => 1]];
            $params[] = ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Field to sort by; prefix with - for descending.'];
        }

        $params[] = [
            'name' => 'Accept-Language',
            'in' => 'header',
            'required' => false,
            'schema' => ['type' => 'string', 'default' => $this->config['locale']],
        ];

        return $params;
    }

    private function requestBody(array $route): ?array
    {
        if (! in_array($route['method'], ['POST', 'PUT', 'PATCH'], true) || ! $route['body']) {
            return null;
        }

        $properties = [];
        $required = [];
        $example = [];

        foreach ($route['body'] as $field) {
            $properties[$field['name']] = $this->propertySchema($field);
            $example[$field['name']] = $field['example'];

            if ($field['required']) {
                $required[] = $field['name'];
            }
        }

        $schema = array_filter([
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ], fn ($v) => $v !== []);

        $media = $route['has_files'] ? 'multipart/form-data' : 'application/json';

        return [
            'required' => $required !== [],
            'content' => [
                $media => array_filter([
                    'schema' => $schema,
                    'example' => $route['has_files'] ? null : $example,
                ], fn ($v) => $v !== null),
            ],
        ];
    }

    private function propertySchema(array $field): array
    {
        $schema = match ($field['type']) {
            'integer' => ['type' => 'integer'],
            'number' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'array' => ['type' => 'array', 'items' => ['type' => 'string']],
            'date' => ['type' => 'string', 'format' => 'date'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'file', 'image' => ['type' => 'string', 'format' => 'binary'],
            default => ['type' => 'string'],
        };

        if ($field['in']) {
            $schema['enum'] = $field['in'];
        }

        if ($field['max'] !== null && is_numeric($field['max'])) {
            $key = in_array($field['type'], ['integer', 'number'], true) ? 'maximum' : 'maxLength';
            $schema[$key] = (int) $field['max'];
        }

        if ($field['min'] !== null && is_numeric($field['min'])) {
            $key = in_array($field['type'], ['integer', 'number'], true) ? 'minimum' : 'minLength';
            $schema[$key] = (int) $field['min'];
        }

        if ($field['example'] !== null && ! in_array($field['type'], ['file', 'image'], true)) {
            $schema['example'] = $field['example'];
        }

        $schema['description'] = $this->describer->line($field);

        return $schema;
    }

    /**
     * The failures a caller has to handle, declared rather than left to be
     * discovered: a client written against a spec with only a 200 in it will
     * not handle a 422 until it meets one.
     */
    /**
     * Every failure the route can produce, with a worked example of each. A
     * spec carrying only a 200 leaves the client to discover the rest in
     * production.
     */
    private function responses(array $route): array
    {
        $responses = [];

        // Each case names its own status and schema, so a new one appears here
        // the moment the factory produces it — nothing to keep in step.
        foreach ($this->examples->forRoute($route) as $example) {
            $responses[(string) $example['status']] = [
                'description' => $example['summary'],
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/'.$example['schema']],
                        'example' => $example['value'],
                    ],
                ],
            ];
        }

        return $responses;
    }

    private function schemas(): array
    {
        return [
            'Envelope' => [
                'type' => 'object',
                'properties' => [
                    'data' => ['description' => 'The payload; shape depends on the endpoint.'],
                    'message' => ['type' => 'string'],
                    'status_code' => ['type' => 'integer', 'enum' => [0, 1], 'description' => '1 success, 0 failure. Not the HTTP status.'],
                ],
            ],
            'Error' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'status_code' => ['type' => 'integer', 'example' => 0],
                ],
            ],
            'ValidationError' => [
                'type' => 'object',
                'properties' => [
                    'message' => ['type' => 'string'],
                    'status_code' => ['type' => 'integer', 'example' => 0],
                    'errors' => [
                        'type' => 'object',
                        'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'example' => ['email' => ['The email field is required.']],
                    ],
                ],
            ],
        ];
    }
}
