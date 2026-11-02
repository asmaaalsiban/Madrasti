<?php

declare(strict_types=1);

namespace App\Shared\Payments;

use RuntimeException;

/** A webhook that is not from the provider (bad signature) or cannot be understood. */
class InvalidWebhook extends RuntimeException {}
