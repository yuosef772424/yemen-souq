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
    case FilterRemoved = 'filter_removed';
    case ListingViewed = 'listing_viewed';
    case ContactRevealed = 'contact_revealed';
    case ListingCreated = 'listing_created';
    case FormAbandoned = 'form_abandoned';
}
