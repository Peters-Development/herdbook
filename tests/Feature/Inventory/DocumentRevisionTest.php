<?php

namespace Tests\Feature\Inventory;

use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class DocumentRevisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_the_body_keeps_the_previous_version_with_its_author(): void
    {
        $user = User::factory()->create();
        $document = Document::factory()->create(['body' => 'versie 1']);

        $this->actingAs($user);
        $document->update(['body' => 'versie 2']);

        $revision = $document->revisions()->sole();
        $this->assertSame('versie 1', $revision->body);
        $this->assertTrue($revision->user->is($user));
    }

    public function test_editing_only_the_title_makes_no_revision(): void
    {
        $document = Document::factory()->create();

        $document->update(['title' => 'Nieuwe titel']);

        $this->assertSame(0, $document->revisions()->count());
    }

    public function test_the_body_stays_out_of_the_activity_log(): void
    {
        $document = Document::factory()->create(['body' => 'geheim lang verhaal']);

        $document->update(['title' => 'Andere titel', 'body' => 'nog langer verhaal']);

        $changes = Activity::query()->where('event', 'updated')->sole()->attribute_changes;
        $this->assertArrayHasKey('title', $changes['attributes']);
        $this->assertArrayNotHasKey('body', $changes['attributes']);
    }
}
