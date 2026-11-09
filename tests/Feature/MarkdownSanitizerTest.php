<?php

namespace Tests\Feature;

use App\Filament\Resources\Documents\DocumentResource;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsIn;
use Tests\TestCase;

class MarkdownSanitizerTest extends TestCase
{
    use RefreshDatabase, SignsIn;

    public function test_the_sanitizer_drops_attributes_that_restyle_the_page(): void
    {
        $html = (string) str('<p style="color:red" class="fi-modal-close-overlay" data-color="danger">Text</p>')->sanitizeHtml();

        $this->assertSame('<p>Text</p>', $html);
    }

    public function test_markdown_cannot_lay_anything_over_the_page(): void
    {
        $this->signIn();
        $document = Document::factory()->create(['body' => <<<'MARKDOWN'
            Restart the workers.

            <div style="position:fixed;inset:0;z-index:9999" class="evil-overlay" data-id="evil">Session expired, <a href="https://phish.example/login">sign in again</a></div>

            ![Diagram](https://example.com/diagram.png)
            MARKDOWN]);

        $this->get(DocumentResource::getUrl('view', ['record' => $document]))
            ->assertOk()
            ->assertDontSee('position:fixed', false)
            ->assertDontSee('evil-overlay', false)
            ->assertDontSee('data-id="evil"', false)
            // The text and its link stay, and images may still come over https.
            ->assertSee('Session expired')
            ->assertSee('href="https://phish.example/login"', false)
            ->assertSee('src="https://example.com/diagram.png"', false);
    }
}
