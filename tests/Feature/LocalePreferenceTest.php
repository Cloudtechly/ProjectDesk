<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LocalePreferenceTest extends TestCase
{
    use RefreshDatabase;

    private const COOKIE = 'project_desk_locale';

    public function test_guest_can_persist_a_supported_locale_for_one_year(): void
    {
        $response = $this->from('/')
            ->put(route('locale.update'), ['locale' => 'en']);

        $response
            ->assertRedirect('/')
            ->assertCookie(self::COOKIE, 'en')
            ->assertCookieNotExpired(self::COOKIE)
            ->assertHeader('Content-Language', 'en');

        $cookie = $response->getCookie(self::COOKIE, false);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', $cookie->getSameSite());
        $this->assertEqualsWithDelta(now()->addYear()->timestamp, $cookie->getExpiresTime(), 5);
    }

    public function test_authenticated_user_can_persist_the_same_cookie_only_preference(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from('/dashboard')
            ->put(route('locale.update'), ['locale' => 'en'])
            ->assertRedirect('/dashboard')
            ->assertCookie(self::COOKIE, 'en');

        $this->assertArrayNotHasKey('locale', $user->getAttributes());
    }

    public function test_invalid_locale_is_rejected_without_writing_a_cookie(): void
    {
        $this->from('/')
            ->put(route('locale.update'), ['locale' => 'fr'])
            ->assertRedirect('/')
            ->assertSessionHasErrors('locale')
            ->assertCookieMissing(self::COOKIE);
    }

    public function test_locale_cookie_controls_shared_props_and_response_language(): void
    {
        $this->withCookie(self::COOKIE, 'en')
            ->get(route('home'))
            ->assertOk()
            ->assertHeader('Content-Language', 'en')
            ->assertInertia(fn (Assert $page) => $page
                ->where('localization.code', 'en')
                ->where('localization.dir', 'ltr')
                ->where('localization.tag', 'en')
                ->has('localization.supported', 2)
                ->where('localization.supported.0.code', 'ar')
                ->where('localization.supported.1.code', 'en'));
    }

    public function test_missing_or_unsupported_cookie_falls_back_to_arabic(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertInertia(fn (Assert $page) => $page
                ->where('localization.code', 'ar')
                ->where('localization.dir', 'rtl')
                ->where('localization.tag', 'ar'));

        $this->withCookie(self::COOKIE, 'fr')
            ->get(route('home'))
            ->assertOk()
            ->assertHeader('Content-Language', 'ar')
            ->assertInertia(fn (Assert $page) => $page
                ->where('localization.code', 'ar'));
    }

    public function test_root_html_language_and_direction_follow_the_locale(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<html lang="ar" dir="rtl">', false);

        $this->withCookie(self::COOKIE, 'en')
            ->get(route('home'))
            ->assertOk()
            ->assertSee('<html lang="en" dir="ltr">', false);
    }
}
