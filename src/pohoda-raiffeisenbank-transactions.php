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

use Ease\Shared;

\define('APP_NAME', 'PohodaRBTransactions');

require_once '../vendor/autoload.php';
/**
 * Get today's transactions list.
 */
Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER'], $argv[1] ?? '../.env');
PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));
$engine = new Transactor(Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'yesterday'));
$engine->import();
