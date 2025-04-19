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

require_once '../vendor/autoload.php';

\define('APP_NAME', 'Pohoda RaiffeisenBank Offline Statements');

$options = getopt('i::e::o::', ['input::environment::output::']);
$statementFile = \array_key_exists('i', $options) ? $options['i'] : (\array_key_exists('input', $options) ? $options['input'] : Shared::cfg('STATEMENT_FILE', 'php://stdin'));
$destination = \array_key_exists('o', $options) ? $options['o'] : (\array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout'));

/**
 * Get today's Statements list.
 */
\Ease\Shared::init(
    ['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'POHODA_BANK_IDS', 'ACCOUNT_NUMBER'],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'));

if (Shared::cfg('APP_DEBUG')) {
    $engine->logBanner('', 'Importing file: '.$statementFile);
}

$report['input'] = $statementFile;

if ($statementFile) {
    $engine->takeXmlStatementFile($statementFile);

    $report['inserted'] = $engine->import(Shared::cfg('POHODA_BANK_IDS', ''));
    $report['messages'] = $engine->getMessages();
    $report['exitcode'] = $engine->getExitCode();

    $exitcode = $engine->getExitCode();

    $written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
    $engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');
} else {
    $exitcode = 1;
    fwrite(fopen('php://stderr', 'wb'), 'No input file (-i) provided. (cwd: '.getcwd().')'.\PHP_EOL);
}

exit($exitcode);
