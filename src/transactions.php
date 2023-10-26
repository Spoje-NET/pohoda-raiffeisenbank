<?php

/**
 * RaiffeisenBank - Transaction importer
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

require_once('../vendor/autoload.php');
/**
 * Get today's tramsactons list
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER'], isset($argv[1]) ? $argv[1] : '../.env');
Transactor::checkCertificatePresence(\Ease\Functions::cfg('CERT_FILE'));
$engine = new Transactor(\Ease\Functions::cfg('ACCOUNT_NUMBER'));
$engine->setScope(\Ease\Functions::cfg('TRANSACTION_IMPORT_SCOPE', 'yesterday'));
$engine->import();
