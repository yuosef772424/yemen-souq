<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * جداول وحدة التحكم.
 *
 * ═══ الدور عمود واحد، لا جدولا أدوار وصلاحيات ═══
 *
 * البديل المرفوض: جدولا roles و permissions مع جدول ربط (نمط Spatie).
 * مرفوض لأن النظام يحتاج ثلاثة أدوار معروفة سلفاً: عضو، مشرف، مدير.
 * جدول صلاحيات بلا شاشة لإدارته ولا صلاحيات دقيقة تُركَّب منه هو ثلاثة
 * جداول وأربع علاقات لتخزين كلمة واحدة — وكل استعلام صلاحية يصير
 * انضمامين. حين تحتاج المنصة «مشرف تصنيف السيارات فقط» يُستبدل العمود
 * بالجداول، والدالة UserRole::of() هي الموضع الوحيد الذي يتغيّر.
 *
 * ولاحظ: العمود على users مباشرة لا في user_profiles. الصلاحية تُفحص في
 * كل طلب إداري، وuser_profiles جدول عرض يُحمَّل عند الحاجة — قراءة
 * الصلاحية منه تعني انضماماً في كل فحص.
 *
 * ═══ reviewed_at على listings: مراجعة بعد النشر لا قبله ═══
 *
 * ListingCreateController ينشر الإعلان بحالة active مباشرة. هذا يعني أن
 * طابور «pending» في لوحة التحكم سيكون فارغاً إلى الأبد.
 *
 * البديل المرفوض: تحويل الإنشاء إلى status = 'pending' ومراجعة كل إعلان
 * قبل ظهوره. مرفوض لسببين، والثاني أهم:
 *   1. مشغّل واحد لا يفرّغ طابوراً يومياً؛ والإعلان الذي ينتظر ست ساعات
 *      يعني معلناً ذهب إلى منصة أخرى.
 *   2. المنصة الفارغة تموت من نقص العرض لا من كثرة السيئ. المراجعة
 *      المسبقة تحمي المشتري في سوق مزدحم، وتقتل السوق قبل أن يزدحم.
 *
 * فالمختار: النشر فوري، والمراجعة تلحق. reviewed_at يفصل «رآه بشر
 * وأقرّه» عن «موجود ولم يُرَ بعد» — وبدون هذا العمود لا يمكن التفريق،
 * فتصير اللوحة قائمة إعلانات لا طابور عمل.
 *
 * ═══ admin_actions منفصل عن user_events ═══
 *
 * البديل المرفوض: تسجيل إجراءات الإدارة كأحداث في user_events. مرفوض
 * لثلاثة أسباب بنيوية: ذلك الجدول بلا مفاتيح أجنبية بقصد (سرعة الكتابة)،
 * ويُقطَع بعد تسعين يوماً بـanalytics:prune، ويُعامَل كسجل تحليلي يجوز
 * فقدان صفوف منه. سجل المساءلة عكس ذلك تماماً: دائم، مرتبط، وفقدان صفٍّ
 * منه عيب. «من حظر هذا الحساب ولماذا» سؤال يُسأل بعد سنة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'role')) {
                $table->enum('role', ['member', 'moderator', 'admin'])
                    ->default('member')
                    ->after('status')
                    ->comment('member=عضو، moderator=مشرف محتوى، admin=مدير');

                $table->index('role');
            }
        });

        Schema::table('listings', function (Blueprint $table) {
            if (! Schema::hasColumn('listings', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('reject_reason')
                    ->comment('فارغ = لم يراجعه بشر بعد — انظر تعليق الهجرة');
                $table->unsignedBigInteger('reviewed_by')->nullable()->after('reviewed_at');

                // status أولاً: يقصي أكبر عدد من الصفوف قبل فحص reviewed_at
                $table->index(['status', 'reviewed_at'], 'idx_moderation_queue');
            }
        });

        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();

            // nullOnDelete لا cascade: حذف حساب المشرف لا يجوز أن يمحو
            // سجل ما فعله. يبقى الصف بلا فاعل معروف، وهذا أصدق من فراغ.
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('action', 40)->comment('من AdminAction — لا نص حر');
            $table->string('entity_type', 30)->nullable()
                ->comment('listing, user, listingreport, listingquestion — من class_basename');
            $table->unsignedBigInteger('entity_id')->nullable();

            // السبب نص حر بقصد: قائمة أسباب مغلقة تُجبر المشرف على أقرب
            // خيار خاطئ، والسجل يصير أرقاماً لا تُفهم بعد سنة.
            $table->string('reason', 255)->nullable();
            $table->json('meta')->nullable()->comment('الحالة قبل وبعد — للتدقيق لا للعرض');

            $table->timestamp('created_at')->nullable();

            $table->index(['admin_id', 'created_at']);
            $table->index(['entity_type', 'entity_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_actions');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('idx_moderation_queue');
            $table->dropColumn(['reviewed_at', 'reviewed_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn('role');
        });
    }
};
