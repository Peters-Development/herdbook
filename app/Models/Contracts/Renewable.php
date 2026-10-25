<?php

namespace App\Models\Contracts;

use App\Enums\CostPeriod;
use App\Enums\RenewalStatus;
use App\Models\Client;
use App\Models\Concerns\HasCost;
use App\Models\Concerns\HasRenewal;
use App\Models\Domain;
use App\Models\Network;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\Subscription;
use BcMath\Number;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Something with a contract: it expires, is renewed and costs money. Domains,
 * servers, subscriptions and the internet line of a network, through
 * HasRenewal and HasCost.
 *
 * @property int $id
 * @property string $name
 * @property ?CarbonImmutable $expires_on
 * @property bool $auto_renews
 * @property int $renewal_period_months
 * @property ?CarbonImmutable $renewal_stopped_at
 * @property ?string $cost_amount
 * @property ?CostPeriod $cost_period
 *
 * @see HasRenewal
 * @see HasCost
 */
interface Renewable
{
    /**
     * The activity-log description of every renewal, stored in the database
     * and translated when it is shown (ActivityPresenter). Never reword it:
     * stored entries would no longer be recognised.
     */
    public const string RENEWED = 'Renewed until :date';

    /**
     * Every renewable model. A new one also goes into RenewalSchema
     * (resource(), icon(), mono()).
     *
     * @var list<class-string<Model&Renewable>>
     */
    public const array MODELS = [Domain::class, Server::class, Subscription::class, Network::class];

    /**
     * The relation of the provider whose renewal lead time applies when the
     * item has none, and whose cost it is (HasRenewal::renewalProvider()).
     */
    public static function renewalProviderRelation(): string;

    /**
     * What costProjects() and costClients() read, to eager-load it.
     *
     * @return list<string>
     */
    public static function costRelations(): array;

    public function renewalProvider(): ?Provider;

    /**
     * The projects it is for (more than one: shared).
     *
     * @return Collection<int, Project>
     */
    public function costProjects(): Collection;

    /**
     * The clients it is for; null: ours (more than one: shared).
     *
     * @return Collection<int, ?Client>
     */
    public function costClients(): Collection;

    public function hasContract(): bool;

    public function renewalStatus(?CarbonInterface $today = null): RenewalStatus;

    public function effectiveRenewalLeadDays(): int;

    public function canBeRenewed(): bool;

    public function renewedExpiryDate(): ?CarbonImmutable;

    public function renewalTerm(): string;

    public function renew(): void;

    public function stopRenewal(): void;

    public function resumeRenewal(): void;

    public function canHaveCost(): bool;

    public function hasCost(): bool;

    public function yearlyCost(): ?Number;

    public function renewalCost(): ?Number;
}
