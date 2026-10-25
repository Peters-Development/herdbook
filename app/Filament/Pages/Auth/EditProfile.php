<?php

namespace App\Filament\Pages\Auth;

use App\Services\Alerts\AlertFailed;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\MailChannel;
use App\Services\Alerts\PushoverChannel;
use Filament\Actions\Action;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Filament's profile page plus the language of the interface and where this
 * user gets alerts.
 */
class EditProfile extends BaseEditProfile
{
    private const string USER_KEY = '/^[A-Za-z0-9]{30}$/';

    /** The language before this save: a new one reloads the page in it. */
    private ?string $previousLocale = null;

    private bool $localeChanged = false;

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
                Select::make('locale')
                    ->label(__('Language'))
                    ->options(config('herdbook.locales'))
                    ->placeholder(fn (): string => __('Default (:language)', [
                        'language' => config('herdbook.locales')[config('herdbook.default_locale')] ?? config('herdbook.default_locale'),
                    ])),
                Section::make(__('Alerts'))
                    ->description(__('An alert goes out through the first channel that works, and only through that one. Critical checks (production down): Pushover, then ntfy, then email. Everything else: ntfy, then email, then Pushover. On an iPhone, only Pushover breaks through Do Not Disturb.'))
                    ->schema([
                        TextInput::make('pushover_user_key')
                            ->label(__('Pushover user key'))
                            ->helperText(fn (): string => filled(config('herdbook.alerts.pushover_token'))
                                ? __('At the top of the Pushover app or on pushover.net. The bell next to it sends a test alert, even before you save.')
                                : __('At the top of the Pushover app or on pushover.net. Herdbook has no application token yet (PUSHOVER_APP_TOKEN in .env): until then, nothing goes out through Pushover.'))
                            ->regex(self::USER_KEY)
                            ->validationMessages(['regex' => __('A user key is 30 letters and digits.')])
                            ->maxLength(50)
                            ->suffixAction($this->testPushoverAction()),
                        Toggle::make('receives_critical_alerts')
                            ->label(__('Receive alerts through Pushover')),
                        Toggle::make('receives_alert_mail')
                            ->label(__('Receive alerts by email'))
                            ->helperText(fn (): string => app(MailChannel::class)->canDeliver()
                                ? __('To the email address above, for the alerts that go out by email: when ntfy (and for critical checks Pushover) is not set up or fails.')
                                : __('Herdbook cannot send mail yet: set MAIL_* in .env (a mailer that writes to the log does not count). Until then, nothing goes out by email.')),
                    ]),
            ]);
    }

    protected function beforeSave(): void
    {
        $this->previousLocale = $this->getUser()->getAttribute('locale');
    }

    protected function afterSave(): void
    {
        $user = $this->getUser();

        if ($user->getAttribute('locale') === $this->previousLocale) {
            return;
        }

        // The "saved" notification in the new language, then the whole page.
        $this->localeChanged = true;
        app()->setLocale($user->preferredLocale() ?? config('herdbook.default_locale'));
    }

    protected function getRedirectUrl(): ?string
    {
        return $this->localeChanged ? static::getUrl() : null;
    }

    /**
     * A test alert to the key in the field, saved or not: "is my key right,
     * and does it come through Do Not Disturb?"
     */
    private function testPushoverAction(): Action
    {
        return Action::make('testPushover')
            ->label(__('Send a test alert'))
            ->icon(Heroicon::OutlinedBellAlert)
            ->visible(fn (): bool => filled(config('herdbook.alerts.pushover_token')))
            ->modalHeading(__('Test Pushover'))
            ->modalDescription(__('Sends an alert to the user key in the field, even if it has not been saved yet.'))
            ->modalSubmitActionLabel(__('Send'))
            ->schema([
                Radio::make('kind')
                    ->label(__('Kind of alert'))
                    ->options([
                        'normal' => __('Normal alert'),
                        'urgent' => __('Emergency alert, as for a critical outage'),
                    ])
                    ->descriptions([
                        'urgent' => __('Repeats every minute until you acknowledge it in the Pushover app (for an hour at most), and breaks through Do Not Disturb on an iPhone if you allowed critical alerts for Pushover.'),
                    ])
                    ->default('normal')
                    ->required(),
            ])
            ->action(function (?string $state, array $data, PushoverChannel $pushover): void {
                $key = trim((string) $state);

                if (preg_match(self::USER_KEY, $key) !== 1) {
                    Notification::make()->danger()->title(__('Enter a valid user key first'))->body(__('A user key is 30 letters and digits.'))->send();

                    return;
                }

                $urgent = $data['kind'] === 'urgent';
                $sentAt = now(config('herdbook.display_timezone'))->translatedFormat(__('formats.datetime'));

                try {
                    $pushover->test($key, new AlertMessage(
                        title: $urgent ? __('Herdbook: emergency alert test') : __('Herdbook: test alert'),
                        body: $urgent
                            ? __('Acknowledge this alert in the app to stop it repeating. Sent :time.', ['time' => $sentAt])
                            : __('Pushover works. Sent :time.', ['time' => $sentAt]),
                        problem: $urgent,
                        critical: $urgent,
                        tag: $urgent ? 'herdbook-test-'.auth()->id() : null,
                    ));
                } catch (AlertFailed $failure) {
                    $unreachable = $failure->getCode() === PushoverChannel::UNREACHABLE;

                    Notification::make()
                        ->danger()
                        ->title($unreachable ? __('Pushover cannot be reached') : __('Pushover did not accept the alert'))
                        ->body($unreachable
                            ? __('Herdbook got no answer from Pushover. Does the server Herdbook runs on have internet access?')
                            : __('Reason given by Pushover: :reason', ['reason' => $failure->getMessage()]))
                        ->persistent()
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('Test alert sent'))
                    ->body($urgent
                        ? __('It repeats every minute until you acknowledge it in the Pushover app.')
                        : __('It reaches your phone within a few seconds. Do not forget to save if the key is new.'))
                    ->send();
            });
    }
}
