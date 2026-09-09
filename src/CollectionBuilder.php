<?php

namespace Hawasly\ApiSpec;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Turns inspected routes into a Postman v2.1 collection.
 *
 * Three things make the result runnable rather than just importable: bearer
 * auth is declared once on the collection and inherited, the login request
 * saves its token into a variable, and every create request saves the id it
 * returns so the next request can use it.
 */
class CollectionBuilder
{
    private array $variables = [];

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
        $tree = [];

        foreach ($routes as $route) {
            $folders = $this->foldersFor($route);
            $item = $this->requestItem($route, $spec);
            $this->place($tree, $folders, $item);
        }

        return [
            'info' => [
                '_postman_id' => (string) Str::uuid(),
                'name' => $this->config['name'],
                'description' => $this->description(),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'auth' => [
                'type' => 'bearer',
                'bearer' => [['key' => 'token', 'value' => '{{'.$this->config['token_variable'].'}}', 'type' => 'string']],
            ],
            'event' => $this->collectionScripts(),
            'variable' => $this->variableList(),
            'item' => $this->materialise($tree),
        ];
    }

    public function environment(): array
    {
        $values = [
            ['key' => 'base_url', 'value' => $this->config['base_url'], 'type' => 'default', 'enabled' => true],
            ['key' => $this->config['token_variable'], 'value' => '', 'type' => 'secret', 'enabled' => true],
            ['key' => $this->config['refresh_token_variable'], 'value' => '', 'type' => 'secret', 'enabled' => true],
        ];

        foreach (array_unique($this->variables) as $name) {
            if (in_array($name, ['base_url', $this->config['token_variable'], $this->config['refresh_token_variable']], true)) {
                continue;
            }

            $values[] = ['key' => $name, 'value' => '', 'type' => 'default', 'enabled' => true];
        }

        return [
            'id' => (string) Str::uuid(),
            'name' => $this->config['name'].' — Environment',
            'values' => $values,
            '_postman_variable_scope' => 'environment',
        ];
    }

    private function description(): string
    {
        $lines = [
            $this->config['description'] ?? '',
            '',
            '**Generated** '.now()->toDateTimeString().' — do not edit by hand; re-run `php artisan postman:generate`.',
            '',
            '### How to use',
            '1. Import this collection **and** the environment file next to it.',
            '2. Select the environment, set `base_url`.',
            '3. Run the login request once — the token is saved automatically and every other request inherits it.',
            '',
            '### Response envelope',
            '```json',
            '{ "data": ..., "message": "...", "status_code": 1 }',
            '```',
            '`status_code` is the application result (1 success / 0 failure) and is **not** the HTTP status.',
        ];

        return implode("\n", array_filter($lines, fn ($l) => $l !== null));
    }

    private function collectionScripts(): array
    {
        return [[
            'listen' => 'test',
            'script' => [
                'type' => 'text/javascript',
                'exec' => [
                    'pm.test("no server error", function () {',
                    '    pm.expect(pm.response.code).to.be.below(500);',
                    '});',
                ],
            ],
        ]];
    }

    private function foldersFor(array $route): array
    {
        return $this->roles->folders($route);
    }

    private function place(array &$tree, array $folders, array $item): void
    {
        $node = &$tree;

        foreach ($folders as $folder) {
            $node['children'][$folder] ??= ['children' => [], 'items' => []];
            $node = &$node['children'][$folder];
        }

        $node['items'][] = $item;
    }

    private function materialise(array $node): array
    {
        $out = [];

        foreach ($node['children'] ?? [] as $name => $child) {
            $out[] = [
                'name' => $name,
                'item' => $this->materialise($child),
            ];
        }

        foreach ($node['items'] ?? [] as $item) {
            $out[] = $item;
        }

        return $out;
    }

