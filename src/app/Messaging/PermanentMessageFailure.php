<?php

declare(strict_types=1);

namespace App\Messaging;

use RuntimeException;

/**
 * A retry can never fix this message, so it goes straight to the dead letter queue.
 */
class PermanentMessageFailure extends RuntimeException
{
}
