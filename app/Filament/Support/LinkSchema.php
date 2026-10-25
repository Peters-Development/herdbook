<?php

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * "Related to": the form fields and infolist entries for incidents,
 * maintenance, documents and credential references (models with
 * LinksToInventory).
 */
final class LinkSchema
{
    /**
     * "?link=project:12" on a create page preselects that item.
     */
    public const string QUERY_PARAMETER = 'link';

    /**
     * Relationship => morph alias of the linked model.
     *
     * @var array<string, string>
     */
    private const array RELATIONSHIPS = [
        'projects' => 'project',
        'servers' => 'server',
        'domains' => 'domain',
        'networks' => 'network',
        'clients' => 'client',
    ];

    public static function linkKey(Model $record): string
    {
        return $record->getMorphClass().':'.$record->getKey();
    }

    /**
     * @param  list<string>|null  $relationships  which of RELATIONSHIPS to offer; null: all
     */
    public static function formSection(?array $relationships = null): Section
    {
        $relationships ??= array_keys(self::RELATIONSHIPS);

        return Section::make(__('Related to'))
            ->description(__('Link it to the :items it is about. It then shows up on their page.', ['items' => self::describe($relationships)]))
            ->columns(2)
            ->collapsible()
            ->schema(array_map(self::select(...), $relationships));
    }

    /**
     * @param  list<string>|null  $relationships  which of RELATIONSHIPS to show; null: all
     */
    public static function infolistSection(?array $relationships = null): Section
    {
        $relationships ??= array_keys(self::RELATIONSHIPS);

        return Section::make(__('Related to'))
            ->columns(count($relationships))
            ->schema(array_map(self::entry(...), $relationships));
    }

    /**
     * @return array<string, string> relationship => label
     */
    private static function labels(): array
    {
        return [
            'projects' => __('Projects'),
            'servers' => __('Servers'),
            'domains' => __('Domains'),
            'networks' => __('Networks'),
            'clients' => __('Clients'),
        ];
    }

    /**
     * "projects, servers and domains": clients stay out of the sentence,
     * they are rarely what something is about.
     *
     * @param  list<string>  $relationships
     */
    private static function describe(array $relationships): string
    {
        $items = collect($relationships)
            ->reject(fn (string $relationship): bool => $relationship === 'clients')
            ->map(fn (string $relationship): string => match ($relationship) {
                'projects' => __('projects'),
                'servers' => __('servers'),
                'domains' => __('domains'),
                'networks' => __('networks'),
            })
            ->values();

        return $items->count() > 1
            ? __(':items and :last', ['items' => $items->slice(0, -1)->implode(', '), 'last' => $items->last()])
            : (string) $items->first();
    }

    private static function select(string $relationship): Select
    {
        return Select::make($relationship)
            ->label(self::labels()[$relationship])
            ->relationship($relationship, 'name')
            ->multiple()
            ->preload()
            ->searchable()
            ->default(fn (): array => self::fromRequest($relationship));
    }

    /**
     * The item named in ?link=, when it belongs to $relationship and exists.
     *
     * @return list<int>
     */
    private static function fromRequest(string $relationship): array
    {
        $link = request()->query(self::QUERY_PARAMETER);
        [$alias, $id] = array_pad(explode(':', is_string($link) ? $link : '', 2), 2, null);

        if ($alias !== self::RELATIONSHIPS[$relationship] || ! ctype_digit((string) $id)) {
            return [];
        }

        $model = Relation::getMorphedModel($alias);

        return $model !== null && $model::query()->whereKey((int) $id)->exists() ? [(int) $id] : [];
    }

    private static function entry(string $relationship): TextEntry
    {
        return TextEntry::make("{$relationship}.name")
            ->label(self::labels()[$relationship])
            ->badge()
            ->placeholder('—');
    }
}
