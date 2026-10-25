<?php
//the app's own access middlewares (e.g. "auth:sanctum"); without them the API answers 404
$accessMiddlewares = array_values(array_filter(array_map('trim', explode(',', (string) config('email_log.access_middleware_api')))));

Route::group([
    'prefix' => 'api/' . config('email_log.routes_prefix_api', ''),
    'middleware' => array_merge(
        ['api', 'Dmcbrn\LaravelEmailDatabaseLog\Http\Middleware\RequireAccessMiddleware:access_middleware_api'],
        $accessMiddlewares
    ),
], function(){
    Route::get(
        '/email-log',
        [
            'as' => 'api.email-log',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@indexApi',
        ]
    );
    Route::post(
        '/email-log/delete',
        [
            'as' => 'api.email-log.delete-old',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@deleteOldEmailsApi',
        ]
    );
    Route::get(
        '/email-log/{id}/attachment/{attachment}',
        [
            'as' => 'api.email-log.fetch-attachment',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@fetchAttachment',
            'where' => ['id' => '[0-9]+', 'attachment' => '[0-9]+'],
        ]
    );
    Route::get(
        '/email-log/{id}',
         [
            'as' => 'api.email-log.show',
            'uses' => 'Dmcbrn\LaravelEmailDatabaseLog\EmailLogController@showApi',
            'where' => ['id' => '[0-9]+'],
        ]
    );
});
