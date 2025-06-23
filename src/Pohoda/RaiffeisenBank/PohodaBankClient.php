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

/**
 * Description of ApiClient.
 *
 * @author vitex
 */
abstract class PohodaBankClient extends \mServer\Bank
{
    /**
     * DateTime Formating eg. 2021-08-01T10:00:00.0Z.
     */
    public static string $dateTimeFormat = 'Y-m-d\\TH:i:s.0\\Z';

    /**
     * DateTime Formating eg. 2021-08-01T10:00:00.0Z.
     */
    public static string $dateFormat = 'Y-m-d';
    public string $currency;
    protected \DateTime $since;
    protected \DateTime $until;
    protected string $bankIDS;
    private int $exitCode = 0;

    /**
     * Transaction Handler.
     *
     * @param string                $bankAccount Account Number
     * @param array<string, string> $options
     */
    public function __construct(string $bankAccount, $options = [])
    {
        parent::__construct(null, $options);
        $this->setDataValue('account', $bankAccount);
        //        $this->constantor = new \Pohoda\RW(null, ['evidence' => 'konst-symbol']);
        //        $this->constSymbols = $this->constantor->getColumnsFromPohoda(['kod'], ['limit' => 0], 'kod');
    }

    /**
     * Source Identifier.
     *
     * @return string
     */
    public function sourceString()
    {
        return substr(__FILE__.'@'.gethostname(), -50);
    }

    /**
     * Try to check certificate.
     *
     * @param string $certFile path to certificate
     * @param string $password
     */
    public static function checkCertificate($certFile, $password): bool
    {
        return self::checkCertificatePresence($certFile) && self::checkCertificatePassword($certFile, $password);
    }

    /**
     * Try to check certificate readability.
     *
     * @param string $certFile path to certificate
     */
    public static function checkCertificatePresence($certFile): bool
    {
        if ((file_exists($certFile) === false) || (is_readable($certFile) === false)) {
            fwrite(\STDERR, 'Cannot read specified certificate file: '.$certFile.\PHP_EOL);

            return false;
        }

        return true;
    }
    /**
     * Try to check certificate readability.
     *
     * @param string $certFile path to certificate
     * @param string $password
     */
    public static function checkCertificatePassword($certFile, $password): bool
    {
        $certContent = file_get_contents($certFile);

        if (openssl_pkcs12_read($certContent, $certs, $password) === false) {
            fwrite(\STDERR, 'Cannot read PKCS12 certificate file: '.$certFile.\PHP_EOL);

            return false;
        }

        return true;
    }

    /**
     * Prepare processing interval.
     *
     * @throws \Exception
     *
     * @return \DatePeriod<\DateTime, \DateInterval, \DateTime>
     */
    public function setScope(string $scope): \DatePeriod
    {
        switch ($scope) {
            case 'today':
                $this->since = (new \DateTime())->setTime(0, 0);
                $this->until = (new \DateTime())->setTime(23, 59);

                break;
            case 'yesterday':
                $this->since = (new \DateTime('yesterday'))->setTime(0, 0);
                $this->until = (new \DateTime('yesterday'))->setTime(23, 59);

                break;
            case 'current_month':
                $this->since = new \DateTime('first day of this month');
                $this->until = new \DateTime();

                break;
            case 'last_month':
                $this->since = new \DateTime('first day of last month');
                $this->until = new \DateTime('last day of last month');

                break;
            case 'last_two_months':
                $this->since = (new \DateTime('first day of last month'))->modify('-1 month');
                $this->until = (new \DateTime('last day of last month'));

                break;
            case 'previous_month':
                $this->since = new \DateTime('first day of -2 month');
                $this->until = new \DateTime('last day of -2 month');

                break;
            case 'two_months_ago':
                $this->since = new \DateTime('first day of -3 month');
                $this->until = new \DateTime('last day of -3 month');

                break;
            case 'this_year':
                $this->since = new \DateTime('first day of January '.date('Y'));
                $this->until = new \DateTime('last day of December'.date('Y'));

                break;
            case 'January':  // 1
            case 'February': // 2
            case 'March':    // 3
            case 'April':    // 4
            case 'May':      // 5
            case 'June':     // 6
            case 'July':     // 7
            case 'August':   // 8
            case 'September': // 9
            case 'October':  // 10
            case 'November': // 11
            case 'December': // 12
                $this->since = new \DateTime('first day of '.$scope.' '.date('Y'));
                $this->until = new \DateTime('last day of '.$scope.' '.date('Y'));

                break;
            case 'auto':
                $latestRecord = $this->getColumnsFromPohoda(['id', 'lastUpdate'], ['limit' => 1, 'order' => 'lastUpdate@A', 'source' => $this->sourceString(), 'bank' => $this->bankIDS]);

                if (\array_key_exists(0, $latestRecord) && \array_key_exists('lastUpdate', $latestRecord[0])) {
                    $this->since = $latestRecord[0]['lastUpdate'];
                } else {
                    $this->addStatusMessage('Previous record for "auto since" not found. Defaulting to today\'s 00:00', 'warning');
                    $this->since = (new \DateTime())->setTime(0, 0);
                }

                $this->until = new \DateTime(); // Now

                break;

            default:
                if (strstr($scope, '>')) {
                    [$begin, $end] = explode('>', $scope);
                    $this->since = new \DateTime($begin);
                    $this->until = new \DateTime($end);
                } else {
                    if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])-(0[1-9]|[1-2][0-9]|3[0-1])$/', $scope)) {
                        $this->since = (new \DateTime($scope))->setTime(0, 0);
                        $this->until = (new \DateTime($scope))->setTime(23, 59, 59, 999);

                        break;
                    }

