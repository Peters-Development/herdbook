<?php

namespace App\Rules;

use App\Rules\Concerns\ParsesMarkdown;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Inline\Text;

/**
 * Markdown from agents links to no other site: a misled agent could plant a
 * link that leads somewhere else than it says, or carries data along in its
 * address. Links into Herdbook pass: relative, or to the origin of APP_URL
 * (never the host a request names: a client chooses that). So do the links
 * of the text it replaces ($replaced; those $keeps lets through), so an
 * agent can add a step to a plan a person wrote without breaking that
 * person's links, and an email address
 * shown as itself ("ssh deploy@app-1.example.com" autolinks as one): it
 * opens a mail program, no more. Checked on the parsed document, other
 * autolinks included. Parsing takes time and memory: put a length rule
 * before it, with "bail".
 */
class NoNewLinks implements ValidationRule
{
    use ParsesMarkdown;

    /**
     * @param  (Closure(string): bool)|null  $keeps  which links of $replaced may stay, by their URL
     */
    public function __construct(private readonly ?string $replaced = null, private readonly ?Closure $keeps = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $kept = $this->replaced === null ? [] : array_map(fn (Link $link): string => $link->getUrl(), self::linksIn($this->replaced));
        $kept = $this->keeps === null ? $kept : array_values(array_filter($kept, $this->keeps));

        foreach (self::linksIn($value) as $link) {
            if (! in_array($link->getUrl(), $kept, true) && ! self::intoHerdbook($link->getUrl()) && ! self::showsItsAddress($link)) {
                $fail(__('No links to other sites: put addresses between backticks.'));

                return;
            }
        }
    }

    /**
     * @return list<Link>
     */
    private static function linksIn(string $markdown): array
    {
        $links = [];

        foreach (self::parseMarkdown($markdown)->iterator() as $node) {
            if ($node instanceof Link) {
                $links[] = $node;
            }
        }

        return $links;
    }

    /**
     * Relative, or on the origin of APP_URL: its scheme, host and port.
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

        $origin = parse_url((string) config('app.url'));

        return is_array($origin) && isset($origin['host']) && self::origin($parts) === self::origin($origin);
    }

    /**
     * @param  array<string, int|string>  $parts  from parse_url()
     */
    private static function origin(array $parts): string
    {
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        return $scheme.'://'.strtolower((string) ($parts['host'] ?? '')).':'.($parts['port'] ?? match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => '',
        });
    }

    /**
     * One plain email address that reads as itself, as GFM autolinks one:
     * no subject or body to fill in, no other recipients.
     */
    private static function showsItsAddress(Link $link): bool
    {
        if (preg_match('/^mailto:[^\s?,&%\/:@]+@[^\s?,&%\/:@]+\z/i', $link->getUrl()) !== 1) {
            return false;
        }

        $text = '';

        foreach ($link->iterator() as $node) {
            if ($node instanceof Text) {
                $text .= $node->getLiteral();
            }
        }

        return strcasecmp($text, substr($link->getUrl(), strlen('mailto:'))) === 0;
    }
}