    private function requestItem(array $route, array $spec): array
    {
        $key = $route['method'].' /'.ltrim(Str::after($route['uri'], $this->config['prefix']), '/');
        $meta = $spec[$key] ?? $spec[$route['name']] ?? [];

        $url = $this->url($route);
        $isLogin = $this->matches($route, $this->config['login_routes']);
        $isRefresh = $this->matches($route, $this->config['refresh_routes']);

        $request = [
            'method' => $route['method'],
            'header' => $this->headers($route),
            'url' => $url,
            'description' => $this->requestDescription($route, $meta),
        ];

        if (! $route['requires_auth'] || $isLogin) {
            $request['auth'] = ['type' => 'noauth'];
        }

        if (in_array($route['method'], ['POST', 'PUT', 'PATCH'], true)) {
            $request['body'] = $this->body($route);
        }

        return array_filter([
            'name' => $meta['name'] ?? $this->requestName($route),
            'request' => $request,
            'event' => $this->requestScripts($route, $isLogin, $isRefresh),
            'response' => $this->savedResponses($route, $request),
        ], fn ($v) => $v !== [] && $v !== null);
    }

    /**
     * The reason phrase for a status, read from Symfony's own table rather
     * than a copy kept here — any status the factory ever emits is named
     * correctly, and an unknown one still yields something printable.
     */
    private function reasonPhrase(int $code): string
    {
        return \Symfony\Component\HttpFoundation\Response::$statusTexts[$code]
            ?? 'HTTP '.$code;
    }

