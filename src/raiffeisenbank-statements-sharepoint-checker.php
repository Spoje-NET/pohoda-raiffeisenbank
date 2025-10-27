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
use Office365\Runtime\Auth\ClientCredential;
use Office365\Runtime\Auth\UserCredentials;
use Office365\SharePoint\ClientContext;

require_once '../vendor/autoload.php';

\define('APP_NAME', 'RaiffStatementCheck');
\define('FIXED_RATE', 1); // We check only presence of statement

/**
 * Get today's Statements list.
 */
$options = getopt('o::e::', ['output::environment::']);
Shared::init(
    [
        'OFFICE365_TENANT', 'OFFICE365_PATH', 'OFFICE365_SITE',
        'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER',
    ],
    \array_key_exists('environment', $options) ? $options['environment'] : (\array_key_exists('e', $options) ? $options['e'] : '../.env'),
);
$destination = \array_key_exists('output', $options) ? $options['output'] : Shared::cfg('RESULT_FILE', 'php://stdout');

$certValid = PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));
$engine = new Statementor(Shared::cfg('ACCOUNT_NUMBER'), ['user' => '', 'password' => '', 'ico' => '', 'url' => '', 'cnbCache' => 'none']);
$engine->setScope(Shared::cfg('IMPORT_SCOPE', 'last_month'));

if (Shared::cfg('APP_DEBUG')) {
    $engine->logBanner('', 'Scope: '.$engine->scope);
}

$exitcode = $certValid ? 0 : 1;
$fileUrls = [];
$report = [
    'account' => Shared::cfg('ACCOUNT_NUMBER'),
    'until' => $engine->getUntil()->format('Y-m-d'),
    'since' => $engine->getSince()->format('Y-m-d'),
    'certificate_valid' => $certValid,
    'missing' => [],
    'existing' => [],
];

if (!$certValid) {
    $engine->addStatusMessage(_('Certificate validation failed. Skipping statement check.'), 'error');
    $report['error'] = 'Invalid certificate';
} else {
    try {
        $pdfStatements = $engine->getStatementFilenames('pdf');

    if ($pdfStatements) {
        if (Shared::cfg('OFFICE365_USERNAME', false) && Shared::cfg('OFFICE365_PASSWORD', false)) {
            $credentials = new UserCredentials(Shared::cfg('OFFICE365_USERNAME'), Shared::cfg('OFFICE365_PASSWORD'));
            $engine->addStatusMessage('Using OFFICE365_USERNAME '.Shared::cfg('OFFICE365_USERNAME').' and OFFICE365_PASSWORD', 'debug');
        } else {
            $credentials = new ClientCredential(Shared::cfg('OFFICE365_CLIENTID'), Shared::cfg('OFFICE365_CLSECRET'));
            $engine->addStatusMessage('Using OFFICE365_CLIENTID '.Shared::cfg('OFFICE365_CLIENTID').' and OFFICE365_CLSECRET', 'debug');
        }

        $ctx = (new ClientContext('https://'.Shared::cfg('OFFICE365_TENANT').'.sharepoint.com/sites/'.Shared::cfg('OFFICE365_SITE')))->withCredentials($credentials);
        $targetFolder = $ctx->getWeb()->getFolderByServerRelativeUrl(Shared::cfg('OFFICE365_PATH'));

        $engine->addStatusMessage('ServiceRootUrl: '.$ctx->getServiceRootUrl(), 'debug');

        $sharepointFilesRaw = $targetFolder->getFiles()->get()->executeQuery();
        $sharepointFiles = [];

        // @phpstan-ignore foreach.nonIterable
        foreach ($sharepointFilesRaw as $fileInSharepint) {
            $sharepointFiles[$fileInSharepint->getName()] = $fileInSharepint->getServerRelativeUrl();
        }

        foreach ($pdfStatements as $pdfStatement) {
            if (\array_key_exists($pdfStatement, $sharepointFiles)) {
                $engine->addStatusMessage(sprintf(_('File %s exists in SharePoint'), $pdfStatement), 'success');
                $report['existing'][] = $pdfStatement;
            } else {
                $engine->addStatusMessage(sprintf(_('File %s does not exist in SharePoint'), $pdfStatement), 'warning');
                $report['missing'][] = $pdfStatement;
            }
        }
    }
    } catch (\VitexSoftware\Raiffeisenbank\ApiException $exc) {
        $report['mesage'] = $exc->getMessage();
        $engine->addStatusMessage($report['mesage'], 'error');
        $exitcode = $exc->getCode();

        if (!$exitcode) {
            if (preg_match('/cURL error ([0-9]*):/', $report['mesage'], $codeRaw)) {
                $exitcode = (int) $codeRaw[1];
            }
        }
    }
}

$written = file_put_contents($destination, json_encode($report, Shared::cfg('DEBUG') ? \JSON_PRETTY_PRINT : 0));
$engine->addStatusMessage(sprintf(_('Saving result to %s'), $destination), $written ? 'success' : 'error');

exit($exitcode);
