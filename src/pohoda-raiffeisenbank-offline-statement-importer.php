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

$exitcode = 0;
$options = getopt('i::e::', ['input::environment::']);
$statementFile = \array_key_exists('i', $options) ? $options['i'] : (\array_key_exists('input', $options) ? $options['input'] : \Ease\Shared::cfg('STATEMENT_FILE', 'php://stdin'));
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : \Ease\Shared::cfg('RESULT_FILE', 'php://stdout'));

/**
 * Get today's Statements list.
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'POHODA_BANK_IDS', 'ACCOUNT_NUMBER'], \array_key_exists('environment', $options) ? $options['environment'] : '../.env');
$engine = new Statementor(\Ease\Shared::cfg('ACCOUNT_NUMBER'));
$engine->logBanner('', 'Importing file: '.$statementFile);

$report['input'] = $statementFile;

$engine->takeXmlStatementFile($statementFile);

$report['inserted'] = $engine->import(\Ease\Shared::cfg('POHODA_BANK_IDS', ''));

$written = file_put_contents($destination, json_encode($report, \Ease\Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
