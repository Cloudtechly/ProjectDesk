<?php

namespace Tests\Feature;

use App\Http\Controllers\ClientController;
use App\Http\Controllers\ContactController;
use App\Models\Client;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ProjectDeskTestData;
use Tests\TestCase;

class ClientContactCrudTest extends TestCase
{
    use ProjectDeskTestData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $router = $this->app->make(Router::class);
        $router->middleware(['web', 'auth', 'verified'])->scopeBindings()->group(function () use ($router): void {
            $router->get('clients/create', [ClientController::class, 'create'])->name('clients.create');
            $router->post('clients', [ClientController::class, 'store'])->name('clients.store');
            $router->get('clients/{client}', [ClientController::class, 'show'])->name('clients.show');
            $router->get('clients/{client}/edit', [ClientController::class, 'edit'])->name('clients.edit');
            $router->put('clients/{client}', [ClientController::class, 'update'])->name('clients.update');
            $router->post('clients/{client}/archive', [ClientController::class, 'archive'])->name('clients.archive');
            $router->post('clients/{client}/contacts', [ContactController::class, 'store'])->name('clients.contacts.store');
            $router->put('clients/{client}/contacts/{contact}', [ContactController::class, 'update'])->name('clients.contacts.update');
            $router->post('clients/{client}/contacts/{contact}/archive', [ContactController::class, 'archive'])->name('clients.contacts.archive');
        });
        $router->getRoutes()->refreshNameLookups();
        $router->getRoutes()->refreshActionLookups();
    }

    public function test_project_manager_can_create_update_and_archive_a_client_without_deleting_it(): void
    {
        $manager = $this->makeUser('project_manager');

        $response = $this->actingAs($manager)->post(route('clients.store'), [
            'code' => 'CL-100',
            'name' => 'Cloud Client',
            'email' => 'client@example.com',
            'phone' => '+218910000000',
            'address' => 'Tripoli',
        ]);

        $client = Client::query()->where('code', 'CL-100')->firstOrFail();
        $response->assertSessionHasNoErrors()->assertRedirect(route('clients.show', $client));

        $this->actingAs($manager)->put(route('clients.update', $client), [
            'code' => 'CL-100',
            'name' => 'Cloud Client Updated',
            'email' => 'accounts@example.com',
            'phone' => '+218920000000',
            'address' => 'Benghazi',
            'status' => 'inactive',
        ])->assertSessionHasNoErrors()->assertRedirect(route('clients.show', $client));

        $client->refresh();
        $this->assertSame('Cloud Client Updated', $client->name);
        $this->assertSame('inactive', $client->status);

        $this->actingAs($manager)->post(route('clients.archive', $client))
            ->assertRedirect(route('clients.index'));

        $client->refresh();
        $this->assertNotNull($client->archived_at);
        $this->assertSame('archived', $client->status);
        $this->assertDatabaseCount('clients', 1);
        $this->assertDatabaseHas('activity_logs', ['action' => 'client.created', 'subject_id' => $client->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'client.updated', 'subject_id' => $client->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'client.archived', 'subject_id' => $client->id]);
    }

    public function test_project_managers_only_see_and_manage_clients_in_their_scope(): void
    {
        $firstManager = $this->makeUser('project_manager');
        $secondManager = $this->makeUser('project_manager');

        $firstClient = Client::query()->create([
            'created_by' => $firstManager->id,
            'code' => 'CL-FIRST',
            'name' => 'عميل المدير الأول',
            'status' => 'active',
        ]);
        $secondClient = Client::query()->create([
            'created_by' => $secondManager->id,
            'code' => 'CL-SECOND',
            'name' => 'عميل المدير الثاني',
            'status' => 'active',
        ]);

        $this->actingAs($firstManager)
            ->get(route('clients.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients.data', 1)
                ->where('clients.data.0.id', $firstClient->id));

        $this->actingAs($firstManager)->get(route('clients.show', $secondClient))->assertForbidden();
        $this->actingAs($firstManager)->put(route('clients.update', $secondClient), [
            'code' => $secondClient->code,
            'name' => 'تعديل غير مسموح',
            'status' => 'active',
        ])->assertForbidden();
    }

    public function test_archived_client_and_contact_can_be_restored_without_deletion(): void
    {
        $manager = $this->makeUser('project_manager');
        $client = Client::query()->create([
            'created_by' => $manager->id,
            'code' => 'CL-RESTORE',
            'name' => 'عميل للاستعادة',
            'status' => 'active',
        ]);
        $contact = $client->contacts()->create([
            'name' => 'جهة للاستعادة',
            'is_primary' => true,
            'is_active' => true,
        ]);

        $this->actingAs($manager)->post(route('clients.archive', $client))->assertRedirect(route('clients.index'));
        $this->assertFalse($contact->fresh()->is_active);

        $this->actingAs($manager)->post(route('clients.restore', $client))->assertRedirect(route('clients.show', $client));
        $this->actingAs($manager)->post(route('clients.contacts.restore', [$client, $contact]))
            ->assertRedirect(route('clients.show', $client));

        $this->assertNull($client->fresh()->archived_at);
        $this->assertTrue($contact->fresh()->is_active);
        $this->assertDatabaseHas('activity_logs', ['action' => 'client.restored', 'subject_id' => $client->id]);
        $this->assertDatabaseHas('activity_logs', ['action' => 'contact.restored', 'subject_id' => $contact->id]);
    }

    public function test_client_with_an_active_project_cannot_be_archived(): void
    {
        $manager = $this->makeUser('project_manager');
        $project = $this->makeProject($manager);

        $this->actingAs($manager)
            ->post(route('clients.archive', $project->client))
            ->assertSessionHasErrors('archive');

        $this->assertNull($project->client->fresh()->archived_at);
    }

    public function test_client_validation_rejects_duplicate_codes_and_invalid_contact_data(): void
    {
        $manager = $this->makeUser('project_manager');
        Client::query()->create(['code' => 'CL-200', 'name' => 'Existing', 'status' => 'active']);

        $this->actingAs($manager)->from(route('clients.create'))->post(route('clients.store'), [
            'code' => 'CL-200',
            'name' => 'Duplicate',
            'email' => 'not-an-email',
        ])->assertSessionHasErrors(['code', 'email']);

        $this->assertDatabaseCount('clients', 1);
    }

    public function test_contacts_are_managed_under_the_client_with_one_active_primary_contact(): void
    {
        $manager = $this->makeUser('project_manager');
        $client = $this->makeProject($manager)->client;

        $this->actingAs($manager)->post(route('clients.contacts.store', $client), [
            'name' => 'First Contact',
            'role' => 'Manager',
            'email' => 'first@example.com',
            'is_primary' => true,
            'is_active' => true,
        ])->assertSessionHasNoErrors()->assertRedirect(route('clients.show', $client));

        $first = Contact::query()->where('email', 'first@example.com')->firstOrFail();

        $this->actingAs($manager)->post(route('clients.contacts.store', $client), [
            'name' => 'Second Contact',
            'phone' => '+218930000000',
            'is_primary' => true,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $second = Contact::query()->where('phone', '+218930000000')->firstOrFail();
        $this->assertFalse($first->fresh()->is_primary);
        $this->assertTrue($second->is_primary);

        $this->actingAs($manager)->put(route('clients.contacts.update', [$client, $second]), [
            'name' => 'Second Contact Updated',
            'phone' => '+218940000000',
            'is_primary' => true,
            'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post(route('clients.contacts.archive', [$client, $second]))
            ->assertRedirect(route('clients.show', $client));

        $second->refresh();
        $this->assertFalse($second->is_active);
        $this->assertFalse($second->is_primary);
        $this->assertDatabaseCount('contacts', 2);
        $this->assertDatabaseHas('activity_logs', ['action' => 'contact.archived', 'subject_id' => $second->id]);
    }

    public function test_client_read_includes_only_projects_visible_to_the_user(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $outsider = $this->makeUser('member');
        $project = $this->makeProject($manager);
        $project->members()->attach($member, ['project_role' => 'member', 'status' => 'active']);

        $this->actingAs($member)->get(route('clients.show', $project->client))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('clients/show', false)
                ->where('client.id', $project->client_id)
                ->where('client.projects_count', 1)
                ->has('client.projects', 1)
            );

        $this->actingAs($outsider)->get(route('clients.show', $project->client))->assertForbidden();
    }

    public function test_members_cannot_mutate_clients_and_nested_contacts_cannot_cross_clients(): void
    {
        $manager = $this->makeUser('project_manager');
        $member = $this->makeUser('member');
        $firstClient = $this->makeProject($manager)->client;
        $secondClient = Client::query()->create(['code' => 'CL-OTHER', 'name' => 'Other', 'status' => 'active']);
        $contact = $firstClient->contacts()->create(['name' => 'Scoped Contact', 'is_active' => true]);

        $this->actingAs($member)->post(route('clients.store'), [
            'code' => 'CL-DENIED',
            'name' => 'Denied',
        ])->assertForbidden();

        $this->actingAs($manager)->put(route('clients.contacts.update', [$secondClient, $contact]), [
            'name' => 'Wrong Parent',
            'is_active' => true,
        ])->assertNotFound();

        $this->assertFalse(Route::has('clients.destroy'));
        $this->assertFalse(Route::has('clients.contacts.destroy'));
    }
}
