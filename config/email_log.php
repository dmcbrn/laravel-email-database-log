<?php

return [
    'disk' => env('EMAIL_LOG_DISK', env('EMAIL_LOG_ATTACHMENT_DISK', 'email_log_attachments')),
    //the queue connection and queue for the package's background work (currently only deleting the
    //attachments of deleted emails); "sync" runs it immediately
    'queue_connection' => env('EMAIL_LOG_QUEUE_CONNECTION', 'sync'),
    'queue' => env('EMAIL_LOG_QUEUE', null),
    'access_middleware' => env('EMAIL_LOG_ACCESS_MIDDLEWARE',null),
    'access_middleware_api' => env('EMAIL_LOG_ACCESS_MIDDLEWARE_API',null),
    'routes_prefix' => env('EMAIL_LOG_ROUTES_PREFIX',''),
    'routes_prefix_api' => env('EMAIL_LOG_ROUTES_PREFIX_API',''),
    'routes_webhook_prefix' => env('EMAIL_LOG_ROUTES_WEBHOOK_PREFIX', env('EMAIL_LOG_ROUTES_PREFIX','')),
    'mailgun' => [
        'secret' => env('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY', env('MAILGUN_SECRET', null)),
        'filter_unknown_emails' => env('EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS', true),
        'max_age' => env('EMAIL_LOG_MAILGUN_WEBHOOK_MAX_AGE', 86400),
    ],
];
