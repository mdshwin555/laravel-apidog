<?php

namespace Hawasly\ApiSpec;

use Illuminate\Support\Str;

/**
 * Builds the response examples an endpoint can actually return.
 *
 * A spec carrying only a 200 teaches nothing about failure, and the client
 * written against it will not handle a 422 until it meets one in production.
 * Every case the route can produce is derived here from what the route
 * declares: a body means validation can fail, a permission means 403 is
 * reachable, a path parameter means 404 is.
 */
class ExampleFactory
{
    public function __construct(private array $config)
    {
    }

    /**
     * Each case carries its own HTTP status and schema name, so a builder
     * never has to keep a parallel list that can fall out of step with this
     * one — adding a case here is enough for every output format.
     *
     * @return array<string,array{status:int,schema:string,summary:string,value:array}>
     */
    public function forRoute(array $route): array
    {
        $examples = ['success' => [
            'status' => $route['method'] === 'POST' ? 201 : 200,
            'schema' => 'Envelope',
            'summary' => $this->successSummary($route),
            'value' => $this->success($route),
        ]];

        if ($route['body']) {
            $examples['validation_failed'] = [
                'status' => 422,
                'schema' => 'ValidationError',
                'summary' => '422 — a required field was missing or invalid',
                'value' => $this->validationError($route),
            ];
        }

        if ($route['requires_auth']) {
            $examples['unauthenticated'] = [
                'status' => 401,
                'schema' => 'Error',
                'summary' => '401 — no token, or an expired one',
                'value' => ['message' => 'Unauthenticated. Please log in.', 'status_code' => 0],
            ];
        }

        if ($route['permission']) {
            $examples['forbidden'] = [
                'status' => 403,
                'schema' => 'Error',
                'summary' => '403 — the account lacks '.$route['permission'],
                'value' => ['message' => 'You do not have permission to perform this action.', 'status_code' => 0],
            ];
        }

        if ($route['path_params']) {
            $examples['not_found'] = [
                'status' => 404,
                'schema' => 'Error',
                'summary' => '404 — the id in the path does not exist',
                'value' => ['message' => 'Resource not found.', 'status_code' => 0],
            ];
        }

        if ($route['throttle']) {
            $examples['rate_limited'] = [
                'status' => 429,
                'schema' => 'Error',
                'summary' => '429 — over the limit of '.$route['throttle'],
                'value' => ['message' => 'Too many attempts. Please try again later.', 'status_code' => 0],
            ];
        }

        // A query-builder endpoint rejects an unknown sort or filter with a 400,
        // not the 422 a body would give: the failure is in the query string and
        // never reaches validation.
        if (! empty($route['query_builder'])) {
            $examples['bad_request'] = [
                'status' => 400,
                'schema' => 'Error',
                'summary' => '400 — an unsupported sort or filter was requested',
                'value' => [
                    'message' => 'Requested sort(s) `unknown` is not allowed.',
                    'status_code' => 0,
                ],
            ];
        }

        // Any endpoint can fail unexpectedly, and a client never shown the shape
        // will parse the 500 as though it were a result.
        $examples['server_error'] = [
            'status' => 500,
            'schema' => 'Error',
            'summary' => '500 — an unexpected server error',
            'value' => ['message' => 'Something went wrong. Please try again later.', 'status_code' => 0],
        ];

        // Sorted by status so both builders emit the cases in the order a
        // reader scans them, without either one holding a list of its own.
        uasort($examples, fn ($a, $b) => $a['status'] <=> $b['status']);

        return $examples;
    }

    private function successSummary(array $route): string
    {
        return match (true) {
            $this->isIndex($route) => '200 — a page of results',
            $route['method'] === 'POST' => '201 — created',
            $route['method'] === 'DELETE' => '200 — deleted',
            in_array($route['method'], ['PUT', 'PATCH'], true) => '200 — updated',
            default => '200 — the record',
        };
    }

    private function success(array $route): array
    {
        $message = $this->message($route);

        if ($this->isIndex($route)) {
            return [
                'data' => [
                    'items' => [$this->record($route), $this->record($route, 2)],
                    'meta' => [
                        'current_page' => 1,
                        'per_page' => 15,
                        'total' => 2,
                        'last_page' => 1,
                    ],
                ],
                'message' => $message,
                'status_code' => 1,
            ];
        }

        if ($route['method'] === 'DELETE') {
            return ['message' => $message, 'status_code' => 1];
        }

        return ['data' => $this->record($route), 'message' => $message, 'status_code' => 1];
    }

    /**
     * A record shaped from the endpoint's own body fields, so the example
     * looks like this resource rather than like a generic placeholder.
     */
    private function record(array $route, int $id = 1): array
    {
        $record = ['id' => $id];

        foreach ($route['body'] as $field) {
            if (in_array($field['type'], ['file', 'image'], true)) {
                $record[$field['name']] = 'storage/'.$field['name'].'/'.$id.'.jpg';

                continue;
            }

            if (str_contains($field['name'], 'password')) {
                continue;
            }

            $record[$field['name']] = $field['example'];
        }

        if (count($record) === 1) {
            $record['name'] = 'Example';
        }

        $record['created_at'] = '2026-01-01T09:00:00+00:00';
        $record['updated_at'] = '2026-01-01T09:00:00+00:00';

        return $record;
    }

    /**
     * The first two required fields, shown failing — the shape a client has to
     * parse, with real field names rather than "field".
     */
    private function validationError(array $route): array
    {
        $required = collect($route['body'])->where('required', true)->take(2);

        if ($required->isEmpty()) {
            $required = collect($route['body'])->take(1);
        }

        $errors = [];

        foreach ($required as $field) {
            $errors[$field['name']] = [$this->violationFor($field)];
        }

        return [
            'message' => $errors ? reset($errors)[0] : 'The given data was invalid.',
            'status_code' => 0,
            'errors' => $errors ?: ['field' => ['The field is required.']],
        ];
    }

    private function violationFor(array $field): string
    {
        $label = Str::headline($field['name']);

        if ($field['in']) {
            return "The selected {$label} is invalid.";
        }

        return match ($field['type']) {
            'email' => "The {$label} must be a valid email address.",
            'file', 'image' => "The {$label} failed to upload.",
            'integer', 'number' => "The {$label} must be a number.",
            'date' => "The {$label} is not a valid date.",
            default => "The {$label} field is required.",
        };
    }

    private function message(array $route): string
    {
        $subject = $route['name']
            ? Str::headline(Str::singular(Str::before(Str::afterLast(Str::beforeLast($route['name'], '.'), '.'), '.')))
            : 'Record';

        return match (true) {
            $this->isIndex($route) => Str::plural($subject).' retrieved successfully.',
            $route['method'] === 'POST' => $subject.' created successfully.',
            $route['method'] === 'DELETE' => $subject.' deleted successfully.',
            in_array($route['method'], ['PUT', 'PATCH'], true) => $subject.' updated successfully.',
            default => $subject.' retrieved successfully.',
        };
    }

    private function isIndex(array $route): bool
    {
        return $route['method'] === 'GET'
            && ! $route['path_params']
            && (! $route['name'] || ! Str::endsWith($route['name'], ['.show', '.edit']));
    }
}
