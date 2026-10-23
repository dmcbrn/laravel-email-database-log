<!DOCTYPE html>
<html>
    <head>
        <title>Emails Log</title>
    </head>

    <body style="background: white;">
        <h1>Email:</h1>

        <ul>
            <li>{{ $email->date }}</li>
            <li>From: {{ $email->from }}</li>
            <li>To: {{ $email->to }}</li>
            <li>Subject: {{ $email->subject }}</li>
            <li>Body: <br>
                <iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $body }}" title="Email body" style="width: 100%; height: 600px; border: 1px solid #ccc; background: white;"></iframe>
            </li>
            <li>Attachments:
                @if(count($email->attachments))
                    <ul>
                        @foreach($email->attachments as $attachment)
                            <li>
                                @if(array_key_exists('route', $attachment))
                                    <a href="{{ $attachment['route'] }}">{{ $attachment['name'] }}</a>
                                @else
                                    {{ $attachment['name'] }} - {{ $attachment['message'] }}
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @else
                    NONE
                @endif
            </li>
            <li>Headers: {{ $email->headers }}</li>
            <li>Message ID: {{ $email->messageId }}</li>
            <li>Mail Driver: {{ $email->mail_driver }}</li>
            <li>Events:
                @if(count($email->events ?? []) > 0)
                    <ul>
                        @foreach($email->events as $event)
                            <li><strong>{{ $event->event }}</strong> {{ $event->created_at }}</li>
                        @endforeach
                    </ul>
                @endif
            </li>
        </ul>

        <a href="{{ route('email-log') }}">Back to All</a>
    </body>
</html>