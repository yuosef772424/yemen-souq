<?php

namespace App\Modules\Notifications\Mail;

use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * قالب واحد لكل أنواع الإشعارات — لا قالبٌ لكل نوع.
 *
 * أربعون نوع إشعار مستقبلاً يعني أربعين ملف Blade لو تفرّع القالب هنا.
 * والعنوان والنصّ يكفيان لبريدٍ يخبر بما وقع، والرابط — إن حملته
 * `data['url']` — يعيد صاحبه إلى الصفحة ليتابع بلا بحث.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $url,
    ) {}

    public static function fromNotification(AppNotification $notification): self
    {
        $url = $notification->url();

        return new self(
            title: $notification->title,
            body: $notification->body,
            url: $url === null ? null : rtrim((string) config('app.url'), '/').$url,
        );
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'notifications::mail.notification');
    }
}
