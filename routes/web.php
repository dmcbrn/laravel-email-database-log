<?php
//the app's own access middlewares (e.g. "auth,admin"); without them the pages answer 404
$accessMiddlewares = array_values(array_filter(array_map('trim', explode(',', (string) config('email_log.access_middleware')))));

Route::group([
    'prefix' => config('email_log.routes_prefix', ''),
    'middleware' => array_merge(
        ['web', 'Dmcbrn\LaravelEmailDatabaseLog\Http\Middleware\RequireAccessMiddleware:access_middleware'],
        $accessMiddlewares
    ),
], function(){
    Route::get(
        '/email-log',
        [
            'as' => 'email-log',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@index',
        ]
    );
    Route::post(
        '/email-log/delete',
        [
            'as' => 'email-log.delete-old',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@deleteOldEmails',
        ]
    );
    Route::get(
        '/email-log/{id}/attachment/{attachment}',
        [
            'as' => 'email-log.fetch-attachment',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@fetchAttachment',
            'where' => ['id' => '[0-9]+', 'attachment' => '[0-9]+'],
        ]
    );
    Route::get(
        '/email-log/{id}',
        [
            'as' => 'email-log.show',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@show',
            'where' => ['id' => '[0-9]+'],
        ]
    );
});

Route::group([
    'prefix' => config('email_log.routes_webhook_prefix', ''),
], function(){
    //webhooks events
    Route::post(
        '/email-log/webhooks/event',
        [
            'as' => 'email-log.webhooks',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@createEvent',
        ]
    );
});
