<?php

namespace App\Services\Alerts;

use RuntimeException;

/**
 * A notification that did not get out; CheckAlerts tries again later.
 */
final class AlertFailed extends RuntimeException {}
