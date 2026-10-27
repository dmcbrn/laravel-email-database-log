<?php

namespace Dmcbrn\LaravelEmailDatabaseLog;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Dmcbrn\LaravelEmailDatabaseLog\Events\EventFactory;
use Dmcbrn\LaravelEmailDatabaseLog\Jobs\DeleteAttachmentFolders;
use Dmcbrn\LaravelEmailDatabaseLog\LaravelEvents\EmailsDeleted;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class EmailLogController extends Controller {
    //attachment types that may be shown in the browser (everything else is downloaded)
    private const INLINE_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    //how many emails "Delete old emails" deletes at a time
    private const DELETE_BATCH_SIZE = 500;

    public function index(Request $request)
    {
        //get list of emails
        list($emails, $filterEmail, $filterSubject) = $this->getEmailsForListing($request);

        //return
        return view('email-logger::index', compact('emails','filterEmail','filterSubject'));
    }

    public function indexApi(Request $request)
    {
        //get list of emails
        list($emails, $filterEmail, $filterSubject) = $this->getEmailsForListing($request);

        //return
        return response()->json($emails, 200);
    }

    public function show(int $id)
    {
        //get email
        $email = $this->getEmail($id, false);

        //the body for the sandboxed frame on the page: plain text escaped with its line breaks,
        //HTML with its inline images embedded
        $body = $email->body_content_type === 'text/plain'
            ? $this->plainTextBody($email)
            : $this->bodyWithInlineImages($email);

        //return
        return view('email-logger::show', compact('email', 'body'));
    }

    public function showApi(int $id)
    {
        //get email
        $email = $this->getEmail($id, true);

        //return
        return response()->json(compact('email'), 200);
    }

    public function fetchAttachment(int $id, int $attachment)
    {
        //get email and attachments' paths (404 if the email or the attachment doesn't exist)
        $email = EmailLog::select('id','attachments')->findOrFail($id);
        $attachmentPaths = array_filter(explode(', ', (string) $email->attachments));
        if (!isset($attachmentPaths[$attachment]))
            abort(404);
        $attachmentFullPath = $attachmentPaths[$attachment];

        //get file and mime type (404 if the file is no longer on the disk)
        $disk = Storage::disk(config('email_log.disk'));
        if (!$disk->exists($attachmentFullPath))
            abort(404);
        $file = $disk->get($attachmentFullPath);
        $mimeType = $disk->mimeType($attachmentFullPath);

        //return file: only plain images may be shown in the browser; everything else (e.g. HTML or SVG,
        //which can contain scripts) is downloaded, so it can't run on this site
        $mimeType = $mimeType ?: 'application/octet-stream';
        $disposition = in_array($mimeType, self::INLINE_TYPES, true) ? 'inline' : 'attachment';

        return response($file, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Disposition', $this->contentDisposition($disposition, $attachmentFullPath))
            ->header('X-Content-Type-Options', 'nosniff');
    }

    private function contentDisposition($disposition, $path)
    {
        //the file name is the last part of the stored path
        $parts = explode('/', str_replace('\\', '/', $path));
        $name = end($parts);
        if ($name === '')
            $name = 'attachment';

        //a plain ASCII version of the name for older browsers ("%" is not allowed there)
        $fallback = preg_replace('/[^\x20-\x7e]/', '', str_replace('%', '', Str::ascii($name)));
        if ($fallback === '')
            $fallback = 'attachment';

        return HeaderUtils::makeDisposition($disposition, $name, $fallback);
    }

    private function bodyWithInlineImages(EmailLog $email)
    {
        $body = (string) $email->body;
        $disk = Storage::disk(config('email_log.disk'));

        //replace each "cid:<id>" with the image itself (a data: URL), so the sandboxed frame
        //doesn't have to load it from this site; only plain images whose file still exists
        foreach ((array) $email->inline_attachments as $contentId => $path) {
            if (!$disk->exists($path))
                continue;

            $mimeType = $disk->mimeType($path);
            if (!in_array($mimeType, self::INLINE_TYPES, true))
                continue;

            $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($disk->get($path));
            $body = str_replace('cid:' . $contentId, $dataUrl, $body);
        }

        return $body;
    }

    private function plainTextBody(EmailLog $email)
    {
        //escaped, so text like "<name>" is shown as it is; pre-wrap keeps the line breaks and still
        //wraps long lines, break-word also breaks long words such as URLs
        return '<pre style="white-space: pre-wrap; word-wrap: break-word; margin: 0;">'
            . e($email->body)
            . '</pre>';
    }

    public function createEvent(Request $request)
    {
        $event = EventFactory::create('mailgun');

        //check if event is valid
        if(!$event)
            return response('Error: Unsupported Service', 400)
                ->header('Content-Type', 'text/plain');

        //validate the $request data for this $event
        if(!$event->verify($request))
            return response('Error: verification failed', 400)
                ->header('Content-Type', 'text/plain');

        //save event
        return $event->saveEvent($request);
    }

    public function deleteOldEmails(Request $request)
    {
        //delete old emails
        $message = $this->deleteEmailsBeforeDate($request);

        //return
        return redirect(route('email-log'))
            ->with('status', $message);
    }

    public function deleteOldEmailsApi(Request $request)
    {
        //delete old emails
        $message = $this->deleteEmailsBeforeDate($request);

        //return
        return response()->json(compact('message'), 200);
    }

    private function getEmail(int $id, bool $isApi)
    {
        //get email
        $email = EmailLog::with('events')
            ->findOrFail($id);

        //format attachments as collection
        $attachments = collect();

        //check if there are any attachments
        $attachmentsArray = array_filter(explode(', ',$email->attachments));
        if(count($attachmentsArray) > 0) {
            //set up new $email->attachments values
            foreach($attachmentsArray as $key => $attachment) {
                //update each attachment's value depending if file can be found on disk
                $fileName = basename($attachment);
                if(Storage::disk(config('email_log.disk'))->exists($attachment)) {
                    $route = $isApi
                        ? 'api.email-log.fetch-attachment'
                        : 'email-log.fetch-attachment';
                    $formattedAttachment = [
                        'name' => $fileName,
                        'route' => route($route, [
                            'id' => $email->id,
                            'attachment' => $key,
                        ]),
                    ];
                } else {
                    $formattedAttachment = [
                        'name' => $fileName,
                        'message' => 'file not found',
                    ];
                }
                $attachments->push($formattedAttachment);
            }
        }

        //update the attachments
        $email->attachments = $attachments;

        //attachments that were too large to store, each with a message saying why
        if (!empty($email->skipped_attachments)) {
            $email->skipped_attachments = array_map(function ($skipped) {
                $skipped['message'] = $skipped['name'] . ' was not stored: when this email was sent, the maximum size for logged attachments was '
                    . $this->formatSize($skipped['limit']) . ', and this file was ' . $this->formatSize($skipped['size']) . '.';

                return $skipped;
            }, $email->skipped_attachments);
        }

        //return
        return $email;
    }

    private function formatSize($bytes)
    {
        //"3 KB" below 1 MB (rounded up, so a file is never shown smaller than it was), "22 MB" or "1.5 MB" above
        if ($bytes >= 1024 * 1024) {
            return rtrim(rtrim(number_format($bytes / (1024 * 1024), 1, '.', ''), '0'), '.') . ' MB';
        }

        return max(1, (int) ceil($bytes / 1024)) . ' KB';
    }

    private function getEmailsForListing(Request $request)
    {
        //validate
        $request->validate([
            'filterEmail' => 'nullable|string',
            'filterSubject' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        //get emails
        $filterEmail = $request->filterEmail;
        $filterSubject = $request->filterSubject;
        $emails = EmailLog::with([
            'events' => function($q) {
                $q->select('messageId','created_at','event');
            }
        ])
            ->select('id','messageId','date','from','to','subject')
            ->when($filterEmail, function($q) use($filterEmail) {
                return $q->where('to','like','%'.$filterEmail.'%');
            })
            ->when($filterSubject, function($q) use($filterSubject) {
                return $q->where('subject','like','%'.$filterSubject.'%');
            })
            ->orderBy('id','desc')
            ->paginate((int) $request->per_page ?: 20)
            ->withQueryString();

        return [$emails, $filterEmail, $filterSubject];
    }

    private function deleteAttachmentFolders(array $folders)
    {
        //immediately by default ("sync"), or on the queue set by EMAIL_LOG_QUEUE_CONNECTION and
        //EMAIL_LOG_QUEUE; the emails are already deleted, so a failure here is only reported
        try {
            $job = (new DeleteAttachmentFolders($folders))
                ->onConnection(config('email_log.queue_connection') ?: 'sync')
                ->onQueue(config('email_log.queue'));
            app(Dispatcher::class)->dispatch($job);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function deleteEmailsBeforeDate(Request $request)
    {
        //validate
        $request->validate([
            'date' => 'required|date',
        ]);

        //emails logged on or before the selected date (= before the start of the next day)
        $date = strtotime($request->date);
        $before = date('Y-m-d H:i:s', strtotime('+1 day', $date));

        //in batches, reading only what is needed (never the bodies), with one query per batch for the
        //events and one for the emails; Eloquent's model events don't fire for these deletes, the
        //package's EmailsDeleted event does, once per batch
        $numberOfDeletedEmails = 0;
        EmailLog::select('id', 'messageId', 'attachments')
            ->where('date', '<', $before)
            ->chunkById(self::DELETE_BATCH_SIZE, function ($emails) use (&$numberOfDeletedEmails) {
                $ids = $emails->pluck('id')->all();
                //emails logged before the messageId column existed have no message ID
                $messageIds = $emails->pluck('messageId')->filter(function ($messageId) {
                    return (string) $messageId !== '';
                })->values()->all();

                if (!empty($messageIds)) {
                    EmailLogEvent::whereIn('messageId', $messageIds)->delete();
                }
                $numberOfDeletedEmails += EmailLog::whereIn('id', $ids)->delete();

                //the attachment folders, named after the message IDs, of the emails that have attachments
                $folders = $emails->filter(function ($email) {
                    return (string) $email->messageId !== '' && (string) $email->attachments !== '';
                })->pluck('messageId')->values()->all();
                if (!empty($folders)) {
                    $this->deleteAttachmentFolders($folders);
                }

                event(new EmailsDeleted($ids, $messageIds));
            });

        //return message
        return 'Deleted ' . $numberOfDeletedEmails . ' emails logged on and before ' . date('D, d M Y', $date);
    }
}
