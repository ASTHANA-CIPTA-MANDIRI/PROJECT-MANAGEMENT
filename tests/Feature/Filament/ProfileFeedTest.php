<?php

namespace Tests\Feature\Filament;

use App\Http\Livewire\Profile;
use App\Models\Organization;
use App\Models\ProfilePost;
use App\Models\ProfilePostComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileFeedTest extends TestCase
{
    use RefreshDatabase;

    private function orgWith(User ...$users): Organization
    {
        $organization = Organization::factory()->create();

        foreach ($users as $user) {
            $organization->users()->attach($user->id, ['role' => 'member']);
        }

        return $organization;
    }

    public function test_user_can_post_and_org_members_see_it(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $organization = $this->orgWith($me, $mate);

        $this->actingAs($me);
        Livewire::test(Profile::class)
            ->set('postBody', 'Halo tim')
            ->call('createPost')
            ->assertSet('postBody', '');

        $this->assertDatabaseHas('profile_posts', ['user_id' => $me->id, 'organization_id' => $organization->id, 'body' => 'Halo tim']);

        $this->actingAs($mate);
        Livewire::test(Profile::class)->assertSee('Halo tim');
    }

    public function test_post_body_is_required_and_escaped(): void
    {
        $me = User::factory()->create();
        $this->orgWith($me);
        $this->actingAs($me);

        Livewire::test(Profile::class)->set('postBody', '')->call('createPost')->assertHasErrors('postBody');

        ProfilePost::create(['user_id' => $me->id, 'organization_id' => null, 'body' => '<script>alert(1)</script>']);
        Livewire::test(Profile::class)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_other_organization_posts_are_invisible_and_untouchable(): void
    {
        $me = User::factory()->create();
        $stranger = User::factory()->create();
        $this->orgWith($me);
        $other = $this->orgWith($stranger);
        $post = ProfilePost::create(['user_id' => $stranger->id, 'organization_id' => $other->id, 'body' => 'Rahasia org lain']);

        $this->actingAs($me);
        Livewire::test(Profile::class)
            ->assertDontSee('Rahasia org lain')
            ->call('toggleLike', $post->id)
            ->set('commentDrafts', [$post->id => 'nyelonong'])
            ->call('addComment', $post->id)
            ->call('deletePost', $post->id);

        $this->assertSame(0, $post->likes()->count());
        $this->assertSame(0, ProfilePostComment::count());
        $this->assertNotNull($post->fresh());
    }

    public function test_like_toggles_and_comment_is_saved(): void
    {
        $me = User::factory()->create();
        $this->orgWith($me);
        $post = ProfilePost::create(['user_id' => $me->id, 'organization_id' => $me->organizations()->first()->id, 'body' => 'Post']);

        $this->actingAs($me);
        Livewire::test(Profile::class)
            ->call('toggleLike', $post->id)
            ->assertSee('Post');
        $this->assertSame(1, $post->likes()->count());

        Livewire::test(Profile::class)->call('toggleLike', $post->id);
        $this->assertSame(0, $post->likes()->count());

        Livewire::test(Profile::class)
            ->set('commentDrafts', [$post->id => 'Mantap'])
            ->call('addComment', $post->id);
        $this->assertDatabaseHas('profile_post_comments', ['profile_post_id' => $post->id, 'user_id' => $me->id, 'body' => 'Mantap']);
    }

    public function test_only_author_can_delete_post_or_comment(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $organization = $this->orgWith($me, $mate);
        $post = ProfilePost::create(['user_id' => $mate->id, 'organization_id' => $organization->id, 'body' => 'Milik teman']);
        $comment = ProfilePostComment::create(['profile_post_id' => $post->id, 'user_id' => $mate->id, 'body' => 'Komentar teman']);

        $this->actingAs($me);
        Livewire::test(Profile::class)->call('deletePost', $post->id)->call('deleteComment', $comment->id);
        $this->assertNotNull($post->fresh());
        $this->assertNotNull($comment->fresh());

        $this->actingAs($mate);
        Livewire::test(Profile::class)->call('deleteComment', $comment->id)->call('deletePost', $post->id);
        $this->assertNull($post->fresh());
    }

    public function test_old_posts_are_pruned_only_when_retention_is_enabled(): void
    {
        $me = User::factory()->create();
        $old = ProfilePost::create(['user_id' => $me->id, 'organization_id' => null, 'body' => 'lama']);
        $old->forceFill(['created_at' => now()->subDays(400)])->save();
        $new = ProfilePost::create(['user_id' => $me->id, 'organization_id' => null, 'body' => 'baru']);

        config(['app.profile_post_retention_days' => 0]);
        $this->artisan('model:prune', ['--model' => ProfilePost::class]);
        $this->assertSame(2, ProfilePost::count());

        config(['app.profile_post_retention_days' => 365]);
        $this->artisan('model:prune', ['--model' => ProfilePost::class]);
        $this->assertNull($old->fresh());
        $this->assertNotNull($new->fresh());
    }

    public function test_posting_is_rate_limited(): void
    {
        $me = User::factory()->create();
        $this->orgWith($me);
        $this->actingAs($me);

        $component = Livewire::test(Profile::class);
        foreach (range(1, 21) as $i) {
            $component->set('postBody', "p$i")->call('createPost');
        }

        $this->assertSame(20, ProfilePost::count());
    }

    public function test_page_survives_a_deleted_author(): void
    {
        $me = User::factory()->create();
        $gone = User::factory()->create(['name' => 'Budi Hilang']);
        $organization = $this->orgWith($me, $gone);
        $post = ProfilePost::create(['user_id' => $gone->id, 'organization_id' => $organization->id, 'body' => 'Post lama']);
        ProfilePostComment::create(['profile_post_id' => $post->id, 'user_id' => $gone->id, 'body' => 'Komentar lama']);
        $gone->delete();

        $this->actingAs($me);
        Livewire::test(Profile::class)->assertSuccessful()->assertSee('Post lama')->assertSee('Budi Hilang');
    }

    public function test_feed_is_locked_when_the_organization_trial_has_ended(): void
    {
        $me = User::factory()->create();
        $organization = Organization::factory()->create(['trial_ends_at' => now()->subDay()]);
        $organization->users()->attach($me->id, ['role' => 'member']);
        $post = ProfilePost::create(['user_id' => $me->id, 'organization_id' => $organization->id, 'body' => 'Sebelum terkunci']);

        $this->actingAs($me);
        Livewire::test(Profile::class)
            ->assertDontSee('Sebelum terkunci')
            ->set('postBody', 'Tidak boleh')
            ->call('createPost')
            ->call('toggleLike', $post->id)
            ->set('commentDrafts', [$post->id => 'Tidak boleh'])
            ->call('addComment', $post->id)
            ->call('deletePost', $post->id);

        $this->assertSame(1, ProfilePost::count());
        $this->assertSame(0, $post->likes()->count());
        $this->assertSame(0, ProfilePostComment::count());
    }

    public function test_feed_works_for_an_organization_with_an_active_trial_or_none(): void
    {
        $me = User::factory()->create();
        $organization = Organization::factory()->create(['trial_ends_at' => now()->addDays(3)]);
        $organization->users()->attach($me->id, ['role' => 'member']);

        $this->actingAs($me);
        Livewire::test(Profile::class)->set('postBody', 'Masih aktif')->call('createPost')->assertSee('Masih aktif');
    }

    public function test_format_escapes_html_before_applying_markers(): void
    {
        $html = (string) ProfilePost::format("<script>alert(1)</script> **tebal** *miring* __garis__\n- satu\n- dua\n1. a\nhttps://example.com/x?a=1&b=2");

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<strong>tebal</strong>', $html);
        $this->assertStringContainsString('<em>miring</em>', $html);
        $this->assertStringContainsString('<u>garis</u>', $html);
        $this->assertStringContainsString('<ul><li>satu</li><li>dua</li></ul>', $html);
        $this->assertStringContainsString('<ol><li>a</li></ol>', $html);
        $this->assertStringContainsString('<a href="https://example.com/x?a=1&amp;b=2" target="_blank" rel="noopener nofollow ugc">', $html);
    }

    public function test_timeline_shows_only_my_posts(): void
    {
        $me = User::factory()->create();
        $mate = User::factory()->create();
        $organization = $this->orgWith($me, $mate);
        ProfilePost::create(['user_id' => $me->id, 'organization_id' => $organization->id, 'body' => 'Punya saya']);
        ProfilePost::create(['user_id' => $mate->id, 'organization_id' => $organization->id, 'body' => 'Punya teman']);

        $this->actingAs($me);
        Livewire::test(Profile::class)
            ->assertSee('Punya teman')
            ->set('feedFilter', 'mine')
            ->assertSee('Punya saya')
            ->assertDontSee('Punya teman');
    }
}
