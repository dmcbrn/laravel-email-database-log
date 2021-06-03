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
        $token = $request->signature['token'];
        $timestamp = $request->signature['timestamp'];
        $signature = $request->signature['signature'];

        //check if the timestamp is fresh
        if (abs(time() - $timestamp) > 15)
            return false;

        //returns true if signature is valid
        return hash_hmac('sha256', $timestamp.$token, $apiKey) === $signature;
    }

    public function saveEvent(Request $request)
    {
        //get email
        $mail_id_str = strtok($request->{'event-data'}['message']['headers']['message-id'], '@');
        $email = $this->getEmail($mail_id_str);
        if(!$email && config('email_log.email.filter_unknown_emails')) {
            return response('Error: no E-mail found', 200)->header('Content-Type', 'text/plain');
        }

        //save event
        EmailLogEvent::create([
            'messageId' => $mail_id_str,
            'event' => $request->{'event-data'}['event'],
            'data' => json_encode($request->all()),
        ]);

        //return success
        return response('Success', 200)->header('Content-Type', 'text/plain');
    }
}