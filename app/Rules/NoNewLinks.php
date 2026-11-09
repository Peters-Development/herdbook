<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Markdown from agents links to no other site: a misled agent could plant a
 * link that leads somewhere else than it says, or carries data along in its
 * address. Links into Herdbook itself pass (relative, or to its own host),
 * and so do the links of the text it replaces ($replaced), so an agent can
 * add a step to a plan a person wrote without breaking that person's links.
 * Checked on the parsed document, autolinks such as "www.example.com" and
 * email addresses included. Parsing takes time and memory: put a length
 * rule before it, with "bail".
 */
class NoNewLinks implements ValidationRule
{
    public function __construct(private readonly ?string $replaced = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $kept = $this->replaced === null ? [] : self::links($this->replaced);

        foreach (self::links($value) as $url) {
            if (! in_array($url, $kept, true) && ! self::intoHerdbook($url)) {
                $fail(__('No links to other sites: put addresses between backticks.'));

                return;
            }
        }
    }

    /**
     * Where the links of $markdown lead.
     *
     * @return list<string>
     */
    public static function links(string $markdown): array
    {
        $document = (new MarkdownParser((new GithubFlavoredMarkdownConverter)->getEnvironment()))->parse($markdown);
        $links = [];

        foreach ($document->iterator() as $node) {
            if ($node instanceof Link) {
                $links[] = $node->getUrl();
            }
        }

        return $links;
    }

    /**
     * A relative link, or one to the host of APP_URL or of this request
     * (an agent may reach Herdbook on another of its addresses).
     */
    private static function intoHerdbook(string $url): bool
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return false;
        }

        if (! isset($parts['scheme']) && ! isset($parts['host'])) {
            return ! str_starts_with($url, '//');
        }

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && in_array(strtolower($parts['host'] ?? ''), array_filter([
                strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
                strtolower(request()->getHost()),
            ]), true);
    }
}
