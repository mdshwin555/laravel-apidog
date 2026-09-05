<?php

namespace Hawasly\ApiSpec;

use Illuminate\Support\Str;

/**
 * Decides which audience an endpoint belongs to, and where inside it.
 *
 * The audience comes first because it is the question a developer actually
 * has: an app developer wants the student endpoints and never the admin ones.
 * A flat list, or one grouped only by resource, forces them to read the whole
 * tree to find their half of it.
 */
class RoleResolver
{
    public function __construct(private array $config)
    {
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
        foreach ($this->config['roles'] as $label => $patterns) {
            foreach ($patterns as $pattern) {
                if (Str::is($pattern, $route['uri']) || ($route['name'] && Str::is($pattern, $route['name']))) {
                    return $label;
                }
            }
        }

        // Nothing matched by name, so fall back to what the middleware says:
        // an endpoint with no auth is reachable by anyone, and one with a
        // permission is administrative by definition.
        if (! $route['requires_auth']) {
            return $this->config['role_fallbacks']['public'];
        }

        if ($route['permission']) {
            return $this->config['role_fallbacks']['admin'];
        }

        return $this->config['role_fallbacks']['authenticated'];
    }

    /**
     * The subject folder inside the audience, taken from the route name with
     * the audience segment dropped so it does not appear twice.
     *
     * @return array<int,string>
     */
    private function group(array $route): array
    {
        $name = $route['name'];

        if ($name && str_contains($name, '.')) {
            $parts = explode('.', $name);
            array_pop($parts);

            $parts = array_values(array_filter(
                $parts,
                fn ($p) => ! in_array(strtolower($p), $this->config['drop_segments'], true),
            ));

            // A trailing verb is an action, not a subject: admin.categories.update
            // and admin.categories.index belong in one folder, not two.
            while (count($parts) > 1 && in_array(strtolower(end($parts)), $this->config['action_segments'], true)) {
                array_pop($parts);
            }

            if ($parts) {
                return array_map(fn ($p) => Str::headline($p), $parts);
            }
        }

        $segments = collect(explode('/', $route['uri']))
            ->reject(fn ($s) => $s === ''
                || str_starts_with($s, '{')
                || in_array(strtolower($s), $this->config['drop_segments'], true)
                || in_array($s, $this->config['strip_segments'], true))
            ->take(2)
            ->map(fn ($s) => Str::headline($s))
            ->all();

        return $segments ?: ['General'];
    }
}
