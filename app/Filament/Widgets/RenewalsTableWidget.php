<?php

namespace App\Filament\Widgets;

use App\Filament\Support\RenewalSchema;
use App\Models\Contracts\Renewable;
use App\Services\RenewalAttention;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Renewals that need attention, for one renewable model. The statuses are
 * computed (RenewalAttention), the table shows those records.
 */
abstract class RenewalsTableWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /**
     * @return class-string<Model&Renewable>
     */
    abstract protected static function model(): string;

    abstract protected function heading(): string;

    /**
     * What the provider column is called: "Registrar" for domains.
     */
    protected function providerLabel(): string
    {
        return __('Provider');
    }

    /**
     * Shown only when something needs attention; domains are always shown.
     */
    public static function canView(): bool
    {
        return app(RenewalAttention::class)->for(static::model())->isNotEmpty();
    }

    /**
     * @return Builder<Model&Renewable>
     */
    protected function attentionQuery(): Builder
    {
        $model = static::model();

        return $model::query()
            ->with($model::renewalProviderRelation())
            ->whereKey(app(RenewalAttention::class)->for($model)->modelKeys());
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading($this->heading())
            ->query(fn (): Builder => $this->attentionQuery())
            ->defaultSort('expires_on')
            ->paginated(false)
            ->emptyStateHeading(__('Nothing to do'))
            ->emptyStateDescription(__('Everything is renewed or not due yet.'))
            ->columns([
                TextColumn::make('name')->label(__('Name'))->weight('medium'),
                TextColumn::make(static::model()::renewalProviderRelation().'.name')->label($this->providerLabel())->placeholder('—'),
                TextColumn::make('expires_on')->label(__('Expires'))->date(__('formats.date')),
                RenewalSchema::statusColumn(),
            ])
            ->recordUrl(fn (Renewable $record): string => RenewalSchema::url($record))
            ->recordActions([
                RenewalSchema::renewAction(),
            ]);
    }
}
