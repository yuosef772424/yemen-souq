<?php

namespace App\Modules\Notifications\Jobs;

use App\Modules\Notifications\Enums\Channel;
use App\Modules\Notifications\Mail\NotificationMail;
use App\Modules\Notifications\Models\AppNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * تسليم الإشعار بريداً.
 *
 * نفس بنية DeliverPush وDeliverSms: المعرّف لا النموذج، وafterCommit،
 * والفشل لا يتصاعد. ولا سائق يُختار هنا كما في Push وSms: `Mail` من
 * نواة لارافل، ومزوّدها `MAIL_MAILER` بيئة الإعداد — والافتراضي `log`
 * سائقٌ آمن يكتب ولا يرسل، نفس فلسفة LogPushSender وOutboxSender.
 */
class DeliverEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public function __construct(
        private readonly int $notificationId,
        private readonly string $email,
    ) {
        $this->afterCommit();
    }

    public function handle(): void
    {
        $notification = AppNotification::find($this->notificationId);

        if ($notification === null) {
            return;
        }

        try {
            Mail::to($this->email)->send(NotificationMail::fromNotification($notification));
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        $notification->markDelivered(Channel::Email);
    }
}
