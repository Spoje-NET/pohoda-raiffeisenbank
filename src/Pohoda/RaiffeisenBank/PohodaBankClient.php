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

    // public Response $response; TODO: Update
    protected $constantor;
    protected $constSymbols;
    protected \DateTime $since;
    protected \DateTime $until;
    protected $bank;

    /**
     * Transaction Handler.
     *
     * @param string $bankAccount Account Number
     * @param array  $options
     */
    public function __construct($bankAccount, $options = [])
    {
        parent::__construct();
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
        return substr(__FILE__ . '@' . gethostname(), -50);
    }

    /**
     * Try to check certificate readibilty.
     *
     * @param string $certFile path to certificate
     */
    public static function checkCertificatePresence($certFile): void
    {
        if ((file_exists($certFile) === false) || (is_readable($certFile) === false)) {
            fwrite(\STDERR, 'Cannot read specified certificate file: ' . $certFile . \PHP_EOL);

            exit(1);
        }
    }

    /**
     * Prepare processing interval.
     *
     * @param string $scope
     *
     * @throws \Exception
     */
    public function setScope($scope): void
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
                $this->since = new \DateTime('first day of January ' . date('Y'));
                $this->until = new \DateTime('last day of December' . date('Y'));

                break;
            case 'January':  // 1
            case 'February': // 2
            case 'March':    // 3
            case 'April':    // 4
            case 'May':      // 5
            case 'June':     // 6
            case 'July':     // 7
            case 'August':   // 8
            case 'September':// 9
            case 'October':  // 10
            case 'November': // 11
            case 'December': // 12
                $this->since = new \DateTime('first day of ' . $scope . ' ' . date('Y'));
                $this->until = new \DateTime('last day of ' . $scope . ' ' . date('Y'));

                break;
            case 'auto':
                $latestRecord = $this->getColumnsFromPohoda(['id', 'lastUpdate'], ['limit' => 1, 'order' => 'lastUpdate@A', 'source' => $this->sourceString(), 'banka' => $this->bank]);

                if (\array_key_exists(0, $latestRecord) && \array_key_exists('lastUpdate', $latestRecord[0])) {
                    $this->since = $latestRecord[0]['lastUpdate'];
                } else {
                    $this->addStatusMessage('Previous record for "auto since" not found. Defaulting to today\'s 00:00', 'warning');
                    $this->since = (new \DateTime())->setTime(0, 0);
                }

                $this->until = new \DateTime(); // Now

                break;

            default:
                throw new \Exception('Unknown scope ' . $scope);

                break;
        }

        if ($scope !== 'auto' && $scope !== 'today' && $scope !== 'yesterday') {
            $this->since = $this->since->setTime(0, 0);
            $this->until = $this->until->setTime(0, 0);
        }
    }

    /**
     * Request Identifier.
     *
     * @return string
     */
    public function getxRequestId()
    {
        return $this->getDataValue('account') . time();
    }

    /**
     * Obtain Current Currency.
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
        return false; // !empty($this->getColumnsFromPohoda('id', ['cisDosle' => $this->getDataValue('cisDosle')])); TODO
    }

    /**
     * @param string $conSym
     */
    public function ensureKSExists($conSym): void
    {
        if (!\array_key_exists($conSym, $this->constSymbols)) {
            $this->constantor->insertToPohoda(['kod' => $conSym, 'poznam' => 'Created by Raiffeisen Bank importer', 'nazev' => '?!?!? ' . $conSym]);
            $this->constantor->addStatusMessage('New constant ' . $conSym . ' created in flexibee', 'warning');
            $this->constSymbols[$conSym] = $conSym;
        }
    }

    /**
     * @param int $success
     *
     * @return int
     */
    public function insertTransactionToPohoda($success)
    {
        $producedId = '';
        $producedNumber = '';
        $producedAction = '';

        if ($this->checkForTransactionPresence() === false) {
            try {
                $cache = $this->getData();
                $this->reset();
                // TODO: $result = $this->sync();
                $result = $this->addToPohoda($cache);

                if ($this->commit()) {
                    ++$success;
                }

                if (property_exists($this->response, 'producedDetails') && \is_array($this->response->producedDetails)) {
                    $producedId = $this->response->producedDetails['id'];
                    $producedNumber = $this->response->producedDetails['number'];
                    $producedAction = $this->response->producedDetails['actionType'];
                } else {
                    echo '';
                }
            } catch (\Pohoda\Exception $exc) {
                $producedId = 'n/a';
                $producedNumber = 'n/a';
                $producedAction = 'n/a';
            }

            $this->addStatusMessage('#' . $producedId . ' ' . $producedAction . ' ' . $producedNumber, $result ? 'success' : 'error'); // TODO: Parse response for docID
        } else {
            $this->addStatusMessage('Record with remoteNumber TODO already present in Pohoda', 'warning');
        }

        return $success;
    }
}
