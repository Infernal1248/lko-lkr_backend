<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private string $temporaryPublic;

    protected function setUp(): void
    {
        parent::setUp();
        config(['site.url' => 'https://xn----stbbbcqr.xn--p1ai']);
        // Independent of a locally deployed frontend; never overwrite its build.
        $this->temporaryPublic = sys_get_temp_dir().'/lko-seo-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->temporaryPublic.'/app-assets/assets');
        File::ensureDirectoryExists($this->temporaryPublic.'/app-assets/site');
        File::ensureDirectoryExists($this->temporaryPublic.'/app-assets/site-images');
        File::put($this->temporaryPublic.'/app-assets/cabinet.html', '<html><head><meta name="robots" content="noindex, follow"></head><body>cabinet-fixture</body></html>');
        File::put($this->temporaryPublic.'/app-assets/assets/old.js', '/* old build */');
        File::put($this->temporaryPublic.'/app-assets/push-sw.js', '/* worker */');
        $origin = 'https://build.example.test';
        $pages = [];
        foreach (['', 'lko', 'lkr', 'printing', 'pricing'] as $slug) {
            $file = ($slug ?: 'index').'.html';
            $pages[$slug] = $file;
            File::put($this->temporaryPublic.'/app-assets/site/'.$file,
                '<html><head><title>Vue '.$slug.'</title><meta name="description" content="Vue page"><link rel="canonical" href="'.$origin.'/'.$slug.'"><script type="application/ld+json">{"@type":"WebSite"}</script></head><body><h1>Vue '.$slug.'</h1><a href="/app/login">Войти в кабинет</a><a href="/app/register">Создать аккаунт</a></body></html>');
        }
        File::put($this->temporaryPublic.'/app-assets/site/404.html', '<html><body><h1>Страница не найдена</h1></body></html>');
        File::put($this->temporaryPublic.'/app-assets/site/manifest.json', json_encode(['version' => 1, 'origin' => $origin, 'pages' => $pages, 'notFound' => '404.html', 'styles' => ['assets/site.css']]));
        File::put($this->temporaryPublic.'/app-assets/site/sitemap.xml', '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.implode('', array_map(fn ($slug) => '<url><loc>'.$origin.'/'.$slug.'</loc></url>', array_keys($pages))).'</urlset>');
        File::put($this->temporaryPublic.'/app-assets/assets/site.css', '/* Vue CSS */');
        File::put($this->temporaryPublic.'/app-assets/site-images/example.png', 'fixture');
        $this->app->usePublicPath($this->temporaryPublic);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->temporaryPublic);
        parent::tearDown();
    }

    public function test_public_pages_have_server_rendered_content_unique_metadata_and_safe_canonicals(): void
    {
        $titles = [];
        foreach (['', 'lko', 'lkr', 'printing', 'pricing'] as $path) {
            $response = $this->get('/'.$path.'?utm_source=test');
            $response->assertOk()->assertSee('<h1>', false)->assertSee('name="description"', false)
                ->assertSee('rel="canonical" href="https://xn----stbbbcqr.xn--p1ai/'.$path.'"', false)
                ->assertSee('application/ld+json', false)->assertDontSee('cabinet-fixture');
            $this->assertNull($response->headers->get('X-Robots-Tag'));
            preg_match('/<title>(.*?)<\/title>/s', $response->getContent(), $title);
            $titles[] = $title[1];
            $this->assertSame(1, substr_count($response->getContent(), '<h1>'));
            preg_match_all('/<script type="application\/ld\+json">(.*?)<\/script>/s', $response->getContent(), $scripts);
            foreach ($scripts[1] as $json) {
                $this->assertIsArray(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
            }
        }
        $this->assertCount(5, array_unique($titles));
    }

    public function test_sitemap_is_valid_xml_and_contains_only_public_canonical_pages(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertCount(5, $xml->url);
        $urls = array_map(fn ($entry) => (string) $entry->loc, iterator_to_array($xml->url, false));
        $this->assertSame([
            'https://xn----stbbbcqr.xn--p1ai/', 'https://xn----stbbbcqr.xn--p1ai/lko',
            'https://xn----stbbbcqr.xn--p1ai/lkr', 'https://xn----stbbbcqr.xn--p1ai/printing',
            'https://xn----stbbbcqr.xn--p1ai/pricing',
        ], $urls);
    }

    public function test_old_email_payment_push_and_legal_links_redirect_with_query_intact(): void
    {
        foreach ([
            '/?reset_token=sample&account_id=1' => '/app/reset-password?reset_token=sample&account_id=1',
            '/?payment=sample' => '/app/billing?payment=sample',
            '/?push=support&ticket=3' => '/app/support?push=support&ticket=3',
            '/?push=receipts' => '/app/receipts?push=receipts',
            '/?page=privacy' => '/app/privacy?page=privacy',
        ] as $url => $target) {
            $this->get($url)->assertStatus(301)->assertRedirect($target)->assertHeader('X-Robots-Tag', 'noindex, noarchive')
                ->assertHeader('Cache-Control', 'no-store, private');
            $this->get($target)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        }
    }

    public function test_cabinet_deep_links_are_preserved_and_excluded_from_search(): void
    {
        foreach (['', '/login', '/register', '/privacy', '/reset-password', '/new', '/history', '/employees', '/employee/42', '/calibration', '/organization', '/billing', '/support', '/help', '/receipts', '/admin-credits', '/admin-packages'] as $path) {
            $this->get('/app'.$path)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        }
        foreach (['login', 'register', 'dashboard', 'new', 'history', 'employees', 'employee/42', 'calibration', 'organization', 'billing', 'support', 'help', 'receipts', 'admin-credits', 'admin-packages', 'app/dashboard', 'app/index.html'] as $path) {
            $target = in_array($path, ['dashboard', 'app/dashboard', 'app/index.html']) ? '/app' : '/app/'.$path;
            $this->get('/'.$path.'?source=bookmark')->assertStatus(301)->assertRedirect($target.'?source=bookmark');
        }
    }

    public function test_public_html_stays_identical_across_sessions_and_session_state_is_available_via_api(): void
    {
        $guest = $this->get('/')->assertOk()->getContent();
        $this->getJson('/api/public-settings')->assertJsonPath('authenticated', false);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get('/')->assertOk()->assertDontSee($user->email);
        $this->assertSame($guest, $response->getContent());
        $this->assertNull($response->headers->get('X-Robots-Tag'));
        $this->getJson('/api/public-settings')->assertOk()->assertJsonPath('authenticated', true)->assertDontSee($user->email);
        $this->get('/?payment=sample')->assertRedirect('/app/billing?payment=sample');
    }

    public function test_legacy_assets_redirect_to_the_existing_file_and_worker_keeps_root_scope(): void
    {
        $this->get('/app/assets/old.js?v=1')->assertStatus(301)->assertRedirect('/app-assets/assets/old.js?v=1');
        $this->get('/app/assets/missing.js')->assertNotFound();
        $this->get('/app/assets/../index.html')->assertNotFound();
        $this->get('/push-sw.js')->assertOk()->assertHeader('Service-Worker-Allowed', '/');
        $this->get('/site-images/example.png')->assertRedirect('/app-assets/site-images/example.png');
        $this->get('/site.css?v=old')->assertRedirect('/app-assets/assets/site.css?v=old');
    }

    public function test_unknown_pages_and_assets_return_real_404_and_api_keeps_json(): void
    {
        foreach (['does-not-exist', 'sitemap-missing.xml', 'app/missing.js', 'employee/not-a-number', 'app/private/missing'] as $path) {
            $this->get('/'.$path)->assertNotFound()->assertSee('Страница не найдена')->assertDontSee('cabinet-fixture')
                ->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        }
        $this->getJson('/api/missing')->assertNotFound()->assertJson(['message' => 'Not Found']);
    }

    public function test_public_catalog_api_returns_live_prices_independently_of_the_html_build(): void
    {
        Package::query()->update(['active' => false]);
        Package::create(['key' => 'seo-test', 'name' => 'Новый пакет', 'credits' => 10, 'amount' => 123456, 'active' => true, 'sort_order' => 1]);
        $html = $this->get('/pricing')->assertOk()->getContent();
        $this->getJson('/api/packages')->assertOk()->assertJsonPath('packages.0.name', 'Новый пакет')->assertJsonPath('packages.0.amount', 123456)->assertJsonCount(1, 'packages');
        Package::whereKey('seo-test')->update(['amount' => 150000]);
        $this->getJson('/api/packages')->assertOk()->assertJsonPath('packages.0.amount', 150000);
        $this->assertSame($html, $this->get('/pricing')->getContent());
    }

    public function test_api_responses_are_not_indexable_and_personal_data_still_require_login(): void
    {
        $this->getJson('/api/public-settings')->assertOk()->assertHeader('X-Robots-Tag', 'noindex, noarchive');
        foreach (['cards', 'generations', 'profiles', 'me'] as $path) {
            $this->getJson('/api/'.$path)->assertUnauthorized();
        }
    }

    public function test_canonical_redirects_preserve_paths_and_query_links_without_redirecting_api(): void
    {
        foreach (['http://xn----stbbbcqr.xn--p1ai', 'https://www.xn----stbbbcqr.xn--p1ai'] as $origin) {
            $this->get($origin.'/lko?utm_source=test')->assertStatus(301)
                ->assertRedirect('https://xn----stbbbcqr.xn--p1ai/lko?utm_source=test');
            $this->get($origin.'/?reset_token=sample&account_id=1')->assertStatus(301)
                ->assertRedirect('https://xn----stbbbcqr.xn--p1ai/?reset_token=sample&account_id=1');
        }
        $this->get('https://xn----stbbbcqr.xn--p1ai/lko')->assertOk();
        $this->getJson('http://xn----stbbbcqr.xn--p1ai/api/public-settings')->assertOk();
    }
}
