<?php

namespace App\Filament\Support;

use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;

/**
 * Who wrote a record down, and through which agent: "Ada via deploy-agent"
 * (RecordsAuthor). Named after user.name, so Filament eager-loads the user.
 */
final class RecordedBy
{
    public static function column(): TextColumn
    {
        return TextColumn::make('user.name')->label(__('By'))->state(fn (Model $record): ?string => $record->recordedBy())->placeholder('—')->toggleable();
    }

    public static function entry(): TextEntry
    {
        return TextEntry::make('user.name')->label(__('By'))->state(fn (Model $record): ?string => $record->recordedBy())->placeholder('—');
    }
}