    /**
     * Saved examples, one per case the route can return. Without them the
     * first question on every endpoint is what a failure looks like.
     */
    private function savedResponses(array $route, array $request): array
    {
        $out = [];

        // The status comes with the case, and its reason phrase from the HTTP
        // spec itself — so a case the factory grows tomorrow lands here with
        // nothing to add. Keeping a second list of codes in this file is what
        // broke generation when 400 and 500 were introduced.
        foreach ($this->examples->forRoute($route) as $example) {
            $code = (int) $example['status'];

            $out[] = [
                'name' => $example['summary'],
                'originalRequest' => $request,
                'status' => $this->reasonPhrase($code),
                'code' => $code,
                '_postman_previewlanguage' => 'json',
                'header' => [['key' => 'Content-Type', 'value' => 'application/json']],
                'body' => json_encode($example['value'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
        }

        return $out;
    }

    private function requestName(array $route): string
    {
        if ($route['name']) {
            return Str::headline(Str::afterLast($route['name'], '.'));
        }

        return $route['method'].' '.$route['uri'];
    }

    private function requestDescription(array $route, array $meta): string
    {
        $lines = [];

        if (! empty($meta['summary'])) {
            $lines[] = $meta['summary'];
            $lines[] = '';
        }

        if (! empty($meta['notes'])) {
            $lines[] = $meta['notes'];
            $lines[] = '';
        }

        $tags = [];
        $tags[] = $route['requires_auth'] ? '🔒 Requires a token' : '🌐 Public';

        if ($route['permission']) {
            $tags[] = '🛡 Permission: `'.$route['permission'].'`';
        }

        if ($route['throttle']) {
            $tags[] = '⏱ Rate limit: '.$route['throttle'];
        }

        $lines[] = implode(' · ', $tags);

        if ($route['body']) {
            $lines[] = '';
            $lines[] = '| Field | Type | Required | Rules and allowed values |';
            $lines[] = '|---|---|---|---|';

            foreach ($route['body'] as $f) {
                $lines[] = sprintf(
                    '| `%s` | %s | %s | %s |',
                    $f['name'],
                    $f['type'],
                    $f['required'] ? '**yes**' : 'no',
                    str_replace('|', '\|', $this->describer->cell($f)),
                );
            }

            $enums = collect($route['body'])->filter(fn ($f) => $f['in']);

            if ($enums->isNotEmpty()) {
                $lines[] = '';
                $lines[] = '**Allowed values**';
                $lines[] = '';

                foreach ($enums as $f) {
                    $lines[] = '- `'.$f['name'].'` → '.$this->describer->values($f['in']);
                }
            }
        }

        if ($route['path_params']) {
            $lines[] = '';
            $lines[] = '**Path parameters**';
            $lines[] = '';

            foreach ($route['path_params'] as $p) {
                $lines[] = '- `'.$p['name'].'` → sent as `{{'.$p['variable'].'}}`, captured from an earlier create.';
            }
        }

        $lines[] = '';
        $lines[] = '**Responses**';
        $lines[] = '';

        foreach ($this->examples->forRoute($route) as $example) {
            $lines[] = '- '.$example['summary'];
        }

        if ($route['name']) {
            $lines[] = '';
            $lines[] = 'Route: `'.$route['name'].'`';
        }

        return implode("\n", $lines);
    }

    private function url(array $route): array
    {
        $path = ltrim($route['uri'], '/');

        foreach ($route['path_params'] as $param) {
            $this->variables[] = $param['variable'];
            $path = str_replace('{'.$param['name'].'}', '{{'.$param['variable'].'}}', $path);
            $path = str_replace('{'.$param['name'].'?}', '{{'.$param['variable'].'}}', $path);
        }

        $query = $this->queryParams($route);

        return array_filter([
            'raw' => '{{base_url}}/'.$path.($query ? '?'.collect($query)->map(fn ($q) => $q['key'].'='.$q['value'])->implode('&') : ''),
            'host' => ['{{base_url}}'],
            'path' => array_values(array_filter(explode('/', $path), fn ($s) => $s !== '')),
            'query' => $query,
            'variable' => array_map(
                fn ($p) => ['key' => $p['name'], 'value' => '{{'.$p['variable'].'}}'],
                $route['path_params'],
            ),
        ], fn ($v) => $v !== [] && $v !== null);
    }

    private function queryParams(array $route): array
    {
        if ($route['method'] !== 'GET') {
            return [];
        }

        $isIndex = $route['name'] && str_ends_with($route['name'], '.index');

        if (! $isIndex && ! $this->config['always_paginate']) {
            return [];
        }

        return [
            ['key' => 'per_page', 'value' => '15', 'disabled' => true, 'description' => 'Rows per page.'],
            ['key' => 'page', 'value' => '1', 'disabled' => true, 'description' => 'Page number.'],
            ['key' => 'sort', 'value' => '-created_at', 'disabled' => true, 'description' => 'Sort field; prefix with - for descending.'],
        ];
    }

    private function headers(array $route): array
    {
        $headers = [
            ['key' => 'Accept', 'value' => 'application/json'],
            ['key' => 'Accept-Language', 'value' => '{{locale}}'],
        ];

        $this->variables[] = 'locale';

        if (in_array($route['method'], ['POST', 'PUT', 'PATCH'], true) && ! $route['has_files']) {
            $headers[] = ['key' => 'Content-Type', 'value' => 'application/json'];
        }

        return $headers;
    }

    private function body(array $route): array
    {
        if (! $route['body']) {
            return ['mode' => 'raw', 'raw' => '{}', 'options' => ['raw' => ['language' => 'json']]];
        }

        if ($route['has_files']) {
            return [
                'mode' => 'formdata',
                'formdata' => array_map(fn ($f) => array_filter([
                    'key' => $f['name'],
                    'type' => in_array($f['type'], ['file', 'image'], true) ? 'file' : 'text',
                    'value' => in_array($f['type'], ['file', 'image'], true) ? null : (string) $this->scalar($f['example']),
                    'src' => in_array($f['type'], ['file', 'image'], true) ? '' : null,
                    'disabled' => ! $f['required'],
                    'description' => $this->describer->line($f),
                ], fn ($v) => $v !== null), $route['body']),
            ];
        }

        $payload = [];

        foreach ($route['body'] as $f) {
            Arr::set($payload, $f['name'], $f['example']);
        }

        return [
            'mode' => 'raw',
            'raw' => json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'options' => ['raw' => ['language' => 'json']],
        ];
    }

    private function scalar(mixed $value): mixed
    {
        return is_array($value) ? json_encode($value) : $value;
    }

    /**
     * Login saves the token; a create saves the id it returns. Between them
     * the collection can be run top to bottom without a single value being
     * copied by hand.
     */
    private function requestScripts(array $route, bool $isLogin, bool $isRefresh): array
    {
        $exec = [];

        if ($isLogin || $isRefresh) {
            $token = $this->config['token_variable'];
            $refresh = $this->config['refresh_token_variable'];
            $hint = $this->config['token_path'];
            $refreshHint = $this->config['refresh_token_path'];

            $exec = [
                'const body = pm.response.json();',
                '',
                'const dig = (obj, path) => path ? path.split(".").reduce((o, k) => (o || {})[k], obj) : undefined;',
                '',
                'const find = (obj, keys, depth) => {',
                '    if (!obj || typeof obj !== "object" || depth > 4) { return undefined; }',
                '    for (const k of Object.keys(obj)) {',
                '        if (keys.includes(k.toLowerCase()) && typeof obj[k] === "string" && obj[k].length > 10) {',
                '            return obj[k];',
                '        }',
                '    }',
                '    for (const k of Object.keys(obj)) {',
                '        const hit = find(obj[k], keys, depth + 1);',
                '        if (hit) { return hit; }',
                '    }',
                '    return undefined;',
                '};',
                '',
                'const token = dig(body, "'.$hint.'")',
                '    || find(body, ["access_token", "token", "jwt", "bearer", "api_token", "auth_token"], 0);',
                '',
                'const refresh = dig(body, "'.$refreshHint.'")',
                '    || find(body, ["refresh_token", "refreshtoken"], 0);',
                '',
                'if (token) {',
                '    pm.collectionVariables.set("'.$token.'", token);',
                '    pm.environment.set("'.$token.'", token);',
                '}',
                '',
                'if (refresh && refresh !== token) {',
                '    pm.collectionVariables.set("'.$refresh.'", refresh);',
                '    pm.environment.set("'.$refresh.'", refresh);',
                '}',
                '',
                'pm.test("token captured", function () {',
                '    pm.expect(token, "no token found in the response").to.be.a("string");',
                '});',
            ];
        } elseif ($route['method'] === 'POST' && $route['name'] && $this->createsResource($route['name'])) {
            // Stored as `id` so the show and update requests that follow can
            // use it without being told which resource it came from.
            $variable = 'id';
            $this->variables[] = $variable;

            $exec = [
                'if (pm.response.code < 300) {',
                '    const id = (pm.response.json().data || {}).id;',
                '    if (id) { pm.collectionVariables.set("'.$variable.'", id); }',
                '}',
            ];
        }

        $exec[] = 'pm.test("expected status", function () {';
        $exec[] = '    pm.expect(pm.response.code).to.be.oneOf(['.$this->expectedCodes($route).']);';
        $exec[] = '});';

        return [[
            'listen' => 'test',
            'script' => ['type' => 'text/javascript', 'exec' => $exec],
        ]];
    }

    private function createsResource(string $name): bool
    {
        foreach (['.store', '.create'] as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function expectedCodes(array $route): string
    {
        return match ($route['method']) {
            'POST' => '200, 201, 422',
            'DELETE' => '200, 204, 404, 422',
            default => '200, 404, 422',
        };
    }

    private function variableList(): array
    {
        $names = array_unique(array_merge(
            ['base_url', $this->config['token_variable'], $this->config['refresh_token_variable'], 'locale'],
            $this->variables,
        ));

        return array_map(fn ($n) => [
            'key' => $n,
            'value' => match ($n) {
                'base_url' => $this->config['base_url'],
                'locale' => $this->config['locale'],
                default => '',
            },
            'type' => 'string',
        ], array_values($names));
    }

    private function matches(array $route, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $route['uri']) || ($route['name'] && Str::is($pattern, $route['name']))) {
                return true;
            }
        }

        return false;
    }
}
