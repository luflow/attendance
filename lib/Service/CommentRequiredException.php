<?php

declare(strict_types=1);

namespace OCA\Attendance\Service;

/**
 * A "no" or "maybe" arrived without the reason the instance asks for. The
 * message is translated and meant for the person answering.
 */
final class CommentRequiredException extends \InvalidArgumentException {
}
