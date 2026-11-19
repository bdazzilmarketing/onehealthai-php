<?php

namespace App\Support;

/**
 * An error whose message is meant for the caller (the equivalent of an Error
 * thrown by a server function in the original application). Anything else
 * that escapes a handler is answered as a generic failure and logged.
 */
class AppError extends \RuntimeException {}
