<?php

/**
 * RaiffeisenBank - BankClient class
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

/**
 * Description of ApiClient
 *
 * @author vitex
 */
abstract class PohodaBankClient extends \mServer\Bank
{
    protected $constantor;

    protected $constSymbols;

    /**
     *
     * @var \DateTime
     */
    protected $since;

    /**
     *
     * @var \DateTime
     */
    protected $until;

    /**
     * DateTime Formating eg. 2021-08-01T10:00:00.0Z
     * @var string
     */
    public static $dateTimeFormat = 'Y-m-d\\TH:i:s.0\\Z';

    /**
     * DateTime Formating eg. 2021-08-01T10:00:00.0Z
     * @var string
     */
    public static $dateFormat = 'Y-m-d';

    /**
     *
     * @var
     */
    protected $bank;

    /**
     * Transaction Handler
     *
     * @param string $bankAccount Account Number
     * @param array $options
     */
    public function __construct($bankAccount, $options = [])
    {
        parent::__construct();
        $this->setDataValue('account', $bankAccount);
//        $this->constantor = new \Pohoda\RW(null, ['evidence' => 'konst-symbol']);
//        $this->constSymbols = $this->constantor->getColumnsFromPohoda(['kod'], ['limit' => 0], 'kod');
    }

    /**
     * Source Identifier
     *
     * @return string
     */
    public function sourceString()
    {
        return substr(__FILE__ . '@' . gethostname(), -50);
    }

    /**
     * Try to check certificate readibilty
     *
     * @param string $certFile path to certificate
     */
    public static function checkCertificatePresence($certFile)
    {
        if ((file_exists($certFile) === false) || (is_readable($certFile) === false)) {
            fwrite(STDERR, 'Cannot read specified certificate file: ' . $certFile . PHP_EOL);
            exit;
        }
    }

    /**
     * Prepare processing interval
     *
     * @param string $scope
     *
     * @throws \Exception
     */
    function setScope($scope)
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
                $this->since = new \DateTime("first day of this month");
                $this->until = new \DateTime();
                break;
            case 'last_month':
                $this->since = new \DateTime("first day of last month");
                $this->until = new \DateTime("last day of last month");
                break;
            case 'last_two_months':
                $this->since = (new \DateTime("first day of last month"))->modify('-1 month');
                $this->until = (new \DateTime("last day of last month"));
                break;
            case 'previous_month':
                $this->since = new \DateTime("first day of -2 month");
                $this->until = new \DateTime("last day of -2 month");
                break;
            case 'two_months_ago':
                $this->since = new \DateTime("first day of -3 month");
                $this->until = new \DateTime("last day of -3 month");
                break;
            case 'this_year':
                $this->since = new \DateTime('first day of January ' . date('Y'));
                $this->until = new \DateTime("last day of December" . date('Y'));
                break;
            case 'January':  //1
            case 'February': //2
            case 'March':    //3
            case 'April':    //4
            case 'May':      //5
            case 'June':     //6
            case 'July':     //7
            case 'August':   //8
            case 'September'://9
            case 'October':  //10
            case 'November': //11
            case 'December': //12
                $this->since = new \DateTime('first day of ' . $scope . ' ' . date('Y'));
                $this->until = new \DateTime('last day of ' . $scope . ' ' . date('Y'));
                break;
            case 'auto':
                $latestRecord = $this->getColumnsFromPohoda(['id', 'lastUpdate'], ['limit' => 1, 'order' => 'lastUpdate@A', 'source' => $this->sourceString(), 'banka' => $this->bank]);
                if (array_key_exists(0, $latestRecord) && array_key_exists('lastUpdate', $latestRecord[0])) {
                    $this->since = $latestRecord[0]['lastUpdate'];
                } else {
                    $this->addStatusMessage('Previous record for "auto since" not found. Defaulting to today\'s 00:00', 'warning');
                    $this->since = (new \DateTime())->setTime(0, 0);
                }
                $this->until = new \DateTime(); //Now
                break;
            default:
                throw new \Exception('Unknown scope ' . $scope);
                break;
        }
        if ($scope != 'auto' && $scope != 'today' && $scope != 'yesterday') {
            $this->since = $this->since->setTime(0, 0);
            $this->until = $this->until->setTime(0, 0);
        }
    }

    /**
     * Request Identifier
     *
     * @return string
     */
    public function getxRequestId()
    {
        return $this->getDataValue('account') . time();
    }

    /**
     * Obtain Current Currency
     *
     * @return string
     */
    public function getCurrencyCode()
    {
        return \Ease\Shared::cfg('ACCOUNT_CURRENCY', 'CZK');
    }

    /**
     * Is Record with current remoteNumber already present in Pohoda ?
     *
     * @return bool
     */
    public function checkForTransactionPresence()
    {
        return false; //!empty($this->getColumnsFromPohoda('id', ['cisDosle' => $this->getDataValue('cisDosle')])); TODO
    }

    /**
     *
     * @param string $conSym
     */
    public function ensureKSExists($conSym)
    {
        if (!array_key_exists($conSym, $this->constSymbols)) {
            $this->constantor->insertToPohoda(['kod' => $conSym, 'poznam' => 'Created by Raiffeisen Bank importer', 'nazev' => '?!?!? ' . $conSym]);
            $this->constantor->addStatusMessage('New constant ' . $conSym . ' created in flexibee', 'warning');
            $this->constSymbols[$conSym] = $conSym;
        }
    }

    /**
     *
     * @param int $success
     *
     * @return int
     */
    public function insertTransactionToPohoda($success)
    {
        if ($this->checkForTransactionPresence() === false) {
            try {
                $cache = $this->getData();
                $this->reset();
                //TODO: $result = $this->sync();
                $result = $this->addToPohoda($cache);
                $this->commit();
                $success++;
            } catch (\Pohoda\Exception $exc) {
            }
            $this->addStatusMessage('New entry ', $result ? 'success' : 'error');
        } else {
            $this->addStatusMessage('Record with remoteNumber ' . 'TODO' . ' already present in Pohoda', 'warning');
        }
        return $success;
    }
}
