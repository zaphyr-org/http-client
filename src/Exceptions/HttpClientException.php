<?php

declare(strict_types=1);

namespace Zaphyr\HttpClient\Exceptions;

use Exception;
use Psr\Http\Client\ClientExceptionInterface;

/**
 * @author merloxx <merloxx@zaphyr.org>
 */
class HttpClientException extends Exception implements ClientExceptionInterface
{
}
