# Laravel Email Database Log

A simple database logger for all outgoing emails sent by a Laravel website.
Forked from ShvetsGroup\LaravelEmailDatabaseLog.

For Laravel 5.8 - use 2.* versions

For Laravel 6 - use 3.* versions

For Laravel 7 - use 4.* versions

For Laravel 8 - use 5.* versions

For Laravel 9 - use 6.* versions. They require Laravel 9.52 or newer: Laravel 9 is end-of-life, 9.52 is its final release line, and updating within Laravel 9 is a routine, backwards-compatible update.

For Laravel 10 - use 7.* versions. They support every Laravel 10 version.

For Laravel 11 - use 8.* versions. They support every Laravel 11 version.

For Laravel 12 - use 9.* versions. They support every Laravel 12 version.

For Laravel 13 - use 10.* versions. They support every Laravel 13 version.

Tested databases:

- 10.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 9.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 8.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 7.*: MySQL 5.7, PostgreSQL 11 and SQLite
- 6.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 5.*: MySQL and SQLite; they cannot be installed on PostgreSQL

**Security note for versions before 10.1.0** (all versions for Laravel 12 and older): they leave the email log open when `EMAIL_LOG_ACCESS_MIDDLEWARE` and `EMAIL_LOG_ACCESS_MIDDLEWARE_API` are not set. Anyone can then read every logged email, including password reset links, and delete the log through the API. If you use one of those versions, set both, as described in [Step 4](#step-4-protect-the-email-log-required).

# Installation

## Step 1: Composer

Laravel Email Database Log can be installed via [composer](http://getcomposer.org) by running this line in terminal:

```bash
composer require dmcbrn/laravel-email-database-log
```

Laravel finds the package by itself (package discovery), so there is nothing to register.

## Step 2: Attachment disk

Attachments are saved on a filesystem disk. Add this disk to the `disks` array in your `config/filesystems.php` file:

```php
'email_log_attachments' => [
    'driver' => 'local',
    'root' => storage_path('app/email_log_attachments'),
],
```

To use another disk, for example one on external storage, set `EMAIL_LOG_DISK` in your `.env` file (`EMAIL_LOG_ATTACHMENT_DISK`, which earlier versions read, still works), or publish the config file and change the `disk` value.

## Step 3: Migration

Now, run this in terminal:

```bash
php artisan migrate
```

## Step 4: Protect the email log (required)

The email log shows every email your app sends, including password reset links, so it is only reachable once you protect it. Until then, its pages and its API answer "404 Not Found", in every environment.

Set the middleware that protects the pages in your `.env` file, for example:

```
EMAIL_LOG_ACCESS_MIDDLEWARE=auth
```

`auth` lets every logged-in user in. To allow only some users, add your own middleware, separated by a comma: `auth,admin`.

The API has its own setting, and stays unreachable until it is set, for example with [Laravel Sanctum](https://laravel.com/docs/sanctum):

```
EMAIL_LOG_ACCESS_MIDDLEWARE_API=auth:sanctum
```

If the log is already protected in another way, for example by your web server, or if you deliberately want it open, for example on your own computer, set `EMAIL_LOG_ACCESS_MIDDLEWARE=web` and `EMAIL_LOG_ACCESS_MIDDLEWARE_API=api`. These add no protection: anyone who can reach the URLs can then read the log and delete it.

The Mailgun webhook does not need these settings; it checks Mailgun's signature instead (see [MailGun webhooks](#mailgun-webhooks)).

## Step 5: Config (optional)

The defaults work without a config file. To change them in a config file, publish it by running this in terminal:

```bash
php artisan vendor:publish --provider="Dmcbrn\LaravelEmailDatabaseLog\LaravelEmailDatabaseLogServiceProvider"
```

Config contains following parameters:

```php
//name of the disk where the attachments will be saved
'disk' => env('EMAIL_LOG_DISK', env('EMAIL_LOG_ATTACHMENT_DISK', 'email_log_attachments')),

//in KB: the most that is logged of an email's body and of its headers (longer values are cut off),
//and the largest attachment that is stored (0 stores attachments of any size); see "Sizes and limits"
'max_body_size' => (int) env('EMAIL_LOG_MAX_BODY_SIZE', 1024),
'max_attachment_size' => (int) env('EMAIL_LOG_MAX_ATTACHMENT_SIZE', 10240),

//required: the middlewares that protect the WEB routes (separate several by comma);
//until they are set, the pages answer 404
'access_middleware' => env('EMAIL_LOG_ACCESS_MIDDLEWARE',null),

//required for the API: the middlewares that protect the API routes (separate several by comma);
//until they are set, the API answers 404
'access_middleware_api' => env('EMAIL_LOG_ACCESS_MIDDLEWARE_API',null),

//this parameter prefixes the routes for listing of logged emails using WEB routes
'routes_prefix' => env('EMAIL_LOG_ROUTES_PREFIX',''),

//this parameter prefixes the routes for listing of logged emails using API routes
'routes_prefix_api' => env('EMAIL_LOG_ROUTES_PREFIX_API',''),

//custom route prefix for webhooks
'routes_webhook_prefix' => env('EMAIL_LOG_ROUTES_WEBHOOK_PREFIX', env('EMAIL_LOG_ROUTES_PREFIX','')),

//mailgun webhook signing key (falls back to MAILGUN_SECRET), whether to omit events for emails which were not found,
//and the maximum age of a webhook request in seconds (0 turns the check off)
'mailgun' => [
    'secret' => env('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY', env('MAILGUN_SECRET', null)),
    'filter_unknown_emails' => env('EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS', true),
    'max_age' => env('EMAIL_LOG_MAILGUN_WEBHOOK_MAX_AGE', 86400),
],
```

# Usage

After installation, any email sent by your website will be logged to the `email_log` table in the site's database, and its attachments saved on the attachment disk.

If you want to process the logged email or save/format some additional data on your system, you can listen to the `Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailLogged` event with a [Laravel listener](https://laravel.com/docs/events#defining-listeners). From Laravel 11, listeners in your `app/Listeners` folder are registered automatically ([event discovery](https://laravel.com/docs/events#event-discovery)); in older versions, register the listener in your `EventServiceProvider`.

If you use queues, restart your queue workers after installing or upgrading the package (`php artisan queue:restart`). They keep the old code in memory until they are restarted ([Laravel docs](https://laravel.com/docs/queues#queue-workers-and-deployment)).

You can review sent emails using the following URI `/email-log`.

You can prefix this URI by adding something like `EMAIL_LOG_ROUTES_PREFIX=prefix/` to your .env file.

The pages are only reachable once `EMAIL_LOG_ACCESS_MIDDLEWARE` is set (see [Step 4](#step-4-protect-the-email-log-required)).

## Sizes and limits

Emails are always sent, whatever their size. The log keeps them within these limits:

- **Body and headers:** up to `EMAIL_LOG_MAX_BODY_SIZE` KB each (default `1024`, which is 1 MB). Longer values are cut off and end with `…[truncated]`.
- **From, To, CC, BCC and subject:** up to 64 KB each, cut off the same way.
- **Attachments:** files larger than `EMAIL_LOG_MAX_ATTACHMENT_SIZE` KB (default `10240`, which is 10 MB) are not stored. The email's page lists them with their size and the limit at the time they were sent; the API returns them as `skipped_attachments`. `0` stores attachments of any size.

Both settings are in KB. In `.env`, write the number itself, for example `EMAIL_LOG_MAX_BODY_SIZE=2048` for 2 MB. A calculation such as `2 * 1024` only works in the published config file, which is PHP; `.env` values are plain text.

Keep `EMAIL_LOG_MAX_BODY_SIZE` well within what your database accepts in a single query. MySQL refuses queries larger than its `max_allowed_packet` setting (4 MB by default on MySQL 5.7, 64 MB on MySQL 8.0), so with a higher limit, large emails would fail to be logged.

If logging an email fails anyway, for example because the database can't be reached, the email is still sent. It is not logged, and the error is reported through Laravel's error handling, so it appears in your log file or in an error tracker such as Sentry.

Message-IDs created by Laravel always fit the log. If your app sets its own Message-IDs, with more than 32 characters before the `@`, those emails are sent but not logged, and the error is reported.

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

If your site is behind Cloudflare, make sure Cloudflare lets Mailgun's webhook requests through. Mailgun sends them from Google Cloud, and Cloudflare's *Bot Fight Mode* answers them with a challenge, so they never reach your site. Bot Fight Mode cannot be bypassed for a single path with WAF custom rules or Page Rules, and Mailgun advises against allowing its IP addresses, so turn Bot Fight Mode off (or, on paid plans, use Super Bot Fight Mode with a skip rule for the webhook URL). Also add the webhook URL as an exception to any of your own rules that challenge or block visitors. This is safe: the package rejects every request that is not signed with your webhook signing key.

## Running the tests

The package has a test suite that boots a throw-away Laravel app with [Orchestra Testbench](https://github.com/orchestral/testbench). By default it uses an in-memory SQLite database, so nothing is sent and nothing is left behind.

```bash
composer update
composer test
```

To run the tests on MySQL or PostgreSQL instead, set `EMAIL_LOG_TEST_DB_CONNECTION` (`mysql` or `pgsql`), together with `EMAIL_LOG_TEST_DB_HOST`, `EMAIL_LOG_TEST_DB_PORT`, `EMAIL_LOG_TEST_DB_DATABASE`, `EMAIL_LOG_TEST_DB_USERNAME` and `EMAIL_LOG_TEST_DB_PASSWORD`. The tests empty that database, so use one created only for them.

GitHub Actions runs the tests on every push: on PHP 8.3, 8.4 and 8.5 with the oldest and the newest allowed dependency versions (SQLite), and on MySQL 5.7 and PostgreSQL 10.

Recent Composer versions refuse to install package versions with known security advisories, which the oldest allowed Laravel 13 versions have. If `composer update` refuses, set `COMPOSER_NO_BLOCKING=1` and `COMPOSER_NO_SECURITY_BLOCKING=1`.

## Upgrading

The upgrade notes for every release are in [UPGRADE.md](UPGRADE.md).
