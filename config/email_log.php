<?php

return [
    'folder' => env('EMAIL_LOG_ATTACHMENT_FOLDER','email_log_attachments'),
    'access_middleware' => env('EMAIL_LOG_ACCESS_MIDDLEWARE',null),
    'routes_prefix' => env('EMAIL_LOG_ROUTES_PREFIX',''), //when changing prefix please be sure to update the webhook's URLs also
    'mailgun' => [
        'secret' => env('MAILGUN_SECRET', null),
        'filter_unknown_emails' => env('EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS', true),
    ],
];
