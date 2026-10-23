<?php

namespace Dmcbrn\LaravelEmailDatabaseLog;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailLogged;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

class EmailLogger
{
    /**
     * Handle the event.
     *
     * @param MessageSending $event
     */
    public function handle(MessageSending $event)
    {
        /** @var Email $message */
        $message = $event->message;
        $headers = $message->getHeaders();

        //Symfony only creates the Message-ID while sending, with a new random value every time it is
        //asked for. Set it now, so the ID logged here is the one that is sent (Mailgun webhooks find
        //emails by it).
        if (!$headers->has('Message-ID')) {
            $headers->addIdHeader('Message-ID', $message->generateMessageId());
        }
        $messageId = strtok($headers->get('Message-ID')->getId(), '@');

        $attachments = [];
        $usedNames = [];
        $inlineAttachments = [];
        foreach ($message->getAttachments() as $part) {
            //attachments and inline (embedded) files
            $originalName = $this->attachmentName($part);
            $position = count($attachments) + 1;
            $name = $this->safeAttachmentName($originalName, $position);
            $name = $this->uniqueAttachmentName($name, $position, $usedNames);
            $attachmentPath = $messageId . '/' . $name;
            Storage::disk(config('email_log.disk'))->put($attachmentPath, $part->getBody());
            $attachments[] = $attachmentPath;

            //remember which stored file an inline image is, so the email can be shown with its images;
            //Laravel refers to inline files in the body by their name ("cid:<name>")
            if ($this->isInline($part) && $originalName !== null) {
                $inlineAttachments[$originalName] = $attachmentPath;
            }
        }

        $fields = [
            'date' => date('Y-m-d H:i:s'),
            'from' => $this->formatAddresses($message->getFrom()),
            'to' => $this->formatAddresses($message->getTo()),
            'cc' => $this->formatAddresses($message->getCc()),
            'bcc' => $this->formatAddresses($message->getBcc()),
            'subject' => (string) $message->getSubject(),
            'body' => $this->getBody($message),
            'headers' => $headers->toString(),
            'attachments' => empty($attachments) ? null : implode(', ', $attachments),
            'messageId' => $messageId,
            'mail_driver' => $this->getMailDriver($event),
        ];

        //only if the migration adding this column has been run, so a missing migration
        //can never stop emails from being logged (and sent)
        if (!empty($inlineAttachments) && Schema::hasColumn('email_log', 'inline_attachments')) {
            $fields['inline_attachments'] = $inlineAttachments;
        }

        $emailLog = EmailLog::create($fields);

        event(new EmailLogged($emailLog));
    }

    /**
     * The HTML version of the body if there is one, otherwise the text version.
     *
     * @param  Email  $message
     * @return string
     */
    protected function getBody(Email $message)
    {
        $body = $message->getHtmlBody() ?? $message->getTextBody() ?? '';

        //Symfony also accepts a stream as the body; read it and rewind it for sending
        if (is_resource($body)) {
            $stream = $body;
            $body = stream_get_contents($stream);
            if (stream_get_meta_data($stream)['seekable'] ?? false) {
                rewind($stream);
            }
        }

        return (string) $body;
    }

    /**
     * The file name of an attachment or inline file, as given when it was attached.
     * Read from the part's headers: DataPart::getFilename() only exists from Symfony 6.1,
     * and Laravel 9 on PHP 8.0 uses Symfony 6.0.
     *
     * @param  DataPart  $part
     * @return null|string
     */
    protected function attachmentName(DataPart $part)
    {
        return $part->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename');
    }

    /**
     * Whether the part is an inline (embedded) file rather than a normal attachment.
     *
     * @param  DataPart  $part
     * @return bool
     */
    protected function isInline(DataPart $part)
    {
        return $part->getPreparedHeaders()->getHeaderBody('Content-Disposition') === 'inline';
    }

    /**
     * The mail driver (transport) of the mailer that sent the email.
     *
     * @param  MessageSending  $event
     * @return null|string
     */
    protected function getMailDriver(MessageSending $event)
    {
        // Old (Laravel <= 6 style) config files still have "mail.driver".
        if (config('mail.driver')) {
            return config('mail.driver');
        }

        // Since Laravel 7 each mailer has a "transport" (smtp, mailgun, ses, ...). Laravel 9 tells us
        // which mailer is sending; fall back to the default mailer ("mail.default").
        $mailer = $event->data['mailer'] ?? config('mail.default');

        return config("mail.mailers.{$mailer}.transport", $mailer);
    }

    /**
     * A file name that is unique within this email, so attachments with the same name
     * don't overwrite each other: a taken name is prefixed with the attachment's position.
     *
     * @param  string  $name
     * @param  int  $position
     * @param  array  $usedNames
     * @return string
     */
    protected function uniqueAttachmentName($name, $position, array &$usedNames)
    {
        // Compared case-insensitively: on some file systems "a.pdf" and "A.pdf" are the same file.
        while (in_array(strtolower($name), $usedNames, true)) {
            $name = $position . '-' . $name;
        }
        $usedNames[] = strtolower($name);

        return $name;
    }

    /**
     * Keep only the last part of a path-like name ("../../x.txt" becomes "x.txt"), so the
     * file is always stored inside this email's own folder.
     *
     * @param  null|string  $name
     * @param  int  $position
     * @return string
     */
    protected function safeAttachmentName($name, $position)
    {
        $name = str_replace('\\', '/', (string) $name);
        $lastSlash = strrpos($name, '/');
        if ($lastSlash !== false) {
            $name = substr($name, $lastSlash + 1);
        }

        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'attachment-' . $position;
        }

        return $name;
    }

    /**
     * Format a list of addresses as "Name <email>, other@example.com".
     *
     * @param  Address[]  $addresses
     * @return null|string
     */
    protected function formatAddresses(array $addresses)
    {
        if (empty($addresses)) {
            return null;
        }

        return implode(', ', array_map(function (Address $address) {
            return $address->getName() !== ''
                ? $address->getName() . ' <' . $address->getAddress() . '>'
                : $address->getAddress();
        }, $addresses));
    }
}