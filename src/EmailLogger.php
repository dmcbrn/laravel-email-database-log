<?php

namespace Dmcbrn\LaravelEmailDatabaseLog;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Schema;
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
        $inlineAttachments = [];
        foreach ($message->getChildren() as $child) {
            //attachments and inline (embedded) files, e.g. Swift_Attachment and Swift_Image
            if ($child instanceof \Swift_Mime_Attachment) {
                $position = count($attachments) + 1;
                $name = $this->safeAttachmentName($child->getFilename(), $position);
                $name = $this->uniqueAttachmentName($name, $position, $usedNames);
                $attachmentPath = $messageId . '/' . $name;
                Storage::disk(config('email_log.disk'))->put($attachmentPath, $child->getBody());
                $attachments[] = $attachmentPath;
                //remember which stored file an inline image is, so the email can be shown with its images
                if ($child instanceof \Swift_Mime_EmbeddedFile) {
                    $inlineAttachments[$child->getId()] = $attachmentPath;
                }
            }
        }

        $fields = [
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
        ];

        //columns added by later migrations are only filled once their migration has run,
        //so a missing migration can never stop emails from being logged (and sent)
        $columns = Schema::getColumnListing('email_log');
        if (!empty($inlineAttachments) && in_array('inline_attachments', $columns, true)) {
            $fields['inline_attachments'] = $inlineAttachments;
        }
        if (in_array('body_content_type', $columns, true)) {
            //"text/plain" or "text/html", so the body can be shown the right way
            $fields['body_content_type'] = $message->getBodyContentType();
        }

        $emailLog = EmailLog::create($fields);

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

        //attachment paths are stored as one text separated by ", ", so a name must not contain it
        $name = preg_replace('/,\s+/', ',', $name);

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
