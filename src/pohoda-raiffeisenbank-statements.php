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

\define('APP_NAME', 'Pohoda RaiffeisenBank Statements');
$exitcode = 0;
/**
 * Get today's Statements list.
 */
$options = getopt('o::e::', ['output::environment::']);
Shared::init(
    [
        'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
        'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
    ],
    \array_key_exists('environment', $options) ? $options['environment'] : '../.env',
);
$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));

$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));

if (Shared::cfg('STATEMENT_LINE')) {
    $engine->setStatementLine(Shared::cfg('STATEMENT_LINE'));
}

$engine->logBanner($engine->statementLine, 'Scope: '.$engine->scope);
$report = [
    'sharepoint' => [],
    'pohoda' => [],
    'pohodaSQL' => [],
];

try {
    $engine->downloadXML();
} catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
    $report['mesage'] = $exc->getMessage();

    $exitcode = $exc->getCode();

    if (!$exitcode) {
        if (preg_match('/cURL error ([0-9]*):/', $report['mesage'], $codeRaw)) {
            $exitcode = (int) $codeRaw[1];
        }
    }
}

if ($engine->isOnline()) {
    $report['inserted'] = $engine->import();
    $report['messages'] = $engine->getMessages();
    $report['exitcode'] = $engine->getExitCode();
} else {
    $engine->addStatusMessage('Error accesing mServer '.$engine->lastCurlResponse, 'error');
    $report['exitcode'] = 3;
}

if ($engine->getExitCode()) {
    $exitcode = $engine->getExitCode();
}

$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
