<?php

namespace App\Modules\Engagement\Policies;

use App\Models\User;
use App\Modules\Engagement\Models\Conversation;

/**
 * المحادثة لطرفيها وحدهما.
 *
 * ولا استثناء لصاحب الإعلان في محادثات مشترين آخرين: كل خيط منفصل، وما
 * كتبه مشترٍ لا يُقرأ من غير من كتب له. هذا أهم سطر في الملف — تسريب
 * محادثة أثقل من تسريب إعلان.
 */
class ConversationPolicy
{
    public function view(User $user, Conversation $conversation): bool
    {
        return $conversation->involves($user);
    }

    public function reply(User $user, Conversation $conversation): bool
    {
        return $conversation->involves($user);
    }
}