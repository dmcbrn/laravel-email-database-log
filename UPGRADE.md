# Upgrade guide

Upgrade notes for every release, newest first. Which version to use for which Laravel version is listed in the [README](README.md).

## Upgrade from 9.0.0 to 10.0.0 - LARAVEL 13

Version 10.0 supports Laravel 13. Laravel 12 apps stay on 9.*, and Composer will not install 10.* there. When upgrading:

- **Requirements:** Laravel 13 and PHP 8.3 or newer.
- **No new migrations and no code changes.** The package works with both Symfony Mailer 7.4 and 8, which Laravel 13 allows.
- If you use queues, restart your queue workers after upgrading.

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
- **Mailgun webhooks:** set your Mailgun *HTTP webhook signing key* as `EMAIL_LOG_MAILGUN_WEBHOOK_SIGNING_KEY` (see [MailGun webhooks](README.md#mailgun-webhooks) in the README). If it is not set, `MAILGUN_SECRET` is used, as before. Requests are now rejected when neither is set. If you published the config file, update its `mailgun` section to:

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

No changes to the package's code. This release adds an automated test suite (see [Running the tests](README.md#running-the-tests) in the README). Nothing to do when upgrading.

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
