<?php

return [

    'name' => env('API_SPEC_NAME', env('APP_NAME', 'API')),

    'description' => env('API_SPEC_DESCRIPTION', ''),

    'base_url' => env('API_SPEC_BASE_URL', rtrim(env('APP_URL', 'http://localhost'), '/').'/api'),

    'locale' => env('API_SPEC_LOCALE', 'ar'),

    // Only routes under this prefix are exported, and it is stripped from the
    // keys used to look descriptions up.
    'prefix' => env('API_SPEC_PREFIX', 'api'),

    'output' => env('API_SPEC_OUTPUT', 'docs/api'),

    'spec' => env('API_SPEC_SPEC', 'docs/api-descriptions.php'),

    // Folders are grouped by audience first, because that is the question a
    // developer actually has: an app developer wants the student endpoints and
    // never the admin ones. Matched in order against the URI and the route
    // name; the first hit wins.
    'roles' => [
        'Guest' => ['*/login', '*/register', '*/public/*', '*/password/*', '*/forgot*', '*/reset*', '*.login', '*.register'],
        'Admin' => ['*/admin/*', 'admin.*'],
        'Student' => ['*/user/*', '*/student/*', 'user.*', 'student.*'],
    ],

    // Used when no pattern above matched.
    'role_fallbacks' => [
        'public' => 'Guest',
        'admin' => 'Admin',
        'authenticated' => 'Student',
    ],

    // Dropped from the folder path so the audience name does not repeat inside
    // its own tree.
    // Verbs that name an action rather than a subject, so they do not become
    // folders of their own.
    'action_segments' => [
        'index', 'show', 'store', 'create', 'update', 'destroy', 'delete', 'edit',
        'attach', 'detach', 'assign', 'publish', 'lock', 'revoke', 'restore',
        'approve', 'reject', 'schedule', 'waive', 'record', 'preview', 'generate',
        'recount', 'submit', 'start', 'effective', 'marks', 'post', 'multipart',
    ],

    'drop_segments' => ['admin', 'user', 'student', 'guest', 'api'],

    'exclude' => [
        '_ignition/*',
        'sanctum/*',
        'telescope*',
        'horizon*',
        '*/{fallbackPlaceholder}',
    ],

    // The requests whose response carries a token. Matched against the URI or
    // the route name.
    'login_routes' => [
        'api/login', 'api/*/login', '*.login', 'login',
    ],

    'refresh_routes' => [
        'api/refresh-token', 'api/*/refresh*', '*.refresh*',
    ],

    // Where the token sits in the login response, in dot notation.
    'token_path' => env('API_SPEC_TOKEN_PATH', 'data.access_token'),

    'refresh_token_path' => env('API_SPEC_REFRESH_TOKEN_PATH', 'data.refresh_token'),

    'token_variable' => 'token',

    'refresh_token_variable' => 'refresh_token',

    'always_paginate' => false,

    'strip_segments' => ['api', 'v1', 'v2'],

    // Empty by design. Examples are derived from each field's own validation
    // rules, which works on any project. Add an entry only to override one:
    //
    //     'examples' => ['phone' => '0912345678'],
    //
    'examples' => [],

];
