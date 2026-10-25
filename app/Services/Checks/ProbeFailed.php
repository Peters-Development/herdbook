<?php

namespace App\Services\Checks;

use RuntimeException;

/**
 * A network probe that did not get an answer; the message is in the active
 * language (it becomes the check's error).
 */
final class ProbeFailed extends RuntimeException {}
