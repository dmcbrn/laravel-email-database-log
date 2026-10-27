# Laravel Email Database Log

A simple database logger for all outgoing emails sent by Laravel website.
Forked from ShvetsGroup\LaravelEmailDatabaseLog.

For Laravel 5.8 - use 2.* versions

For Laravel 6 - use 3.* versions

For Laravel 7 - use 4.* versions

For Laravel 8 - use 5.* versions

For Laravel 9 - use 6.* versions. They require Laravel 9.52 or newer: Laravel 9 is end-of-life, 9.52 is its final release line, and updating within Laravel 9 is a routine, backwards-compatible update.

For Laravel 10 - use 7.* versions. They support every Laravel 10 version.

For Laravel 11 - use 8.* versions. They support every Laravel 11 version.

For Laravel 12 - use 9.* versions. They support every Laravel 12 version.

Tested databases:

- 9.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 8.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 7.*: MySQL 5.7, PostgreSQL 11 and SQLite
- 6.*: MySQL 5.7, PostgreSQL 10 and SQLite
- 5.*: MySQL and SQLite; they cannot be installed on PostgreSQL

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

//in KB: the most that is logged of an email's body and of its headers (longer values are cut off),
//and the largest attachment that is stored (0 stores attachments of any size); see "Sizes and limits"
'max_body_size' => (int) env('EMAIL_LOG_MAX_BODY_SIZE', 1024),
'max_attachment_size' => (int) env('EMAIL_LOG_MAX_ATTACHMENT_SIZE', 10240),

//the queue connection and queue for the package's background work (currently only deleting the
//attachments of deleted emails); "sync" runs it immediately; see "Deleting old emails"
'queue_connection' => env('EMAIL_LOG_QUEUE_CONNECTION', 'sync'),
'queue' => env('EMAIL_LOG_QUEUE', null),

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

//mailgun webhook signing key (falls back to MAILGUN_SECRET), whether to ommit events for emails which were not found,
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

You can review sent emails using the following URI `/email-log`. The list shows 20 emails per page; add `per_page` to the URL to change that, for example `/email-log?per_page=50`. It must be a whole number from 1 to 100. The same applies to the API (`/api/email-log`), which answers other values with a validation error (HTTP 422).

You can prefix this URI by adding something like `EMAIL_LOG_ROUTES_PREFIX=prefix/` to your .env file.

You can protect this URI using middleware by adding something like `EMAIL_LOG_ACCESS_MIDDLEWARE=auth,landlord` to your .env file.

## Deleting old emails

The list page has a form to delete all emails logged on or before a date; the API offers the same (`POST /api/email-log/delete` with a `date`). The emails are deleted in batches of up to 500, without loading them in full, so large logs can be cleaned up too.

**Events:** after each batch, the package fires `Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailsDeleted`, with the deleted emails' IDs (`$event->ids`) and message IDs (`$event->messageIds`). It is fired **once per batch, not once per deletion**: deleting 1,200 emails fires it three times (500, 500 and 200 emails). Eloquent's model events (`deleting`, `deleted`) are not fired for these deletes; they still are when you delete a single email in your own code with `$email->delete()`.

**Attachments:** each deleted email's attachment folder is deleted by a job. By default it runs immediately, as part of the request. To delete attachments in the background instead, for example when they are on S3 and there are many, set the queue connection, and optionally a queue name, in your `.env` file:

```
EMAIL_LOG_QUEUE_CONNECTION=redis
EMAIL_LOG_QUEUE=email-log
```

A queue worker then has to run for that connection and queue; until it does, the files stay on the disk. A folder that can't be deleted is reported through Laravel's error handling (your log file, or an error tracker such as Sentry), and the others are still deleted. A folder whose name isn't a plain folder name (for example `x/..`) is never deleted; a warning is written to your log instead.

Without a queue, deleting a very large number of emails with attachments can run into your server's time limit for a request. Delete in smaller steps (older dates first) in that case, or use a queue.

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

GitHub Actions runs the tests on every push: on PHP 8.2, 8.3, 8.4 and 8.5 with the oldest and the newest allowed dependency versions (SQLite), and on MySQL 5.7 and PostgreSQL 10.

Recent Composer versions refuse to install package versions with known security advisories, which the oldest allowed Laravel 12 versions have. If `composer update` refuses, set `COMPOSER_NO_BLOCKING=1` and `COMPOSER_NO_SECURITY_BLOCKING=1`.

```bash
composer update
composer test
```

Recent Composer versions refuse to install packages with known security advisories, which every Laravel 8 release has. To run the tests anyway, set `COMPOSER_NO_BLOCKING=1` and `COMPOSER_NO_SECURITY_BLOCKING=1` when running `composer update`.

## Upgrade from 9.1.0 to 9.2.0 - DELETING OLD EMAILS IN BATCHES, PER_PAGE LIMIT, BREAKING CHANGES

