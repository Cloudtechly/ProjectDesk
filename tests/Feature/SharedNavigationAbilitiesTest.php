<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class SharedNavigationAbilitiesTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    public function test_admin_only_navigation_abilities_match_their_policies(): void
    {
        $admin = $this->makeUser('admin');
        $member = $this->makeUser('member');

        $this->actingAs($admin)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('abilities.viewDataCenter', true)
                ->where('abilities.viewSettings', true));

        $this->actingAs($member)
            ->get(route('tasks.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('abilities.viewDataCenter', false)
                ->where('abilities.viewSettings', false));
    }
}
