<?php

namespace App\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Icon;
use Filament\Schemas\Components\Section;
use Filament\Support\Icons\Heroicon;
use Livewire\Component;

/**
 * Explanations next to inputs, which each account can turn off. On, an
 * explanation shows under its field or section heading; off, it moves to an
 * info icon next to the label or heading (still there on hovering).
 *
 * It sets the helper text and hint icon (a field) or the description and
 * after-header slot (a section): set those after it and they win.
 *
 * Only for what explains a field. What the app has to say about its own
 * state (a token that is missing, the amount per month) is no hint: it
 * always shows, as a helper text or a hint of its own.
 */
final class Hints
{
    public static function shown(): bool
    {
        return Filament::auth()->user()?->shows_hints ?? true;
    }

    /**
     * ->explain() on fields and sections (AdminPanelProvider::boot()).
     */
    public static function register(): void
    {
        Field::macro('explain', function (string|Closure|null $text): Field {
            /** @var Field $this */
            return $this
                ->helperText(fn (): ?string => Hints::shown() ? $this->evaluate($text) : null)
                ->hintIcon(
                    fn (): ?Heroicon => Hints::shown() || blank($this->evaluate($text)) ? null : Heroicon::OutlinedInformationCircle,
                    fn (): ?string => Hints::shown() ? null : $this->evaluate($text),
                );
        });

        Section::macro('explain', function (string|Closure|null $text): Section {
            /** @var Section $this */
            return $this
                ->description(fn (): ?string => Hints::shown() ? $this->evaluate($text) : null)
                // After the header actions, which Section::setUp() puts there.
                ->afterHeader(fn (): array => [
                    ...$this->getHeaderActions(),
                    ...(Hints::shown() || blank($this->evaluate($text)) ? [] : [
                        Icon::make(Heroicon::OutlinedInformationCircle)->color('gray')->tooltip($this->evaluate($text)),
                    ]),
                ]);
        });
    }

    /**
     * "Hide explanations" / "Show explanations" in the user menu; the page
     * reloads with the other setting (as it is: the same address).
     */
    public static function toggleAction(): Action
    {
        return Action::make('toggleHints')
            ->label(fn (): string => self::shown() ? __('Hide explanations') : __('Show explanations'))
            ->icon(fn (): Heroicon => self::shown() ? Heroicon::OutlinedEyeSlash : Heroicon::OutlinedLightBulb)
            ->action(function (Component $livewire): void {
                $user = Filament::auth()->user();
                $user->update(['shows_hints' => ! $user->shows_hints]);

                $livewire->js('window.location.reload()');
            });
    }
}
