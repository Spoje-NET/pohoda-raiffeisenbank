<?php

declare(strict_types=1);

/**
 * This file is part of the PohodaRaiffeisenbank package
 *
 * https://github.com/Spoje-NET/pohoda-raiffeisenbank
 *
 * (c) Spoje.Net IT s.r.o. <https://spojenet.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Pohoda\RaiffeisenBank;

/**
 * A Microsoft Graph API call returned an error status. Mirrors
 * \Office365\Runtime\Http\RequestException's shape (getCode() = HTTP status,
 * getResponseBody() = raw response) so PohodaBankClientOffice::
 * describeRequestException() can handle both without callers caring which
 * transport (classic SharePoint REST vs Graph) actually failed.
 */
final class GraphApiException extends \RuntimeException
{
    public function __construct(string $message, int $httpCode, private readonly ?string $responseBody = null)
    {
        parent::__construct($message, $httpCode);
    }

    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }
}
