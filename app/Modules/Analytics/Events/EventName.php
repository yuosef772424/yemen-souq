<?php

namespace App\Modules\Analytics\Events;

/**
 * أسماء الأحداث المسموح بها.
 *
 * لماذا enum لا نصوص حرة: خطأ إملائي واحد في اسم حدث يعني بياناً
 * يُكتب ولا يُحسب أبداً، بلا خطأ ولا تحذير. وأسوأ من غياب البيانات
 * هو بيانات ناقصة تظن أنها كاملة.
 */
enum EventName: string
{
    case SearchPerformed = 'search_performed';
    case SearchImpression = 'search_impression';
    case SearchResultClicked = 'search_result_clicked';
    case FilterApplied = 'filter_applied';
    case ListingViewed = 'listing_viewed';
    case ContactRevealed = 'contact_revealed';
    case ListingCreated = 'listing_created';

    /**
     * أُضيفت لأن ما يقيسه الكود صار أكثر مما يعرفه هذا الـenum.
     *
     * favorite_added وfavorite_removed: عمود listing_daily_stats.favorites
     * كان يُكتب صفراً ثابتاً بتعليق «المفضلة غير مبنية بعد» — وقد بُنيت.
     * والرقم الصفري في لوحة المعلن أسوأ من غياب العمود: يقرأه المعلن
     * كأن أحداً لم يحفظ إعلانه.
     *
     * listing_reported: البلاغ حدث سلوك دالّ — تكراره على تصنيف واحد
     * إشارة على مشكلة في ذلك السوق لا على مُبلِّغ واحد.
     */
    case FavoriteAdded = 'favorite_added';
    case FavoriteRemoved = 'favorite_removed';
    case ListingReported = 'listing_reported';
}
