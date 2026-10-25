<?php

namespace App\Filament\Resources\Incidents\Schemas;

use App\Enums\IncidentSeverity;
use App\Enums\IncidentStatus;
use App\Filament\Support\LinkSchema;
use App\Rules\NoSecretMaterial;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class IncidentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Incident'))
                    ->columns(3)
                    ->schema([
                        TextInput::make('title')->label(__('Title'))->required()->maxLength(255)->columnSpanFull(),
                        Select::make('severity')->label(__('Severity'))->options(IncidentSeverity::class)->default(IncidentSeverity::Sev2)->required(),
                        Select::make('status')->label(__('Status'))->options(IncidentStatus::class)->default(IncidentStatus::Open)->required(),
                        DatePicker::make('occurred_on')
                            ->label(__('Date'))
                            ->native(false)
                            ->displayFormat(__('formats.date'))
                            ->default(fn () => now(config('herdbook.display_timezone'))->toDateString())
                            ->required(),
                        self::moment('started_at', __('Started')),
                        self::moment('detected_at', __('Detected'))
                            ->afterOrEqual('started_at')
                            ->validationMessages(['after_or_equal' => __('The detection time cannot be before the start.')]),
                        self::moment('resolved_at', __('Resolved'))
                            ->afterOrEqual('started_at')
                            ->validationMessages(['after_or_equal' => __('The resolution time cannot be before the start.')]),
                    ]),
                Section::make(__('Impact and root cause'))
                    ->schema([
                        Textarea::make('impact')
                            ->label(__('Impact'))
                            ->helperText(__('Who noticed what, and for how long?'))
                            ->rows(3)->maxLength(10000)
                            ->rules([new NoSecretMaterial]),
                        MarkdownEditor::make('root_cause')->label(__('Root cause'))->rules([new NoSecretMaterial]),
                    ]),
                Section::make(__('Report'))
                    ->collapsible()
                    ->schema([
                        MarkdownEditor::make('body')->label(__('Report'))->hiddenLabel()->rules([new NoSecretMaterial]),
                    ]),
                LinkSchema::formSection(),
            ]);
    }

    private static function moment(string $name, string $label): DateTimePicker
    {
        return DateTimePicker::make($name)
            ->label($label)
            ->native(false)
            ->displayFormat(__('formats.datetime'))
            ->seconds(false);
    }
}