                    throw new \Exception('Unknown scope '.$scope);
                }

                break;
        }

        if ($scope !== 'auto' && $scope !== 'today' && $scope !== 'yesterday') {
            $this->since = $this->since->setTime(0, 0);
            $this->until = $this->until->setTime(23, 59, 59, 999);
        }

        return new \DatePeriod($this->since, new \DateInterval('P1D'), $this->until);
    }

    /**
     * Request Identifier.
     *
     * @return string
     */
    public function getxRequestId()
    {
        return $this->getDataValue('account').time();
    }

    /**
     * Obtain Current Currency.
     *
     * @return string
     */
    public function getCurrencyCode()
    {
        return $this->currency;
    }

    /**
     * Is Record with current remoteNumber already present in Pohoda ?
     *
     * @todo Implement using Pohoda API UserList
     */
    public function checkForTransactionPresence(): bool
    {
        /** @var null|string[] $transactions */
        static $transactions = null;

        if ($transactions === null) {
            $columns = $this->getColumnsFromPohoda(['intNote'], ['dateFrom' => $this->since->format(self::$dateFormat)]);
            $transactions = \array_unique(\array_filter(\array_column($columns, 'intNote')));
        }

        return \in_array($this->getDataValue('intNote'), $transactions, strict: true);
    }

    /**
     * Insert Transaction to Pohoda.
     *
     * @return array<int, array<string, string>> Imported Transactions
     */
    public function insertTransactionToPohoda(string $bankIDS = ''): array
    {
        $producedId = '';
        $producedNumber = '';
        $producedAction = '';
        $result = [];
        $transactionId = $this->getDataValue('intNote');

        if ($this->checkForTransactionPresence()) {
            $this->addStatusMessage("Transaction with ID '{$transactionId}' already present in Pohoda", 'warning');
            $result['message'] = "Duplicate transaction: {$transactionId}";
            $result['success'] = false;
        } else {
            try {
                $cache = $this->getData();
                $result['id'] = $transactionId;
                $this->reset();

                // TODO: $result = $this->sync();
                if ($bankIDS) {
                    $cache['account'] = $bankIDS;
                }

                $this->takeData($cache);

                if ($this->addToPohoda() && $this->commit() && isset($this->response->producedDetails) && \is_array($this->response->producedDetails)) {
                    $producedId = $this->response->producedDetails['id'];
                    $producedNumber = $this->response->producedDetails['number'];
                    $producedAction = $this->response->producedDetails['actionType'];
                    $result['details'] = $this->response->producedDetails;
                    $result['messages'] = $this->response->messages;
                    $this->automaticLiquidation($producedNumber);
                    $this->addStatusMessage('Bank #'.$producedId.' '.$producedAction.' '.$producedNumber, 'success'); // TODO: Parse response for docID
                    $result['success'] = true;
                } else {
                    $result['success'] = false;
                    $resultMessages = $this->messages;

                    if (\array_key_exists('error', $resultMessages) && \count($resultMessages['error'])) {
                        foreach ($resultMessages['error'] as $errMsg) {
                            $result['messages'][] = 'error: '.$errMsg;
                        }

                        $this->exitCode = 401;
                    }
                }
            } catch (\Exception $exc) {
                $producedId = 'n/a';
                $producedNumber = 'n/a';
                $producedAction = 'n/a';
                $result['message'] = $exc->getMessage();
                $result['success'] = false;
                $this->exitCode = $exc->getCode() ?: 254;
            }
        }

        return $result;
    }

    /**
     * Enable automatic liquidation.
     *
     * @see https://www.stormware.cz/schema/version_2/liquidation.xsd for details
     * @see https://www.stormware.cz/xml/samples/version_2/import/Banka/Bank_03_v2.0.xml
     * @see https://github.com/riesenia/pohoda/issues/49
     *
     * @param mixed $producedNumber
     *
     * @return bool
     */
    public function automaticLiquidation($producedNumber)
    {
        /*
          <lqd:automaticLiquidation version="2.0">
          <!-- výběr agendy -->
          <lqd:record>
          <!-- Výběr záznamu z agendy agenda -->
          <!-- budou vybrány pouze záznamy/pohyby v agendě, které mají částku k likvidaci > 0kč a dále splňují podmínku filtru -->
          <ftr:filter>
          <!-- výběr záznamů dle čísla účtu --><!-- <ftr:bankAccount> <typ:id>2</typ:id> <typ:ids>CS</typ:ids> </ftr:bankAccount> -->
          <!-- výběr záznamů dle datum pohybu --><!-- <ftr:dateFrom>2022-12-27</ftr:dateFrom> --><!-- datum od --><!-- <ftr:dateTill>2022-12-31</ftr:dateTill> -->
          <!-- datum do --><!-- výběr záznamů dle nové a změně záznamy/pohybu -->
          <!-- <ftr:lastChanges>2023-01-09T08:30:00</ftr:lastChanges> -->
          <!-- záznamy změněné od zadaného data a času -->
          <!-- <ftr:selectedNumbers> <ftr:number> <typ:numberRequested>KB0010003</typ:numberRequested> </ftr:number> </ftr:selectedNumbers> -->
          <!-- <ftr:bankAccount> <typ:id>3</typ:id> </ftr:bankAccount> -->
          </ftr:filter>
          </lqd:record>
          <!-- Výber pravidla párování dokladů -->
          <lqd:ruleOfPairing>
          <typ:id>1</typ:id>
          <!-- <typ:ids>Výpisy</typ:ids> -->
          </lqd:ruleOfPairing>
          </lqd:automaticLiquidation>

               <ftr:selectedNumbers>
                    <ftr:number>
                      <typ:numberRequested>KB0010003</typ:numberRequested>
                    </ftr:number>
                </ftr:selectedNumbers>


         */

        file_put_contents($this->xmlCache, $this->generateAutomaticLiquidationXML($producedNumber));

        $this->addStatusMessage('Automatic liquidation', 'success');

        $this->setPostFields(file_get_contents($this->xmlCache));

        if ($this->debug) {
            $this->addStatusMessage('validate request by: xmllint --schema '.\dirname(__DIR__, 3).'/vendor/vitexsoftware/pohoda-connector/doc/xsd/data.xsd '.$this->xmlCache.' --noout', 'debug');
        }

        return $this->performRequest('/xml');
    }

    /**
     * Generate XML for automatic liquidation.
     *
     * @param mixed $producedNumber
     *
     * @return string
     */
    public function generateAutomaticLiquidationXML($producedNumber)
    {
        $xmlString = <<<'EOD'
<?xml version="1.0" encoding="Windows-1250"?>
<dat:dataPack xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"
              xmlns:lqd="http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd"
              xmlns:ftr="http://www.stormware.cz/schema/version_2/filter.xsd"
              xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd"
              version="2.0" id="01" ico="
EOD.$this->getCompanyId().<<<'EOD'
" application="Tisk" note="aut. livkidace dokladů tisk z programu Pohoda">
</dat:dataPack>
EOD;

        $xml = new \SimpleXMLElement($xmlString);

        $dataPackItem = $xml->addChild('dat:dataPackItem');
        $dataPackItem->addAttribute('version', '2.0');
        $dataPackItem->addAttribute('id', '001');

        $automaticLiquidation = $dataPackItem->addChild('automaticLiquidation', null, 'http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd');
        $automaticLiquidation->addAttribute('version', '2.0');

        $record = $automaticLiquidation->addChild('record', null, 'http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd');

        $filter = $record->addChild('filter', null, 'http://www.stormware.cz/schema/version_2/filter.xsd');

        $selectedNumbers = $filter->addChild('selectedNumbers');

        $ftrNumber = $selectedNumbers->addChild('number');

        $numberRequested = $ftrNumber->addChild('numberRequested', $producedNumber, 'http://www.stormware.cz/schema/version_2/type.xsd');

        $ruleOfPairing = $automaticLiquidation->addChild('lqd:ruleOfPairing', null, 'http://www.stormware.cz/schema/version_2/automaticLiquidation.xsd');
        $ruleOfPairing->addChild('id', '1', 'http://www.stormware.cz/schema/version_2/type.xsd');

        return $xml->asXML();
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    public function getCompanyId(): string
    {
        return Shared::cfg('POHODA_ICO');
    }
}