- **`per_page` must now be a whole number from 1 to 100**, on the list page and in the API. Other values, such as `per_page=500` or `per_page=-1`, now give a validation error (HTTP 422 in the API) instead of being used. API clients that asked for more than 100 emails at once need to page through the results.
- **"Delete old emails"** (on the page and in the API) now deletes in batches of up to 500 emails, with one query per batch, without loading the emails in full. **Eloquent's model events (`deleting`, `deleted`) are no longer fired for these deletes.** If your app listened to them, listen to the new `EmailsDeleted` event instead. It is fired **once per batch**, not once per deletion (see "Deleting old emails" above). Deleting a single email in your own code with `$email->delete()` still fires the model events.
- **Attachment folders** of deleted emails are now deleted by a job. By default it runs immediately, as before. To run it in the background, set `EMAIL_LOG_QUEUE_CONNECTION` and, optionally, `EMAIL_LOG_QUEUE`. A folder that can't be deleted is reported, and the others are still deleted.
- **A folder whose name isn't a plain folder name** (for example `x/..`) is never deleted; a warning is written to your log instead.
- No migrations. If you use queues, restart your queue workers after upgrading.

## Upgrade from 9.0.2 to 9.1.0 - EMAILS OF ANY SIZE, ATTACHMENT LIMIT, NEW MIGRATION

Large emails could stop an email from being sent: an HTML body over 64 KB on MySQL, or more than 255 characters of recipients or subject on MySQL and PostgreSQL. Saving the log entry failed, and that error stopped the sending. Now the email is always sent, and a logging error is reported through Laravel's error handling instead (your log file, or an error tracker such as Sentry). When upgrading:

- **Run the new migration:** `php artisan migrate`. It enlarges the email log's columns, adds a `skipped_attachments` column, and drops the indexes on `to` and `subject` (the package's search can't use them). On a large MySQL table this can lock the table for a while, so run it outside busy hours.
- **New limits** (see "Sizes and limits" above): bodies and headers over 1 MB are cut off (`EMAIL_LOG_MAX_BODY_SIZE`, in KB), and attachments over 10 MB are no longer stored (`EMAIL_LOG_MAX_ATTACHMENT_SIZE`, in KB; `0` stores any size). On PostgreSQL and SQLite, longer bodies were stored in full before.
- **API:** the single-email response now includes `skipped_attachments`.
- If you published the config file, the defaults apply without changes. To change the limits there, add the two settings shown above.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 9.0.1 to 9.0.2 - BUG FIX

Attachments whose name contains a comma followed by a space (such as "Invoice, March.pdf") were shown as "file not found" on the email's page, and the download links of the attachments after them pointed to the wrong files. Such names are now stored without the spaces after the comma ("Invoice,March.pdf"). Emails logged before this fix keep their names. No migrations; nothing to do when upgrading.

## Upgrade from 9.0.0 to 9.0.1 - BUG FIX

"Delete old emails" (on the page and in the API) failed with an error when emails logged before 2018, which have no message ID, were among the emails to delete. They are now deleted like the others. No migrations; nothing to do when upgrading.

## Upgrade from 8.0.0 to 9.0.0 - LARAVEL 12

Version 9.0 supports Laravel 12. Laravel 11 apps stay on 8.*, and Composer will not install 9.* there. When upgrading:

- **Requirements:** Laravel 12 and PHP 8.2 or newer.
- **No new migrations.**
- **Inline images on Laravel 12.40 and newer:** from Laravel 12.40, `embedData()` and `embed()` refer to inline images by their Content-ID instead of their file name. The package now remembers each inline image under both, so the email's page shows its images on every Laravel 12 version.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 7.0.0 to 8.0.0 - LARAVEL 11

Version 8.0 supports Laravel 11. Laravel 10 apps stay on 7.*, and Composer will not install 8.* there. When upgrading:

- **Requirements:** Laravel 11 and PHP 8.2 or newer.
- **`doctrine/dbal` is no longer required.** Laravel 11 changes database columns without it. Composer removes it automatically, unless your app requires it in its own `composer.json`.
- **No new migrations and no code changes.**
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 6.0.0 to 7.0.0 - LARAVEL 10

Version 7.0 supports Laravel 10. Laravel 9 apps stay on 6.*, and Composer will not install 7.* there. When upgrading:

- **Requirements:** Laravel 10, PHP 8.1 or newer, and `doctrine/dbal` 3.5.1 or newer (Laravel 10 no longer supports DBAL 2). If your app has DBAL 2 installed, update it together with the package:

```bash
composer update dmcbrn/laravel-email-database-log doctrine/dbal --with-dependencies
```

