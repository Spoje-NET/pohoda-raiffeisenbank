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
/**
 * Get List of bank accounts and import it into Pohoda.
 */
Shared::init(['POHODA_URL', 'POHODA_USERNAME', 'POHODA_PASSWORD', 'POHODA_ICO', 'CERT_FILE', 'CERT_PASS', 'XIBMCLIENTID'], $argv[1] ?? '../.env');
$apiInstance = new \VitexSoftware\Raiffeisenbank\PremiumAPI\GetAccountsApi();

PohodaBankClient::checkCertificate(Shared::cfg('CERT_FILE'), Shared::cfg('CERT_PASS'));

/**
 * Grant the low-privilege runtime DB user (pohodaSQL-raiffeisenbank-statements-sharepoint.php,
 * pohoda-sharepoint-link-fixer.php) INSERT on DOC, so PohodaBankClient::attachSharepointUrl()
 * can write the SharePoint link attachment.
 *
 * Run this once per Pohoda MSSQL database with DB_* pointed at an account that has GRANT
 * rights (e.g. sa) - the standard MultiFlexi SQLServer/DatabaseConnection credential only
 * ever supplies DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD/DB_SETTINGS,
 * so that's the connection this admin credential fills in here; GRANT_INSERT_TO is a plain
 * (non-credential) field naming the separate low-privilege runtime account to grant to.
 */
if (Shared::cfg('GRANT_INSERT_TO', false)) {
    $adminDoc = new \SpojeNet\PohodaSQL\DOC(null, [
        'dbType' => Shared::cfg('DB_CONNECTION', 'sqlsrv'),
        'server' => Shared::cfg('DB_HOST'),
        'dbLogin' => Shared::cfg('DB_USERNAME'),
        'dbPass' => Shared::cfg('DB_PASSWORD'),
        'database' => Shared::cfg('DB_DATABASE'),
        'port' => Shared::cfg('DB_PORT', '1433'),
        'dbSettings' => Shared::cfg('DB_SETTINGS', ''),
    ]);
    $grantTo = Shared::cfg('GRANT_INSERT_TO');
    $adminDoc->getFluentPDO(true)->pdo->exec(\sprintf('GRANT INSERT ON dbo.DOC TO [%s]', $grantTo));
    echo \sprintf('Granted INSERT on DOC to %s', $grantTo).\PHP_EOL;
}
