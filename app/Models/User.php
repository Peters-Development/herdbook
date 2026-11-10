<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'locale', 'shows_hints', 'password', 'pushover_user_key', 'receives_critical_alerts', 'receives_alert_mail'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * No language of its own: the default (APP_LOCALE). Set here too, so a
     * freshly created account can be asked for it (strict models).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => null,
        'shows_hints' => true,
    ];

    /**
     * The agent token this request came in with (AuthenticateAgent), if any.
     * A property, not an attribute: it is never stored.
     */
    private ?AgentToken $currentAgentToken = null;

    /**
     * Every account is an administrator: there is no registration, accounts
     * are created on the command line (`users:create`).
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Whether the account has set up its second factor. The same check as
     * Filament's AppAuthentication::isEnabled().
     */
    public function hasTwoFactor(): bool
    {
        return filled($this->getAppAuthenticationSecret());
    }

    /**
     * The agents that may use the MCP server for this account.
     *
     * @return HasMany<AgentToken, $this>
     */
    public function agentTokens(): HasMany
    {
        return $this->hasMany(AgentToken::class);
    }

    /**
     * @return int how many were revoked
     */
    public function revokeAgentTokens(): int
    {
        return AgentToken::revoke($this->agentTokens()->get());
    }

    public function withAgentToken(AgentToken $token): static
    {
        $this->currentAgentToken = $token;

        return $this;
    }

    /**
     * The agent acting for this account in this request; null: a person.
     * Tools read it from `$request->user()?->currentAgentToken()`. They never
     * type-hint AgentToken: the container would build an empty one.
     */
    public function currentAgentToken(): ?AgentToken
    {
        return $this->currentAgentToken;
    }

    /**
     * The language this account picked in its profile, as long as Herdbook
     * still speaks it; null: the default (APP_LOCALE). Laravel also uses it
     * for mail and notifications sent to this model; Herdbook's alerts are
     * composed once, in the default language, and go to addresses.
     */
    public function preferredLocale(): ?string
    {
        return array_key_exists((string) $this->locale, config('herdbook.locales')) ? $this->locale : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'shows_hints' => 'boolean',
            'password' => 'hashed',
            'receives_critical_alerts' => 'boolean',
            'receives_alert_mail' => 'boolean',
        ];
    }
}
