<?php

namespace Dmcbrn\LaravelEmailDatabaseLog\Events;

use Illuminate\Http\Request;
use Dmcbrn\LaravelEmailDatabaseLog\EmailLogEvent;

class MailgunEvent extends Event
{
    public function verify(Request $request)
    {
        //get needed data
        $apiKey = config('email_log.mailgun.secret', null);
        //without a secret anyone could make a valid signature (with an empty key), so reject
        if (empty($apiKey))
            return false;
        $token = $request->input('signature.token');
        $timestamp = $request->input('signature.timestamp');
        $signature = $request->input('signature.signature');

        //reject malformed requests (e.g. from bots) instead of crashing on them
        if (!is_string($token) || !is_numeric($timestamp) || !is_string($signature))
            return false;

        //check if the timestamp is fresh
        if (abs(time() - $timestamp) > 15)
            return false;

        //returns true if signature is valid (hash_equals compares in constant time)
        return hash_equals(hash_hmac('sha256', $timestamp.$token, $apiKey), $signature);
    }

    public function saveEvent(Request $request)
    {
        //get email
        $messageId = $request->input('event-data.message.headers.message-id');
        $eventName = $request->input('event-data.event');
        if (!is_string($messageId) || !is_string($eventName))
            return response('Error: malformed event', 400)->header('Content-Type', 'text/plain');

        $mail_id_str = strtok($messageId, '@');
        $email = $this->getEmail($mail_id_str);
        if(!$email && config('email_log.mailgun.filter_unknown_emails')) {
            return response('Error: no E-mail found', 200)->header('Content-Type', 'text/plain');
        }

        //save event
        EmailLogEvent::create([
            'messageId' => $mail_id_str,
            'event' => $eventName,
            'data' => json_encode($request->all()),
        ]);

        //return success
        return response('Success', 200)->header('Content-Type', 'text/plain');
    }
}