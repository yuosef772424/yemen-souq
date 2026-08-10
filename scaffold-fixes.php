<?php

/**
 * scaffold-engagement.php — التفاعل والتعليقات.
 *
 * يُشغَّل من جذر المشروع:  php scaffold-engagement.php
 *
 * وحدة Engagement كانت هجرةً بلا كود: ثمانية جداول وصفر أسطر منطق.
 * هذه الدفعة تُشغّل ثلاثة منها ومعها جدول جديد:
 *
 *   المفضلة          favorites
 *   المراسلة         conversations + messages
 *   الأسئلة العامة   listing_questions (جديد)
 *
 * التقييمات (reviews) مؤجّلة بقصد — التفصيل في تعليق ReviewsDeferred أدناه.
 */

if (! is_dir(__DIR__ . '/app/Modules/Engagement')) {
    fwrite(STDERR, "خطأ: شغّل هذا السكربت من جذر المشروع.\n");
    exit(1);
}

$files = [];

// ═════════════════════════════════════════════════════════════════════════════
// (1) الأسئلة العامة — جدول جديد
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Engagement/Database/Migrations/2026_01_01_000401_create_listing_questions.php'] = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * الأسئلة العامة على الإعلان.
 *
 * ═══ لماذا جدول جديد ولم تكفِ reviews ═══
 *
 * لأنهما شيئان مختلفان تماماً. reviews يقيّم *البائع* بعد تعامل، والسؤال
 * العام معلومة عن *السلعة* تفيد كل مشترٍ لاحق. خلطهما خطأ تصنيفي يُنتج
 * جدولاً لا يخدم أياً منهما.
 *
 * ═══ ولماذا سؤال عام لا رسالة خاصة ═══
 *
 * هذه أقوى حجة في الميزة كلها: عشرة مشترين يسألون «هل الفحص مسموح؟» في
 * عشر محادثات خاصة يعني عشر إجابات من البائع. سؤال عام واحد وجواب واحد
 * يعني صفر تكرار — والعاشر يقرأ الجواب بلا أن يسأل. الأسئلة العامة
 * تخفّف الرسائل لا تزيدها.
 *
 * ═══ الجواب في نفس الصف لا في جدول ردود ═══
 *
 * سؤال وجواب، لا نقاش. جدول ردود يفتح الباب لسلسلة تعليقات عامة على
 * الإعلان — وهي مساحة تُدار وتُراقَب ولا تخدم بيع سلعة. القيد البنيوي
 * أرخص من قواعد إشراف لاحقة.
 *
 * ═══ المالك لا يخفي سؤالاً ولا يحذفه ═══
 *
 * وهذا قرار منتج لا تفصيل تقني. لو استطاع المالك حذف «هل هي مصدومة؟»
 * لصارت الأسئلة العامة لوحة دعاية لا مصدر معلومة، وفقد المشتري السبب
 * الوحيد لقراءتها. الحذف للسائل وحده، والإخفاء للإدارة عند الإساءة —
 * ولهذا hidden_at وhidden_reason هنا من الآن، تنتظر لوحة الإدارة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('asker_id')->constrained('users')->cascadeOnDelete();

            $table->string('body', 400);

            // جواب واحد من المالك — answered_at يفرّق «لم يُجَب» عن «جواب فارغ»
            $table->string('answer', 800)->nullable();
            $table->timestamp('answered_at')->nullable();

            // للإدارة وحدها — انظر تعليق الملف
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason', 160)->nullable();

            $table->timestamps();

            $table->index(['listing_id', 'created_at']);
            $table->index(['asker_id', 'created_at']);

            // المالك يحتاج قائمة «أسئلة تنتظر جوابي» عبر إعلاناته كلها
            $table->index(['answered_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_questions');
    }
};
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (2) حارس النص العام
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Shared/Services/PublicContactGuard.php'] = <<<'PHP'
<?php

namespace App\Modules\Shared\Services;

/**
 * كشف أرقام الهاتف في نص يُعرض للعموم.
 *
 * ═══ لماذا يُمنع الرقم في السؤال العام ═══
 *
 * السؤال العام يقرأه كل زائر. فكتابة الرقم فيه تتجاوز
 * ContactRevealController كلياً — ومعه تُفقد أهم إشارة نية شراء في
 * المنصة (كشف الرقم أقوى من المشاهدة ومن الإضافة للمفضلة)، ويُفتح باب
 * حصاد الأرقام آلياً من صفحات الإعلانات، وهو ما بُني ذلك المتحكّم
 * لتقليله أصلاً.
 *
 * وفي الرسائل الخاصة لا يُمنع: بين طرفين اثنين لا معنى للمنع.
 *
 * ═══ لماذا الرفض لا الاستبدال الصامت ═══
 *
 * الاستبدال بـ«***» يجعل السائل يظن أن سؤاله ظهر كما كتبه، فينتظر
 * اتصالاً لن يأتي. الرفض برسالة صريحة يكلّف محاولة ثانية ويوفّر انتظاراً
 * بلا نهاية.
 *
 * ═══ لماذا شكل الرقم لا طول الأرقام ═══
 *
 * القاعدة الساذجة «سبعة أرقام متتالية = هاتف» تكسر في اليمن تحديداً:
 * السعر بالريال اليمني يبلغ سبعة وثمانية أرقام بسهولة (4500000)، فيُرفض
 * كل سؤال عن السعر. لذلك نطابق *شكل* الجوال اليمني: تسعة أرقام تبدأ
 * بـ7، أو رمز الدولة 967 ومعه الرقم.
 *
 * الحدّ المعلوم: سعر من تسعة أرقام يبدأ بـ7 (700 مليون ريال) يُرفض
 * خطأً. سعر عبثي لسلعة مبوَّبة، وثمن مقبول لقاعدة تعمل في الباقي.
 *
 * ═══ التكرار مع Identity\YemeniPhone معلوم ومقصود ═══
 *
 * Shared لا يجوز أن يعتمد على Identity — الاتجاه ينقلب. والوظيفتان
 * مختلفتان: ذاك يُطبّع رقماً واحداً للتخزين، وهذا يمسح نصاً حراً عن أي
 * رقم. معرفة الشكل مشتركة، والمسؤولية ليست كذلك.
 */
final class PublicContactGuard
{
    /** الأرقام العربية والفارسية إلى لاتينية — الفحص يجري على اللاتينية */
    private const DIGIT_MAP = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    public function containsPhoneNumber(?string $text): bool
    {
        if ($text === null || trim($text) === '') {
            return false;
        }

        $digits = $this->digitsOnly($text);

        if ($digits === '') {
            return false;
        }

        // 00967 / +967 / 967 ثم تسعة أرقام
        if (preg_match('/(?:00)?967\d{9}/', $digits) === 1) {
            return true;
        }

        // جوال محلي: تسعة أرقام تبدأ بـ7 — وليست جزءاً من رقم أطول
        return preg_match('/(?<!\d)7\d{8}(?!\d)/', $digits) === 1;
    }

