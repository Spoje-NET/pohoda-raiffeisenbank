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

class BankProbe extends \mServer\Bank
{
    use \Ease\datescope;
}

\define('APP_NAME', 'Pohoda Bank Statement Reporter');

$options = getopt('o::e::', ['output::environment::', 'scope::']);
Shared::init([
    'POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO',
    'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
], array_key_exists('environment', $options) ? $options['environment'] : '../.env');
$destination = array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');
$scope = array_key_exists('scope', $options) ? $options['scope'] : Shared::cfg('REPORT_SCOPE', 'yesterday');

$engine = new BankProbe();

$engine->setScope($scope);

if (Shared::cfg('APP_DEBUG', false)) {
    $engine->logBanner();
}

$status = 'ok';
$exitcode = 0;
$payments = [
    'source' => \Ease\Logger\Message::getCallerName($engine),
    'account' => Shared::cfg('ACCOUNT_NUMBER'),
    'status' => $status,
    'in' => [],
    'out' => [],
    'in_total' => 0,
    'out_total' => 0,
    'in_sum_total' => 0,
    'out_sum_total' => 0,
    'from' => $engine->getSince()->format('Y-m-d'),
    'to' => $engine->getUntil()->format('Y-m-d'),
];

try {
    $movements = $engine->getColumnsFromPohoda(['id', 'number', 'symVar'], ['dateFrom' => $engine->getSince()->format('Y-m-d'), 'dateTill' => $engine->getUntil()->format('Y-m-d')]);
} catch (\Exception $exc) {
    $status = $exc->getMessage();
    $exitcode = (int) $exc->getCode();
}

if (empty($movements) === false) {
    $payments['status'] = 'statement '.key($movements);

    try {
        foreach ($movements as $statement => $xmlFile) {
            $statementXml = simplexml_load_file($xmlFile);

            if ($statementXml === false) {
                $payments['status'] = 'Invalid XML file: '.$xmlFile;

                continue;
            }

            $statementArray = json_decode(json_encode($statementXml), true);
            $iban = $statementArray['BkToCstmrStmt']['Stmt']['Acct']['Id']['IBAN'] ?? null;

            if ($iban) {
                $payments['iban'] = $iban;
            }

            $entries = $statementArray['BkToCstmrStmt']['Stmt']['Ntry'] ?? [];

            if (isset($entries[0])) {
                // multiple entries
            } else {
                $entries = [$entries];
            }

            foreach ($entries as $payment) {
                $type = $payment['CdtDbtInd'] ?? '';
                $amount = (float) ($payment['Amt'] ?? 0);
                $date = $payment['BookgDt']['DtTm'] ?? null;

                if ($type === 'CRDT') {
                    $payments['in'][$date] = $amount;
                    $payments['in_sum_total'] += $amount;
                    ++$payments['in_total'];
                } elseif ($type === 'DBIT') {
                    $payments['out'][$date] = $amount;
                    $payments['out_sum_total'] += $amount;
                    ++$payments['out_total'];
                } else {
                    $engine->addStatusMessage(sprintf(_('Unknown CdtDbtInd value: %s'), $type), 'warning');
                }
            }
        }
    } catch (\Exception $exc) {
        $exitcode = (int) $exc->getCode();
        $payments['status'] = $exc->getMessage();
    }
} else {
    if ($exitcode === 0) {
        $payments['status'] = 'no movements returned';
    }
}

$written = file_put_contents($destination, json_encode($payments, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode ?: ($written ? 0 : 2));