- **No new migrations.** Dates are now converted with the model's `$casts` instead of the `$dates` property, which Laravel 10 removed. The pages and the API show dates exactly as before.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 5.6.0 to 6.0.0 - LARAVEL 9, POSTGRESQL, NEW MIGRATION

Version 6.0 supports Laravel 9. Laravel 8 apps stay on 5.*, and Composer will not install 6.* there. When upgrading:

- **Requirements:** Laravel 9.52 or newer and PHP 8.0.2 or newer.
- **Laravel 9 sends emails with Symfony Mailer** instead of SwiftMailer. The logger was rewritten for it; what it logs stays the same.
- **Run the new migration:** `php artisan migrate`. On MySQL and MariaDB it removes the foreign key from `email_log_events.messageId` (the index stays). On other databases it does nothing.
- **Deleting emails:** an email's events are now deleted together with the email on every database, whenever it is deleted through the model: `$email->delete()`, `EmailLog::destroy()` and the "delete old emails" page and API. Bulk deletes such as `EmailLog::where(...)->delete()` or raw SQL do not fire model events, so on MySQL they no longer delete the events. To remove leftover events afterwards: `EmailLogEvent::whereDoesntHave('email')->delete();` (this also removes events kept for unknown emails, see below).
- **`EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS=false` now works on MySQL.** Saving events for emails that are not in the log failed there because of the foreign key.
- **`mail_driver`** now records the transport of the mailer that actually sent the email (for example with `Mail::mailer('ses')`), not always the default mailer.
- **PostgreSQL** is supported. The migrations could not run there before.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 5.5.0 to 5.6.0 - PLAIN-TEXT EMAILS, NEW MIGRATION

The email detail page now shows plain-text emails with their line breaks, and shows text like `<name>` as written instead of treating it as HTML. When upgrading:

- **Run the new migration:** `php artisan migrate` adds a `body_content_type` column (`text/plain` or `text/html`) to the `email_log` table. Until it has run, emails are logged and shown as before. Emails logged before the upgrade are shown as before.
- **API:** the single-email response now includes `body_content_type`, so your own code can tell plain-text bodies from HTML ones.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 5.4.0 to 5.5.0 - SECURITY FIXES, INLINE IMAGES, NEW MIGRATION

This release fixes two security issues on the email log pages and shows inline images on the email detail page. When upgrading:

- **Run the new migration:** `php artisan migrate` adds an `inline_attachments` column to the `email_log` table. Until it has run, emails are logged as before, but inline images are not shown on the detail page.
- **Email body:** the detail page now shows the body in a sandboxed iframe, so scripts in a logged email no longer run on your site. If you override the package's `show.blade.php` (in `resources/views/vendor/email-logger/`), replace `{!! $email->body !!}` with the iframe from the package's `views/show.blade.php`, which uses `{{ $body }}`.
- **Attachments:** only PNG, JPEG, GIF and WebP images open in the browser; all other attachments are downloaded.
- **API:** the single-email response now includes `inline_attachments`. Its `body` is still the original HTML; if your own code displays it, isolate it the same way.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 5.3.3 to 5.4.0 - BUG FIXES, MAILGUN WEBHOOK SETTINGS

This release fixes a number of bugs (see the release notes on GitHub for the full list) and has no new database migrations. When upgrading, check the following:

- **Access to the email log pages:** the controller now extends Laravel's `Illuminate\Routing\Controller` instead of your app's `App\Http\Controllers\Controller`. If your base controller applied middleware (e.g. `auth`) in its constructor, the email log pages no longer inherit it. Protect them with `EMAIL_LOG_ACCESS_MIDDLEWARE`.
- **Mailgun webhooks:** set your Mailgun *HTTP webhook signing key* as `EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY` (see "MailGun webhooks" above). If it is not set, `MAILGUN_SECRET` is used, as before. Requests are now rejected when neither is set. If you published the config file, update its `mailgun` section to:

```php
'mailgun' => [
    'secret' => env('EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY', env('MAILGUN_SECRET', null)),
    'filter_unknown_emails' => env('EMAIL_LOG_MAILGUN_FILTER_UNKNOWN_EMAILS', true),
    'max_age' => env('EMAIL_LOG_MAILGUN_WEBHOOK_MAX_AGE', 86400),
],
```

- **Delete old emails** now deletes emails logged on and before the selected date, on every database.
- **Attachments with the same name** in one email are now stored with a numbered prefix (`2-invoice.pdf`) instead of overwriting each other.
- **Attachment disk:** `EMAIL_LOG_DISK` is now read, as documented. `EMAIL_LOG_ATTACHMENT_DISK` still works, but if you set both to different disks, `EMAIL_LOG_DISK` now wins.
- If you use queues, restart your queue workers after upgrading.

## Upgrade from 5.3.2 to 5.3.3 - TESTS ADDED

No changes to the package's code. This release adds an automated test suite (see "Running the tests"). Nothing to do when upgrading.

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