    /**
     * الأرقام وحدها بعد إزالة كل ما يُستخدم لتفريق الرقم.
     *
     * «٧٧٧-١٢٣-٤٥٦» و«777 123 456» و«777.123.456» رقم واحد. وإزالة
     * الفواصل قبل الفحص هي ما يجعل الحيلة الشائعة — تفريق الرقم بمسافات
     * أو نقاط — بلا فائدة.
     *
     * والحروف تُستبدل بفاصل لا تُحذف: «١٢٣ ريال ٤٥٦» ليست رقماً واحداً،
     * وحذف الكلمة بينهما يجعلها كذلك.
     */
    private function digitsOnly(string $text): string
    {
        $text = strtr($text, self::DIGIT_MAP);

        // الفواصل المستخدمة داخل الأرقام تُزال
        $text = preg_replace('/[\s\-\.\(\)\+_\/]+/u', '', $text) ?? '';

        // أي شيء آخر يفصل رقمين — يُستبدل بحرف واحد ليس رقماً
        return preg_replace('/[^0-9]+/u', 'x', $text) ?? '';
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (3) الموديلات
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Engagement/Models/ListingQuestion.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingQuestion extends Model
{
    protected $table = 'listing_questions';

    /**
     * answer وanswered_at غائبان عن fillable بقصد: الجواب يُكتب من
     * QuestionService بعد فحص الملكية، لا من نموذج. وhidden_at للإدارة.
     */
    protected $fillable = [
        'listing_id',
        'asker_id',
        'body',
    ];

    protected function casts(): array
    {
        return [
            'answered_at' => 'datetime',
            'hidden_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }

    public function asker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asker_id');
    }

    /** المرئي للعموم — المخفيّ إدارياً يُقصى من كل عرض */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }

    public function scopeUnanswered(Builder $query): Builder
    {
        return $query->whereNull('answered_at');
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
PHP;

$files['app/Modules/Engagement/Models/Conversation.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * محادثة واحدة لكل (إعلان، مشترٍ) — والقيد في المخطط لا في الكود:
 * unique(listing_id, buyer_id).
 *
 * ولهذا «بدء محادثة» في الخدمة هو firstOrCreate لا create: زرّ يُضغط
 * مرتين، أو مشترٍ يعود بعد أسبوع، لا يجوز أن يُنتج خيطين للحديث نفسه.
 */
class Conversation extends Model
{
    protected $table = 'conversations';

    public $timestamps = false;

    /**
     * seller_id هنا لأن الخدمة تنسخه من الإعلان — ولا يُقرأ من الطلب
     * أبداً. لو قُرئ منه لاستطاع أي أحد إنشاء محادثة تنسب البيع لغيره.
     */
    protected $fillable = [
        'listing_id',
        'buyer_id',
        'seller_id',
        'last_message_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class)->withTrashed();
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /** محادثات مستخدم بأي دور — القائمة الواحدة تجمع ما باع وما اشترى */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $inner) use ($user): void {
            $inner->where('buyer_id', $user->id)->orWhere('seller_id', $user->id);
        });
    }

    public function involves(User $user): bool
    {
        return $user->id === $this->buyer_id || $user->id === $this->seller_id;
    }

    /** الطرف الآخر — ما يُعرض في القائمة، لأن دور المستخدم يتغيّر بين خيط وخيط */
    public function counterpart(User $user): ?User
    {
        return $user->id === $this->buyer_id ? $this->seller : $this->buyer;
    }

    public function unreadCountFor(User $user): int
    {
        return $this->messages()
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();
    }
}
PHP;

$files['app/Modules/Engagement/Models/Message.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Message extends Model
{
    protected $table = 'messages';

    public $timestamps = false;

    protected $fillable = [
        'conversation_id',
        'sender_id',
        'body',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
PHP;

$files['app/Modules/Engagement/Models/Favorite.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * جدول بمفتاح مركّب بلا id.
 *
 * ولهذا $incrementing = false و$primaryKey فارغ: Eloquent يفترض عمود id
 * تلقائياً، فيبني استعلامات على عمود غير موجود. القيد المركّب هو ما يمنع
 * التكرار في القاعدة نفسها — لا شرط في الكود.
 */
class Favorite extends Model
{
    protected $table = 'favorites';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    protected $fillable = [
        'user_id',
        'listing_id',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class);
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (4) الخدمات
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Engagement/Services/FavoriteToggler.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use Illuminate\Support\Facades\DB;

/**
 * إضافة الإعلان إلى المفضلة وإزالته، مع العدّاد.
 *
 * ═══ لماذا insertOrIgnore ثم فحص عدد الصفوف المتأثرة ═══
 *
 * العدّاد listings.favorites_count ذاكرة محسوبة، ومصدر الحقيقة جدول
 * favorites. والخطأ الشائع هنا: «أدرج ثم زد العدّاد». ضغطتان متلاحقتان
 * على الزر (أو نقرة مزدوجة على اتصال بطيء) تعطيان إدراجاً واحداً —
 * يمنعه المفتاح المركّب — وزيادتين للعدّاد. فيصبح العدّاد أكبر من عدد
 * الصفوف، ولا شيء يكشف ذلك لاحقاً.
 *
 * insertOrIgnore يُرجع عدد الصفوف المُدرجة فعلاً. العدّاد يزيد إن كان
 * واحداً، ولا يزيد إن كان صفراً. المعاملة تجعل الاثنين ذرّة واحدة.
 *
 * ═══ ولماذا عدّاد أصلاً ═══
 *
 * لأن بطاقة الإعلان في نتائج البحث تعرضه، وحساب COUNT لكل بطاقة في كل
 * صفحة نتائج يعني 24 استعلاماً إضافياً. العدّاد يُقرأ مع الصف نفسه.
 */
final class FavoriteToggler
{
    /**
     * @return bool صار مفضّلاً (true) أو أُزيل (false)
     */
    public function toggle(User $user, Listing $listing): bool
    {
        return DB::transaction(function () use ($user, $listing): bool {
            $inserted = DB::table('favorites')->insertOrIgnore([
                'user_id' => $user->id,
                'listing_id' => $listing->id,
                'created_at' => now(),
            ]);

            if ($inserted === 1) {
                $this->adjust($listing, 1);

                return true;
            }

            $deleted = DB::table('favorites')
                ->where('user_id', $user->id)
                ->where('listing_id', $listing->id)
                ->delete();

            if ($deleted === 1) {
                $this->adjust($listing, -1);
            }

            return false;
        });
    }

    public function has(User $user, Listing $listing): bool
    {
        return DB::table('favorites')
            ->where('user_id', $user->id)
            ->where('listing_id', $listing->id)
            ->exists();
    }

    /**
     * العدّاد لا ينزل تحت الصفر.
     *
     * unsignedInteger في المخطط، فقيمة سالبة خطأ SQL يُسقط الطلب. وحدوثه
     * ممكن: صف أُدرج قبل وجود هذه الخدمة، أو عدّاد أُعيد ضبطه يدوياً.
     * الحدّ عند الصفر يجعل العطل انحرافاً في رقم لا صفحة خطأ.
     */
    private function adjust(Listing $listing, int $delta): void
    {
        $query = Listing::withTrashed()->whereKey($listing->getKey());

        if ($delta > 0) {
            $query->increment('favorites_count', $delta);

            return;
        }

        $query->where('favorites_count', '>', 0)->decrement('favorites_count', abs($delta));
    }
}
PHP;

$files['app/Modules/Engagement/Services/ConversationService.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Models\Message;
use Illuminate\Support\Facades\DB;

/**
 * فتح المحادثات وإرسال الرسائل.
 *
 * ═══ قرارات ═══
 *
 * 1. المشتري وحده يفتح المحادثة.
 *    القيد unique(listing_id, buyer_id) يعني أن المالك لو راسل عبر
 *    إعلانه لأنشأ صفاً يكون فيه المشتري والبائع شخصاً واحداً — خيط يحدّث
 *    فيه نفسه، ويظهر في قائمته مرتين. الأسوأ أنه يفسد كل إحصاء عن
 *    «المحادثات المفتوحة لكل إعلان». المنع صريح في canStart.
 *
 * 2. الإعلان المغلق: لا محادثة جديدة، والقائمة تُقرأ وتُجاب.
 *    قطع خيط جارٍ لأن الإعلان انتهى يضرّ الطرفين — قد يكونا في منتصف
 *    اتفاق. أما فتح خيط جديد على إعلان منتهٍ فإرسال المشتري إلى طريق
 *    مسدود. الفرق بين الحالتين هو كل الفرق، ولهذا canStart وحدها تفحص
 *    حالة الإعلان.
 *
 * 3. أول رسالة تُسجَّل حدثاً في التحليلات، وما بعدها لا.
 *    الحدث المفيد هو «تواصل حدث»، لا عدد الرسائل. تسجيل كل رسالة يجعل
 *    محادثةً طويلة واحدة تبدو كخمسين نية شراء.
 *
 * 4. last_message_at على المحادثة تكرار مقصود.
 *    بلاه، ترتيب قائمة المحادثات يحتاج ربطاً بجدول messages وتجميعاً
 *    لكل صف. عمود واحد يُحدَّث مع كل رسالة أرخص بمراحل — وهو أيضاً ما
 *    يفهرسه المخطط أصلاً: index(buyer_id, last_message_at).
 */
final class ConversationService
{
    public function __construct(
        private readonly EventRecorder $events,
    ) {
    }

    /**
     * هل يجوز لهذا المستخدم فتح محادثة على هذا الإعلان؟
     *
     * تُستخدم في القالب أيضاً: زرّ يظهر ثم يُرفض بعد الضغط أسوأ من زرّ
     * لا يظهر.
     */
    public function canStart(User $user, Listing $listing): bool
    {
        if ($listing->user_id === $user->id) {
            return false;
        }

        if ($listing->trashed() || $listing->status !== 'active' || $listing->isExpired()) {
            return false;
        }

        return true;
    }

    /**
     * إرسال أول رسالة أو متابعة الخيط القائم.
     *
     * firstOrCreate لا create — انظر تعليق Conversation.
     */
    public function startOrContinue(User $buyer, Listing $listing, string $body): Conversation
    {
        $conversation = DB::transaction(function () use ($buyer, $listing, $body): array {
            $conversation = Conversation::firstOrCreate(
                ['listing_id' => $listing->id, 'buyer_id' => $buyer->id],
                ['seller_id' => $listing->user_id, 'created_at' => now()]
            );

            $isFirst = $conversation->wasRecentlyCreated;

            $this->append($conversation, $buyer, $body);

            return [$conversation, $isFirst];
        });

        [$conversation, $isFirst] = $conversation;

        if ($isFirst) {
            $this->events->record(EventName::ContactRevealed, 'listing', $listing->id, [
                'category_id' => $listing->category_id,
                'method' => 'message',
            ]);

            // نفس عدّاد كشف الرقم: كلاهما «تواصل حدث»، والتفريق بينهما
            // في حمولة الحدث لا في عمودين منفصلين
            Listing::withTrashed()->whereKey($listing->getKey())->increment('contacts_count');
        }

        return $conversation;
    }

    /** رسالة في خيط قائم — من أي من الطرفين */
    public function reply(Conversation $conversation, User $sender, string $body): Message
    {
        return DB::transaction(fn (): Message => $this->append($conversation, $sender, $body));
    }

    /**
     * وسم رسائل الطرف الآخر مقروءةً.
     *
     * رسائل المرسل نفسه لا تُوسم: «مقروءة» تعني أن المستقبل رآها، ووسمها
     * للمرسل يجعل مؤشّر القراءة كذباً — وهو مؤشّر يُبنى عليه سلوك (أعيد
     * الإرسال أم أنتظر).
     */
    public function markRead(Conversation $conversation, User $reader): int
    {
        return $conversation->messages()
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function append(Conversation $conversation, User $sender, string $body): Message
    {
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $sender->id,
            'body' => $body,
            'created_at' => now(),
        ]);

        $conversation->forceFill(['last_message_at' => $message->created_at])->save();

        return $message;
    }
}
PHP;

$files['app/Modules/Engagement/Services/QuestionService.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Services;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingQuestion;

/**
 * الأسئلة العامة.
 *
 * الخدمة رقيقة بقصد: الفحوص الحقيقية موزّعة على مواضعها —
 * الصلاحية في ListingQuestionPolicy، ومنع الأرقام في AskQuestionRequest
 * عبر PublicContactGuard، والحدّ الزمني في المتحكّم. جمعها كلها هنا كان
 * سيجعل الخدمة هي التطبيق.
 */
final class QuestionService
{
    public function canAsk(User $user, Listing $listing): bool
    {
        // المالك لا يسأل نفسه سؤالاً عاماً — لو أراد إضافة معلومة فمكانها
        // الوصف، وهو يملك تعديله
        if ($listing->user_id === $user->id) {
            return false;
        }

        return ! $listing->trashed() && $listing->status === 'active' && ! $listing->isExpired();
    }

    public function ask(User $asker, Listing $listing, string $body): ListingQuestion
    {
        return ListingQuestion::create([
            'listing_id' => $listing->id,
            'asker_id' => $asker->id,
            'body' => $body,
        ]);
    }

    /**
     * الجواب يُكتب مرة ويُعدَّل.
     *
     * answered_at لا يُحرَّك عند التعديل: تاريخ الجواب هو متى أجاب البائع
     * أولاً، وهو ما يقيس سرعة استجابته. تحريكه مع كل تصحيح إملائي يجعل
     * كل بائع يبدو مستجيباً فوراً.
     */
    public function answer(ListingQuestion $question, string $answer): ListingQuestion
    {
        $question->forceFill([
            'answer' => $answer,
            'answered_at' => $question->answered_at ?? now(),
        ])->save();

        return $question;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, ListingQuestion> */
    public function forListing(Listing $listing)
    {
        return ListingQuestion::query()
            ->visible()
            ->where('listing_id', $listing->id)
            ->with('asker')
            // المُجاب أولاً: الجواب هو ما جاء الزائر ليقرأه، والسؤال بلا
            // جواب معلومة عن البائع لا عن السلعة
            ->orderByRaw('answered_at is null')
            ->latest('created_at')
            ->get();
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (5) الصلاحيات
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Engagement/Policies/ConversationPolicy.php'] = <<<'PHP'
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
PHP;

$files['app/Modules/Engagement/Policies/ListingQuestionPolicy.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Policies;

use App\Models\User;
use App\Modules\Engagement\Models\ListingQuestion;

/**
 * من يفعل ماذا بالسؤال العام.
 *
 * التقسيم هو القرار: المالك يجيب ولا يحذف، والسائل يحذف ولا يجيب.
 *
 * ولا صلاحية حذف للمالك بقصد — انظر تعليق الهجرة: لو حذف المالك السؤال
 * المحرج لصارت الأسئلة العامة لوحة دعاية، وفقد المشتري السبب الوحيد
 * لقراءتها. الإخفاء للإدارة، وهو ليس صلاحية مستخدم أصلاً.
 */
class ListingQuestionPolicy
{
    public function answer(User $user, ListingQuestion $question): bool
    {
        return $user->id === $question->listing?->user_id;
    }

    public function delete(User $user, ListingQuestion $question): bool
    {
        return $user->id === $question->asker_id;
    }
}
PHP;

$files['app/Modules/Engagement/Providers/EngagementServiceProvider.php'] = <<<'PHP'
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
PHP;

$files['bootstrap/providers.php'] = <<<'PHP'
<?php

use App\Modules\Analytics\Providers\AnalyticsServiceProvider;
use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Engagement\Providers\EngagementServiceProvider;
use App\Modules\Search\Providers\SearchServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ModuleServiceProvider;

return [
    AppServiceProvider::class,
    ModuleServiceProvider::class,
    CatalogServiceProvider::class,
    AnalyticsServiceProvider::class,
    SearchServiceProvider::class,
    EngagementServiceProvider::class,
];
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (6) الطلبات والمتحكّمات
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Engagement/Http/Requests/AskQuestionRequest.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Shared\Services\PublicContactGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:8', 'max:400', $this->noPhoneNumber()],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'اكتب سؤالك.',
            'body.min' => 'السؤال قصير جداً — اجعله واضحاً حتى يستطيع البائع الإجابة.',
            'body.max' => 'السؤال طويل — اختصره أو أرسل رسالة خاصة.',
        ];
    }

    /**
     * قاعدة مضمّنة لا صنف Rule منفصل.
     *
     * القاعدة تُستخدم في موضعين فقط (السؤال والجواب) وكلاهما في هذا
     * المجلد. صنف منفصل في app/Rules يفرّق التحقق عن سياقه، ويجعل قارئ
     * الطلب لا يرى ما يُمنع — وهو أهم ما يجب أن يراه هنا.
     */
    private function noPhoneNumber(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (app(PublicContactGuard::class)->containsPhoneNumber(is_string($value) ? $value : null)) {
                    $fail('لا تكتب رقم هاتف في نص يظهر للعموم — استخدم الرسائل الخاصة، أو زرّ إظهار الرقم في الإعلان.');
                }
            }
        };
    }
}
PHP;

$files['app/Modules/Engagement/Http/Requests/AnswerQuestionRequest.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Http\Requests;

use App\Modules\Shared\Services\PublicContactGuard;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AnswerQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'answer' => ['required', 'string', 'min:2', 'max:800', $this->noPhoneNumber()],
        ];
    }

    public function messages(): array
    {
        return [
            'answer.required' => 'اكتب الجواب.',
            'answer.max' => 'الجواب طويل — اختصره.',
        ];
    }

    /**
     * المنع يشمل جواب المالك أيضاً.
     *
     * وهذه الحالة أهم من السؤال: البائع هو من له مصلحة في نشر رقمه علناً
     * لتجاوز زرّ إظهار الرقم. استثناؤه يُفرغ القاعدة من معناها.
     */
    private function noPhoneNumber(): ValidationRule
    {
        return new class implements ValidationRule
        {
            public function validate(string $attribute, mixed $value, \Closure $fail): void
            {
                if (app(PublicContactGuard::class)->containsPhoneNumber(is_string($value) ? $value : null)) {
                    $fail('لا تكتب رقم هاتف في جواب يظهر للعموم — رقمك يظهر عبر زرّ إظهار الرقم في إعلانك.');
                }
            }
        };
    }
}
PHP;

$files['app/Modules/Engagement/Http/Requests/SendMessageRequest.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * الرسالة الخاصة بلا حارس أرقام.
 *
 * بين طرفين اثنين لا معنى للمنع: تبادل الرقم هو الغرض من المحادثة. الحارس
 * يخصّ ما يُعرض للعموم وحده — وخلط الحالتين يجعل الميزة تعاقب استخدامها
 * الصحيح.
 */
class SendMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'اكتب رسالتك.',
            'body.max' => 'الرسالة طويلة — اختصرها.',
        ];
    }
}
PHP;

$files['app/Modules/Engagement/Http/Controllers/FavoriteController.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Services\FavoriteToggler;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class FavoriteController
{
    public function __construct(
        private readonly FavoriteToggler $favorites,
    ) {
    }

    /**
     * التبديل يعود إلى حيث جاء المستخدم.
     *
     * back() لا redirect ثابت: الزرّ يُضغط من صفحة الإعلان ومن نتائج البحث
     * ومن صفحة المفضلة نفسها. إعادةٌ ثابتة تُخرج المستخدم من تصفّحه —
     * وهو يريد إضافة إعلان ومتابعة النظر لا الانتقال.
     */
    public function toggle(Request $request, Listing $listing): RedirectResponse
    {
        $added = $this->favorites->toggle($request->user(), $listing);

        return back()->with('status', $added ? 'أُضيف إلى المفضلة.' : 'أُزيل من المفضلة.');
    }

    /**
     * المفضلة تعرض المحذوف والمنتهي أيضاً — بوسم واضح.
     *
     * إخفاء الإعلان الذي انتهى يجعل المستخدم يظن أنه فقد ما حفظه بلا
     * تفسير. وإظهاره كأنه قائم يهدر رحلة اتصال. الوسم هو الجواب.
     */
    public function index(Request $request): View
    {
        /**
         * الترتيب بتاريخ *الحفظ* لا بتاريخ الإعلان.
         *
         * وهذا سبب الربط بدل whereIn: القائمة ذاكرةُ المستخدم، وما حفظه
         * قبل دقيقة يجب أن يكون أولاً — ولو كان الإعلان نفسه من العام
         * الماضي. ترتيب بتاريخ الإعلان يجعل ما حفظه للتو يظهر في الصفحة
         * الثالثة، فيظن أن الحفظ لم ينجح.
         *
         * والربط لا يكرّر الصفوف: مفتاح favorites مركّب (user_id,
         * listing_id) والاستعلام يثبّت المستخدم — فصف واحد لكل إعلان.
         */
        $listings = Listing::query()
            ->withTrashed()
            ->join('favorites', 'favorites.listing_id', '=', 'listings.id')
            ->where('favorites.user_id', $request->user()->id)
            ->select('listings.*', 'favorites.created_at as favorited_at')
            ->with(['images', 'currency', 'category', 'location'])
            ->orderByDesc('favorites.created_at')
            ->paginate(12);

        return view('engagement::favorites', ['listings' => $listings]);
    }
}
PHP;

$files['app/Modules/Engagement/Http/Controllers/ConversationController.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\SendMessageRequest;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Services\ConversationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ConversationController
{
    /** رسائل في الدقيقة لكل مستخدم */
    private const RATE_LIMIT = 12;

    public function __construct(
        private readonly ConversationService $conversations,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->forUser($user)
            ->with(['listing.images', 'buyer', 'seller'])
            ->orderByDesc('last_message_at')
            ->paginate(15);

        return view('engagement::conversations.index', [
            'conversations' => $conversations,
            'user' => $user,
        ]);
    }

    public function show(Request $request, Conversation $conversation): View
    {
        Gate::authorize('view', $conversation);

        $user = $request->user();

        // الوسم عند الفتح لا عند الإرسال — انظر ConversationService::markRead
        $this->conversations->markRead($conversation, $user);

        $conversation->load(['messages.sender', 'listing.images', 'buyer', 'seller']);

        return view('engagement::conversations.show', [
            'conversation' => $conversation,
            'user' => $user,
            'counterpart' => $conversation->counterpart($user),
        ]);
    }

    /**
     * أول رسالة من صفحة الإعلان.
     *
     * canStart تُفحص هنا لا في Policy: الشرط ليس ملكيةً بل حالةَ إعلان
     * (منتهٍ، محذوف، أو للمستخدم نفسه) — وهذا منطق مجال لا صلاحية.
     * وضعه في Policy يجعل «الإعلان انتهى» تظهر للمستخدم كـ403.
     */
    public function store(SendMessageRequest $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();

        if (! $this->conversations->canStart($user, $listing)) {
            return back()->with('error', 'لا يمكن بدء محادثة على هذا الإعلان.');
        }

        $this->guardRate($user->id);

        $conversation = $this->conversations->startOrContinue(
            $user,
            $listing,
            $request->string('body')->value()
        );

        return redirect()
            ->route('account.messages.show', $conversation)
            ->with('status', 'أُرسلت رسالتك.');
    }

    public function reply(SendMessageRequest $request, Conversation $conversation): RedirectResponse
    {
        Gate::authorize('reply', $conversation);

        $this->guardRate($request->user()->id);

        $this->conversations->reply(
            $conversation,
            $request->user(),
            $request->string('body')->value()
        );

        return redirect()->route('account.messages.show', $conversation);
    }

    /**
     * حدّ زمني على الإرسال.
     *
     * الرسائل الخاصة أرخص طريق للسبام في أي منصة إعلانات: حساب واحد يبعث
     * نفس النص إلى مئة معلن. الحدّ لا يمنع الاستخدام الطبيعي — اثنتا عشرة
     * رسالة في الدقيقة أسرع من أي محادثة حقيقية.
     *
     * ValidationException لا abort(429): الرسالة تعود إلى نفس النموذج
     * ويقرأها المستخدم بالعربية، بدل صفحة خطأ لا تقول ما يفعل.
     */
    private function guardRate(int $userId): void
    {
        $key = "messages:{$userId}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'body' => 'رسائل كثيرة في وقت قصير. انتظر دقيقة ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 60);
    }
}
PHP;

$files['app/Modules/Engagement/Http/Controllers/ListingQuestionController.php'] = <<<'PHP'
<?php

namespace App\Modules\Engagement\Http\Controllers;

use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Http\Requests\AnswerQuestionRequest;
use App\Modules\Engagement\Http\Requests\AskQuestionRequest;
use App\Modules\Engagement\Models\ListingQuestion;
use App\Modules\Engagement\Services\QuestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ListingQuestionController
{
    /** أسئلة في الساعة لكل مستخدم */
    private const RATE_LIMIT = 10;

    public function __construct(
        private readonly QuestionService $questions,
    ) {
    }

    public function store(AskQuestionRequest $request, Listing $listing): RedirectResponse
    {
        $user = $request->user();

        if (! $this->questions->canAsk($user, $listing)) {
            return back()->with('error', 'لا يمكن طرح سؤال على هذا الإعلان.');
        }

        $key = "questions:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw ValidationException::withMessages([
                'body' => 'أسئلة كثيرة في وقت قصير. انتظر قليلاً ثم أعد المحاولة.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        $this->questions->ask($user, $listing, $request->string('body')->value());

        return back()->with('status', 'أُرسل سؤالك. سيظهر جواب البائع هنا للجميع.');
    }

    public function answer(AnswerQuestionRequest $request, ListingQuestion $question): RedirectResponse
    {
        Gate::authorize('answer', $question);

        $this->questions->answer($question, $request->string('answer')->value());

        return back()->with('status', 'نُشر جوابك.');
    }

    /**
     * الحذف للسائل وحده.
     *
     * لا صلاحية للمالك — انظر ListingQuestionPolicy. ومن يجرّب المسار
     * بمعرّف سؤال على إعلانه يحصل على 403، لا على حذف صامت.
     */
    public function destroy(ListingQuestion $question): RedirectResponse
    {
        Gate::authorize('delete', $question);

        $question->delete();

        return back()->with('status', 'حُذف سؤالك.');
    }

    /**
     * أسئلة تنتظر جوابي، عبر كل إعلاناتي.
     *
     * لماذا صفحة مستقلة: سؤال بلا جواب على إعلان لا يزوره المالك يبقى
     * معلّقاً إلى الأبد، ويقرأه كل زائر كإشارة إلى بائع لا يردّ. القائمة
     * المجمّعة هي ما يجعل الميزة تعمل فعلاً بدل أن تكون حقلاً في صفحة.
     */
    public function inbox(Request $request): View
    {
        $questions = ListingQuestion::query()
            ->visible()
            ->whereIn('listing_id', function ($query) use ($request): void {
                $query->select('id')
                    ->from('listings')
                    ->whereNull('deleted_at')
                    ->where('user_id', $request->user()->id);
            })
            ->with(['listing', 'asker'])
            ->orderByRaw('answered_at is null desc')
            ->latest('created_at')
            ->paginate(20);

        return view('engagement::questions-inbox', ['questions' => $questions]);
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// (7) المسارات + صفحة الإعلان
// ═════════════════════════════════════════════════════════════════════════════

$files['routes/web.php'] = <<<'PHP'
<?php

use App\Modules\Catalog\Http\Controllers\AccountListingController;
use App\Modules\Catalog\Http\Controllers\ContactRevealController;
use App\Modules\Catalog\Http\Controllers\ListingController;
use App\Modules\Catalog\Http\Controllers\ListingCreateController;
use App\Modules\Engagement\Http\Controllers\ConversationController;
use App\Modules\Engagement\Http\Controllers\FavoriteController;
use App\Modules\Engagement\Http\Controllers\ListingQuestionController;
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
*/

Route::middleware(['auth', EnsurePhoneIsVerified::class])->group(function (): void {
    Route::get('/add', [ListingCreateController::class, 'create'])->name('listing.create');
    Route::post('/add', [ListingCreateController::class, 'store'])->name('listing.store');
});

/*
|--------------------------------------------------------------------------
| التفاعل — يتطلب حساباً فقط
|--------------------------------------------------------------------------
|
| بلا EnsurePhoneIsVerified بقصد، وبنفس منطق لوحة المعلن: بوابة الهاتف
| تخصّ *إنشاء* محتوى معروض للسوق. أما سؤال البائع أو حفظ إعلان في
| المفضلة فسلوك مشترٍ — وحظره يحرم المنصة من الطرف الذي تحتاجه أكثر.
|
| والسبام يُحسب له هنا بحدّ زمني على الإرسال لا ببوابة توثيق: البوابة
| تمنع المشتري الجدّي مثل ما تمنع المُزعِج.
|
*/

Route::middleware('auth')->group(function (): void {
    Route::post('/listing/{listing}/favorite', [FavoriteController::class, 'toggle'])
        ->name('listing.favorite');

    Route::post('/listing/{listing}/questions', [ListingQuestionController::class, 'store'])
        ->name('listing.questions.store');

    Route::post('/questions/{question}/answer', [ListingQuestionController::class, 'answer'])
        ->name('questions.answer');

    Route::delete('/questions/{question}', [ListingQuestionController::class, 'destroy'])
        ->name('questions.destroy');

    // بدء المحادثة من صفحة الإعلان
    Route::post('/listing/{listing}/messages', [ConversationController::class, 'store'])
        ->name('listing.messages.store');
});

/*
|--------------------------------------------------------------------------
| لوحة الحساب
|--------------------------------------------------------------------------
|
| withTrashed على مسار الاستعادة فقط، وscopeBindings على مسار الصورة —
| بلا الثاني يقبل المسار معرّف صورة من إعلان آخر.
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

    // التفاعل
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites');
    Route::get('/messages', [ConversationController::class, 'index'])->name('messages');
    Route::get('/messages/{conversation}', [ConversationController::class, 'show'])->name('messages.show');
    Route::post('/messages/{conversation}', [ConversationController::class, 'reply'])->name('messages.reply');
    Route::get('/questions', [ListingQuestionController::class, 'inbox'])->name('questions');
});

/*
|--------------------------------------------------------------------------
| الهوية
|--------------------------------------------------------------------------
*/

require base_path('app/Modules/Identity/Routes/web.php');
PHP;

$files['app/Modules/Catalog/Http/Controllers/ListingController.php'] = <<<'PHP'
<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Analytics\Events\EventName;
use App\Modules\Analytics\Services\EventRecorder;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Services\ConversationService;
use App\Modules\Engagement\Services\FavoriteToggler;
use App\Modules\Engagement\Services\QuestionService;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Services\ExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * صفحة الإعلان.
 *
 * تعتمد على ثلاث خدمات من وحدة Engagement. والاتجاه صحيح: Engagement
 * يعتمد على Catalog (المحادثة تخصّ إعلاناً)، وCatalog يستهلك خدماتها في
 * طبقة العرض وحدها — لا في الموديل ولا في الـObserver. لو استوردت
 * Listing أو ListingObserver شيئاً من Engagement لصار الاعتماد دائرياً،
 * وذلك هو الخط الذي لا يُعبَر.
 */
class ListingController
{
    public function __construct(
        private readonly ExchangeRateService $rates,
        private readonly EventRecorder $events,
        private readonly FavoriteToggler $favorites,
        private readonly QuestionService $questions,
        private readonly ConversationService $conversations,
    ) {}

    public function show(Request $request, Listing $listing): View
    {
        $user = $request->user();
        $isOwner = $user?->id === $listing->user_id;

        // المالك يرى إعلانه في كل حالة — وإلا فمعاينة ما عُدِّل تقود إلى 404
        abort_unless($listing->status === 'active' || $isOwner, 404);

        $listing->load(['currency', 'category.parent', 'location.parent', 'images', 'user']);

        /**
         * زيارة المالك لإعلانه لا تُحسب — لا في العدّاد ولا في الأحداث.
         *
         * المعلن يفتح إعلانه ليتأكد أنه ظهر، ثم ليرى المشاهدات، ثم يحدّث
         * الصفحة. فيصبح الرقم الذي جاء ليقرأه من صنعه هو. وأسوأ من رقم
         * مفقود رقمٌ يبدو صحيحاً وليس كذلك.
         */
        if (! $isOwner) {
            // زيادة بلا لمس updated_at — وإلا بدا كل إعلان مُعدَّلاً للتو
            $listing->newQuery()->whereKey($listing->getKey())->increment('views_count');

            $this->recordVisit($request, $listing);
        }

        return view('catalog::show', [
            'listing' => $listing,
            'baseCurrency' => Currency::base(),
            'equivalents' => $this->equivalents($listing),
            'similar' => $this->similar($listing),
            'isOwner' => $isOwner,

            // التفاعل: كل قرار عرض محسوب في الخادم لا في القالب —
            // زرّ يظهر ثم يُرفض بعد الضغط أسوأ من زرّ لا يظهر
            'questions' => $this->questions->forListing($listing),
            'isFavorited' => $user !== null && $this->favorites->has($user, $listing),
            'canAsk' => $user !== null && $this->questions->canAsk($user, $listing),
            'canMessage' => $user !== null && $this->conversations->canStart($user, $listing),
        ]);
    }

    /**
     * حدث المشاهدة، وحدث النقر إن جاء من نتائج البحث.
     *
     * لماذا هنا لا بـJavaScript في صفحة النتائج: الرابط يحمل from وpos
     * وq، فالنقرة تُسجَّل بمجرد وصول الزائر — ولا تُفقد إن كان JS معطّلاً.
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
     * السعر مُعبَّراً عنه بكل العملات النشطة — المشكلة الأصلية معكوسة:
     * بدل أن يخمّن المشتري قيمة رقم بعملة لا يفكر بها، نعرضها بعملته.
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

// ═════════════════════════════════════════════════════════════════════════════
// (8) القوالب
// ═════════════════════════════════════════════════════════════════════════════

$files['app/Modules/Engagement/Resources/Views/partials/questions.blade.php'] = <<<'BLADE'
{{--
    الأسئلة العامة — تُضمَّن في صفحة الإعلان.

    القالب في وحدة Engagement لا في Catalog، وصفحة الإعلان تضمّنه. البديل
    — نقل الترميز إلى Catalog — يجعل وحدة الكتالوج تعرف شكل الأسئلة
    وأزرارها، فتنتقل الميزة إلى وحدتين. الصفحة تؤلّف؛ الوحدة تملك.

    متغيّراته: $listing, $questions, $canAsk, $isOwner
--}}

<section id="questions" class="rounded-2xl border border-line bg-white p-5">
    <div class="flex flex-wrap items-baseline justify-between gap-2">
        <h2 class="text-sm font-semibold">
            أسئلة عن هذا الإعلان
            <span class="tabular-nums text-xs text-muted">({{ $questions->count() }})</span>
        </h2>
        <p class="text-xs text-muted">الأسئلة وأجوبتها تظهر لكل الزوار</p>
    </div>

    @if ($canAsk)
        <form method="POST" action="{{ route('listing.questions.store', $listing) }}" class="mt-4">
            @csrf
            <label class="sr-only" for="question-body">سؤالك</label>
            <textarea id="question-body" name="body" rows="2" maxlength="400" required
                      placeholder="مثال: هل الفحص مسموح؟ وهل السعر قابل للتفاوض؟"
                      class="w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

            @error('body')
                <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                <p class="text-[11px] text-muted">
                    لا تكتب رقم هاتفك هنا — السؤال يظهر للجميع. للتواصل الخاص استخدم زرّ «راسل المعلن».
                </p>
                <button type="submit"
                        class="rounded-lg bg-ink px-4 py-2 text-xs font-semibold text-white transition hover:bg-black">
                    أرسل السؤال
                </button>
            </div>
        </form>
    @elseif ($isOwner)
        <p class="mt-3 rounded-xl bg-tint px-3 py-2 text-xs text-muted">
            هذا إعلانك — تظهر لك الأسئلة هنا لتجيب عليها.
        </p>
    @elseif (! auth()->check())
        <p class="mt-3 text-xs text-muted">
            <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
            لطرح سؤال.
        </p>
    @endif

    @if ($questions->isEmpty())
        <p class="mt-4 text-sm text-muted">لا أسئلة بعد.</p>
    @else
        <ul class="mt-4 divide-y divide-line">
            @foreach ($questions as $question)
                <li class="py-4 first:pt-0 last:pb-0">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <p class="text-sm font-medium">{{ $question->asker?->displayName() ?? 'مستخدم' }}</p>
                        <p class="text-[11px] text-muted">{{ $question->created_at?->diffForHumans() }}</p>
                    </div>

                    <p class="mt-1 whitespace-pre-line text-sm leading-relaxed">{{ $question->body }}</p>

                    @if ($question->isAnswered())
                        <div class="mt-3 rounded-xl bg-tint p-3">
                            <p class="text-[11px] font-semibold text-muted">جواب المعلن</p>
                            <p class="mt-1 whitespace-pre-line text-sm leading-relaxed">{{ $question->answer }}</p>
                        </div>
                    @elseif ($isOwner)
                        <form method="POST" action="{{ route('questions.answer', $question) }}" class="mt-3">
                            @csrf
                            <label class="sr-only" for="answer-{{ $question->id }}">جوابك</label>
                            <textarea id="answer-{{ $question->id }}" name="answer" rows="2" maxlength="800" required
                                      placeholder="اكتب جوابك — سيظهر لكل الزوار"
                                      class="w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none"></textarea>
                            <button type="submit"
                                    class="mt-2 rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                نشر الجواب
                            </button>
                        </form>
                    @else
                        <p class="mt-2 text-[11px] text-muted">لم يجب المعلن بعد.</p>
                    @endif

                    {{-- الحذف للسائل وحده. المالك لا يحذف سؤالاً محرجاً،
                         وإلا صارت الأسئلة العامة لوحة دعاية لا مصدر معلومة --}}
                    @if (auth()->id() === $question->asker_id)
                        <form method="POST" action="{{ route('questions.destroy', $question) }}" class="mt-2"
                              onsubmit="return confirm('حذف سؤالك؟')">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="text-[11px] text-red-700 hover:underline">حذف سؤالي</button>
                        </form>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</section>
BLADE;

$files['app/Modules/Catalog/Resources/Views/show.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', $listing->title)
@section('description', Str::limit($listing->description ?? $listing->title, 150))

@section('content')

<nav aria-label="مسار التصفح" class="mb-4 text-xs text-muted">
    <ol class="flex flex-wrap items-center gap-1.5">
        <li><a href="{{ route('search') }}" class="hover:text-ink hover:underline">الرئيسية</a></li>
        @if ($listing->category?->parent)
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('search', ['category' => $listing->category->parent->id]) }}"
                   class="hover:text-ink hover:underline">{{ $listing->category->parent->name_ar }}</a>
            </li>
        @endif
        @if ($listing->category)
            <li aria-hidden="true">/</li>
            <li>
                <a href="{{ route('search', ['category' => $listing->category->id]) }}"
                   class="hover:text-ink hover:underline">{{ $listing->category->name_ar }}</a>
            </li>
        @endif
    </ol>
</nav>

@if ($isOwner)
    <p class="mb-4 rounded-xl border border-line bg-tint px-4 py-2.5 text-xs">
        هذه معاينة إعلانك — زياراتك له لا تُحسب في المشاهدات.
        <a href="{{ route('account.listings.edit', $listing) }}" class="font-medium text-brand-dark hover:underline">تعديل</a>
    </p>
@endif

<div class="grid gap-6 lg:grid-cols-[1fr_340px]">

    <div class="space-y-5">

        {{-- معرض الصور --}}
        <div x-data="{ active: 0 }" class="overflow-hidden rounded-2xl border border-line bg-white">
            <div class="aspect-[16/10] bg-tint">
                @forelse ($listing->images as $index => $image)
                    <img @if ($index > 0) x-show="active === {{ $index }}" x-cloak
                         @else x-show="active === 0" @endif
                         src="{{ asset('storage/' . $image->path) }}"
                         alt="{{ $listing->title }} — صورة {{ $index + 1 }}"
                         class="size-full object-cover">
                @empty
                    <div class="grid size-full place-items-center text-sm text-muted">لا توجد صور لهذا الإعلان</div>
                @endforelse
            </div>

            @if ($listing->images->count() > 1)
                <div class="flex gap-2 overflow-x-auto p-3">
                    @foreach ($listing->images as $index => $image)
                        <button type="button" @click="active = {{ $index }}"
                                :class="active === {{ $index }} ? 'ring-2 ring-brand' : 'ring-1 ring-line'"
                                class="size-16 shrink-0 overflow-hidden rounded-lg"
                                aria-label="الصورة {{ $index + 1 }}">
                            <img src="{{ asset('storage/' . $image->path) }}" alt="" class="size-full object-cover">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- العنوان والتفاصيل --}}
        <div class="rounded-2xl border border-line bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <h1 class="text-xl font-bold leading-snug">{{ $listing->title }}</h1>
                @if ($listing->isFeaturedNow())
                    <span class="rounded-md bg-ink px-2 py-0.5 text-[11px] font-semibold text-white">مميّز</span>
                @endif
            </div>

            <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-3 text-sm sm:grid-cols-4">
                @if ($listing->condition_type)
                    <div>
                        <dt class="text-xs text-muted">الحالة</dt>
                        <dd class="mt-0.5 font-medium">
                            {{ ['new' => 'جديد', 'like_new' => 'كالجديد', 'used' => 'مستعمل', 'for_parts' => 'قطع غيار'][$listing->condition_type] ?? '—' }}
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-xs text-muted">الموقع</dt>
                    <dd class="mt-0.5 font-medium">{{ $listing->location?->name_ar }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">النشر</dt>
                    <dd class="mt-0.5 font-medium">{{ $listing->published_at?->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted">المشاهدات</dt>
                    <dd class="mt-0.5 font-medium tabular-nums">{{ number_format($listing->views_count) }}</dd>
                </div>
            </dl>

            @if ($listing->description)
                <div class="mt-5 border-t border-line pt-5">
                    <h2 class="text-sm font-semibold">الوصف</h2>
                    <p class="mt-2 whitespace-pre-line text-sm leading-relaxed text-ink/85">{{ $listing->description }}</p>
                </div>
            @endif
        </div>

        {{-- الأسئلة العامة: الوحدة تملك القالب، والصفحة تؤلّف --}}
        @include('engagement::partials.questions', [
            'listing' => $listing,
            'questions' => $questions,
            'canAsk' => $canAsk,
            'isOwner' => $isOwner,
        ])
    </div>

    {{-- العمود الجانبي --}}
    <aside class="space-y-4 lg:sticky lg:top-20 lg:self-start">

        <div class="rounded-2xl border border-line bg-white p-5">
            <p class="text-2xl font-bold tabular-nums text-brand-dark">{{ $listing->formattedPrice() }}</p>

            @if ($listing->is_negotiable)
                <p class="mt-1 text-xs text-muted">قابل للتفاوض</p>
            @endif

            @if ($equivalents !== [])
                <div class="mt-4 border-t border-line pt-4">
                    <p class="text-xs font-semibold text-muted">يقابل تقريباً</p>
                    <ul class="mt-2 space-y-1.5 text-sm tabular-nums">
                        @foreach ($equivalents as $equivalent)
                            <li class="flex items-baseline justify-between gap-3">
                                <span class="text-muted">{{ $equivalent['currency']->name_ar }}</span>
                                <span class="font-medium">{{ $equivalent['currency']->format($equivalent['amount']) }}</span>
                            </li>
                        @endforeach
                    </ul>

                    @if ($listing->rate_date)
                        <p class="mt-3 text-[11px] leading-relaxed text-muted">
                            محسوب بسعر صرف {{ $listing->rate_date->translatedFormat('j F Y') }}.
                            السعر المعتمد هو ما حدده المعلن بعملته الأصلية.
                        </p>
                    @endif
                </div>
            @endif

            {{-- المفضلة: نموذج POST لا زرّ Alpine.
                 الحفظ يجب أن يعمل بلا JavaScript — وهو أول ما يسقط على
                 اتصال ضعيف، وهناك بالضبط يحفظ الناس ليعودوا لاحقاً --}}
            @auth
                <form method="POST" action="{{ route('listing.favorite', $listing) }}" class="mt-4">
                    @csrf
                    <button type="submit"
                            @class([
                                'w-full rounded-xl border px-4 py-2.5 text-sm font-semibold transition',
                                'border-brand bg-brand/5 text-brand-dark' => $isFavorited,
                                'border-line hover:bg-tint' => ! $isFavorited,
                            ])>
                        {{ $isFavorited ? '★ في المفضلة' : '☆ أضف إلى المفضلة' }}
                    </button>
                </form>
            @endauth
        </div>

        <div class="rounded-2xl border border-line bg-white p-5">
            <p class="text-sm font-semibold">{{ $listing->user?->displayName() ?? 'معلن' }}</p>
            <p class="mt-0.5 text-xs text-muted">عضو منذ {{ $listing->user?->created_at?->translatedFormat('F Y') }}</p>

            @if ($listing->contact_phone && ! $listing->hide_phone)
                <div x-data="contactReveal('{{ $listing->slug }}')" class="mt-4">
                    <button type="button" x-show="!phone" :disabled="loading" @click="reveal()"
                            class="w-full rounded-xl bg-brand px-4 py-3 text-sm font-semibold text-white transition hover:bg-brand-dark disabled:opacity-60">
                        <span x-text="loading ? 'جارٍ الجلب…' : 'إظهار رقم التواصل'"></span>
                    </button>

                    <a x-show="phone" x-cloak :href="'tel:' + phone" x-text="phone"
                       class="block w-full rounded-xl border border-brand px-4 py-3 text-center text-sm font-semibold tabular-nums text-brand-dark"></a>

                    <p x-show="error" x-cloak x-text="error" class="mt-2 text-xs text-red-700"></p>
                </div>
            @endif

            {{-- المراسلة الخاصة: هنا يجوز تبادل الأرقام، وفي السؤال العام لا --}}
            @if ($canMessage)
                <form method="POST" action="{{ route('listing.messages.store', $listing) }}" class="mt-4 border-t border-line pt-4">
                    @csrf
                    <label for="message-body" class="block text-xs font-semibold">راسل المعلن</label>
                    <textarea id="message-body" name="body" rows="3" maxlength="2000" required
                              placeholder="السلام عليكم، هل الإعلان ما زال متاحاً؟"
                              class="mt-1.5 w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

                    @error('body')
                        <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
                    @enderror

                    <button type="submit"
                            class="mt-2 w-full rounded-xl border border-line px-4 py-2.5 text-sm font-semibold transition hover:bg-tint">
                        إرسال رسالة
                    </button>
                </form>
            @elseif (! auth()->check())
                <p class="mt-4 border-t border-line pt-4 text-xs text-muted">
                    <a href="{{ route('login') }}" class="font-medium text-brand-dark hover:underline">سجّل الدخول</a>
                    لمراسلة المعلن.
                </p>
            @endif

            <p class="mt-3 text-[11px] leading-relaxed text-muted">
                افحص المنتج قبل الدفع، ولا تحوّل مبالغ مقدماً لمن لا تعرفه.
            </p>
        </div>
    </aside>
</div>

@if (count($similar) > 0)
    <section class="mt-10">
        <h2 class="text-base font-semibold">إعلانات مشابهة في القيمة</h2>
        <p class="mt-1 text-xs text-muted">المقارنة بالقيمة الموحّدة، لا بالرقم المكتوب</p>

        <ul class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($similar as $item)
                @include('search::partials.listing-card', ['listing' => $item, 'baseCurrency' => $baseCurrency])
            @endforeach
        </ul>
    </section>
@endif

@endsection
BLADE;

$files['app/Modules/Engagement/Resources/Views/favorites.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'المفضلة')

@section('content')

<h1 class="text-xl font-bold">المفضلة</h1>

@if ($listings->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا إعلانات محفوظة. استخدم زرّ «أضف إلى المفضلة» في صفحة أي إعلان.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($listings as $listing)
            <li class="rounded-2xl border border-line bg-white p-3.5">
                <div class="flex gap-3.5">
                    <div class="size-20 shrink-0 overflow-hidden rounded-xl bg-tint">
                        @if ($listing->images->isNotEmpty())
                            <img src="{{ asset('storage/' . ($listing->images->first()->thumb_path ?? $listing->images->first()->path)) }}"
                                 alt="" loading="lazy" class="size-full object-cover">
                        @else
                            <div class="grid size-full place-items-center text-[10px] text-muted">لا صورة</div>
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        {{-- المحذوف والمنتهي يبقيان في القائمة بوسم صريح:
                             إخفاؤهما يجعل المستخدم يظن أنه فقد ما حفظه --}}
                        @php
                            $gone = $listing->trashed() || $listing->status !== 'active';
                            $expired = ! $gone && $listing->isExpired();
                        @endphp

                        <h2 class="text-sm font-semibold leading-snug">
                            @if ($listing->trashed())
                                <span class="text-muted">{{ $listing->title }}</span>
                            @else
                                <a href="{{ route('listing.show', $listing) }}" class="hover:underline">{{ $listing->title }}</a>
                            @endif
                        </h2>

                        <p class="mt-1 text-sm font-bold tabular-nums text-brand-dark">{{ $listing->formattedPrice() }}</p>

                        <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-muted">
                            <span>{{ $listing->location?->name_ar }}</span>

                            @if ($listing->trashed())
                                <span class="rounded-md bg-tint px-2 py-0.5 font-semibold">حُذف الإعلان</span>
                            @elseif ($gone)
                                <span class="rounded-md bg-tint px-2 py-0.5 font-semibold">غير متاح</span>
                            @elseif ($expired)
                                <span class="rounded-md bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">منتهي</span>
                            @endif
                        </p>

                        <form method="POST" action="{{ route('listing.favorite', $listing) }}" class="mt-3">
                            @csrf
                            <button type="submit"
                                    class="rounded-lg border border-line px-3 py-1.5 text-xs font-medium transition hover:bg-tint">
                                إزالة من المفضلة
                            </button>
                        </form>
                    </div>
                </div>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $listings->links() }}</div>
@endif

@endsection
BLADE;

$files['app/Modules/Engagement/Resources/Views/conversations/index.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'رسائلي')

@section('content')

<h1 class="text-xl font-bold">رسائلي</h1>

@if ($conversations->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا محادثات بعد.
    </p>
@else
    {{-- قائمة واحدة تجمع ما بعتَ وما اشتريت.
         قائمتان منفصلتان («كمشترٍ» و«كبائع») تبدو أنظف وتفشل عملياً:
         المستخدم يتذكّر أنه كلّم فلاناً عن سيارة، لا أي دور لعب --}}
    <ul class="mt-5 divide-y divide-line overflow-hidden rounded-2xl border border-line bg-white">
        @foreach ($conversations as $conversation)
            @php
                $counterpart = $conversation->counterpart($user);
                $unread = $conversation->unreadCountFor($user);
                $role = $user->id === $conversation->buyer_id ? 'أنت المشتري' : 'أنت البائع';
            @endphp

            <li>
                <a href="{{ route('account.messages.show', $conversation) }}"
                   class="flex gap-3.5 p-4 transition hover:bg-tint">
                    <div class="size-14 shrink-0 overflow-hidden rounded-xl bg-tint">
                        @if ($conversation->listing?->images->isNotEmpty())
                            <img src="{{ asset('storage/' . ($conversation->listing->images->first()->thumb_path ?? $conversation->listing->images->first()->path)) }}"
                                 alt="" loading="lazy" class="size-full object-cover">
                        @endif
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-2">
                            <p class="text-sm font-semibold">{{ $counterpart?->displayName() ?? 'مستخدم' }}</p>
                            <p class="text-[11px] text-muted">{{ $conversation->last_message_at?->diffForHumans() }}</p>
                        </div>

                        <p class="mt-0.5 truncate text-xs text-muted">{{ $conversation->listing?->title }}</p>

                        <p class="mt-1 flex items-center gap-2 text-[11px]">
                            <span class="text-muted">{{ $role }}</span>

                            @if ($unread > 0)
                                <span class="rounded-md bg-brand px-2 py-0.5 font-semibold tabular-nums text-white">
                                    {{ $unread }} جديدة
                                </span>
                            @endif
                        </p>
                    </div>
                </a>
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $conversations->links() }}</div>
@endif

@endsection
BLADE;

$files['app/Modules/Engagement/Resources/Views/conversations/show.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'محادثة — ' . ($conversation->listing?->title ?? ''))

@section('content')

<div class="mx-auto max-w-2xl">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-bold">{{ $counterpart?->displayName() ?? 'مستخدم' }}</h1>
            <p class="mt-0.5 truncate text-xs text-muted">
                @if ($conversation->listing)
                    <a href="{{ route('listing.show', $conversation->listing) }}" class="hover:underline">
                        {{ $conversation->listing->title }}
                    </a>
                @endif
            </p>
        </div>
        <a href="{{ route('account.messages') }}" class="text-sm text-muted hover:underline">كل الرسائل</a>
    </div>

    {{-- الإعلان المحذوف لا يقطع الخيط: قد يكون الطرفان في منتصف اتفاق --}}
    @if ($conversation->listing?->trashed())
        <p class="mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-xs text-amber-900">
            حُذف هذا الإعلان. المحادثة تبقى مفتوحة بينكما.
        </p>
    @endif

    <ul class="mt-5 space-y-3">
        @foreach ($conversation->messages as $message)
            @php $mine = $message->sender_id === $user->id; @endphp

            <li @class(['flex', 'justify-end' => $mine])>
                <div @class([
                    'max-w-[80%] rounded-2xl px-4 py-2.5',
                    'bg-brand text-white' => $mine,
                    'border border-line bg-white' => ! $mine,
                ])>
                    <p class="whitespace-pre-line text-sm leading-relaxed">{{ $message->body }}</p>

                    <p @class([
                        'mt-1 text-[10px] tabular-nums',
                        'text-white/70' => $mine,
                        'text-muted' => ! $mine,
                    ])>
                        {{ $message->created_at?->translatedFormat('j M · H:i') }}

                        {{-- مؤشّر القراءة يظهر لمرسل الرسالة وحده: للمستقبل
                             لا معنى له، وإظهاره للطرفين يجعله يُقرأ خطأً --}}
                        @if ($mine)
                            <span>· {{ $message->read_at ? 'قُرئت' : 'أُرسلت' }}</span>
                        @endif
                    </p>
                </div>
            </li>
        @endforeach
    </ul>

    <form method="POST" action="{{ route('account.messages.reply', $conversation) }}" class="mt-5">
        @csrf
        <label class="sr-only" for="reply-body">رسالتك</label>
        <textarea id="reply-body" name="body" rows="3" maxlength="2000" required
                  placeholder="اكتب رسالتك…"
                  class="w-full rounded-xl border border-line px-3 py-2.5 text-sm focus:border-brand focus:outline-none">{{ old('body') }}</textarea>

        @error('body')
            <p class="mt-1 text-xs text-red-700">{{ $message }}</p>
        @enderror

        <button type="submit"
                class="mt-2 rounded-xl bg-brand px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-brand-dark">
            إرسال
        </button>
    </form>

    <p class="mt-4 text-[11px] leading-relaxed text-muted">
        لا تحوّل مبالغ مقدماً لمن لا تعرفه، وافحص المنتج قبل الدفع.
    </p>
</div>

@endsection
BLADE;

$files['app/Modules/Engagement/Resources/Views/questions-inbox.blade.php'] = <<<'BLADE'
@extends('shared::layouts.app')

@section('title', 'أسئلة على إعلاناتي')

@section('content')

<h1 class="text-xl font-bold">أسئلة على إعلاناتي</h1>
<p class="mt-1 text-sm text-muted">غير المُجاب أولاً — السؤال المعلّق يقرأه كل زائر كإشارة إلى بائع لا يردّ.</p>

@if ($questions->isEmpty())
    <p class="mt-8 rounded-2xl border border-line bg-white px-4 py-10 text-center text-sm text-muted">
        لا أسئلة على إعلاناتك.
    </p>
@else
    <ul class="mt-5 space-y-3">
        @foreach ($questions as $question)
            <li class="rounded-2xl border border-line bg-white p-4">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <a href="{{ route('listing.show', $question->listing) }}#questions"
                       class="text-xs font-medium text-brand-dark hover:underline">
                        {{ $question->listing?->title }}
                    </a>
                    <p class="text-[11px] text-muted">{{ $question->created_at?->diffForHumans() }}</p>
                </div>

                <p class="mt-2 whitespace-pre-line text-sm leading-relaxed">{{ $question->body }}</p>

                @if ($question->isAnswered())
                    <div class="mt-3 rounded-xl bg-tint p-3">
                        <p class="text-[11px] font-semibold text-muted">جوابك</p>
                        <p class="mt-1 whitespace-pre-line text-sm">{{ $question->answer }}</p>
                    </div>
                @else
                    <form method="POST" action="{{ route('questions.answer', $question) }}" class="mt-3">
                        @csrf
                        <label class="sr-only" for="inbox-answer-{{ $question->id }}">جوابك</label>
                        <textarea id="inbox-answer-{{ $question->id }}" name="answer" rows="2" maxlength="800" required
                                  placeholder="اكتب جوابك — سيظهر لكل الزوار"
                                  class="w-full rounded-xl border border-line px-3 py-2 text-sm focus:border-brand focus:outline-none"></textarea>
                        <button type="submit"
                                class="mt-2 rounded-lg bg-ink px-4 py-2 text-xs font-semibold text-white transition hover:bg-black">
                            نشر الجواب
                        </button>
                    </form>
                @endif
            </li>
        @endforeach
    </ul>

    <div class="mt-6">{{ $questions->links() }}</div>
@endif

@endsection
BLADE;

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
    <div class="mx-auto flex max-w-6xl items-center gap-2 px-4 py-3">
        <a href="{{ route('search') }}" class="flex items-center gap-2 shrink-0">
            <span class="grid size-9 place-items-center rounded-lg bg-ink text-sm font-bold text-white">سوق</span>
            <span class="hidden text-base font-semibold sm:block">سوق يمني</span>
        </a>

        <div class="flex-1"></div>

        @auth
            {{--
                روابط بلا عدّاد غير مقروء بقصد.

                شارة «3 رسائل جديدة» في الترويسة تعني استعلام COUNT في كل
                صفحة من الموقع لكل مستخدم مسجَّل — بما فيها صفحات لا علاقة
                لها بالرسائل. الرقم يُعرض في صفحة الرسائل نفسها حيث يُقرأ.
            --}}
            <nav class="flex items-center gap-1 text-sm" aria-label="حسابي">
                <a href="{{ route('account.listings') }}"
                   class="rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink">إعلاناتي</a>
                <a href="{{ route('account.messages') }}"
                   class="rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink">رسائلي</a>
                <a href="{{ route('account.favorites') }}"
                   class="hidden rounded-lg px-2.5 py-2 font-medium text-muted transition hover:text-ink sm:block">المفضلة</a>
            </nav>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rounded-lg px-2.5 py-2 text-sm font-medium text-muted transition hover:text-ink">
                    خروج
                </button>
            </form>
        @else
            <a href="{{ route('login') }}" class="rounded-lg px-3 py-2 text-sm font-medium text-muted transition hover:text-ink">
                دخول
            </a>
        @endauth

        <a href="{{ route('listing.create') }}" class="shrink-0 rounded-lg bg-brand px-4 py-2 text-sm font-semibold text-white transition hover:bg-brand-dark focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand">
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

    {{-- محاولة مرفوضة ليست نجاحاً --}}
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

// ═════════════════════════════════════════════════════════════════════════════
// (9) الاختبارات
// ═════════════════════════════════════════════════════════════════════════════

$files['tests/Unit/Shared/PublicContactGuardTest.php'] = <<<'PHP'
<?php

namespace Tests\Unit\Shared;

use App\Modules\Shared\Services\PublicContactGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * حارس النص العام.
 *
 * الحالات السلبية هنا أهم من الموجبة: القاعدة الساذجة «سبعة أرقام
 * متتالية = هاتف» ترفض كل سؤال عن السعر في اليمن، لأن السعر بالريال
 * اليمني يبلغ سبعة وثمانية أرقام بسهولة. ولهذا نطابق شكل الجوال لا
 * طول الأرقام.
 */
class PublicContactGuardTest extends TestCase
{
    private PublicContactGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->guard = new PublicContactGuard;
    }

    /** @return array<string, array{0: string}> */
    public static function phoneTexts(): array
    {
        return [
            'رقم محلي' => ['تواصل معي 771234567'],
            'أرقام عربية' => ['رقمي ٧٧١٢٣٤٥٦٧'],
            'مفرَّق بمسافات' => ['رقمي ٧٧١ ٢٣٤ ٥٦٧'],
            'مفرَّق بشرطات' => ['الرقم 771-234-567'],
            'مفرَّق بنقاط' => ['واتساب 771.234.567'],
            'رمز الدولة' => ['واتساب +967771234567'],
            'رمز الدولة بصفرين' => ['اتصل 00967 73 123 456'],
        ];
    }

    #[Test]
    #[DataProvider('phoneTexts')]
    public function it_detects_a_phone_number(string $text): void
    {
        $this->assertTrue($this->guard->containsPhoneNumber($text));
    }

    /** @return array<string, array{0: string}> */
    public static function safeTexts(): array
    {
        return [
            'سؤال عادي' => ['كم السعر النهائي؟ وهل الفحص مسموح؟'],
            'سعر بسبعة أرقام' => ['السعر 4500000 ريال قابل للتفاوض'],
            'سعر بثمانية أرقام' => ['المطلوب 12500000 ريال يمني'],
            'ممشى وموديل' => ['ماشية 120000 كم وموديل 2018'],
            'رقم شاسي طويل' => ['رقم الشاسي 7123456789012'],
            'أرقام مفصولة بكلمات' => ['دفعت 123 ألف وبقي 456 ألف'],
            'فارغ' => [''],
        ];
    }

    #[Test]
    #[DataProvider('safeTexts')]
    public function it_leaves_ordinary_text_alone(string $text): void
    {
        $this->assertFalse($this->guard->containsPhoneNumber($text));
    }

    #[Test]
    public function null_is_not_a_phone_number(): void
    {
        $this->assertFalse($this->guard->containsPhoneNumber(null));
    }
}
PHP;

$files['tests/Feature/Engagement/FavoriteTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Services\FavoriteToggler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class FavoriteTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $user;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->user = User::factory()->create();
        $this->listing = $this->listingFor(User::factory()->create());
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('listing.favorite', $this->listing))->assertRedirect(route('login'));
    }

    #[Test]
    public function the_first_press_adds_and_the_second_removes(): void
    {
        $this->actingAs($this->user)
            ->from(route('listing.show', $this->listing))
            ->post(route('listing.favorite', $this->listing))
            ->assertRedirect(route('listing.show', $this->listing));

        $this->assertDatabaseHas('favorites', [
            'user_id' => $this->user->id,
            'listing_id' => $this->listing->id,
        ]);
        $this->assertSame(1, (int) $this->listing->fresh()->favorites_count);

        $this->actingAs($this->user)->post(route('listing.favorite', $this->listing));

        $this->assertDatabaseMissing('favorites', [
            'user_id' => $this->user->id,
            'listing_id' => $this->listing->id,
        ]);
        $this->assertSame(0, (int) $this->listing->fresh()->favorites_count);
    }

    /**
     * العدّاد لا يسبق الجدول.
     *
     * الخطأ الذي يحرسه هذا الاختبار: «أدرج ثم زد العدّاد». ضغطتان
     * متلاحقتان تعطيان إدراجاً واحداً — يمنعه المفتاح المركّب — وزيادتين
     * للعدّاد، فيصبح أكبر من عدد الصفوف بلا ما يكشفه لاحقاً.
     */
    #[Test]
    public function adding_twice_counts_once(): void
    {
        $toggler = app(FavoriteToggler::class);

        $this->assertTrue($toggler->toggle($this->user, $this->listing));

        // محاكاة الإدراج المكرّر بلا مرور بالتبديل
        \Illuminate\Support\Facades\DB::table('favorites')->insertOrIgnore([
            'user_id' => $this->user->id,
            'listing_id' => $this->listing->id,
            'created_at' => now(),
        ]);

        $this->assertSame(1, (int) $this->listing->fresh()->favorites_count);
        $this->assertSame(1, \Illuminate\Support\Facades\DB::table('favorites')->count());
    }

    #[Test]
    public function the_counter_never_goes_below_zero(): void
    {
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($this->user, $this->listing);

        $this->assertSame(0, (int) $this->listing->fresh()->favorites_count);
    }

    #[Test]
    public function two_users_can_favourite_the_same_listing(): void
    {
        $other = User::factory()->create();

        app(FavoriteToggler::class)->toggle($this->user, $this->listing);
        app(FavoriteToggler::class)->toggle($other, $this->listing);

        $this->assertSame(2, (int) $this->listing->fresh()->favorites_count);
    }

    #[Test]
    public function the_page_lists_only_the_users_own_favourites(): void
    {
        $mine = $this->listingFor(User::factory()->create(), ['title' => 'إعلان حفظته أنا']);
        $theirs = $this->listingFor(User::factory()->create(), ['title' => 'إعلان حفظه غيري']);

        app(FavoriteToggler::class)->toggle($this->user, $mine);
        app(FavoriteToggler::class)->toggle(User::factory()->create(), $theirs);

        $this->actingAs($this->user)
            ->get(route('account.favorites'))
            ->assertOk()
            ->assertSee($mine->title)
            ->assertDontSee($theirs->title);
    }

    /**
     * الإعلان المحذوف يبقى في المفضلة بوسم.
     *
     * إخفاؤه يجعل المستخدم يظن أنه فقد ما حفظه بلا تفسير، وإظهاره كأنه
     * قائم يهدر رحلة اتصال. الوسم هو الجواب.
     */
    #[Test]
    public function a_deleted_listing_stays_in_the_list_but_is_marked(): void
    {
        $listing = $this->listingFor(User::factory()->create(), ['title' => 'إعلان سيُحذف']);

        app(FavoriteToggler::class)->toggle($this->user, $listing);

        $listing->delete();

        $this->actingAs($this->user)
            ->get(route('account.favorites'))
            ->assertOk()
            ->assertSee($listing->title)
            ->assertSee('حُذف الإعلان');
    }
}
PHP;

$files['tests/Feature/Engagement/ConversationTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\Conversation;
use App\Modules\Engagement\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ConversationTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $seller;

    private User $buyer;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->seller = User::factory()->create();
        $this->buyer = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->seller, ['status' => 'active']);
    }

    private function send(User $user, string $body = 'هل الإعلان ما زال متاحاً؟')
    {
        return $this->actingAs($user)->post(route('listing.messages.store', $this->listing), ['body' => $body]);
    }

    #[Test]
    public function a_buyer_starts_a_thread_and_lands_in_it(): void
    {
        $this->send($this->buyer)->assertRedirect(
            route('account.messages.show', Conversation::firstOrFail())
        );

        $this->assertDatabaseHas('conversations', [
            'listing_id' => $this->listing->id,
            'buyer_id' => $this->buyer->id,
            'seller_id' => $this->seller->id,
        ]);
        $this->assertDatabaseCount('messages', 1);
    }

    /**
     * القيد unique(listing_id, buyer_id) يعني خيطاً واحداً.
     *
     * ضغطة مزدوجة أو عودة بعد أسبوع لا تُنتج خيطين للحديث نفسه — ولهذا
     * الخدمة firstOrCreate لا create.
     */
    #[Test]
    public function sending_twice_continues_the_same_thread(): void
    {
        $this->send($this->buyer, 'الرسالة الأولى');
        $this->send($this->buyer, 'الرسالة الثانية');

        $this->assertDatabaseCount('conversations', 1);
        $this->assertDatabaseCount('messages', 2);
    }

    /**
     * البائع لا يفتح محادثة على إعلانه.
     *
     * لو فتحها لأنشأ صفاً يكون فيه المشتري والبائع شخصاً واحداً — خيط
     * يحدّث فيه نفسه، ويفسد كل إحصاء عن «المحادثات لكل إعلان».
     */
    #[Test]
    public function the_owner_cannot_start_a_thread_on_his_own_listing(): void
    {
        $this->send($this->seller)->assertSessionHas('error');

        $this->assertDatabaseCount('conversations', 0);
    }

    #[Test]
    public function no_new_thread_on_a_closed_listing(): void
    {
        $this->listing->forceFill(['expires_at' => now()->subDay()])->save();

        $this->send($this->buyer)->assertSessionHas('error');

        $this->assertDatabaseCount('conversations', 0);
    }

    /**
     * الخيط القائم يبقى مفتوحاً بعد انتهاء الإعلان.
     *
     * قطعه يضرّ الطرفين — قد يكونا في منتصف اتفاق. الفرق بين «لا تفتح
     * جديداً» و«لا تكمل القائم» هو كل الفرق.
     */
    #[Test]
    public function an_existing_thread_survives_the_listing_expiring(): void
    {
        $this->send($this->buyer);

        $conversation = Conversation::firstOrFail();

        $this->listing->delete();

        $this->actingAs($this->buyer)
            ->post(route('account.messages.reply', $conversation), ['body' => 'ما زلت مهتماً'])
            ->assertRedirect(route('account.messages.show', $conversation));

        $this->assertDatabaseCount('messages', 2);
    }

    /** تسريب محادثة أثقل من تسريب إعلان */
    #[Test]
    public function a_third_party_cannot_read_or_write_the_thread(): void
    {
        $this->send($this->buyer);

        $conversation = Conversation::firstOrFail();

        $this->actingAs($this->stranger)
            ->get(route('account.messages.show', $conversation))
            ->assertForbidden();

        $this->actingAs($this->stranger)
            ->post(route('account.messages.reply', $conversation), ['body' => 'أنا لست طرفاً'])
            ->assertForbidden();

        $this->assertDatabaseCount('messages', 1);
    }

    #[Test]
    public function both_sides_see_the_thread_in_their_list(): void
    {
        $this->send($this->buyer);

        foreach ([$this->buyer, $this->seller] as $party) {
            $this->actingAs($party)
                ->get(route('account.messages'))
                ->assertOk()
                ->assertSee($this->listing->title);
        }
    }

    /**
     * «مقروءة» تعني أن المستقبل رآها.
     *
     * وسم رسائل المرسل نفسه يجعل المؤشّر كذباً — وهو مؤشّر يُبنى عليه
     * سلوك: أعيد الإرسال أم أنتظر.
     */
    #[Test]
    public function opening_the_thread_marks_only_the_other_sides_messages(): void
    {
        $this->send($this->buyer);

        $conversation = Conversation::firstOrFail();

        $this->actingAs($this->buyer)->get(route('account.messages.show', $conversation));

        $this->assertNull($conversation->messages()->firstOrFail()->read_at);

        $this->actingAs($this->seller)->get(route('account.messages.show', $conversation));

        $this->assertNotNull($conversation->messages()->firstOrFail()->read_at);
    }

    #[Test]
    public function the_first_message_counts_as_a_contact_and_later_ones_do_not(): void
    {
        $this->send($this->buyer, 'الأولى');
        $this->send($this->buyer, 'الثانية');
        $this->send($this->buyer, 'الثالثة');

        $this->assertSame(1, (int) $this->listing->fresh()->contacts_count);
    }

    #[Test]
    public function the_seller_id_comes_from_the_listing_not_the_request(): void
    {
        $this->actingAs($this->buyer)->post(route('listing.messages.store', $this->listing), [
            'body' => 'محاولة تزييف البائع',
            'seller_id' => $this->stranger->id,
        ]);

        $this->assertSame($this->seller->id, Conversation::firstOrFail()->seller_id);
    }

    #[Test]
    public function an_empty_message_is_rejected(): void
    {
        $this->send($this->buyer, '')->assertSessionHasErrors('body');

        $this->assertDatabaseCount('conversations', 0);
    }

    /** الرسائل الخاصة لا يُمنع فيها الرقم: بين طرفين لا معنى للمنع */
    #[Test]
    public function a_phone_number_is_allowed_in_a_private_message(): void
    {
        $this->send($this->buyer, 'رقمي 771234567 كلمني')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('messages', 1);
    }

    #[Test]
    public function the_service_refuses_to_start_for_the_owner(): void
    {
        $this->assertFalse(app(ConversationService::class)->canStart($this->seller, $this->listing));
        $this->assertTrue(app(ConversationService::class)->canStart($this->buyer, $this->listing));
    }
}
PHP;

$files['tests/Feature/Engagement/ListingQuestionTest.php'] = <<<'PHP'
<?php

namespace Tests\Feature\Engagement;

use App\Models\User;
use App\Modules\Catalog\Models\Listing;
use App\Modules\Engagement\Models\ListingQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\SeedsCatalog;
use Tests\TestCase;

class ListingQuestionTest extends TestCase
{
    use RefreshDatabase, SeedsCatalog;

    private User $owner;

    private User $asker;

    private User $stranger;

    private Listing $listing;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedReferenceData();

        $this->owner = User::factory()->create();
        $this->asker = User::factory()->create();
        $this->stranger = User::factory()->create();
        $this->listing = $this->listingFor($this->owner, ['status' => 'active']);
    }

    private function ask(User $user, string $body = 'هل الفحص مسموح قبل الشراء؟')
    {
        return $this->actingAs($user)
            ->from(route('listing.show', $this->listing))
            ->post(route('listing.questions.store', $this->listing), ['body' => $body]);
    }

    #[Test]
    public function a_signed_in_visitor_can_ask(): void
    {
        $this->ask($this->asker)->assertRedirect(route('listing.show', $this->listing));

        $this->assertDatabaseHas('listing_questions', [
            'listing_id' => $this->listing->id,
            'asker_id' => $this->asker->id,
        ]);
    }

    #[Test]
    public function a_guest_is_sent_to_the_login_page(): void
    {
        $this->post(route('listing.questions.store', $this->listing), ['body' => 'سؤال من زائر'])
            ->assertRedirect(route('login'));
    }

    /** لو أراد المالك إضافة معلومة فمكانها الوصف، وهو يملك تعديله */
    #[Test]
    public function the_owner_cannot_ask_on_his_own_listing(): void
    {
        $this->ask($this->owner)->assertSessionHas('error');

        $this->assertDatabaseCount('listing_questions', 0);
    }

    /**
     * أخطر اختبار في الملف.
     *
     * السؤال العام يقرأه كل زائر، فكتابة الرقم فيه تتجاوز
     * ContactRevealController كلياً — ومعه تُفقد أهم إشارة نية شراء،
     * ويُفتح باب حصاد الأرقام آلياً من صفحات الإعلانات.
     */
    #[Test]
    public function a_phone_number_in_a_public_question_is_rejected(): void
    {
        $this->ask($this->asker, 'كلمني على 771234567 وأشتري منك')
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('listing_questions', 0);
    }

    /** ولا يُستثنى البائع: هو الأكثر مصلحةً في نشر رقمه علناً */
    #[Test]
    public function a_phone_number_in_the_owners_answer_is_rejected_too(): void
    {
        $this->ask($this->asker);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->owner)
            ->from(route('listing.show', $this->listing))
            ->post(route('questions.answer', $question), ['answer' => 'اتصل 00967771234567'])
            ->assertSessionHasErrors('answer');

        $this->assertNull($question->fresh()->answered_at);
    }

    #[Test]
    public function a_question_about_a_price_is_not_mistaken_for_a_phone_number(): void
    {
        $this->ask($this->asker, 'هل تقبل 4500000 ريال نقداً؟')->assertSessionHasNoErrors();

        $this->assertDatabaseCount('listing_questions', 1);
    }

    #[Test]
    public function the_owner_answers_and_the_answer_shows_publicly(): void
    {
        $this->ask($this->asker);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->owner)
            ->from(route('listing.show', $this->listing))
            ->post(route('questions.answer', $question), ['answer' => 'نعم الفحص مسموح في أي ورشة']);

        $this->assertNotNull($question->fresh()->answered_at);

        $this->get(route('listing.show', $this->listing))
            ->assertOk()
            ->assertSee('نعم الفحص مسموح في أي ورشة');
    }

    #[Test]
    public function only_the_owner_may_answer(): void
    {
        $this->ask($this->asker);

        $this->actingAs($this->stranger)
            ->post(route('questions.answer', ListingQuestion::firstOrFail()), ['answer' => 'أنا لست البائع'])
            ->assertForbidden();
    }

    /**
     * تاريخ الجواب لا يتحرّك عند التعديل.
     *
     * answered_at يقيس سرعة استجابة البائع؛ تحريكه مع كل تصحيح إملائي
     * يجعل كل بائع يبدو مستجيباً فوراً.
     */
    #[Test]
    public function editing_the_answer_keeps_the_original_answer_time(): void
    {
        $this->ask($this->asker);

        $question = ListingQuestion::firstOrFail();

        $this->actingAs($this->owner)->post(route('questions.answer', $question), ['answer' => 'نعم مسموح']);

        $first = $question->fresh()->answered_at;

        $this->travel(2)->hours();

        $this->actingAs($this->owner)->post(route('questions.answer', $question), ['answer' => 'نعم مسموح في أي ورشة']);

        $this->assertTrue($first->equalTo($question->fresh()->answered_at));
    }

    /**
     * المالك لا يحذف سؤالاً محرجاً — وهذا قرار منتج لا تفصيل تقني.
     *
     * لو استطاع لصارت الأسئلة العامة لوحة دعاية لا مصدر معلومة، وفقد
     * المشتري السبب الوحيد لقراءتها.
     */
    #[Test]
    public function the_owner_cannot_delete_a_question(): void
    {
        $this->ask($this->asker, 'هل السيارة مصدومة؟');

        $this->actingAs($this->owner)
            ->delete(route('questions.destroy', ListingQuestion::firstOrFail()))
            ->assertForbidden();

        $this->assertDatabaseCount('listing_questions', 1);
    }

    #[Test]
    public function the_asker_may_delete_his_own_question(): void
    {
        $this->ask($this->asker);

        $this->actingAs($this->asker)
            ->from(route('listing.show', $this->listing))
            ->delete(route('questions.destroy', ListingQuestion::firstOrFail()));

        $this->assertDatabaseCount('listing_questions', 0);
    }

    #[Test]
    public function a_hidden_question_disappears_from_the_public_page(): void
    {
        $this->ask($this->asker, 'سؤال سيُخفى إدارياً تماماً');

        ListingQuestion::firstOrFail()->forceFill([
            'hidden_at' => now(),
            'hidden_reason' => 'إساءة',
        ])->save();

        $this->get(route('listing.show', $this->listing))
            ->assertOk()
            ->assertDontSee('سؤال سيُخفى إدارياً تماماً');
    }

    /** سؤال معلّق على إعلان لا يزوره المالك يبقى إلى الأبد بلا هذه الصفحة */
    #[Test]
    public function the_inbox_lists_unanswered_questions_across_all_listings(): void
    {
        $second = $this->listingFor($this->owner, ['title' => 'إعلان آخر للمالك']);

        $this->ask($this->asker, 'سؤال على الإعلان الأول');

        $this->actingAs($this->asker)->post(route('listing.questions.store', $second), [
            'body' => 'سؤال على الإعلان الثاني',
        ]);

        $this->actingAs($this->owner)
            ->get(route('account.questions'))
            ->assertOk()
            ->assertSee('سؤال على الإعلان الأول')
            ->assertSee('سؤال على الإعلان الثاني');
    }

    #[Test]
    public function the_inbox_shows_no_questions_from_other_peoples_listings(): void
    {
        $foreign = $this->listingFor($this->stranger, ['title' => 'إعلان شخص آخر']);

        $this->actingAs($this->asker)->post(route('listing.questions.store', $foreign), [
            'body' => 'سؤال لا يخصّ المالك',
        ]);

        $this->actingAs($this->owner)
            ->get(route('account.questions'))
            ->assertOk()
            ->assertDontSee('سؤال لا يخصّ المالك');
    }
}
PHP;

// ═════════════════════════════════════════════════════════════════════════════
// الكتابة
// ═════════════════════════════════════════════════════════════════════════════

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
echo "  php artisan test\n";
echo "  php artisan route:list --path=messages\n\n";
echo "الْتزامات مقترحة:\n";
echo "  feat(engagement): add public questions and answers on listings\n";
echo "  feat(shared): reject phone numbers in text shown publicly\n";
echo "  feat(engagement): add favourites with a race-safe counter\n";
echo "  feat(engagement): add buyer-seller conversations scoped to a listing\n";
echo "  feat(engagement): add an inbox for unanswered questions across listings\n";
echo "  feat(catalog): surface favourites, messaging and questions on the listing page\n";