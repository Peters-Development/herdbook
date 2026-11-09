<?php

namespace App\Rules\Concerns;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Markdown parsed the way Filament renders it: GitHub-flavoured.
 */
trait ParsesMarkdown
{
    protected static function parseMarkdown(string $markdown): Document
    {
        return (new MarkdownParser((new GithubFlavoredMarkdownConverter)->getEnvironment()))->parse($markdown);
    }
}
