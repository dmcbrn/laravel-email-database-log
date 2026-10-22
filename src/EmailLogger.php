<?php

namespace Dmcbrn\LaravelEmailDatabaseLog;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Storage;
use Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailLogged;

class EmailLogger
{
    /**
     * Handle the event.
     *
     * @param MessageSending $event
     */
    public function handle(MessageSending $event)
    {
        $message = $event->message;

        $messageId = strtok($message->getId(), '@');

        $attachments = [];
        $usedNames = [];
        foreach ($message->getChildren() as $child) {
            //attachments and inline (embedded) files, e.g. Swift_Attachment and Swift_Image
            if ($child instanceof \Swift_Mime_Attachment) {
                $position = count($attachments) + 1;
                $name = $this->safeAttachmentName($child->getFilename(), $position);
                $name = $this->uniqueAttachmentName($name, $position, $usedNames);
                $attachmentPath = $messageId . '/' . $name;
                Storage::disk(config('email_log.disk'))->put($attachmentPath, $child->getBody());
                $attachments[] = $attachmentPath;
            }
        }

        $emailLog = EmailLog::create([
            'date' => date('Y-m-d H:i:s'),
            'from' => $this->formatAddressField($message, 'From'),
            'to' => $this->formatAddressField($message, 'To'),
            'cc' => $this->formatAddressField($message, 'Cc'),
            'bcc' => $this->formatAddressField($message, 'Bcc'),
            'subject' => $message->getSubject(),
            'body' => $message->getBody(),
            'headers' => (string)$message->getHeaders(),
            'attachments' => empty($attachments) ? null : implode(', ', $attachments),
            'messageId' => $messageId,
            'mail_driver' => $this->getMailDriver(),
        ]);

        event(new EmailLogged($emailLog));
    }

    /**
     * The mail driver, read the same way Laravel 8 does.
     *
     * @return null|string
     */
    protected function getMailDriver()
    {
        // Old (Laravel <= 6 style) config files still have "mail.driver".
        if (config('mail.driver')) {
            return config('mail.driver');
        }

        // Since Laravel 7, "mail.default" names the default mailer, and its "transport" is the driver.
        $mailer = config('mail.default');

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
     * Format address strings for sender, to, cc, bcc.
     *
     * @param $message
     * @param $field
     * @return null|string
     */
    function formatAddressField($message, $field)
    {
        $headers = $message->getHeaders();

        if (!$headers->has($field)) {
            return null;
        }

        $mailboxes = $headers->get($field)->getFieldBodyModel();

        $strings = [];
        foreach ($mailboxes as $email => $name) {
            $mailboxStr = $email;
            if (null !== $name) {
                $mailboxStr = $name . ' <' . $mailboxStr . '>';
            }
            $strings[] = $mailboxStr;
        }
        return implode(', ', $strings);
    }
}
