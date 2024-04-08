<?php

/**
 * RaiffeisenBank - Transaction importer
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023-2024 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

require_once('../vendor/autoload.php');
/**
 * Get today's tramsactons list
 */
\Ease\Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID', 'ACCOUNT_NUMBER'], isset($argv[1]) ? $argv[1] : '../.env');
Transactor::checkCertificatePresence(\Ease\Shared::cfg('CERT_FILE'));
$engine = new Transactor(\Ease\Shared::cfg('ACCOUNT_NUMBER'));
$engine->setScope(\Ease\Shared::cfg('TRANSACTION_IMPORT_SCOPE', 'yesterday'));
$engine->import();
