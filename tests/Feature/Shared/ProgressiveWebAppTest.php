<?php
namespace Tests\Feature\Shared;

use App\Models\User;
use App\Modules\Shared\Models\Currency;
use App\Modules\Shared\Models\ExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * قشرة التطبيق على الجوال.
 *
 * ما يحرسه ليس الشكل بل ما لا يُرى حين ينكسر: manifest بلا display
 * صحيح يجعل التثبيت يفتح متصفحاً بشريط عنوان، وعامل خدمة يخزّن HTML
 * يقدّم صفحة صاحبها السابق. الاثنان لا يُنتجان خطأً — يُنتجان سلوكاً
 * خاطئاً صامتاً.
 */
class ProgressiveWebAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create([
            'code' => 'YER', 'name_ar' => 'ريال يمني', 'name_en' => 'YER',
            'symbol' => 'ر.ي', 'decimals' => 0, 'is_base' => true,
        ]);

        ExchangeRate::create([
            'currency_code' => 'YER', 'rate_to_base' => 1,
            'effective_date' => now()->subYear()->toDateString(),
            'source' => 'test', 'created_at' => now(),
        ]);
    }

    #[Test]
    public function the_manifest_declares_a_standalone_installable_app(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.json')), true);

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['start_url']);
        $this->assertSame('rtl', $manifest['dir']);

        // أيقونة maskable شرط ألّا تظهر الأيقونة داخل مربّع أبيض على أندرويد
        $purposes = array_column($manifest['icons'], 'purpose');
        $this->assertContains('maskable', $purposes);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')));
        }
    }

    /** viewport-fit=cover شرط عمل env(safe-area-inset-*) — بلاه يُقصّ الشريط السفلي */
    #[Test]
    public function the_layout_opts_into_the_full_screen_and_links_the_manifest(): void
    {
        $this->get(route('search'))
            ->assertOk()
            ->assertSee('viewport-fit=cover', false)
            ->assertSee('rel="manifest"', false)
            ->assertSee('name="theme-color"', false)
            ->assertSee('apple-mobile-web-app-capable', false);
    }

    #[Test]
    public function the_offline_page_renders_without_a_session(): void
    {
        $this->get(route('offline'))
            ->assertOk()
            ->assertSee('لا يوجد اتصال');
    }

    /**
     * أخطر سطر في عامل الخدمة هو ما لا يفعله: تخزين HTML.
     *
     * الاختبار يقرأ الملف نصّاً لأن لا وسيلة لتشغيله في PHPUnit — والقيمة
     * أنه يفشل حين يضيف أحدهم لاحقاً تخزيناً للتنقّل بلا أن ينتبه للسبب.
     */
    #[Test]
    public function the_service_worker_never_caches_navigation_responses(): void
    {
        $worker = (string) file_get_contents(public_path('sw.js'));

        $this->assertStringContainsString("request.method !== 'GET'", $worker);
        $this->assertStringContainsString("request.mode === 'navigate'", $worker);

        // التنقّل يمرّ إلى الشبكة ويقع إلى صفحة الانقطاع — لا إلى مخزَّن
        $navigation = substr($worker, strpos($worker, "request.mode === 'navigate'"), 300);
        $this->assertStringContainsString('fetch(request)', $navigation);
        $this->assertStringNotContainsString('cache.put', $navigation);
    }

    #[Test]
    public function the_bottom_navigation_offers_five_reachable_tabs(): void
    {
        $this->get(route('search'))
            ->assertOk()
            ->assertSee('التنقّل الرئيسي')
            ->assertSee('الرئيسية')
            ->assertSee('المفضلة')
            ->assertSee('رسائلي')
            ->assertSee('حسابي');
    }

    /** الضيف يُساق إلى الدخول لا إلى صفحة محروسة تردّه */
    #[Test]
    public function a_guest_tab_points_at_the_login_page(): void
    {
        $this->get(route('search'))->assertOk()->assertSee(route('login'), false);
    }

    #[Test]
    public function a_signed_in_user_gets_the_real_destinations(): void
    {
        $user = User::factory()->create(['phone_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('search'))
            ->assertOk()
            ->assertSee(route('account.favorites'), false)
            ->assertSee(route('account.messages'), false);
    }
}
