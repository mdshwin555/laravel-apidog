<?php

namespace Hawasly\ApiSpec\Commands;

use Hawasly\ApiSpec\CollectionBuilder;
use Hawasly\ApiSpec\OpenApiBuilder;
use Hawasly\ApiSpec\RoleResolver;
use Hawasly\ApiSpec\RouteInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;

class GenerateApiSpec extends Command
{
    protected $signature = 'api:spec
                            {--format=all : openapi, postman or all}
                            {--output= : Directory to write into}
                            {--prefix= : Only routes whose URI starts with this}
                            {--describe : Also write a stub for hand-written descriptions}';

    protected $description = 'Generate an OpenAPI document, a Postman collection and a JSON Schema file from this application routes';

    public function handle(): int
    {
        $config = config('api-spec');
        $prefix = $this->option('prefix') ?: $this->detectPrefix($config);
        $config['prefix'] = $prefix;
        $output = $this->option('output') ?: base_path($config['output']);

        $inspector = new RouteInspector($config);
        $routes = [];
        $skipped = 0;

        foreach (RouteFacade::getRoutes() as $route) {
            $uri = $route->uri();

            if ($prefix && ! str_starts_with($uri, ltrim($prefix, '/'))) {
                $skipped++;

                continue;
            }

            if ($this->excluded($uri, $config['exclude'])) {
                $skipped++;

                continue;
            }

            $routes[] = $inspector->inspect($route);
        }

        if (! $routes) {
            $this->error('No routes matched. Try --prefix=, or check config/api-spec.php.');

            return self::FAILURE;
        }

        usort($routes, fn ($a, $b) => [$a['name'] ?? $a['uri'], $a['uri']] <=> [$b['name'] ?? $b['uri'], $b['uri']]);

        $spec = $this->loadSpec($config);
        $format = $this->option('format');

        $roles = new RoleResolver($config);
        $roles->learn($routes);
        $config['learned_audiences'] = $roles->learned();

        File::ensureDirectoryExists($output);
        $slug = Str::slug($config['name']);
        $dir = rtrim($output, '/\\').DIRECTORY_SEPARATOR;
        $written = [];

        if (in_array($format, ['all', 'openapi'], true)) {
            $path = $dir.$slug.'.openapi.json';
            File::put($path, $this->encode((new OpenApiBuilder($config, $roles))->build($routes, $spec)));
            $written['OpenAPI 3.1'] = $path;
        }

        if (in_array($format, ['all', 'postman'], true)) {
            $builder = new CollectionBuilder($config, $roles);
            $collection = $dir.$slug.'.postman_collection.json';
            $environment = $dir.$slug.'.postman_environment.json';

            File::put($collection, $this->encode($builder->build($routes, $spec)));
            File::put($environment, $this->encode($builder->environment()));

            $written['Postman collection'] = $collection;
            $written['Postman environment'] = $environment;
        }

        // Written on every run, alongside whichever format was asked for: the
        // schemas are what a client generator and a validator consume, and
        // having to remember a second command is how a file goes stale.
        $schemas = $dir.$slug.'.schemas.json';
        File::put($schemas, $this->encode($this->schemaDocument($routes, $config)));
        $written['JSON Schema'] = $schemas;

        if ($this->option('describe')) {
            $this->writeSpecStub($routes, $config, $spec);
        }

        $this->report($routes, $skipped, $written, $spec, $config);

        return self::SUCCESS;
    }

    /**
     * A standalone JSON Schema document: the shared envelope shapes, plus one
     * schema per endpoint that accepts a body.
     *
     * Derived from the OpenAPI document rather than rebuilt, so the two can
     * never describe the same endpoint differently. Emitted as JSON Schema
     * 2020-12 with local `$defs`, which is what a validator or a client
     * generator reads without needing the whole API description.
     */
    private function schemaDocument(array $routes, array $config): array
    {
        $roles = new RoleResolver($config);
        $roles->learn($routes);

        $openapi = (new OpenApiBuilder($config, $roles))->build($routes);
        $defs = $openapi['components']['schemas'] ?? [];

        foreach ($openapi['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                $content = $operation['requestBody']['content'] ?? null;

                if (! $content) {
                    continue;
                }

                $schema = reset($content)['schema'] ?? null;

                if (! $schema) {
                    continue;
                }

                $defs[$this->schemaName($method, $path)] = $schema;
            }
        }

        ksort($defs);

        // References inside the OpenAPI document point at components/schemas;
        // in a standalone document they point at $defs.
        $defs = json_decode(str_replace(
            '#/components/schemas/',
            '#/$defs/',
            json_encode($defs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ), true);

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => Str::slug($config['name']).'.schemas.json',
            'title' => $config['name'].' — schemas',
            'description' => 'Request and envelope schemas, generated from the application routes.',
            '$defs' => $defs,
        ];
    }

