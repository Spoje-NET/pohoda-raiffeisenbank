<?php

/**
 * RaiffeisenBank - Inital setup.
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023-2024 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

require_once('../vendor/autoload.php');
/**
 * Get List of bank accounts and import it into Pohoda
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_LOGIN', 'POHODA_PASSWORD', 'POHODA_COMPANY', 'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID'], isset($argv[1]) ? $argv[1] : '../.env');
$apiInstance = new \VitexSoftware\Raiffeisenbank\PremiumAPI\GetAccountsApi();
$x_request_id = time(); // string | Unique request id provided by consumer application for reference and auditing.

Transactor::checkCertificatePresence(\Ease\Shared::cfg('CERT_FILE'));
try {
    $result = $apiInstance->getAccounts($x_request_id);
    if (array_key_exists('accounts', $result)) {
        $banker = new \Pohoda\RW(null, ['evidence' => 'bankovni-ucet']);
        if (\Ease\Shared::cfg('APP_DEBUG')) {
            $banker->logBanner($apiInstance->getConfig()->getUserAgent());
        }
        $currentAccounts = $banker->getColumnsFromPohoda(['id', 'kod', 'nazev', 'iban', 'bic', 'nazBanky', 'poznam'], ['limit' => 0], 'iban');
        foreach ($result['accounts'] as $account) {
            if (array_key_exists($account->iban, $currentAccounts)) {
                $banker->addStatusMessage(sprintf('Account %s already exists in flexibee as %s', $account->friendlyName, $currentAccounts[$account->iban]['kod']));
            } else {
                $banker->dataReset();
                $banker->setDataValue('kod', 'RB' . $account->accountId);
                $banker->setDataValue('nazev', $account->accountName);
                $banker->setDataValue('buc', $account->accountNumber);
                $banker->setDataValue('nazBanky', 'Raiffeisenbank');
                $banker->setDataValue('popis', $account->friendlyName);
                $banker->setDataValue('iban', $account->iban);
                $banker->setDataValue('smerKod', \Pohoda\RO::code($account->bankCode));
                $banker->setDataValue('bic', $account->bankBicCode);
                $saved = $banker->sync();
                $banker->addStatusMessage(
                    sprintf('Account %s registered in flexibee as %s', $account->friendlyName, $banker->getRecordCode()),
                    ($saved ? 'success' : 'error')
                );
            }
        }
    }
} catch (Exception $e) {
    echo 'Exception when calling GetAccountsApi->getAccounts: ', $e->getMessage(), PHP_EOL;
}
