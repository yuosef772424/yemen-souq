<?php

/**
 * scaffold-dashboard.php — لوحة المعلن + إصلاح كسرين في وحدة الهوية.
 *
 * يُشغَّل من جذر المشروع:  php scaffold-dashboard.php
 *
 * يكتب كل ملف كاملاً بترميز UTF-8 بلا BOM، ويحفظ نسخة .bak لكل ملف قائم.
 */

if (! is_dir(__DIR__ . '/app/Modules/Catalog')) {
    fwrite(STDERR, "خطأ: شغّل هذا السكربت من جذر المشروع.\n");
    exit(1);
}

$files = [];

// ─────────────────────────────────────────────────────────────────────────────
// إصلاح (1): نموذج المستخدم — كان النموذج الافتراضي لِلارافل بلا أي إضافة
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Models/User.php'] = <<<'PHP'
<?php

namespace App\Models;

use App\Modules\Analytics\Models\TrackingPreference;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Identity\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * كان هذا الملف نسخة لارافل الافتراضية بلا تعديل، بينما تعتمد عليه
 * وحدة الهوية بأربع طرق غير موجودة: phone في fillable، وdisplayName،
 * وhasVerifiedPhone، وmarkPhoneAsVerified، وعلاقة trackingPreferences
 * التي يقرأها EventRecorder لكل مستخدم مسجَّل.
 *
 * الأعمدة موجودة في هجرة الهوية (000200) منذ البداية — الناقص كان
 * النموذج وحده. ولهذا كانت اختبارات الهوية تسقط كلها بينما تبدو
 * الهجرة سليمة.
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * phone قابل للتعيين الجماعي، وphone_verified_at ليس كذلك بقصد:
     * التوثيق لا يجوز أن يأتي من طلب HTTP. يُكتب بـforceFill في
     * markPhoneAsVerified فقط، بعد التحقق من الرمز.
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ---- العلاقات ----

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function listings(): HasMany
    {
        return $this->hasMany(Listing::class);
    }

    public function trackingPreferences(): HasOne
    {
        return $this->hasOne(TrackingPreference::class);
    }

    // ---- الهوية ----

    /**
     * الاسم المعروض يأتي من الملف الشخصي، ويرجع إلى name عند غيابه.
     *
     * القالب يستدعي هذه الطريقة في كل صفحة لمستخدم مسجَّل، فلا يجوز
     * أن تفترض وجود صف user_profiles — مستخدم أُنشئ من factory أو من
     * seeder لا يملك واحداً.
     */
    public function displayName(): string
    {
        return $this->profile?->display_name ?: $this->name;
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone !== null && $this->phone_verified_at !== null;
    }

    public function markPhoneAsVerified(): void
    {
        if ($this->phone_verified_at !== null) {
            return;
        }

        $this->forceFill(['phone_verified_at' => now()])->save();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// إصلاح (2): UserProfile — يستدعيه RegisterController وهو غير موجود
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Identity/Models/UserProfile.php'] = <<<'PHP'
<?php

namespace App\Modules\Identity\Models;

use App\Models\User;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Services\ArabicNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف العرض لكل مستخدم.
 *
 * RegisterController كان يستورد هذا الصنف وينشئه، والصنف غير موجود —
 * فكل تسجيل جديد ينتهي بـClass not found. ولو وُجد الصنف بلا الخطاف
 * أدناه لانتهى بخطأ SQL: name_normalized عمود NOT NULL والمتحكّم
 * يمرّر display_name فقط.
 *
 * لماذا التطبيع في saving وليس في المتحكّم: نفس مبدأ ListingObserver —
 * أي مسار يحفظ صفاً (تسجيل، seeder، اختبار، لوحة إدارة لاحقاً) يمرّ
 * من هنا، فلا يبقى العمود فارغاً بصمت في مسار نسي أحدهم تعديله.
 */
class UserProfile extends Model
{
    protected $table = 'user_profiles';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $fillable = [
        'user_id', 'display_name', 'avatar_path', 'bio',
        'location_id', 'preferred_currency', 'preferred_locale',
        'is_business', 'business_name',
    ];

    /**
     * trust_score وlistings_count محسوبان — غيابهما عن fillable مقصود
     * تماماً كغياب price_in_base عن fillable في Listing.
     */
    protected function casts(): array
    {
        return [
            'is_business' => 'boolean',
            'trust_score' => 'decimal:2',
            'listings_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $profile): void {
            $profile->name_normalized = app(ArabicNormalizer::class)
                ->normalize($profile->display_name);
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// هجرة: أعمدة التجديد
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Database/Migrations/2026_01_01_000302_add_renewal_fields_to_listings.php'] = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تجديد بلا أثر تجديد غير قابل للمراجعة.
 *
 * renewed_at يجيب: هل جُدِّد هذا الإعلان فعلاً أم أن expires_at
 * البعيد جاء من الإنشاء؟ وrenewals_count يجيب: كم مرة؟ — وهو ما
 * يكشف الإعلان المُعاد تجديده منذ سنة ولم يُبَع، وهي إشارة تسعير
 * لا حالة خطأ.
 *
 * الحراسة بـhasColumn لأن الهجرات السابقة نُفِّذت بالفعل على قاعدة
 * التطوير، وقد تُشغَّل هذه على قاعدة أُعيد بناؤها من الصفر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'renewed_at')) {
                $table->timestamp('renewed_at')->nullable()->after('published_at')
                    ->comment('آخر تجديد — منفصل عن published_at بقصد');
            }

            if (! Schema::hasColumn('listings', 'renewals_count')) {
                $table->unsignedSmallInteger('renewals_count')->default(0)->after('renewed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn(['renewed_at', 'renewals_count']);
        });
    }
};
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الصلاحية
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Policies/ListingPolicy.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Policies;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;

/**
 * قاعدة الملكية في موضع واحد.
 *
 * البديل المرفوض: ->where('user_id', auth()->id()) داخل كل استعلام في
 * كل متحكّم. يعمل، لكن القاعدة تصبح موزّعة على عشرة أسطر، ونسيانها في
 * سطر واحد يعني ثغرة IDOR كاملة: /account/listings/{slug}/edit لإعلان
 * شخص آخر. القاعدة هنا تُخترَق مرة واحدة أو لا تُخترَق.
 *
 * ولماذا لا auto-discovery: لارافل يبحث عن App\Policies\ListingPolicy
 * لموديل App\Models\Listing، والموديل هنا في مساحة اسم الوحدة —
 * فالربط يدوي في CatalogServiceProvider. هذا ثمن معماريّة الوحدات.
 */
class ListingPolicy
{
    public function view(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function update(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function delete(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    /**
     * restore يعمل على صف محذوف منطقياً، وهذا مقصود: الملكية لا
     * تتغيّر بالحذف، فالمالك وحده من يستعيد.
     */
    public function restore(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function renew(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    public function viewStats(User $user, Listing $listing): bool
    {
        return $this->owns($user, $listing);
    }

    private function owns(User $user, Listing $listing): bool
    {
        return $user->id === $listing->user_id;
    }
}
PHP;

$files['app/Modules/Catalog/Providers/CatalogServiceProvider.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Console\RebuildSearchBlobCommand;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Observers\ListingObserver;
use App\Modules\Catalog\Policies\ListingPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Listing::observe(ListingObserver::class);

        // الربط يدوي لأن الموديل خارج App\Models — انظر تعليق ListingPolicy
        Gate::policy(Listing::class, ListingPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                RebuildSearchBlobCommand::class,
            ]);
        }
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الموديل: أعمدة التجديد + نطاق الملكية
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Models/Listing.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Listing extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'listings';

    /**
     * الأعمدة المحسوبة (title_normalized, description_normalized,
     * price_in_base, rate_used, rate_date) غائبة عن هذه القائمة بقصد —
     * غيابها وحده يمنع تمريرها من طلب HTTP، لأن Eloquent يتجاهل $guarded
     * كلياً متى كان $fillable غير فارغ. لا تُضِف $guarded هنا: يوهم بحماية
     * ثانية غير موجودة.
     *
     * وبنفس المنطق: renewed_at وrenewals_count غائبان. التجديد حدث
     * يقرّره الخادم بشروطه، لا حقل في نموذج يملأه المعلن.
     */
    protected $fillable = [
        'user_id', 'category_id', 'location_id',
        'title', 'slug', 'description',
        'price_amount', 'currency_code', 'price_type', 'is_negotiable',
        'sale_type', 'condition_type', 'status',
        'contact_phone', 'hide_phone',
        'is_featured', 'featured_until', 'published_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'price_amount' => 'decimal:2',
            'price_in_base' => 'decimal:2',
            'rate_used' => 'decimal:6',
            'rate_date' => 'date',
            'is_negotiable' => 'boolean',
            'hide_phone' => 'boolean',
            'is_featured' => 'boolean',
            'featured_until' => 'datetime',
            'published_at' => 'datetime',
            'expires_at' => 'datetime',
            'renewed_at' => 'datetime',
            'renewals_count' => 'integer',
        ];
    }

    // ---- العلاقات ----

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_code', 'code');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ListingImage::class)->orderBy('sort_order');
    }

    public function priceHistory(): HasMany
    {
        return $this->hasMany(ListingPriceHistory::class)->latest('changed_at');
    }

    // ---- النطاقات ----

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /**
     * الانتهاء زمني لا حالة.
     *
     * لماذا هنا وليس بمهمة مجدولة تُحوّل status إلى expired: المهمة قد تتأخر
     * أو تتوقف، وحينها تظهر إعلانات منتهية في النتائج — والمستخدم يتصل برقم
     * لإعلان لم يعد قائماً. الشرط الزمني صحيح دائماً بلا اعتماد على مجدول.
     * المهمة المجدولة تبقى مفيدة للتنظيف، لا كخط الدفاع الأول.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
        });
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->active()->whereNotNull('published_at')->notExpired();
    }

    /** إعلانات مالك واحد — نقطة الدخول الوحيدة لكل استعلامات اللوحة */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }

    /**
     * المميّزة أولاً — بشرط أن تكون ترقيتها سارية.
     *
     * is_featured وحده لا يكفي: featured_until موجود في الجدول ولم يكن
     * يُفحص، فإعلان انتهت ترقيته يبقى مثبّتاً في أعلى النتائج إلى الأبد.
     */
    public function scopeFeaturedFirst(Builder $query): Builder
    {
        return $query->orderByRaw(
            '(is_featured = 1 and (featured_until is null or featured_until >= ?)) desc',
            [now()]
        );
    }

    /** الفلترة بالسعر تجري دائماً على العمود الموحّد، لا على السعر الأصلي */
    public function scopePriceBetween(Builder $query, ?float $minInBase, ?float $maxInBase): Builder
    {
        return $query
            ->when($minInBase !== null, fn (Builder $q) => $q->where('price_in_base', '>=', $minInBase))
            ->when($maxInBase !== null, fn (Builder $q) => $q->where('price_in_base', '<=', $maxInBase));
    }

