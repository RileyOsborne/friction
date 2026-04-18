<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Game;
use App\Models\Category;
use App\Models\Topic;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class UserScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_are_redirected_to_login()
    {
        $this->get(route('games.index'))->assertRedirect(route('login'));
        $this->get(route('categories.index'))->assertRedirect(route('login'));
    }

    public function test_users_can_login_with_username()
    {
        $user = User::factory()->create([
            'username' => 'testuser',
            'password' => \Illuminate\Support\Facades\Hash::make('password123'),
        ]);

        Volt::test('pages.auth.login')
            ->set('username', 'testuser')
            ->set('password', 'password123')
            ->call('login')
            ->assertHasNoErrors()
            ->assertRedirect(route('games.index'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_users_can_only_see_their_own_games()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $game1 = Game::factory()->create(['user_id' => $user1->id, 'name' => 'User 1 Game']);
        $game2 = Game::factory()->create(['user_id' => $user2->id, 'name' => 'User 2 Game']);

        $this->actingAs($user1);

        Volt::test('pages.games.index')
            ->assertSee('User 1 Game')
            ->assertDontSee('User 2 Game');
    }

    public function test_users_cannot_access_others_games()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $game2 = Game::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1);

        $this->get(route('games.show', $game2))->assertStatus(403);
        $this->get(route('games.edit', $game2))->assertStatus(403);
        $this->get(route('games.control', $game2))->assertStatus(403);
    }

    public function test_users_can_only_see_their_own_categories()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $cat1 = Category::factory()->create(['user_id' => $user1->id, 'title' => 'User 1 Category']);
        $cat2 = Category::factory()->create(['user_id' => $user2->id, 'title' => 'User 2 Category']);

        $this->actingAs($user1);

        Volt::test('pages.categories.index')
            ->assertSee('User 1 Category')
            ->assertDontSee('User 2 Category');
    }

    public function test_users_cannot_access_others_categories()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $cat2 = Category::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1);

        $this->get(route('categories.show', $cat2))->assertStatus(403);
        $this->get(route('categories.edit', $cat2))->assertStatus(403);
    }

    public function test_users_can_only_see_their_own_topics()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $topic1 = Topic::factory()->create(['user_id' => $user1->id, 'name' => 'User 1 Topic']);
        $topic2 = Topic::factory()->create(['user_id' => $user2->id, 'name' => 'User 2 Topic']);

        $this->actingAs($user1);

        Volt::test('pages.categories.index')
            ->assertSee('User 1 Topic')
            ->assertDontSee('User 2 Topic');
    }

    public function test_users_cannot_delete_others_games()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $game2 = Game::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1);

        Volt::test('pages.games.index')
            ->set('gameToDelete', $game2->id)
            ->call('deleteGame');

        $this->assertDatabaseHas('games', ['id' => $game2->id]);
    }

    public function test_users_cannot_delete_others_categories()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $cat2 = Category::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1);

        Volt::test('pages.categories.index')
            ->set('categoryToDelete', $cat2->id)
            ->call('deleteCategory');

        $this->assertDatabaseHas('categories', ['id' => $cat2->id]);
    }

    public function test_users_cannot_delete_others_topics()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $topic2 = Topic::factory()->create(['user_id' => $user2->id]);

        $this->actingAs($user1);

        Volt::test('pages.categories.index')
            ->set('topicToDelete', $topic2->id)
            ->call('deleteTopic');

        $this->assertDatabaseHas('topics', ['id' => $topic2->id]);
    }
}
