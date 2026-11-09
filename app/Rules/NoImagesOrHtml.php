<?php

namespace App\Rules;

use App\Rules\Concerns\ParsesMarkdown;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;

/**
 * Markdown from agents without images or HTML. An image loads the moment a
 * person opens the page, so a misled agent could send data off with its
 * address; HTML could lay a fake form over the page. Checked on the parsed
 * document, the way Filament renders it (GitHub-flavoured). The markdown
 * editor goes further: while text is edited it shows an image for any line
 * that starts like one, even where the page renders text ("![a](x y)", or
 * inside a link title that runs over lines). So a line that starts with
 * "![" is refused as well, whatever surrounds it. Code spans, code blocks
 * and links stay. Parsing takes time and memory: put a length rule before
 * it, with "bail".
 */
class NoImagesOrHtml implements ValidationRule
{
    use ParsesMarkdown;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        // The editor splits lines on \r too.
        if (preg_match('/^!\[/m', str_replace(["\r\n", "\r"], "\n", $value)) === 1) {
            $fail(__('No images or HTML: put tags and placeholders between backticks.'));

            return;
        }

        foreach (self::parseMarkdown($value)->iterator() as $node) {
            if ($node instanceof Image || $node instanceof HtmlInline || $node instanceof HtmlBlock) {
                $fail(__('No images or HTML: put tags and placeholders between backticks.'));

                return;
            }
        }
    }
}
