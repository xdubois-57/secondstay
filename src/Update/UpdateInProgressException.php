<?php

declare(strict_types=1);

namespace SecondStay\Update;

use RuntimeException;

/**
 * Une autre mise à jour tient le verrou : celle-ci n'a rien touché et peut
 * être retentée plus tard.
 */
final class UpdateInProgressException extends RuntimeException
{
}