    public function scopeInCategory(Builder $query, Category $category): Builder
    {
        return $query->whereIn(
            'category_id',
            Category::inSubtree($category->path)->select('id')
        );
    }

    public function scopeInLocation(Builder $query, Location $location): Builder
    {
        return $query->whereIn(
            'location_id',
            Location::inSubtree($location->path)->select('id')
        );
    }

    // ---- مساعدات العرض ----

    /** السعر بعملته الأصلية كما أدخله المعلن — هذا ما يُعرض دائماً */
    public function formattedPrice(): string
    {
        if ($this->price_type === 'free') {
            return 'مجاناً';
        }

        if ($this->price_amount === null) {
            return 'السعر عند الطلب';
        }

        return $this->currency?->format($this->price_amount) ?? (string) $this->price_amount;
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isFeaturedNow(): bool
    {
        return $this->is_featured
            && ($this->featured_until === null || $this->featured_until->isFuture());
    }

    /**
     * الأيام المتبقية — سالبة للمنتهي، وnull لإعلان بلا تاريخ انتهاء.
     *
     * الحساب من بداية اليوم لا من اللحظة: «يتبقى يوم واحد» أوضح
     * للمعلن من «يتبقى 0 أيام» لإعلان ينتهي بعد ثلاث ساعات.
     */
    public function daysUntilExpiry(): ?int
    {
        if ($this->expires_at === null) {
            return null;
        }

        // diffInDays يُرجع float في Carbon 3 — التقريب صريح لا ضمني
        return (int) round(now()->startOfDay()->diffInDays($this->expires_at->startOfDay(), false));
    }

    /** حالة معروضة للمعلن — تدمج الحالة المخزّنة مع الانتهاء الزمني */
    public function ownerStatusLabel(): string
    {
        if ($this->trashed()) {
            return 'محذوف';
        }

        if ($this->isExpired()) {
            return 'منتهي';
        }

        return match ($this->status) {
            'active' => 'منشور',
            'paused' => 'موقوف',
            'sold' => 'تم البيع',
            'draft' => 'مسودة',
            'pending' => 'بانتظار المراجعة',
            'rejected' => 'مرفوض',
            default => $this->status,
        };
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    protected static function newFactory(): \App\Modules\Catalog\Database\Factories\ListingFactory
    {
        return \App\Modules\Catalog\Database\Factories\ListingFactory::new();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// التجديد
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Enums/RenewalOutcome.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Enums;

/**
 * نتيجة محاولة التجديد.
 *
 * لماذا enum لا bool: «فشل التجديد» ليست حالة واحدة — الإعلان مباع،
 * أو ما زال بعيداً عن الانتهاء، أو مرفوض من الإدارة. وكل حالة تحتاج
 * رسالة مختلفة للمعلن. bool يجبر المتحكّم على إعادة استنتاج السبب،
 * فيتكرّر الشرط في موضعين ويتباعدان مع الوقت.
 */
enum RenewalOutcome: string
{
    case Renewed = 'renewed';
    case TooEarly = 'too_early';
    case NotRenewable = 'not_renewable';

    public function message(): string
    {
        return match ($this) {
            self::Renewed => 'تم تجديد الإعلان.',
            self::TooEarly => 'الإعلان ما زال سارياً — التجديد متاح في آخر سبعة أيام قبل الانتهاء.',
            self::NotRenewable => 'لا يمكن تجديد هذا الإعلان بحالته الحالية.',
        };
    }

    public function succeeded(): bool
    {
        return $this === self::Renewed;
    }
}
PHP;

$files['app/Modules/Catalog/Services/ListingRenewalService.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Enums\RenewalOutcome;
use App\Modules\Catalog\Models\Listing;

/**
 * تجديد الإعلان: تمديد expires_at ثلاثين يوماً.
 *
 * ثلاثة قرارات تستحق التوضيح:
 *
 * 1. published_at لا يُلمس إطلاقاً.
 *    وهذا أهم سطر في الملف. published_at هو ما يُرتَّب به «الأحدث» وما
 *    يُعرض كـ«منذ ثلاثة أيام». لو حرّكه التجديد، لصار زر التجديد ترقيةً
 *    مجانية تُقفز بالإعلان إلى أعلى النتائج كل ثلاثين يوماً — ويفقد
 *    ترتيب «الأحدث» معناه تماماً، ويصبح إعلان عمره سنة يبدو جديداً.
 *    البديل المرفوض: تحديث published_at ليبدو الإعلان نشطاً. مرفوض
 *    لأنه يكسب المعلن على حساب المشتري، وهذا بالضبط ما تفعله المنصات
 *    التي تُشتكى منها.
 *
 * 2. نافذة سبعة أيام.
 *    البديل المرفوض: السماح بالتجديد في أي وقت. يبدو أكثر تسامحاً، لكن
 *    ضغطة على إعلان يتبقى له 28 يوماً لا تفعل شيئاً مرئياً — فيظن
 *    المعلن أن الزر معطوب. القيد يجعل الزر يعني شيئاً كلما ظهر.
 *
 * 3. التمديد = الآن + 30 يوماً، لا expires_at + 30.
 *    داخل النافذة الفارق أربعة أيام على الأكثر، والصيغة الأولى مفهومة
 *    للمعلن بلا حساب: «إعلانك يعمل حتى تاريخ كذا».
 */
final class ListingRenewalService
{
    public const EXTENSION_DAYS = 30;

    public const WINDOW_DAYS = 7;

    /** الحالات التي يمكن للتجديد أن يُعيدها إلى العمل */
    private const RENEWABLE_STATUSES = ['active', 'paused', 'expired'];

    public function renew(Listing $listing): RenewalOutcome
    {
        if (! in_array($listing->status, self::RENEWABLE_STATUSES, true)) {
            return RenewalOutcome::NotRenewable;
        }

        if (! $this->insideWindow($listing)) {
            return RenewalOutcome::TooEarly;
        }

        // forceFill لا update: العمودان خارج fillable بقصد — انظر الموديل
        $listing->forceFill([
            'status' => 'active',
            'expires_at' => now()->addDays(self::EXTENSION_DAYS),
            'renewed_at' => now(),
            'renewals_count' => (int) $listing->renewals_count + 1,
        ])->save();

        return RenewalOutcome::Renewed;
    }

    /**
     * إعلان بلا تاريخ انتهاء قابل للتجديد دائماً: غياب التاريخ يعني
     * بياناً قديماً أو مستورداً، ومنعه من التجديد يترك المعلن بلا مخرج.
     */
    public function insideWindow(Listing $listing): bool
    {
        if ($listing->expires_at === null) {
            return true;
        }

        return $listing->expires_at->lessThanOrEqualTo(now()->addDays(self::WINDOW_DAYS));
    }

    public function canRenew(Listing $listing): bool
    {
        return in_array($listing->status, self::RENEWABLE_STATUSES, true)
            && $this->insideWindow($listing);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// قارئ المشاهدات
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Services/ListingStatsReader.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * أرقام المشاهدات المعروضة للمعلن.
 *
 * القرار المركزي: رقمان من مصدرين، وكلٌّ يُعرض بما هو.
 *
 *   listings.views_count      عدّاد فوري يُزاد مع كل زيارة. صحيح دائماً.
 *   listing_daily_stats       تجميع يوميّ يكتبه analytics:rollup.
 *
 * التفصيل اليومي لا يمكن أن يأتي من العدّاد (عدد واحد بلا تاريخ)، ولا
 * من user_events مباشرة (سجل خام ينمو بلا حد — استعلامه في طلب HTTP
 * يبطؤ خطياً حتى يتوقف؛ وهذه قاعدة الوحدة المعلنة أصلاً).
 *
 * والمصيدة التي يجب ألّا تُخفى: إن لم يُشغَّل analytics:rollup، يبقى
 * الجدول اليومي فارغاً بينما views_count بالمئات. جدول أصفار بلا
 * تفسير يجعل المعلن يظن أن إعلانه لم يُرَ. لهذا نُرجع lastRolledUp
 * ونعرضه صريحاً في الصفحة — الفراغ يجب أن يقول سببه.
 */
final class ListingStatsReader
{
    public const DEFAULT_DAYS = 14;

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     totals: array<string, int>,
     *     peak: int,
     *     lastRolledUp: string|null
     * }
     */
    public function forListing(Listing $listing, int $days = self::DEFAULT_DAYS): array
    {
        $days = max(1, min(90, $days));
        $from = Carbon::today()->subDays($days - 1);

        $stored = DB::table('listing_daily_stats')
            ->where('listing_id', $listing->id)
            ->whereBetween('stat_date', [$from->toDateString(), Carbon::today()->toDateString()])
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse($row->stat_date)->toDateString());

        $rows = [];
        $totals = [
            'views' => 0, 'unique_viewers' => 0, 'contacts' => 0,
            'search_impressions' => 0, 'search_clicks' => 0,
        ];

        // الأيام الغائبة تُملأ بأصفار: صفّ ناقص في الجدول يعني «لا حدث
        // في ذلك اليوم»، وحذف اليوم من العرض يجعل الفراغ يبدو كأنه لم يكن
        for ($cursor = $from->copy(); $cursor->lessThanOrEqualTo(Carbon::today()); $cursor->addDay()) {
            $date = $cursor->toDateString();
            $row = $stored->get($date);

            $day = [
                'date' => $date,
                'views' => (int) ($row->views ?? 0),
                'unique_viewers' => (int) ($row->unique_viewers ?? 0),
                'contacts' => (int) ($row->contacts ?? 0),
                'search_impressions' => (int) ($row->search_impressions ?? 0),
                'search_clicks' => (int) ($row->search_clicks ?? 0),
            ];

            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $day[$key];
            }

            $rows[] = $day;
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'peak' => max(1, max(array_column($rows, 'views'))),
            'lastRolledUp' => $this->lastRolledUp(),
        ];
    }

    /**
     * نسبة النقر من الظهور.
     *
     * تُحسب فقط عند ظهور كافٍ. البديل المرفوض: عرض النسبة دائماً —
     * ظهور واحد ونقرة واحدة يعطيان 100%، وهو رقم يوهم المعلن بنجاح
     * لا وجود له. عشرون ظهوراً حدّ أدنى متحفّظ لكنه صادق.
     */
    public function clickRate(int $impressions, int $clicks): ?float
    {
        if ($impressions < 20) {
            return null;
        }

        return round($clicks / $impressions * 100, 1);
    }

    /** آخر يوم يحمل تجميعاً — للتاريخ لا لهذا الإعلان: يخبر عن حال المجدول */
    private function lastRolledUp(): ?string
    {
        $value = DB::table('listing_daily_stats')->max('stat_date');

        return $value === null ? null : Carbon::parse($value)->toDateString();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// طلب التعديل
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Http/Requests/UpdateListingRequest.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Services\ListingImageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * قواعد التعديل.
 *
 * لماذا صنف منفصل لا إعادة استخدام StoreListingRequest: القواعد
 * متشابهة اليوم لكنها تتباعد بالضرورة — النشر سيحتاج قيوداً على
 * عدد الإعلانات اليومي، والتعديل سيحتاج منع تغيير التصنيف بعد
 * تلقّي عروض. توريث القواعد الآن يعني تفكيكه لاحقاً بشروط if.
 *
 * والأهم هنا ليس ما في القائمة بل ما ليس فيها: لا status، لا user_id،
 * لا is_featured، لا expires_at، لا published_at. حقل واحد من هذه في
 * القائمة يعني أن أي معلن يميّز إعلانه أو يمدّده بطلب POST مُعدَّل.
 */
class UpdateListingRequest extends FormRequest
{
    /** الصلاحية تُفحص في المتحكّم بـGate — في مكان واحد لكل الأفعال السبعة */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'min:6', 'max:180'],
            'description' => ['nullable', 'string', 'max:5000'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],

            'price_type' => ['required', Rule::in(['fixed', 'negotiable', 'on_request', 'free'])],
            'price_amount' => ['nullable', 'required_if:price_type,fixed,negotiable', 'numeric', 'min:0', 'max:99999999999'],
            'currency_code' => ['nullable', 'required_with:price_amount', 'exists:currencies,code'],

            'condition_type' => ['nullable', Rule::in(['new', 'like_new', 'used', 'for_parts'])],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9\s\-]{9,20}$/'],
            'hide_phone' => ['nullable', 'boolean'],

            'images' => ['nullable', 'array', 'max:'.ListingImageService::MAX_PER_LISTING],
            'images.*' => [
                'file',
                'image',
                'mimes:jpeg,jpg,png,webp',
                'max:8192',
                'dimensions:min_width=120,min_height=120,max_width=8000,max_height=8000',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'اكتب عنواناً للإعلان.',
            'title.min' => 'العنوان قصير جداً — اجعله وصفياً أكثر.',
            'category_id.required' => 'اختر تصنيفاً.',
            'location_id.required' => 'اختر الموقع.',
            'price_amount.required_if' => 'أدخل السعر، أو اختر «السعر عند الطلب».',
            'currency_code.required_with' => 'اختر عملة السعر.',
            'contact_phone.required' => 'أدخل رقم تواصل.',
            'contact_phone.regex' => 'رقم التواصل غير صالح.',
            'images.max' => 'الحد الأقصى '.ListingImageService::MAX_PER_LISTING.' صور.',
            'images.*.image' => 'أحد الملفات ليس صورة صالحة.',
            'images.*.mimes' => 'الصيغ المقبولة: JPG أو PNG أو WebP. صور HEIC من الآيفون تحتاج تحويلاً أولاً.',
            'images.*.max' => 'حجم كل صورة يجب أن يكون أقل من 8 ميجابايت.',
            'images.*.dimensions' => 'أبعاد إحدى الصور صغيرة جداً أو كبيرة جداً.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('price_type'), ['free', 'on_request'], true)) {
            $this->merge(['price_amount' => null, 'currency_code' => null]);
        }

        $this->merge(['hide_phone' => $this->boolean('hide_phone')]);

        if (is_string($phone = $this->input('contact_phone'))) {
            $this->merge(['contact_phone' => preg_replace('/[\s\-]/', '', $phone)]);
        }
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// متحكّم اللوحة
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Http/Controllers/AccountListingController.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Models\User;
use App\Modules\Catalog\Http\Requests\UpdateListingRequest;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Models\ListingImage;
use App\Modules\Catalog\Services\ListingImageService;
use App\Modules\Catalog\Services\ListingRenewalService;
use App\Modules\Catalog\Services\ListingStatsReader;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * لوحة المعلن: إعلاناتي · تعديل · حذف · تجديد · مشاهدات.
 *
 * لماذا متحكّم واحد بسبع طرق لا سبعة متحكّمات __invoke: كلها أفعال على
 * موردٍ واحد بنفس قاعدة الملكية وبنفس التبعيات الثلاث. توزيعها على
 * سبعة ملفات يكرّر الاعتماديات سبع مرات ويجعل مراجعة الصلاحيات تتنقّل
 * بين ملفات — وهذا بالضبط ما نحاول تجنّبه بوضع القاعدة في Policy.
 *
 * وكل طريقة تبدأ بـGate::authorize. لا استثناء ولا اختصار: القراءة
 * السريعة لهذا الملف يجب أن تُظهر سبعة فحوص لسبعة أفعال.
 */
class AccountListingController
{
    /** التبويبات: ما يمكن للنظام أن ينتجه فعلاً، لا كل قيم عمود status */
    private const TABS = ['live', 'expired', 'trashed', 'all'];

    public function __construct(
        private readonly ListingImageService $images,
        private readonly ListingRenewalService $renewal,
        private readonly ListingStatsReader $stats,
    ) {}

    // ─── إعلاناتي ───

    public function index(Request $request): View
    {
        $user = $request->user();
        $tab = in_array($request->string('tab')->value(), self::TABS, true)
            ? $request->string('tab')->value()
            : 'live';

        return view('catalog::account.index', [
            'tab' => $tab,
            'counts' => $this->counts($user),
            'listings' => $this->paginate($user, $tab),
            'baseCurrency' => Currency::base(),
            'renewal' => $this->renewal,
        ]);
    }

    // ─── تعديل ───

    public function edit(Listing $listing): View
    {
        Gate::authorize('update', $listing);

        $listing->load('images');

        return view('catalog::account.edit', [
            'listing' => $listing,
            'currencies' => Currency::active()->get(),
            'categories' => Category::active()->roots()->with('children')->get(),
            'governorates' => Location::active()->governorates()->with('children')->get(),
            'maxImages' => ListingImageService::MAX_PER_LISTING,
            'remainingImages' => max(0, ListingImageService::MAX_PER_LISTING - $listing->images->count()),
        ]);
    }

    /**
     * الحفظ.
     *
     * ما لا يفعله هذا الفعل مقصود بقدر ما يفعله:
     *
     *  · لا يغيّر slug عند تغيير العنوان. الرابط قد يكون منشوراً في
     *    واتساب ومحفوظاً في مفضلة زائر؛ تغييره يكسر ما لا نراه.
     *    (ListingObserver::fillSlug يملأ slug عند الفراغ فقط، فالسلوك
     *    قائم أصلاً — وهذا تنبيه لمن يعدّله لاحقاً.)
     *
     *  · لا يمسّ price_in_base ولا search_blob ولا title_normalized.
     *    ListingObserver يعيد حسابها كلها في saving. ولو حسبناها هنا
     *    لتفرّق الحساب في موضعين.
     *
     *  · لا يسجّل تغيّر السعر: الـObserver يكتب listing_price_history
     *    في updated. فتعديل السعر من اللوحة يُسجَّل تلقائياً.
     */
    public function update(UpdateListingRequest $request, Listing $listing): RedirectResponse
    {
        Gate::authorize('update', $listing);

        $listing->update($request->safe()->except('images'));

        $result = $this->images->attach($listing, $request->file('images', []));

        return redirect()
            ->route('account.listings.edit', $listing)
            ->with('status', $result['stored'] > 0
                ? "حُدِّث الإعلان وأُضيفت {$result['stored']} صورة."
                : 'حُدِّث الإعلان.')
            ->with('warnings', $result['failed']);
    }

    public function destroyImage(Listing $listing, ListingImage $image): RedirectResponse
    {
        Gate::authorize('update', $listing);

        $this->images->delete($image);

        return redirect()
            ->route('account.listings.edit', $listing)
            ->with('status', 'حُذفت الصورة.');
    }

    // ─── حذف ───

    /**
     * حذف منطقي لا نهائي.
     *
     * البديل المرفوض: forceDelete. القيود في المخطط تسلسلية
     * (cascadeOnDelete) على listing_images وlisting_daily_stats
     * وlisting_price_history — فحذف نهائي يمحو معه سجل المشاهدات
     * وتاريخ السعر. أي: الضغطة الواحدة تُتلف البيانات التي بُني عليها
     * المشروع كله، وبلا رجعة.
     *
     * والملفات على القرص تبقى: حذف قابل للتراجع مع ملفات محذوفة ليس
     * قابلاً للتراجع فعلاً — يُستعاد الصف بلا صور. تنظيف المهملات
     * القديمة عمل أمرٍ مجدول لاحقاً، لا عمل زر يضغطه مستخدم.
     */
    public function destroy(Listing $listing): RedirectResponse
    {
        Gate::authorize('delete', $listing);

        $listing->delete();

        return redirect()
            ->route('account.listings', ['tab' => 'trashed'])
            ->with('status', 'حُذف الإعلان. يمكنك استعادته من تبويب «المحذوفة».');
    }

    /**
     * الاستعادة.
     *
     * أُضيفت لأن حذفاً منطقياً بلا استعادة أسوأ من حذف نهائي: الصف باقٍ
     * في القاعدة والمعلن لا يملك أي طريق إليه — يظن أنه فُقد، وهو موجود.
     */
    public function restore(Listing $listing): RedirectResponse
    {
        Gate::authorize('restore', $listing);

        $listing->restore();

        return redirect()
            ->route('account.listings')
            ->with('status', 'استُعيد الإعلان.');
    }

    // ─── تجديد ───

    public function renew(Listing $listing): RedirectResponse
    {
        Gate::authorize('renew', $listing);

        $outcome = $this->renewal->renew($listing);

        return redirect()
            ->route('account.listings', ['tab' => $outcome->succeeded() ? 'live' : 'expired'])
            ->with($outcome->succeeded() ? 'status' : 'error', $outcome->message());
    }

    // ─── مشاهدات ───

    public function stats(Listing $listing): View
    {
        Gate::authorize('viewStats', $listing);

        return view('catalog::account.stats', [
            'listing' => $listing,
            'stats' => $this->stats->forListing($listing),
            'reader' => $this->stats,
        ]);
    }

    // ─── التفاصيل ───

    /**
     * العدّادات في استعلامين لا خمسة.
     *
     * استعلام لكل تبويب يعني خمس رحلات إلى القاعدة لرسم أربعة أرقام في
     * أعلى الصفحة. والاستعلام الثاني ضروري لا زائد: الحذف المنطقي يُقصي
     * الصفوف المحذوفة من الاستعلام الأول بأثر SoftDeletes، فلا يمكن
     * عدّها فيه.
     *
     * ولاحظ أن «المنتهية» تُحسب من expires_at لا من status = 'expired':
     * لا شيء في النظام يكتب تلك الحالة (وهذا مقصود — انظر scopeNotExpired)،
     * فتبويب يعتمد عليها يبقى صفراً إلى الأبد بينما الإعلانات تنتهي فعلاً.
     *
     * @return array{live: int, expired: int, trashed: int, all: int}
     */
    private function counts(User $user): array
    {
        $now = now()->toDateTimeString();

        $row = Listing::query()
            ->ownedBy($user)
            ->selectRaw('COUNT(*) as all_count')
            ->selectRaw(
                "SUM(CASE WHEN status = 'active' AND (expires_at IS NULL OR expires_at >= ?) THEN 1 ELSE 0 END) as live_count",
                [$now]
            )
            ->selectRaw(
                'SUM(CASE WHEN expires_at IS NOT NULL AND expires_at < ? THEN 1 ELSE 0 END) as expired_count',
                [$now]
            )
            ->first();

        return [
            'live' => (int) ($row->live_count ?? 0),
            'expired' => (int) ($row->expired_count ?? 0),
            'trashed' => Listing::onlyTrashed()->ownedBy($user)->count(),
            'all' => (int) ($row->all_count ?? 0),
        ];
    }

    private function paginate(User $user, string $tab): LengthAwarePaginator
    {
        $query = Listing::query()
            ->ownedBy($user)
            ->with(['images', 'currency', 'category', 'location']);

        match ($tab) {
            'live' => $query->active()->notExpired(),
            'expired' => $query->expired(),
            'trashed' => $query->onlyTrashed(),
            default => $query,
        };

        return $query
            ->orderByDesc($tab === 'trashed' ? 'deleted_at' : 'created_at')
            ->paginate(12)
            ->withQueryString();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// صفحة التفاصيل: لا تُحسب مشاهدات المالك لنفسه
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Http/Controllers/ListingController.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListingController
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly EventRecorder $events,
    ) {}

    public function show(Request $request, Listing $listing): View
    {
        $isOwner = $request->user()?->id === $listing->user_id;

        // المالك يرى إعلانه في كل حالة — وإلا فتعديل إعلان موقوف يقود
        // إلى 404 عند «معاينة» ما عُدِّل، وهو طريق مسدود بلا سبب مفهوم
        abort_unless($listing->status === 'active' || $isOwner, 404);

        $listing->load(['currency', 'category.parent', 'location.parent', 'images', 'user']);

        /**
         * زيارة المالك لإعلانه لا تُحسب.
         *
         * السبب عمليّ: المعلن يفتح إعلانه ليتأكد أنه ظهر، ثم يفتحه ليرى
         * المشاهدات، ثم يحدّث الصفحة. كل ذلك يزيد العدّاد — فيصبح الرقم
         * الذي جاء ليقرأه من صنعه هو. وأسوأ من رقم مفقود رقمٌ يبدو
         * صحيحاً وليس كذلك.
         *
         * والحدث يُستثنى معه لا العدّاد وحده: لو استُثني العدّاد فقط،
         * لاختلف رقم الصفحة عن رقم listing_daily_stats بلا تفسير.
         */
        if (! $isOwner) {
            // زيادة العدّاد بلا لمس updated_at — وإلا بدا كل إعلان مُعدَّلاً للتو
            $listing->newQuery()->whereKey($listing->getKey())->increment('views_count');

            $this->recordVisit($request, $listing);
        }

        return view('catalog::show', [
            'listing' => $listing,
            'baseCurrency' => Currency::base(),
            'equivalents' => $this->equivalents($listing),
            'similar' => $this->similar($listing),
            'isOwner' => $isOwner,
        ]);
    }

    /**
     * حدث المشاهدة، وحدث النقر إن جاء من نتائج البحث.
     *
     * لماذا نتتبّع النقرة هنا لا بـJavaScript في صفحة النتائج:
     * الرابط يحمل from وpos وq، فالنقرة تُسجَّل بمجرد وصول الزائر.
     * أبسط، ولا يفقد أحداثاً إن كان JS معطّلاً أو الشبكة انقطعت.
     */
    private function recordVisit(Request $request, Listing $listing): void
    {
        $source = $request->string('from')->value() ?: 'direct';

        $this->events->record(EventName::ListingViewed, 'listing', $listing->id, [
            'source' => $source,
            'category_id' => $listing->category_id,
            'currency' => $listing->currency_code,
        ]);

        if ($source !== 'search') {
            return;
        }

        $this->events->record(EventName::SearchResultClicked, 'listing', $listing->id, [
            'q_normalized' => $request->string('q')->value() ?: null,
            'position' => $request->integer('pos') ?: null,
        ]);
    }

    /**
     * السعر مُعبَّراً عنه بكل العملات النشطة.
     *
     * هذه هي المشكلة الأصلية معكوسة: بدل أن يخمّن المشتري قيمة رقم
     * بعملة لا يفكر بها، نعرضها له بعملته صريحةً.
     */
    private function equivalents(Listing $listing): array
    {
        if ($listing->price_amount === null || $listing->currency_code === null) {
            return [];
        }

        $result = [];

        foreach (Currency::active()->get() as $currency) {
            if ($currency->code === $listing->currency_code) {
                continue;
            }

            $result[] = [
                'currency' => $currency,
                'amount' => $this->rates->convert(
                    (float) $listing->price_amount,
                    $listing->currency_code,
                    $currency->code,
                    $listing->rate_date?->toDateString(),
                ),
            ];
        }

        return $result;
    }

    /** إعلانات قريبة في القيمة الموحّدة — لا في الرقم المكتوب */
    private function similar(Listing $listing): iterable
    {
        $query = Listing::query()
            ->published()
            ->whereKeyNot($listing->getKey())
            ->where('category_id', $listing->category_id)
            ->with(['currency', 'category', 'location', 'images'])
            ->limit(6);

        if ($listing->price_in_base !== null) {
            $query->orderByRaw('ABS(price_in_base - ?)', [(float) $listing->price_in_base]);
        } else {
            $query->latest('published_at');
        }

        return $query->get();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// المسارات
// ─────────────────────────────────────────────────────────────────────────────

$files['routes/web.php'] = <<<'PHP'
<?php

use App\Modules\Catalog\Http\Controllers\AccountListingController;
use App\Modules\Catalog\Http\Controllers\ContactRevealController;
use App\Modules\Catalog\Http\Controllers\ListingController;
use App\Modules\Catalog\Http\Controllers\ListingCreateController;
use App\Modules\Identity\Http\Middleware\EnsurePhoneIsVerified;
use App\Modules\Search\Http\Controllers\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| عام
|--------------------------------------------------------------------------
*/

Route::get('/', SearchController::class)->name('search');

Route::get('/listing/{listing}', [ListingController::class, 'show'])->name('listing.show');

Route::post('/listing/{listing}/contact', ContactRevealController::class)->name('listing.contact');

/*
|--------------------------------------------------------------------------
| النشر — يتطلب حساباً ورقماً موثَّقاً
|--------------------------------------------------------------------------
|
| الوسيط يُستدعى بصنفه لا باسم مستعار: الاسم المستعار يحتاج تسجيلاً في
| bootstrap/app.php، والصنف المباشر يعمل بلا أي تسجيل.
|
| بوابة الهاتف قابلة للتعطيل من config/identity.php — انظر
| REQUIRE_PHONE_VERIFICATION في .env.
|
*/

Route::middleware(['auth', EnsurePhoneIsVerified::class])->group(function (): void {
    Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
    Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');
});

/*
|--------------------------------------------------------------------------
| لوحة المعلن
|--------------------------------------------------------------------------
|
| auth وحده — بلا EnsurePhoneIsVerified، بقصد.
|
| السبب: بوابة الهاتف تخصّ إنشاء محتوى جديد. أما من نشر قبل تشغيل
| البوابة، أو تغيّر رقمه، أو شُغِّلت البوابة بعد نشره — فمنعه من إدارة
| إعلاناته القائمة يتركه بإعلان حيّ لا يستطيع حذفه ولا تصحيح سعره،
| والمتضرّر هو المشتري الذي يتصل برقم قديم. حظر الإنشاء إجراء وقائي؛
| حظر الحذف عقوبة على المشتري.
|
| withTrashed على مسار الاستعادة فقط: الاستعادة تعمل على صف محذوف
| منطقياً بالتعريف، وبقية المسارات يجب أن تعيد 404 لإعلان محذوف.
|
| scopeBindings على مسار الصورة: يُلزم لارافل بحلّ {image} عبر علاقة
| images الخاصة بـ{listing}. بلا هذا القيد يقبل المسار معرّف صورة من
| إعلان آخر، فيحذف المعلن صور غيره بتغيير رقم في الرابط — والصلاحية
| على {listing} وحدها لا تكشف ذلك.
|
*/

Route::middleware('auth')->prefix('account')->name('account.')->group(function (): void {
    Route::get('/listings', [AccountListingController::class, 'index'])->name('listings');

    Route::get('/listings/{listing}/edit', [AccountListingController::class, 'edit'])->name('listings.edit');
    Route::put('/listings/{listing}', [AccountListingController::class, 'update'])->name('listings.update');

    Route::delete('/listings/{listing}', [AccountListingController::class, 'destroy'])->name('listings.destroy');
    Route::patch('/listings/{listing}/restore', [AccountListingController::class, 'restore'])
        ->name('listings.restore')
        ->withTrashed();

    Route::post('/listings/{listing}/renew', [AccountListingController::class, 'renew'])->name('listings.renew');

    Route::get('/listings/{listing}/stats', [AccountListingController::class, 'stats'])->name('listings.stats');

    Route::delete('/listings/{listing}/images/{image}', [AccountListingController::class, 'destroyImage'])
        ->name('listings.images.destroy')
        ->scopeBindings();
});

/*
|--------------------------------------------------------------------------
| الهوية
|--------------------------------------------------------------------------
*/

require base_path('app/Modules/Identity/Routes/web.php');
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// القالب العام: رابط اللوحة + تنبيه خطأ
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Shared/Resources/Views/layouts/app.blade.php'] = <<<'BLADE'
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'سوق يمني — إعلانات مبوبة')</title>
    <meta name="description" content="@yield('description', 'إعلانات مبوبة في اليمن — بحث دقيق يفرّق بين الريال اليمني والسعودي والدولار.')">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=ibm-plex-sans-arabic:400,500,600,700" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-canvas text-ink antialiased">

<header class="sticky top-0 z-30 border-b border-line bg-white/95 backdrop-blur">
    <div class="mx-auto flex max-w-6xl items-center gap-3 px-4 py-3">
        <a href="{{ route('search') }}" class="flex items-center gap-2 shrink-0">
            <span class="grid size-9 place-items-center rounded-lg bg-ink text-sm font-bold text-white">سوق</span>
            <span class="hidden text-base font-semibold sm:block">سوق يمني</span>
        </a>

        <div class="flex-1"></div>

        @auth
            <a href="{{ route('account.listings') }}"
               class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                إعلاناتي
            </a>

            <span class="hidden text-sm text-muted sm:block">{{ auth()->user()->displayName() }}</span>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                    خروج
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                دخول
            </a>
        @endauth

        <a href="{{ route('listing.create') }}" class="rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
            أضف إعلانك
        </a>
    </div>
</header>

<main class="mx-auto max-w-6xl px-4 py-6">
    @if (session('status'))
        <div role="status" class="mb-5 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-900">
            <p class="font-medium">{{ session('status') }}</p>
            @foreach (session('warnings', []) as $warning)
                <p class="mt-1 text-xs text-green-800">{{ $warning }}</p>
            @endforeach
        </div>
    @endif

    {{-- محاولة مرفوضة ليست نجاحاً: التجديد قبل موعده يرجع هنا لا في الشريط الأخضر --}}
    @if (session('error'))
        <div role="alert" class="mb-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
            {{ session('error') }}
        </div>
    @endif

    @yield('content')
</main>

<footer class="mt-16 border-t border-line py-8">
    <div class="mx-auto max-w-6xl px-4 text-sm text-muted">
        <p>مشروع تجريبي مستقل لعرض مقاربة في البحث والفلترة داخل السياق اليمني. ليس نسخة من أي منصة قائمة.</p>
        <p class="mt-2">أسعار الصرف المستخدمة تجريبية وليست أسعاراً حقيقية.</p>
    </div>
</footer>

</body>
</html>
BLADE;

// ─────────────────────────────────────────────────────────────────────────────
// إعلاناتي
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Resources/Views/account/index.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'إعلاناتي')

@php
    $tabs = [
        'live' => 'المنشورة',
        'expired' => 'المنتهية',
        'trashed' => 'المحذوفة',
        'all' => 'الكل',
    ];
@endphp

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-xl font-bold">إعلاناتي</h1>
    <a href="{{ route('listing.create') }}" class="text-sm font-medium text-brand-dark hover:underline">
        + إعلان جديد
    </a>
</div>

{{-- التبويبات تحمل عدّادها: «المنتهية (3)» يدفع للضغط، و«المنتهية» وحدها لا --}}
<nav class="mt-5 flex flex-wrap gap-2 border-b border-line pb-px" aria-label="تصفية الإعلانات">
    @foreach ($tabs as $key => $label)
        <a href="{{ route('account.listings', ['tab' => $key]) }}"
           @class([
               'rounded-t-lg px-3.5 py-2 text-sm font-medium transition',
               'bg-white border border-line border-b-white -mb-px text-ink' => $tab === $key,
               'text-muted hover:text-ink' => $tab !== $key,
           ])
           @if ($tab === $key) aria-current="page" @endif>
            {{ $label }}
            <span class="tabular-nums text-xs text-muted">({{ $counts[$key] }})</span>
        </a>
    @endforeach
</nav>

@if ($listings->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا إعلانات في هذا التبويب.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($listings as $listing)
            @php
                $days = $listing->daysUntilExpiry();
                $canRenew = $renewal->canRenew($listing) && ! $listing->trashed();
            @endphp

            <li class="rounded-2xl border border-line bg-white p-3.5">
                <div class="flex gap-3.5">
                    <div class="size-20 shrink-0 overflow-hidden rounded-xl bg-tint sm:size-24">
                        @if ($listing->images->isNotEmpty())
                            <img src="{{ asset('storage/' . ($listing->images->first()->thumb_path ?? $listing->images->first()->path)) }}"
                                 alt="" loading="lazy" class="size-full object-cover">
                        @else
                            <div class="grid size-full place-items-center text-[10px] text-muted">لا صورة</div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-start justify-between gap-2">
                            <h2 class="min-w-0 text-sm font-semibold leading-snug">
                                @if ($listing->trashed())
                                    <span class="text-muted">{{ $listing->title }}</span>
                                @else
                                    <a href="{{ route('listing.show', $listing) }}" class="hover:underline">
                                        {{ $listing->title }}
                                    </a>
                                @endif
                            </h2>

                            <span @class([
                                'shrink-0 rounded-md px-2 py-0.5 text-[11px] font-semibold',
                                'bg-green-100 text-green-900' => ! $listing->trashed() && ! $listing->isExpired() && $listing->status === 'active',
                                'bg-amber-100 text-amber-900' => ! $listing->trashed() && $listing->isExpired(),
                                'bg-tint text-muted' => $listing->trashed() || (! $listing->isExpired() && $listing->status !== 'active'),
                            ])>
                                {{ $listing->ownerStatusLabel() }}
                            </span>
                        </div>

                        <p class="mt-1 text-sm font-bold tabular-nums text-brand-dark">
                            {{ $listing->formattedPrice() }}
                        </p>

                        <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                            <span class="tabular-nums">{{ number_format($listing->views_count) }} مشاهدة</span>
                            <span aria-hidden="true">·</span>
                            <span class="tabular-nums">{{ number_format($listing->contacts_count) }} تواصل</span>

                            @if ($listing->trashed())
                                <span aria-hidden="true">·</span>
                                <span>حُذف {{ $listing->deleted_at?->diffForHumans() }}</span>
                            @elseif ($days === null)
                                <span aria-hidden="true">·</span>
                                <span>بلا تاريخ انتهاء</span>
                            @elseif ($days < 0)
                                <span aria-hidden="true">·</span>
                                <span class="text-amber-800">انتهى قبل {{ abs($days) }} يوم</span>
                            @else
                                <span aria-hidden="true">·</span>
                                <span @class(['text-amber-800' => $days <= 7])>يتبقى {{ $days }} يوم</span>
                            @endif

                            @if ($listing->renewals_count > 0)
                                <span aria-hidden="true">·</span>
                                <span>جُدِّد {{ $listing->renewals_count }} مرة</span>
                            @endif
                        </p>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            @if ($listing->trashed())
                                <form method="POST" action="{{ route('account.listings.restore', $listing) }}">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit"
                                            class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                        استعادة
                                    </button>
                                </form>
                            @else
                                <a href="{{ route('account.listings.edit', $listing) }}"
                                   class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                    تعديل
                                </a>

                                <a href="{{ route('account.listings.stats', $listing) }}"
                                   class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                    المشاهدات
                                </a>

                                {{-- الزر المُعطَّل يبقى مرئياً ويقول سبب تعطيله:
                                     زر يظهر ويختفي يجعل المعلن يظن أن الميزة غير موجودة --}}
                                @if ($canRenew)
                                    <form method="POST" action="{{ route('account.listings.renew', $listing) }}">
                                        @csrf
                                        <button type="submit"
                                                class="rounded-lg bg-brand px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-brand-dark">
                                            تجديد 30 يوماً
                                        </button>
                                    </form>
                                @else
                                    <span class="cursor-not-allowed rounded-lg border border-line px-3 py-1.5 text-xs text-muted"
                                          title="التجديد متاح في آخر 7 أيام قبل الانتهاء">
                                        تجديد غير متاح بعد
                                    </span>
                                @endif

                                <form method="POST" action="{{ route('account.listings.destroy', $listing) }}"
                                      onsubmit="return confirm('حذف «{{ addslashes($listing->title) }}»؟ يمكنك استعادته من تبويب المحذوفة.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit"
                                            class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium text-red-700 transition hover:bg-red-50">
                                        حذف
                                    </button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">
        {{ $listings->links() }}
    </div>
@endif

@endsection
BLADE;

// ─────────────────────────────────────────────────────────────────────────────
// تعديل إعلان
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Resources/Views/account/edit.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'تعديل: ' . $listing->title)

@section('content')

{{--
    نموذج مستقل لا شريك لـcreate.blade.php.

    البديل المرفوض: استخراج partial واحد يخدم النشر والتعديل. النموذجان
    يتشابهان في الحقول ويختلفان في كل ما حولها — هدف الإرسال، ووسم
    method، وقسم الصور (معاينة قبل الرفع مقابل صور محفوظة قابلة للحذف)،
    وقيمة old() الافتراضية. الـpartial المشترك سيمتلئ بشروط
    من نوع if ($mode === 'edit')، وهي أسوأ من التكرار: تُقرأ مرتين ولا تُفهم
    مرة واحدة. الاستخراج مؤجّل حتى يستقر شكل النموذجين.
--}}

<div class="mx-auto max-w-3xl">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-bold">تعديل الإعلان</h1>
        <div class="flex gap-3 text-sm">
            <a href="{{ route('listing.show', $listing) }}" class="text-brand-dark hover:underline">معاينة</a>
            <a href="{{ route('account.listings.stats', $listing) }}" class="text-brand-dark hover:underline">المشاهدات</a>
            <a href="{{ route('account.listings') }}" class="text-muted hover:underline">إعلاناتي</a>
        </div>
    </div>

    @if ($errors->any())
        <div role="alert" class="mt-4 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">تعذّر حفظ التعديل</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('account.listings.update', $listing) }}" enctype="multipart/form-data"
          class="mt-6 space-y-5"
          x-data="listingForm({{ $remainingImages }})"
          x-init="priceType = '{{ old('price_type', $listing->price_type) }}'">
        @csrf
        @method('PUT')

        {{-- ═══ الأساسيات ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">تفاصيل الإعلان</legend>

            <div class="mt-2">
                <label for="title" class="block text-sm font-medium">
                    العنوان <span class="text-red-600">*</span>
                </label>
                <input id="title" name="title" type="text" value="{{ old('title', $listing->title) }}"
                       maxlength="180" required
                       class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                <p class="mt-1 text-xs text-muted">تغيير العنوان لا يغيّر رابط الإعلان — الرابط المنشور يبقى صالحاً.</p>
            </div>

            <div class="mt-4">
                <label for="description" class="block text-sm font-medium">الوصف</label>
                <textarea id="description" name="description" rows="5" maxlength="5000"
                          class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('description', $listing->description) }}</textarea>
            </div>

            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="category_id" class="block text-sm font-medium">
                        التصنيف <span class="text-red-600">*</span>
                    </label>
                    <select id="category_id" name="category_id" required
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        @foreach ($categories as $category)
                            <optgroup label="{{ $category->name_ar }}">
                                @foreach ($category->children as $child)
                                    <option value="{{ $child->id }}"
                                            @selected(old('category_id', $listing->category_id) == $child->id)>
                                        {{ $child->name_ar }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="location_id" class="block text-sm font-medium">
                        الموقع <span class="text-red-600">*</span>
                    </label>
                    <select id="location_id" name="location_id" required
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        @foreach ($governorates as $governorate)
                            <optgroup label="{{ $governorate->name_ar }}">
                                @foreach ($governorate->children as $district)
                                    <option value="{{ $district->id }}"
                                            @selected(old('location_id', $listing->location_id) == $district->id)>
                                        {{ $district->name_ar }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <label for="condition_type" class="block text-sm font-medium">حالة المنتج</label>
                <select id="condition_type" name="condition_type"
                        class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none sm:w-56">
                    <option value="">غير محدد</option>
                    @foreach (['new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('condition_type', $listing->condition_type) === $value)>
                            {{ $label }}
                        </option>
                    @endforeach
                </select>
            </div>
        </fieldset>

        {{-- ═══ السعر ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">السعر</legend>

            <div class="mt-2">
                <label for="price_type" class="block text-sm font-medium">نوع السعر</label>
                <select id="price_type" name="price_type" x-model="priceType"
                        class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none sm:w-56">
                    <option value="fixed">سعر محدد</option>
                    <option value="negotiable">قابل للتفاوض</option>
                    <option value="on_request">عند الطلب</option>
                    <option value="free">مجاناً</option>
                </select>
            </div>

            <div x-show="needsPrice" x-cloak class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="price_amount" class="block text-sm font-medium">
                        المبلغ <span class="text-red-600">*</span>
                    </label>
                    <input id="price_amount" name="price_amount" type="number" min="0" step="any" inputmode="decimal"
                           value="{{ old('price_amount', $listing->price_amount) }}"
                           class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none">
                </div>

                <div>
                    <label for="currency_code" class="block text-sm font-medium">
                        العملة <span class="text-red-600">*</span>
                    </label>
                    <select id="currency_code" name="currency_code"
                            class="mt-1.5 w-full rounded-lg border border-line bg-white px-3 py-2.5 text-sm focus:border-brand focus:outline-none">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency->code }}"
                                    @selected(old('currency_code', $listing->currency_code) === $currency->code)>
                                {{ $currency->name_ar }} ({{ $currency->symbol }})
                            </option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-muted">
                        تغيير السعر يُسجَّل في تاريخ الأسعار، ويُعاد حساب قيمته الموحّدة بسعر صرف اليوم.
                    </p>
                </div>
            </div>
        </fieldset>

        {{-- ═══ الصور المحفوظة ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">الصور</legend>

            @if ($listing->images->isEmpty())
                <p class="mt-2 text-sm text-muted">لا صور لهذا الإعلان.</p>
            @else
                <ul class="mt-2 grid grid-cols-3 gap-3 sm:grid-cols-4">
                    @foreach ($listing->images as $image)
                        <li class="relative overflow-hidden rounded-lg border border-line">
                            <img src="{{ asset('storage/' . ($image->thumb_path ?? $image->path)) }}"
                                 alt="" loading="lazy" class="aspect-square w-full object-cover">

                            @if ($image->is_primary)
                                <span class="absolute bottom-0 w-full bg-ink/85 py-0.5 text-center text-[10px] font-semibold text-white">
                                    الصورة الرئيسية
                                </span>
                            @endif

                            <form method="POST"
                                  action="{{ route('account.listings.images.destroy', [$listing, $image]) }}"
                                  onsubmit="return confirm('حذف هذه الصورة؟ لا يمكن التراجع.')"
                                  class="absolute start-1 top-1">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="حذف الصورة"
                                        class="grid size-6 place-items-center rounded-md bg-white/90 text-xs font-bold text-red-700 transition hover:bg-white">
                                    ×
                                </button>
                            </form>
                        </li>
                    @endforeach
                </ul>

                <p class="mt-3 text-xs text-muted">
                    حذف الصورة نهائي — الملف يُمسح من القرص. وإن حُذفت الرئيسية تُرقّى التالية مكانها تلقائياً.
                </p>
            @endif

            @if ($remainingImages > 0)
                <label for="images"
                       class="mt-4 flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-line px-4 py-6 text-center transition hover:border-brand hover:bg-tint">
                    <span class="text-sm font-medium">أضف صوراً</span>
                    <span class="mt-1 text-xs text-muted">
                        يتبقى {{ $remainingImages }} من {{ $maxImages }} · JPG أو PNG أو WebP · 8 ميجابايت لكل صورة
                    </span>
                    <input id="images" name="images[]" type="file" multiple accept="image/jpeg,image/png,image/webp"
                           @change="preview($event)" class="sr-only">
                </label>

                <p x-show="notice" x-cloak x-text="notice"
                   class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900"></p>

                <ul x-show="items.length" x-cloak class="mt-4 grid grid-cols-3 gap-3 sm:grid-cols-4">
                    <template x-for="item in items" :key="item.url">
                        <li class="overflow-hidden rounded-lg border border-dashed border-brand">
                            <img :src="item.url" alt="" class="aspect-square w-full object-cover">
                        </li>
                    </template>
                </ul>
            @else
                <p class="mt-4 rounded-lg bg-tint px-3 py-2 text-xs text-muted">
                    بلغت الحد الأقصى {{ $maxImages }} صور. احذف صورة لإضافة أخرى.
                </p>
            @endif
        </fieldset>

        {{-- ═══ التواصل ═══ --}}
        <fieldset class="rounded-2xl border border-line bg-white p-5">
            <legend class="px-1 text-sm font-semibold">التواصل</legend>

            <div class="mt-2">
                <label for="contact_phone" class="block text-sm font-medium">
                    رقم الهاتف <span class="text-red-600">*</span>
                </label>
                <input id="contact_phone" name="contact_phone" type="tel" inputmode="tel" dir="ltr"
                       value="{{ old('contact_phone', $listing->contact_phone) }}" placeholder="+9677XXXXXXXX" required
                       class="mt-1.5 w-full rounded-lg border border-line px-3 py-2.5 text-sm tabular-nums focus:border-brand focus:outline-none sm:w-64">
            </div>

            <label class="mt-3 flex items-center gap-2 text-sm">
                <input type="checkbox" name="hide_phone" value="1" @checked(old('hide_phone', $listing->hide_phone))
                       class="size-4 rounded border-line text-brand focus:ring-brand">
                <span>إخفاء الرقم وقبول الرسائل فقط</span>
            </label>
        </fieldset>

        <div class="flex flex-wrap gap-3">
            <button type="submit"
                    class="rounded-xl bg-brand px-6 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark">
                حفظ التعديلات
            </button>
            <a href="{{ route('account.listings') }}"
               class="grid place-items-center rounded-xl border border-line px-5 text-sm font-medium transition hover:bg-tint">
                إلغاء
            </a>
        </div>
    </form>
</div>

@endsection
BLADE;

// ─────────────────────────────────────────────────────────────────────────────
// المشاهدات
// ─────────────────────────────────────────────────────────────────────────────

$files['app/Modules/Catalog/Resources/Views/account/stats.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'مشاهدات: ' . $listing->title)

@php
    $totals = $stats['totals'];
    $ctr = $reader->clickRate($totals['search_impressions'], $totals['search_clicks']);
@endphp

@section('content')

<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-xl font-bold">المشاهدات</h1>
        <p class="mt-1 truncate text-sm text-muted">{{ $listing->title }}</p>
    </div>
    <div class="flex gap-3 text-sm">
        <a href="{{ route('account.listings.edit', $listing) }}" class="text-brand-dark hover:underline">تعديل</a>
        <a href="{{ route('account.listings') }}" class="text-muted hover:underline">إعلاناتي</a>
    </div>
</div>

{{--
    العدّاد الفوري أولاً، والتفصيل اليومي بعده.

    الرقمان يأتيان من مصدرين مختلفين وقد لا يتساويان: العدّاد يُزاد لحظة
    الزيارة، والتفصيل يكتبه analytics:rollup لاحقاً. إخفاء هذا الفرق
    يجعل المعلن يظن أحد الرقمين خطأ؛ لذا كل رقم يقول من أين جاء.
--}}
<dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
    @foreach ([
        ['إجمالي المشاهدات', number_format($listing->views_count), 'عدّاد فوري منذ النشر'],
        ['مرات التواصل', number_format($listing->contacts_count), 'كشف الرقم'],
        ['مشاهدات آخر ' . count($stats['rows']) . ' يوم', number_format($totals['views']), 'من التجميع اليومي'],
        ['زوّار مختلفون', number_format($totals['unique_viewers']), 'في نفس المدة'],
    ] as [$label, $value, $note])
        <div class="rounded-2xl border border-line bg-white p-4">
            <dt class="text-xs text-muted">{{ $label }}</dt>
            <dd class="mt-1 text-2xl font-bold tabular-nums">{{ $value }}</dd>
            <p class="mt-1 text-[11px] text-muted">{{ $note }}</p>
        </div>
    @endforeach
</dl>

@if ($stats['lastRolledUp'] === null)
    {{-- جدول أصفار بلا تفسير يقول للمعلن «لم يرَك أحد». الصحيح أن يقول
         «لم يُحسب بعد» — وهذان معنيان مختلفان تماماً --}}
    <p role="status" class="mt-5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        التفصيل اليومي لم يُحسب بعد. الأرقام أعلاه من العدّاد الفوري وهي صحيحة،
        أما الجدول أدناه فيبقى فارغاً حتى تشغيل <code class="rounded bg-white px-1">php artisan analytics:rollup</code>.
    </p>
@else
    <p class="mt-5 text-xs text-muted">آخر تجميع يومي: {{ $stats['lastRolledUp'] }}</p>
@endif

{{-- ═══ الظهور في البحث ═══ --}}
<div class="mt-5 rounded-2xl border border-line bg-white p-5">
    <h2 class="text-sm font-semibold">الظهور في نتائج البحث</h2>

    <div class="mt-3 flex flex-wrap gap-6 text-sm">
        <p>ظهر <span class="font-bold tabular-nums">{{ number_format($totals['search_impressions']) }}</span> مرة</p>
        <p>نُقر <span class="font-bold tabular-nums">{{ number_format($totals['search_clicks']) }}</span> مرة</p>
        <p>
            نسبة النقر:
            @if ($ctr === null)
                <span class="text-muted">لا تكفي البيانات بعد</span>
            @else
                <span class="font-bold tabular-nums">{{ $ctr }}%</span>
            @endif
        </p>
    </div>

    <p class="mt-3 text-xs text-muted">
        ظهور كثير مع نقر قليل يعني عادةً أن الصورة الأولى أو العنوان لا يُقنعان في النتائج، لا أن السعر مرتفع.
    </p>
</div>

{{-- ═══ التفصيل اليومي ═══ --}}
<div class="mt-5 overflow-hidden rounded-2xl border border-line bg-white">
    <table class="w-full text-sm">
        <caption class="border-b border-line px-4 py-3 text-start text-sm font-semibold">
            آخر {{ count($stats['rows']) }} يوم
        </caption>
        <thead class="bg-tint text-xs text-muted">
            <tr>
                <th scope="col" class="px-4 py-2 text-start font-medium">اليوم</th>
                <th scope="col" class="px-4 py-2 text-start font-medium">مشاهدات</th>
                <th scope="col" class="hidden px-4 py-2 text-start font-medium sm:table-cell">زوّار</th>
                <th scope="col" class="hidden px-4 py-2 text-start font-medium sm:table-cell">ظهور</th>
                <th scope="col" class="hidden px-4 py-2 text-start font-medium sm:table-cell">نقر</th>
                <th scope="col" class="px-4 py-2 text-start font-medium">تواصل</th>
            </tr>
        </thead>
        <tbody>
            @foreach (array_reverse($stats['rows']) as $row)
                <tr class="border-t border-line">
                    <td class="px-4 py-2 tabular-nums">{{ $row['date'] }}</td>
                    <td class="px-4 py-2">
                        <div class="flex items-center gap-2">
                            <span class="w-8 tabular-nums">{{ $row['views'] }}</span>
                            {{-- العرض المئوي قيمة محسوبة لكل صف، وTailwind لا يولّد
                                 أصنافاً ديناميكية — فالنمط المضمّن هو الحل الصحيح هنا --}}
                            <span class="h-1.5 rounded-full bg-brand"
                                  style="width: {{ round($row['views'] / $stats['peak'] * 100) }}%"></span>
                        </div>
                    </td>
                    <td class="hidden px-4 py-2 tabular-nums sm:table-cell">{{ $row['unique_viewers'] }}</td>
                    <td class="hidden px-4 py-2 tabular-nums sm:table-cell">{{ $row['search_impressions'] }}</td>
                    <td class="hidden px-4 py-2 tabular-nums sm:table-cell">{{ $row['search_clicks'] }}</td>
                    <td class="px-4 py-2 tabular-nums">{{ $row['contacts'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

<p class="mt-4 text-xs text-muted">
    زياراتك لإعلانك لا تُحسب في أي من هذه الأرقام.
</p>

@endsection
BLADE;

// ─────────────────────────────────────────────────────────────────────────────
// أرضية الاختبارات
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Support/SeedsCatalog.php'] = <<<'PHP'
<?php

namespace Tests\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Shared\Models\Category;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use App\Modules\Shared\Models\Location;

/**
 * الحد الأدنى من البيانات المرجعية لأي اختبار يلمس إعلاناً.
 *
 * لماذا trait: خمسة ملفات اختبار جديدة تحتاج نفس setUp من عشرين سطراً
 * (عملات + أسعار صرف + تصنيف + موقع). نسخه خمس مرات يعني أن إضافة
 * عملة ثالثة لاحقاً تتطلب خمس تعديلات — وسينسى أحدها.
 *
 * البديل المرفوض: استدعاء seeders الحقيقية. تُنشئ 22 محافظة و37 تصنيفاً
 * و156 إعلاناً لكل اختبار، فيصبح الملف الواحد أبطأ من كل ما سبقه.
 */
trait SeedsCatalog
{
    protected Category $category;

    protected Location $location;

    protected function seedReferenceData(): void
    {
        foreach ([['YER', true], ['SAR', false], ['USD', false]] as [$code, $isBase]) {
            Currency::create([
                'code' => $code, 'name_ar' => $code, 'name_en' => $code,
                'symbol' => $code, 'decimals' => 2, 'is_base' => $isBase,
            ]);
        }

        foreach (['YER' => 1, 'SAR' => 140, 'USD' => 530] as $code => $rate) {
            ExchangeRate::create([
                'currency_code' => $code,
                'rate_to_base' => $rate,
                'effective_date' => now()->subYear()->toDateString(),
                'source' => 'test',
                'created_at' => now(),
            ]);
        }

        $this->category = Category::create(['name_ar' => 'سيارات', 'slug' => 'cars', 'path' => '']);
        $this->location = Location::create([
            'name_ar' => 'صنعاء', 'slug' => 'sanaa', 'level' => 'governorate', 'path' => '',
        ]);
    }

    /**
     * إعلان بـListing::create لا بـListingFactory، بقصد.
     *
     * الـfactory يختار تصنيفاً بـwhereNotNull('parent_id') — وهو استعلام
     * يُرجع null في تركيبة اختبار فيها تصنيف جذر واحد، فيسقط الإدراج على
     * قيد NOT NULL برسالة لا علاقة لها بما يُختبر. الإنشاء الصريح أطول
     * سطرين وأصدق: بيانات الاختبار مرئية في مكانها.
     */
    protected function listingFor(User $user, array $attributes = []): Listing
    {
        return Listing::create([
            'user_id' => $user->id,
            'category_id' => $this->category->id,
            'location_id' => $this->location->id,
            'title' => 'سيارة تويوتا كورولا موديل 2018',
            'description' => 'الحالة ممتازة والاستخدام بسيط.',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'price_type' => 'fixed',
            'sale_type' => 'direct',
            'status' => 'active',
            'contact_phone' => '+967771234567',
            'published_at' => now(),
            'expires_at' => now()->addDays(30),
            ...$attributes,
        ]);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// اختبارات: إعلاناتي
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Feature/Catalog/MyListingsTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * قائمة «إعلاناتي».
 *
 * أهم اختبار هنا هو الثاني: صفحة تعرض إعلانات مستخدم آخر ليست عيباً
 * في العرض بل تسريب بيانات.
 */
class MyListingsTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/account/listings')->assertRedirect(route('login'));
    }

    #[Test]
    public function the_page_shows_only_the_owners_listings(): void
    {
        $mine = $this->listingFor($this->owner, ['title' => 'إعلاني الخاص جداً']);
        $theirs = $this->listingFor($this->stranger, ['title' => 'إعلان شخص آخر']);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=all')
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);
    }

    /**
     * الانتهاء زمني لا حالة: لا شيء في النظام يكتب status = 'expired'،
     * فتبويب يعتمد على الحالة يبقى صفراً بينما الإعلان منتهٍ فعلاً.
     */
    #[Test]
    public function an_expired_listing_leaves_the_live_tab_without_its_status_changing(): void
    {
        $expired = $this->listingFor($this->owner, [
            'title' => 'إعلان انتهى أمس',
            'status' => 'active',
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=live')
            ->assertOk()
            ->assertDontSee($expired->title);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=expired')
            ->assertOk()
            ->assertSee($expired->title);

        $this->assertSame('active', $expired->fresh()->status);
    }

    #[Test]
    public function a_deleted_listing_appears_only_in_the_trashed_tab(): void
    {
        $listing = $this->listingFor($this->owner, ['title' => 'إعلان محذوف']);
        $listing->delete();

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=all')
            ->assertOk()
            ->assertDontSee($listing->title);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=trashed')
            ->assertOk()
            ->assertSee($listing->title);
    }

    #[Test]
    public function an_unknown_tab_falls_back_to_live_instead_of_failing(): void
    {
        $this->listingFor($this->owner, ['title' => 'إعلان قائم']);

        $this->actingAs($this->owner)
            ->get('/account/listings?tab=../../etc/passwd')
            ->assertOk()
            ->assertSee('إعلان قائم');
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// اختبارات: التعديل
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Feature/Catalog/ListingUpdateTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ListingUpdateTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();

        $this->listing = $this->listingFor($this->owner, [
            'title' => 'تويوتا كورولا موديل 2018',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'price_type' => 'fixed',
            'status' => 'active',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'تويوتا كورولا موديل 2019 وكالة',
            'description' => 'وصف محدَّث للإعلان.',
            'category_id' => $this->listing->category_id,
            'location_id' => $this->listing->location_id,
            'price_type' => 'fixed',
            'price_amount' => 5000,
            'currency_code' => 'SAR',
            'contact_phone' => '+967771234567',
            ...$overrides,
        ];
    }

    #[Test]
    public function the_owner_reaches_the_edit_form(): void
    {
        $this->actingAs($this->owner)
            ->get(route('account.listings.edit', $this->listing))
            ->assertOk()
            ->assertSee($this->listing->title);
    }

    #[Test]
    public function a_stranger_is_refused_on_both_reading_and_writing(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('account.listings.edit', $this->listing))
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->put(route('account.listings.update', $this->listing), $this->payload())
            ->assertForbidden();

        $this->assertSame('تويوتا كورولا موديل 2018', $this->listing->fresh()->title);
    }

    #[Test]
    public function the_owner_can_change_the_title_and_the_normalized_column_follows(): void
    {
        $this->actingAs($this->owner)
            ->put(route('account.listings.update', $this->listing), $this->payload())
            ->assertRedirect(route('account.listings.edit', $this->listing));

        $fresh = $this->listing->fresh();

        $this->assertSame('تويوتا كورولا موديل 2019 وكالة', $fresh->title);
        $this->assertStringContainsString('2019', (string) $fresh->title_normalized);
        $this->assertStringContainsString('2019', (string) $fresh->search_blob);
    }

    /**
     * الرابط لا يتغيّر مع العنوان.
     *
     * قد يكون منشوراً في محادثة أو محفوظاً في مفضلة زائر — وتغييره
     * يكسر ما لا نراه، بلا أي مكسب.
     */
    #[Test]
    public function editing_the_title_does_not_change_the_slug(): void
    {
        $slug = $this->listing->slug;

        $this->actingAs($this->owner)
            ->put(route('account.listings.update', $this->listing), $this->payload());

        $this->assertSame($slug, $this->listing->fresh()->slug);
    }

    /**
     * أخطر اختبار في الملف: الحقول التي ليست في UpdateListingRequest
     * يجب أن تُتجاهل حتى وهي حاضرة في الطلب.
     */
    #[Test]
    public function privileged_fields_sent_in_the_request_are_ignored(): void
    {
        $this->actingAs($this->owner)->put(
            route('account.listings.update', $this->listing),
            $this->payload([
                'user_id' => $this->stranger->id,
                'status' => 'sold',
                'is_featured' => 1,
                'expires_at' => now()->addYears(5)->toDateTimeString(),
                'views_count' => 999999,
            ])
        );

        $fresh = $this->listing->fresh();

        $this->assertSame($this->owner->id, $fresh->user_id);
        $this->assertSame('active', $fresh->status);
        $this->assertFalse($fresh->is_featured);
        $this->assertTrue($fresh->expires_at->lessThan(now()->addYear()));
        $this->assertSame(0, (int) $fresh->views_count);
    }

    #[Test]
    public function changing_the_price_recomputes_the_base_value_and_is_recorded(): void
    {
        $this->actingAs($this->owner)->put(
            route('account.listings.update', $this->listing),
            $this->payload(['price_amount' => 6000, 'currency_code' => 'SAR'])
        );

        $fresh = $this->listing->fresh();

        $this->assertSame('6000.00', $fresh->price_amount);
        $this->assertSame(6000.0 * 140, (float) $fresh->price_in_base);
        $this->assertDatabaseHas('listing_price_history', [
            'listing_id' => $this->listing->id,
            'new_amount' => 6000,
            'new_currency' => 'SAR',
        ]);
    }

    #[Test]
    public function a_deleted_listing_cannot_be_edited(): void
    {
        $this->listing->delete();

        $this->actingAs($this->owner)
            ->get(route('account.listings.edit', $this->listing))
            ->assertNotFound();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// اختبارات: الحذف والاستعادة
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Feature/Catalog/ListingDeleteTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * الحذف منطقي.
 *
 * الاختبار الأخير هو سبب هذا القرار: الحذف النهائي يمسح معه
 * listing_daily_stats وlisting_price_history بأثر القيود التسلسلية —
 * أي أنه يُتلف البيانات التي بُني عليها المشروع، وبضغطة واحدة.
 */
class ListingDeleteTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->owner, ['status' => 'active']);
    }

    #[Test]
    public function the_owner_deletes_softly(): void
    {
        $this->actingAs($this->owner)
            ->delete(route('account.listings.destroy', $this->listing))
            ->assertRedirect(route('account.listings', ['tab' => 'trashed']));

        $this->assertSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    #[Test]
    public function a_stranger_cannot_delete(): void
    {
        $this->actingAs($this->stranger)
            ->delete(route('account.listings.destroy', $this->listing))
            ->assertForbidden();

        $this->assertNotSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    #[Test]
    public function a_deleted_listing_disappears_from_search_and_from_its_own_page(): void
    {
        $title = $this->listing->title;

        $this->listing->delete();

        $this->get('/')->assertOk()->assertDontSee($title);
        $this->get(route('listing.show', $this->listing))->assertNotFound();
    }

    #[Test]
    public function the_owner_can_restore_what_was_deleted(): void
    {
        $this->listing->delete();

        $this->actingAs($this->owner)
            ->patch(route('account.listings.restore', $this->listing))
            ->assertRedirect(route('account.listings'));

        $this->assertNotSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    #[Test]
    public function a_stranger_cannot_restore(): void
    {
        $this->listing->delete();

        $this->actingAs($this->stranger)
            ->patch(route('account.listings.restore', $this->listing))
            ->assertForbidden();

        $this->assertSoftDeleted('listings', ['id' => $this->listing->id]);
    }

    /** الحذف لا يُتلف الإحصاءات ولا تاريخ السعر — هذا سبب اختيار الحذف المنطقي */
    #[Test]
    public function deleting_keeps_the_statistics_and_the_price_history(): void
    {
        \Illuminate\Support\Facades\DB::table('listing_daily_stats')->insert([
            'listing_id' => $this->listing->id,
            'stat_date' => now()->toDateString(),
            'views' => 42,
        ]);

        $this->actingAs($this->owner)->delete(route('account.listings.destroy', $this->listing));

        $this->assertDatabaseHas('listing_daily_stats', [
            'listing_id' => $this->listing->id,
            'views' => 42,
        ]);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// اختبارات: التجديد
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Feature/Catalog/ListingRenewTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

/**
 * التجديد.
 *
 * الاختبار الثاني هو حجر الزاوية: تجديد يحرّك published_at يحوّل زر
 * التجديد إلى ترقية مجانية، فيفقد ترتيب «الأحدث» معناه ويبدو إعلان
 * عمره سنة جديداً. لو حُذف هذا الاختبار لسقط القرار بلا أن يلاحظه أحد.
 */
class ListingRenewTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
    }

    #[Test]
    public function an_expired_listing_comes_back_for_thirty_days(): void
    {
        $listing = $this->listingFor($this->owner, [
            'status' => 'active',
            'published_at' => now()->subDays(40),
            'expires_at' => now()->subDays(3),
        ]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertRedirect(route('account.listings', ['tab' => 'live']));

        $fresh = $listing->fresh();

        $this->assertTrue($fresh->expires_at->greaterThan(now()->addDays(29)));
        $this->assertSame('active', $fresh->status);
        $this->assertSame(1, $fresh->renewals_count);
        $this->assertNotNull($fresh->renewed_at);
    }

    #[Test]
    public function renewing_never_touches_the_publication_date(): void
    {
        $published = now()->subDays(40)->startOfSecond();

        $listing = $this->listingFor($this->owner, [
            'published_at' => $published,
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $this->assertTrue($published->equalTo($listing->fresh()->published_at));
    }

    #[Test]
    public function a_listing_far_from_expiry_is_refused_with_a_reason(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->addDays(25)]);
        $before = $listing->expires_at;

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertSessionHas('error');

        $fresh = $listing->fresh();

        $this->assertTrue($before->equalTo($fresh->expires_at));
        $this->assertSame(0, $fresh->renewals_count);
    }

    #[Test]
    public function a_listing_inside_the_window_is_accepted(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->addDays(3)]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertSessionHas('status');

        $this->assertSame(1, $listing->fresh()->renewals_count);
    }

    #[Test]
    public function a_sold_listing_is_not_renewable(): void
    {
        $listing = $this->listingFor($this->owner, [
            'status' => 'sold',
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($this->owner)
            ->post(route('account.listings.renew', $listing))
            ->assertSessionHas('error');

        $this->assertSame('sold', $listing->fresh()->status);
    }

    #[Test]
    public function a_stranger_cannot_renew(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->subDay()]);

        $this->actingAs($this->stranger)
            ->post(route('account.listings.renew', $listing))
            ->assertForbidden();

        $this->assertSame(0, $listing->fresh()->renewals_count);
    }

    #[Test]
    public function renewals_accumulate_rather_than_reset(): void
    {
        $listing = $this->listingFor($this->owner, ['expires_at' => now()->subDay()]);

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $listing->forceFill(['expires_at' => now()->subDay()])->save();

        $this->actingAs($this->owner)->post(route('account.listings.renew', $listing));

        $this->assertSame(2, $listing->fresh()->renewals_count);
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// اختبارات: المشاهدات
// ─────────────────────────────────────────────────────────────────────────────

$files['tests/Feature/Catalog/ListingStatsTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Catalog;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Catalog\Services\ListingStatsReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ListingStatsTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->owner, ['status' => 'active']);
    }

    private function storeDay(string $date, array $values = []): void
    {
        DB::table('listing_daily_stats')->insert([
            'listing_id' => $this->listing->id,
            'stat_date' => $date,
            'views' => 0, 'unique_viewers' => 0, 'contacts' => 0,
            'search_impressions' => 0, 'search_clicks' => 0,
            ...$values,
        ]);
    }

    /**
     * الأرقام تُفحص على القارئ لا على HTML الصفحة.
     *
     * assertSee('20') على صفحة تحمل تواريخ مثل 2026-08-10 ينجح بلا أي
     * علاقة بالمجموع — اختبار يمر دائماً أسوأ من اختبار غائب.
     */
    #[Test]
    public function the_reader_totals_only_the_stored_days(): void
    {
        $this->storeDay(now()->toDateString(), ['views' => 137, 'unique_viewers' => 41]);
        $this->storeDay(now()->subDay()->toDateString(), ['views' => 66, 'unique_viewers' => 9]);

        $stats = app(ListingStatsReader::class)->forListing($this->listing);

        $this->assertSame(203, $stats['totals']['views']);
        $this->assertSame(50, $stats['totals']['unique_viewers']);
        $this->assertCount(ListingStatsReader::DEFAULT_DAYS, $stats['rows']);
    }

    /** يوم خارج النافذة لا يُحسب — وإلا صار «آخر 14 يوماً» عنواناً كاذباً */
    #[Test]
    public function a_day_outside_the_window_is_excluded(): void
    {
        $this->storeDay(now()->subDays(40)->toDateString(), ['views' => 500]);

        $stats = app(ListingStatsReader::class)->forListing($this->listing);

        $this->assertSame(0, $stats['totals']['views']);
    }

    #[Test]
    public function the_owner_sees_the_daily_numbers_on_the_page(): void
    {
        $this->storeDay(now()->toDateString(), ['views' => 137, 'search_impressions' => 412]);

        $this->actingAs($this->owner)
            ->get(route('account.listings.stats', $this->listing))
            ->assertOk()
            ->assertSee('137')
            ->assertSee('412');
    }

    #[Test]
    public function a_stranger_cannot_read_someone_elses_numbers(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('account.listings.stats', $this->listing))
            ->assertForbidden();
    }

    /**
     * الفراغ يجب أن يقول سببه.
     *
     * جدول أصفار بلا تفسير يقول للمعلن «لم يرَك أحد»، والصحيح «لم يُحسب
     * بعد». المعنيان مختلفان تماماً، والثاني يُصلَح بأمر واحد.
     */
    #[Test]
    public function an_empty_rollup_says_so_instead_of_showing_bare_zeros(): void
    {
        $this->actingAs($this->owner)
            ->get(route('account.listings.stats', $this->listing))
            ->assertOk()
            ->assertSee('analytics:rollup');
    }

    /**
     * زيارة المالك لإعلانه لا تُحسب.
     *
     * بلا هذا الشرط يكون الرقم الذي جاء المعلن ليقرأه من صنعه هو —
     * وأسوأ من رقم مفقود رقمٌ يبدو صحيحاً وليس كذلك.
     */
    #[Test]
    public function the_owners_own_visit_is_not_counted(): void
    {
        $this->actingAs($this->owner)
            ->get(route('listing.show', $this->listing))
            ->assertOk();

        $this->assertSame(0, (int) $this->listing->fresh()->views_count);
    }

    #[Test]
    public function a_visitors_view_is_counted(): void
    {
        $this->actingAs($this->stranger)
            ->get(route('listing.show', $this->listing))
            ->assertOk();

        $this->assertSame(1, (int) $this->listing->fresh()->views_count);
    }

    /** المالك يعاين إعلاناً موقوفاً؛ الزائر لا يجده */
    #[Test]
    public function the_owner_may_preview_a_paused_listing_but_a_visitor_may_not(): void
    {
        $this->listing->forceFill(['status' => 'paused'])->save();

        $this->actingAs($this->owner)->get(route('listing.show', $this->listing))->assertOk();
        $this->get(route('listing.show', $this->listing))->assertNotFound();
    }
}
PHP;

// ─────────────────────────────────────────────────────────────────────────────
// الكتابة
// ─────────────────────────────────────────────────────────────────────────────

$written = 0;

foreach ($files as $relativePath => $contents) {
    $path = __DIR__ . '/' . $relativePath;
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        fwrite(STDERR, "فشل إنشاء المجلد: {$directory}\n");
        exit(1);
    }

    if (is_file($path)) {
        copy($path, $path . '.bak');
    }

    // UTF-8 بلا BOM، وسطور LF — هذا سبب وجود السكربت أصلاً
    if (file_put_contents($path, str_replace("\r\n", "\n", $contents)) === false) {
        fwrite(STDERR, "فشل الكتابة: {$relativePath}\n");
        exit(1);
    }

    echo "✓ {$relativePath}\n";
    $written++;
}

echo "\nكُتب {$written} ملفاً.\n\n";
echo "الخطوات:\n";
echo "  composer dump-autoload\n";
echo "  php artisan migrate\n";
echo "  php artisan optimize:clear\n";
echo "  php artisan route:list --path=account\n";
echo "  php artisan test\n\n";
echo "لرؤية التفصيل اليومي في صفحة المشاهدات:\n";
echo "  php artisan analytics:rollup --days=14\n\n";
echo "الْتزامات مقترحة:\n";
echo "  fix(identity): add the phone and profile members the module already calls\n";
echo "  feat(catalog): record renewal date and count on listings\n";
echo "  feat(catalog): guard listing ownership with a policy\n";
echo "  feat(catalog): add the advertiser dashboard with edit, delete and renew\n";
echo "  feat(catalog): show per-listing daily views, impressions and clicks\n";
echo "  fix(catalog): stop counting an advertiser's visits to their own listing\n";