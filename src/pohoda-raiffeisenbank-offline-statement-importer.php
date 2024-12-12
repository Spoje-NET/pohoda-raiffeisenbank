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

require_once '../vendor/autoload.php';

\define('APP_NAME', 'Pohoda RaiffeisenBank Offline Statements');

$options = getopt('i::e::', ['input::environment::']);
$statementFile = \array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('STATEMENT_FILE', 'php://stdin');
/**
 * Get today's Statements list.
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'POHODA_BANK_IDS', 'ACCOUNT_NUMBER'], \array_key_exists('environment', $options) ? $options['environment'] : '../.env');
$engine = new Statementor(\Ease\Shared::cfg('ACCOUNT_NUMBER'));
$engine->logBanner('', 'Importing file: '.$statementFile);

$engine->takeXmlStatementFile($statementFile);
$inserted = $engine->import(\Ease\Shared::cfg('POHODA_BANK_IDS', ''));
