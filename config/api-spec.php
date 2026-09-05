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
    // developer actually has. The audiences are LEARNED from the route table,
    // not listed here: a fixed list fits one project and mislabels the rest.
    //
    // Override only if the detection gets it wrong. Keys become folder names,
    // values are matched against the URI and the route name.
    //
    //     'roles' => ['Seller' => ['*/seller/*'], 'Buyer' => ['*/buyer/*']],
    //
    'roles' => [],

    // A section becomes an audience when it holds at least this many routes
    // and this share of the API, and its routes agree about authentication.
    'audience_min_routes' => 3,
    'audience_min_share' => 0.05,

    // Used for routes outside any detected section.
    'public_label' => 'Public',
    'authenticated_label' => 'Authenticated',

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

    'drop_segments' => ['api', 'v1', 'v2'],

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
