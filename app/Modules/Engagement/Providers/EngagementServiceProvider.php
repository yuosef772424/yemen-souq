<?php

namespace App\Modules\Engagement\Providers;

use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Engagement\Policies\ConversationPolicy;
use App\Modules\Engagement\Policies\ListingQuestionPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * ربط الصلاحيات يدوياً — الموديلات خارج App\Models فلا يجدها الاكتشاف
 * التلقائي. نفس سبب CatalogServiceProvider، وهو ثمن معماريّة الوحدات.
 */
class EngagementServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(ListingQuestion::class, ListingQuestionPolicy::class);
    }
}