    /**
     * A stable name for an endpoint's request schema: the method and the path,
     * with parameters read as "By Id" so two endpoints on the same resource do
     * not collide.
     */
    private function schemaName(string $method, string $path): string
    {
        $segments = collect(explode('/', trim($path, '/')))
            ->reject(fn ($s) => $s === '')
            ->map(fn ($s) => str_starts_with($s, '{')
                ? 'By'.Str::studly(trim($s, '{}'))
                : Str::studly($s))
            ->implode('');

        return Str::studly($method).$segments.'Request';
    }

    /**
     * The configured prefix when it matches anything, otherwise the commonest
     * first segment in the route table. A project serving its API from /v1 or
     * from the root should not have to be told about it.
     */
    private function detectPrefix(array $config): string
    {
        $configured = $config['prefix'];
        $uris = collect(RouteFacade::getRoutes())->map(fn ($r) => $r->uri());

        if ($configured && $uris->contains(fn ($u) => str_starts_with($u, ltrim($configured, '/')))) {
            return $configured;
        }

        $guess = $uris
            ->reject(fn ($u) => $u === '/' || $this->excluded($u, $config['exclude']))
            ->map(fn ($u) => Str::before($u, '/'))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->first();

        if ($guess) {
            $this->warn("No routes under '{$configured}' — using '{$guess}' instead.");
        }

        return $guess ?: '';
    }

    private function excluded(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    private function loadSpec(array $config): array
    {
        $path = base_path($config['spec']);

        return File::exists($path) ? (array) require $path : [];
    }

    /**
     * A stub keyed the way the builders look descriptions up, so filling it in
     * is the only manual step and it survives every regeneration.
     */
    private function writeSpecStub(array $routes, array $config, array $existing): void
    {
        $path = base_path($config['spec']);
        File::ensureDirectoryExists(dirname($path));

        $lines = ['<?php', '', 'return [', ''];

        foreach ($routes as $route) {
            $key = $route['method'].' /'.ltrim(Str::after($route['uri'], $config['prefix']), '/');
            $have = $existing[$key] ?? [];

            $lines[] = "    '".addslashes($key)."' => [";
            $lines[] = "        'name' => '".addslashes($have['name'] ?? '')."',";
            $lines[] = "        'summary' => '".addslashes($have['summary'] ?? '')."',";
            $lines[] = "        'notes' => '".addslashes($have['notes'] ?? '')."',";
            $lines[] = '    ],';
        }

        $lines[] = '];';

        File::put($path, implode("\n", $lines)."\n");
        $this->line('description stub: '.$path);
    }

    private function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function report(array $routes, int $skipped, array $written, array $spec, array $config): void
    {
        $described = collect($routes)->filter(function ($r) use ($spec, $config) {
            $key = $r['method'].' /'.ltrim(Str::after($r['uri'], $config['prefix']), '/');

            return ! empty($spec[$key]['summary']);
        })->count();

        $this->newLine();
        $this->info('API spec generated.');
        $this->newLine();
        $this->table(['', ''], [
            ['endpoints', count($routes)],
            ['routes skipped', $skipped],
            ['requiring a token', collect($routes)->where('requires_auth', true)->count()],
            ['with a request body', collect($routes)->filter(fn ($r) => $r['body'] !== [])->count()],
            ['with file uploads', collect($routes)->where('has_files', true)->count()],
            ['with a written description', $described.' / '.count($routes)],
        ]);

        $dir = $written ? dirname(reset($written)) : '';

        $this->newLine();
        $this->line('<options=bold>Output folder</>');
        $this->line('  <fg=cyan>'.$dir.'</>');
        $this->newLine();
        $this->line('<options=bold>Files</>');

        foreach ($written as $label => $path) {
            $this->line('  '.str_pad($label, 22).'<fg=green>'.basename($path).'</>');
        }

        $learned = collect($config['learned_audiences'] ?? [])
            ->reject(fn ($v, $k) => str_starts_with($k, 'pattern:'));

        if ($learned->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold>Audiences detected</>');

            foreach ($learned as $segment => $label) {
                $count = collect($routes)->filter(fn ($r) => str_contains('/'.$r['uri'].'/', '/'.$segment.'/'))->count();
                $this->line('  <fg=yellow>'.str_pad($label, 20).'</>/'.$segment.'  ('.$count.' endpoints)');
            }
        }

        $this->newLine();
        $this->line('<options=bold>Import</>');
        $this->line('  Apidog   New project → Import → OpenAPI → pick the .openapi.json file');
        $this->line('  Postman  import the collection and the environment together');
    }
}
