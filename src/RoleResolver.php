<?php

namespace Hawasly\ApiSpec;

use Illuminate\Support\Str;

/**
 * Works out which audiences an API serves, from the API itself.
 *
 * Nothing here is a fixed list of names. A shipped list of roles fits the one
 * project it was written for and mislabels every other: `/api/user/*` is not
 * "Student" outside an education app. So the audiences are learned from the
 * route table — the sections a project actually divides its API into — and
 * each is called by its own name.
 */
class RoleResolver
{
    /** @var array<string,string> segment => label */
    private array $audiences = [];

    private string $publicLabel;

    private string $authenticatedLabel;

    public function __construct(private array $config)
    {
        $this->publicLabel = $config['public_label'] ?? 'Public';
        $this->authenticatedLabel = $config['authenticated_label'] ?? 'Authenticated';
    }

    /**
     * Reads the whole route table once and decides what the audiences are.
     *
     * A segment is an audience when enough routes sit under it and they mostly
     * agree about authentication — which is what an audience is: the endpoints
     * one kind of caller reaches. A segment that is merely a resource fails
     * that test, because a resource mixes public and private freely while a
     * whole section of an API does not.
     */
    public function learn(array $routes): void
    {
        if ($configured = $this->config['roles'] ?? []) {
            foreach ($configured as $label => $patterns) {
                foreach ($patterns as $pattern) {
                    $this->audiences['pattern:'.$pattern] = $label;
                }
            }

            return;
        }

        $prefix = trim($this->config['prefix'] ?? '', '/');
        $buckets = [];

        foreach ($routes as $route) {
            $segment = $this->leadingSegment($route, $prefix);

            if ($segment !== null && ! in_array($segment, $this->config['action_segments'] ?? [], true)) {
                $buckets[$segment][] = $route;
            }
        }

        $total = max(count($routes), 1);
        $minShare = $this->config['audience_min_share'] ?? 0.05;
        $minRoutes = $this->config['audience_min_routes'] ?? 3;

        foreach ($buckets as $segment => $group) {
            if (count($group) < $minRoutes || count($group) / $total < $minShare) {
                continue;
            }

            // A clear majority rather than unanimity: an admin section holds
            // its own login, which needs no token, and demanding agreement
            // from every route would throw the whole section away over it.
            $authed = collect($group)->filter(fn ($r) => $r['requires_auth'])->count();
            $share = $authed / count($group);

            if ($share > 0.2 && $share < 0.8) {
                continue;
            }

            $this->audiences[$segment] = Str::headline($segment);
        }

        // Longest first: a route under /admin/users matches both, and the
        // more specific section is the one that describes it.
        uksort($this->audiences, fn ($a, $b) => strlen($b) <=> strlen($a));
    }

    /**
     * @return array<int,string> the folder path, audience first
     */
    public function folders(array $route): array
    {
        return array_merge([$this->role($route)], $this->group($route));
    }

    public function role(array $route): string
    {
        foreach ($this->audiences as $key => $label) {
            if (! str_starts_with($key, 'pattern:')) {
                continue;
            }

            $pattern = Str::after($key, 'pattern:');

            if (Str::is($pattern, $route['uri']) || ($route['name'] && Str::is($pattern, $route['name']))) {
                return $label;
            }
        }

        // Every segment of the path is checked, not the first alone: a route
        // named admin.categories.index but served from /api/categories still
        // belongs to Admin, and matching only the leading segment would file
        // it under the generic bucket instead.
        $segment = $this->leadingSegment($route, trim($this->config['prefix'] ?? '', '/'));

        if ($segment !== null && isset($this->audiences[$segment])) {
            return $this->audiences[$segment];
        }

        if ($route['name'] && str_contains($route['name'], '.')) {
            $head = strtolower(Str::before($route['name'], '.'));

            if (isset($this->audiences[$head])) {
                return $this->audiences[$head];
            }
        }

        // Outside any learned section the only thing that can be said with
        // certainty is whether a caller needs a token.
        return $route['requires_auth'] ? $this->authenticatedLabel : $this->publicLabel;
    }

    /**
     * The section a route sits in: the first URI segment after the API prefix,
     * or the first part of a dotted route name. Both are how a project marks
     * off a part of its API.
     */
    private function leadingSegment(array $route, string $prefix): ?string
    {
        $uri = trim($route['uri'], '/');

        if ($prefix && str_starts_with($uri, $prefix)) {
            $uri = trim(Str::after($uri, $prefix), '/');
        }

        $first = Str::before($uri, '/');

        if ($first !== '' && ! str_starts_with($first, '{')) {
            return strtolower($first);
        }

        if ($route['name'] && str_contains($route['name'], '.')) {
            return strtolower(Str::before($route['name'], '.'));
        }

        return null;
    }

    /**
     * The subject folder inside the audience, with the audience segment and
     * any trailing verb removed so neither becomes a folder of its own.
     *
     * @return array<int,string>
     */
    private function group(array $route): array
    {
        $drop = array_map('strtolower', $this->config['drop_segments'] ?? []);
        $leading = $this->leadingSegment($route, trim($this->config['prefix'] ?? '', '/'));

        if ($leading !== null && isset($this->audiences[$leading])) {
            $drop[] = $leading;
        }

        if ($route['name'] && str_contains($route['name'], '.')) {
            $head = strtolower(Str::before($route['name'], '.'));

            if (isset($this->audiences[$head])) {
                $drop[] = $head;
            }
        }

        $name = $route['name'];

        if ($name && str_contains($name, '.')) {
            $parts = explode('.', $name);
            array_pop($parts);
            $parts = array_values(array_filter($parts, fn ($p) => ! in_array(strtolower($p), $drop, true)));

            while (count($parts) > 1 && in_array(strtolower(end($parts)), $this->config['action_segments'] ?? [], true)) {
                array_pop($parts);
            }

            if ($parts) {
                return array_map(fn ($p) => Str::headline($p), $parts);
            }
        }

        $segments = collect(explode('/', $route['uri']))
            ->reject(fn ($s) => $s === ''
                || str_starts_with($s, '{')
                || in_array(strtolower($s), $drop, true)
                || in_array($s, $this->config['strip_segments'] ?? [], true))
            ->take(2)
            ->map(fn ($s) => Str::headline($s))
            ->all();

        return $segments ?: ['General'];
    }

    /**
     * @return array<string,string>
     */
    public function learned(): array
    {
        return $this->audiences;
    }
}
