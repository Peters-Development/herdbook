<?php

namespace App\Mcp\Tools\Concerns;

use App\Mcp\HiddenValues;
use App\Mcp\Serializer;
use App\Rules\NoAddressMasks;
use App\Rules\NoCredentials;
use App\Rules\NoImagesOrHtml;
use App\Rules\NoNewLinks;
use App\Rules\NoSecretMaterial;
use Carbon\CarbonInterface;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * What the tools that write share, whatever they write: the rules for the
 * texts agents write (stricter than for people: no credentials, no images,
 * HTML or links to other sites, no masks of addresses), times in ISO 8601,
 * and the version an agent gives back when it changes what is there.
 */
trait WritesTexts
{
    use RequiresWriteAccess, ValidatesArguments;

    protected static function momentFormat(): string
    {
        return 'ISO 8601: 2026-11-12T22:00 (in '.config('herdbook.display_timezone').'), or with an offset (Z, +01:00).';
    }

    /**
     * Markdown that agents may write: what MAX_TEXT allows (first: the other
     * checks take time on a long text), no private keys or credentials, no
     * masks, no images or HTML, and no links to other sites. A text that
     * replaces one may keep the links of the one it replaces, which only the
     * locked row tells: a tool that passes $linksCheckedOnLockedRow must
     * call checkTexts() there.
     *
     * @return list<mixed>
     */
    protected function textRules(bool $linksCheckedOnLockedRow = false): array
    {
        return [...$this->plainTextRules(Serializer::MAX_TEXT), new NoImagesOrHtml, ...($linksCheckedOnLockedRow ? [] : [new NoNewLinks])];
    }

    /**
     * Text the panel shows as text (an impact, a note, an action item): no
     * private keys or credentials, no masks.
     *
     * @return list<mixed>
     */
    protected function plainTextRules(int $max): array
    {
        return ['bail', 'nullable', 'string', 'max:'.$max, new NoSecretMaterial, new NoCredentials, new NoAddressMasks];
    }

    /**
     * @param  string  $when  when the tool needs it
     * @param  string  $from  the tools that give it
     */
    protected function versionSchemaFrom(JsonSchema $schema, string $when, string $from): Type
    {
        return $schema->string()->max(64)->description('Its "version", from '.$from.' or the answer of your last change to it; '.$when.' Refused when someone changed it since.');
    }

    /**
     * @return list<string>
     */
    protected function versionRules(bool $required = false): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:64'];
    }

    /**
     * Texts given for $record, on its locked row: not over one the agent
     * couldn't read whole (cut, as it read it: masked), and with no links to
     * other sites but those of the text each replaces.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, string>  $markdown  argument => attribute, for its markdown texts
     * @param  array<string, string>  $plain  argument => attribute, for texts the panel shows as text (no links to check)
     */
    protected function checkTexts(Model $record, array $changes, array $markdown, array $plain = []): void
    {
        $hidden = app(HiddenValues::class);
        $cut = [];
        $links = [];

        foreach ([...$markdown, ...$plain] as $argument => $attribute) {
            if (! array_key_exists($argument, $changes)) {
                continue;
            }

            $masked = (string) $hidden->mask($record->{$attribute});

            if (mb_strlen($masked) > Serializer::MAX_TEXT || ($masked === $hidden->hiddenText() && filled($record->{$attribute}))) {
                $cut[$argument] = __('This text is longer than an agent reads whole: change it in Herdbook itself.');
            }

            if (isset($markdown[$argument])) {
                $links[$argument] = [new NoNewLinks($record->{$attribute})];
            }
        }

        if ($cut !== []) {
            throw self::named(ValidationException::withMessages($cut));
        }

        $validator = Validator::make(array_intersect_key($changes, $links), $links);

        if ($validator->fails()) {
            throw self::named(new ValidationException($validator));
        }
    }

    /**
     * Whether what a call gives is what is stored: nothing, or a repeat of
     * a call whose answer was lost.
     *
     * @param  array<string, mixed>  $given  as it would be stored
     */
    protected function asStored(Model $record, array $given): bool
    {
        foreach ($given as $field => $value) {
            $stored = $record->{$field};
            $same = $value instanceof CarbonInterface ? $stored instanceof CarbonInterface && $value->eq($stored) : $value === $stored;

            if (! $same) {
                return false;
            }
        }

        return true;
    }

    /**
     * What a description tells an agent about the texts it writes;
     * $replacing: over a text that is there, whose links may stay.
     */
    protected static function textsSentence(string $what, bool $replacing = true): string
    {
        return $what.' is markdown of at most '.number_format(Serializer::MAX_TEXT).' characters, and refuses passwords, tokens, private keys, masks such as [address of app-1], images, HTML and links to other sites (put tags, placeholders and web addresses between backticks; link into Herdbook with a path, such as [app-1](/servers/1); '
            .($replacing ? 'links already in the text you replace, and ' : '')
            .'email addresses such as deploy@app-1.example.com in an ssh command, may stay). ';
    }

    /**
     * What a description tells an agent about texts it can't change.
     *
     * @param  string  $readWith  the tool that lists them in "truncated"
     */
    protected static function cutTextsSentence(string $what, string $readWith): string
    {
        return $what.' that '.$readWith.' lists in "truncated" can only be changed in Herdbook itself. ';
    }

    /**
     * @param  array<int|string>  $ids
     * @return list<int>
     */
    protected static function ids(array $ids): array
    {
        return array_values(array_unique(array_map(intval(...), $ids)));
    }
}
