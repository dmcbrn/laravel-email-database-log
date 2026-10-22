# Laravel Email Database Log

A simple database logger for all outgoing emails sent by Laravel website.
Forked from ShvetsGroup\LaravelEmailDatabaseLog.

For Laravel 5.8 - use 2.* versions

For Laravel 6 - use 3.* versions

For Laravel 7 - use 4.* versions

For Laravel 8 - use 5.* versions

Version 5.1 is using Filesystems which is a breaking change from 5.3. See below for upgrade information.

# Installation

## Step 1: Composer

Laravel Email Database Log can be installed via [composer](http://getcomposer.org) by running this line in terminal:

```bash
composer require dmcbrn/laravel-email-database-log
```

## Step 2: Configuration

You can skip this step if your version of Laravel is 5.5 or above. Otherwise, you have to add the following to your config/app.php in the providers array:

```php
'providers' => [
    // ...
    Dmcbrn\LaravelEmailDatabaseLog\LaravelEmailDatabaseLogServiceProvider::class,
],
```

## Step 3: Migration

Now, run this in terminal:

```bash
php artisan migrate
```

## Step 4: Config

To publish config file run this in terminal:

```bash
php artisan vendor:publish --provider="Dmcbrn\LaravelEmailDatabaseLog\LaravelEmailDatabaseLogServiceProvider"
```

Config contains following parameters:

```php
//name of the disk where the attachments will be saved
'disk' => env('EMAIL_LOG_DISK', env('EMAIL_LOG_ATTACHMENT_DISK', 'email_log_attachments')),

//to prevent access to list of logged emails through WEB routes add a middlewares
//multiple middlewares can be used (separate by comma)
'access_middleware' => env('EMAIL_LOG_ACCESS_MIDDLEWARE',null),

//to prevent access to list of logged emails through API routes add a middlewares
//multiple middlewares can be used (separate by comma)
'access_middleware_api' => env('EMAIL_LOG_ACCESS_MIDDLEWARE_API',null),

//this parameter prefixes the routes for listing of logged emails using WEB routes
'routes_prefix' => env('EMAIL_LOG_ROUTES_PREFIX',''),

//this parameter prefixes the routes for listing of logged emails using API routes
'routes_prefix_api' => env('EMAIL_LOG_ROUTES_PREFIX_API',''),

//custom route prefix for webhooks
'routes_webhook_prefix' => env('EMAIL_LOG_ROUTES_WEBHOOK_PREFIX', env('EMAIL_LOG_ROUTES_PREFIX','')),

///mailgun webhook signing key (falls back to MAILGUN_SECRET), whether to ommit events for emails which were not found,
//and the maximum age of a webhook request in seconds (0 turns the check off)
'mailgun' => [
    'secret' => env('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY', env('MAILGUN_SECRET', null)),
    'filter_unknown_emails' => env('EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS', true),
    'max_age' => env('EMAIL_LOG_MAILGUN_WEBHOOK_MAX_AGE', 86400),
],
```

# Usage

After installation, any email sent by your website will be logged to `email_log` table in the site's database.

Any attachments will be saved on the `email_log_attachments` disk. To use another disk, set `EMAIL_LOG_DISK` in your `.env` file (`EMAIL_LOG_ATTACHMENT_DISK`, which earlier versions read, still works), or publish the config file and change the `disk` value.

You also need to add the following disk in the `config/filesystems.php` file:

```
'email_log_attachments' => [
    'driver' => 'local',
    'root' => storage_path('app/email_log_attachments'),
],
```

If you want to process the logged email or save/format some additional data on your system you can hook up to the `Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailLogged` event via a Laravel listener:

https://laravel.com/docs/5.5/events#defining-listeners

Don't forget to register the event:

https://laravel.com/docs/5.5/events#registering-events-and-listeners

If you're using Laravel >=5.8.9 you can use `Event Discovery` instead:

https://laravel.com/docs/5.8/events#registering-events-and-listeners 

If using queues on your server you will need to restart the worker for the library to work:

```
Remember, queue workers are long-lived processes and store the booted application state in memory. 
As a result, they will not notice changes in your code base after they have been started. 
So, during your deployment process, be sure to restart your queue workers.


https://laravel.com/docs/5.5/queues#running-the-queue-worker
```

You can review sent emails using the following URI `/email-log`.

You can prefix this URI by adding something like `EMAIL_LOG_ROUTES_PREFIX=prefix/` to your .env file.

You can protect this URI using middleware by adding something like `EMAIL_LOG_ACCESS_MIDDLEWARE=auth,landlord` to your .env file.

## MailGun webhooks

You can use Mailgun webhooks to log webhook events. In your MailGun Webhooks section add:

```
https://example.com/email-log/webhooks/event
```

for all of the events. If you used a `prefix` in the config file then this should be reflected in the url:

```
https://example.com/your-prefix/email-log/webhooks/event
```

Mailgun signs webhook requests with your *HTTP webhook signing key* (in Mailgun: Settings → API Security), which is different from your API key. Add it to your `.env` file:

```
EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY=your-webhook-signing-key
```

If it is not set, `MAILGUN_SECRET` is used, as in earlier versions. Webhook requests are rejected when neither is set or when the signature does not match.

If you published the config file, update the `secret` line in its `mailgun` section to:

```php
'secret' => env('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY', env('MAILGUN_SECRET', null)),
```

Webhook requests signed more than 24 hours ago are rejected. Mailgun can deliver webhooks late and retries failed ones for up to 8 hours, so keep this generous. To change it, set the maximum age in seconds (`0` turns the check off):

```
EMAIL_LOG_MAILGUN_WEBHOOK_MAX_AGE=86400
```

If you published the config file before this setting existed, the 24-hour default still applies. To change it, add this line to the `mailgun` section of your published config file:

```php
'max_age' => env('EMAIL_LOG_MAILGUN_WEBHOOK_MAX_AGE', 86400),
```

## Running the tests

The package has a test suite that boots a throw-away Laravel app with [Orchestra Testbench](https://github.com/orchestral/testbench), using an in-memory SQLite database. Nothing is sent and nothing is left behind.

```bash
composer update
composer test
```

Recent Composer versions refuse to install packages with known security advisories, which every Laravel 8 release has. To run the tests anyway, set `COMPOSER_NO_BLOCKING=1` and `COMPOSER_NO_SECURITY_BLOCKING=1` when running `composer update`.

## Upgrade from 5.2.2 to 5.3.0 - API ENDPOINTS AVAILABLE, BREAKING CHANGE

API ENDPOINTS AVAILABLE:

New routes with JSON responses:
```
https://example.com/api/your-prefix/email-log
https://example.com/api/your-prefix/email-log/{id}
https://example.com/api/your-prefix/email-log/{id}/attachment/{attachment}
https://example.com/api/your-prefix/email-log/delete
```

BREAKING CHANGE:

The logic for presentation of attachments (on single email view: `show.blade.php`) has been moved to backend. If you are not using the default `show.blade.php` and are displaying list of attachments you should reflect these changes in your code. Namely, you no need to check if the file exists or add the attachment to the route to access it. Instead the returned data will be array with of following formats (depending if file can be found on disk or not):

```
//file can be found
[
    'name' => 'filename.pdf',
    'route' => 'https://example.com/api/email-log/email-id/attachment/attachment-key',
]
//file can't be found
[
    'name' => 'filename.pdf',
    'message' => 'file not found',
]
```

Note following: `/api` part will be dropped in html response, `email-id` is the `id` of the email, `attachment-key` is the order of attachment starting at `0`.

## Upgrade from 5.1.0 to 5.2.0 - BREAKING CHANGE

Add the following parameters to the end of the `config/email_log.php` array:

```
    ...

    'routes_webhook_prefix' => env('EMAIL_LOG_ROUTES_WEBHOOK_PREFIX', env('EMAIL_LOG_ROUTES_PREFIX','')),
    'mailgun' => [
        'secret' => env('MAILGUN_SECRET', null),
        'filter_unknown_emails' => env('EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS', true),
    ],
```


## Upgrade from 5.0.3 to 5.1.0 - BREAKING CHANGE

IMPORTANT - please upgrade to 5.2.1 right away as there are some fixes for the 5.1.0 upgrade. I was hastly and missed some issues which are corrected in 5.2.1.

As email log attachments might quickly grow to large size you'd want to use some external storage to save them. To enable this we need to utilize the Laravel's Filesystem. Follow the guide below if you were using the 5.0.3 and wish to upgrade to 5.1.0.

Change a line in `config/email_log.php` file from:

```
'folder' => env('EMAIL_LOG_ATTACHMENT_FOLDER','email_log_attachments'),
```

to

```
'disk' => env('EMAIL_LOG_DISK','email_log_attachments'),
```

In `config/filesystems.php` add 


```
'email_log_attachments' => [
    'driver' => 'local',
    'root' => storage_path('app/email_log_attachments'),
],
```

the `'root'` must point to the folder where you were previously saving the attachements.

You will also need to drop the current prefix from the `email_log.attachments` column (default was `email_log_attachments`). For example `email_log_attachments/12345678910/my_file.jpg` should be renamed to `12345678910/my_file.jpg`.

You can run following code using `php artisan tinker` to fix these issues. Depending on the amount of data, it could take some time to finish:

```
$log = Dmcbrn\LaravelEmailDatabaseLog\EmailLog::where('attachments','!=', null)
$log->count()
$log->chunk(100, function($chunk) { foreach($chunk as $l) { $l->attachments = str_replace('email_log_attachments/', '', $l->attachments); $l->save(); } })

```