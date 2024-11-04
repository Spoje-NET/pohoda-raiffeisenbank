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
 * Handle bank transactions.
 *
 * @author vitex
 */
class Transactor extends PohodaBankClient
{

    /**
     * Transaction Handler.
     *
     * @param mixed $bankAccount
     * @param array $options
     */
    public function __construct($bankAccount, $options = [])
    {
        parent::__construct($bankAccount, $options);
    }

    /**
     * Obtain Transactions from RB.
     *
     * @return array
     */
    public function getTransactions()
    {
        $apiInstance = new \VitexSoftware\Raiffeisenbank\PremiumAPI\GetTransactionListApi();
        $page = 1;
        $transactions = [];
        $this->addStatusMessage(sprintf(_('Request transactions from %s to %s'), $this->since->format(self::$dateTimeFormat), $this->until->format(self::$dateTimeFormat)), 'debug');

        try {
            do {
                $result = $apiInstance->getTransactionList($this->getxRequestId(), $this->getDataValue('account'), $this->getCurrencyCode(), $this->since->format(self::$dateTimeFormat), $this->until->format(self::$dateTimeFormat), $page);

                if (empty($result)) {
                    $this->addStatusMessage(sprintf(_('No transactions from %s to %s'), $this->since->format(self::$dateTimeFormat), $this->until->format(self::$dateTimeFormat)));
                    $result['lastPage'] = true;
                }

                if (\array_key_exists('transactions', $result)) {
                    $transactions = array_merge($transactions, $result['transactions']);
                }
            } while ($result['lastPage'] === false);
        } catch (\VitexSoftware\Raiffeisenbank\ApiException $e) {
            echo 'Exception when calling GetTransactionListApi->getTransactionList: ', $e->getMessage(), \PHP_EOL;
            exit($e->getCode());
        }

        return $transactions;
    }

    /**
     * Import process itself.
     */
    public function import(): void
    {
        //        $allMoves = $this->getColumnsFromPohoda('id', ['limit' => 0, 'banka' => $this->bank]);
        $allTransactions = $this->getTransactions();
        $this->addStatusMessage(\count($allTransactions) . ' transactions obtained via API', 'debug');
        $success = 0;

        foreach ($allTransactions as $transaction) {
            // $this->dataReset();
            $this->takeTransactionData($transaction);
            $success = $this->insertTransactionToPohoda($success);
            $this->reset();
        }

        $this->addStatusMessage('Import done. ' . $success . ' of ' . \count($allTransactions) . ' imported');
    }

    /**
     * Use Transaction data for Bank record.
     *
     * @param array $transactionData
     */
    public function takeTransactionData($transactionData): void
    {
        //        $this->setMyKey(\Pohoda\RO::code('RB' . $transactionData->entryReference));
        $moveTrans = [
            'DBIT' => 'expense',
            'CRDT' => 'receipt',
        ];
        $this->setDataValue('bankType', $moveTrans[$transactionData->creditDebitIndication]);
        $this->setDataValue('account', \Ease\Shared::cfg('POHODA_BANK_IDS')); // RB
        $this->setDataValue('datePayment', (new \DateTime($transactionData->valueDate))->format('Y-m-d'));
        $this->setDataValue('intNote', _('Automatic Import') . ': ' . \Ease\Shared::appName() . ' ' . \Ease\Shared::appVersion() . ' ' . $transactionData->entryReference);
        $this->setDataValue('statementNumber', ['statementNumber' => $transactionData->bankTransactionCode->code]);
        $this->setDataValue('symPar', (string) $transactionData->entryReference);

        // $bankRecord = [
        // //    "MOSS" => ['ids' => 'AB'],
        //    'account' => 'KB',
        // //    "accounting",
        // //    "accountingPeriodMOSS",
        // //    "activity" => 'testing',
        //    'bankType' => 'receipt',
        // //    "centre",
        // //    "classificationKVDPH",
        // //    "classificationVAT",
        //    "contract" => 'n/a',
        //    "datePayment" => date('Y-m-d'),
        //    "dateStatement" => date('Y-m-d'),
        // //    "evidentiaryResourcesMOSS",
        //    "intNote" => 'Import works well',
        // //    "myIdentity",
        //    "note" => 'Automated import',
        //    'partnerIdentity' => ['address' => ['street' => 'dlouha'], 'shipToAddress' => ['street' => 'kratka']],
        //    "paymentAccount" => ['accountNo' => '1234', 'bankCode' => '5500'],
        //    'statementNumber' => [
        //        'statementNumber' => (string) time(),
        //    //'numberMovement' => (string) time()
        //    ],
        // //    "symConst" => 'XX',
        // // ?"symPar",
        //    "symSpec" => '23',
        //    "symVar" => (string) time(),
        //    "text" => 'Testing income ' . time(),
        //    'homeCurrency' => ['priceNone' => '1001']
        // ];
        //        $this->setDataValue('cisDosle', $transactionData->entryReference);
        if (property_exists($transactionData->entryDetails->transactionDetails->remittanceInformation, 'creditorReferenceInformation')) {
            if (property_exists($transactionData->entryDetails->transactionDetails->remittanceInformation->creditorReferenceInformation, 'variable')) {
                $this->setDataValue('symVar', $transactionData->entryDetails->transactionDetails->remittanceInformation->creditorReferenceInformation->variable);
            }
            //            if (property_exists($transactionData->entryDetails->transactionDetails->remittanceInformation->creditorReferenceInformation, 'constant')) {
            //                $conSym = $transactionData->entryDetails->transactionDetails->remittanceInformation->creditorReferenceInformation->constant;
            //                if (intval($conSym)) {
            //                    $conSym = sprintf('%04d', $conSym);
            //                    $this->ensureKSExists($conSym);
            //                    $this->setDataValue('konSym', \Pohoda\RO::code($conSym));
            //                }
            //            }
        }

        //        $this->setDataValue('datVyst', $transactionData->bookingDate);
        // $this->setDataValue('duzpPuv', $transactionData->valueDate);
        if (property_exists($transactionData->entryDetails->transactionDetails->remittanceInformation, 'originatorMessage')) {
            $this->setDataValue('text', $transactionData->entryDetails->transactionDetails->remittanceInformation->originatorMessage);
        }

        $this->setDataValue('note', 'Import Job ' . \Ease\Shared::cfg('JOB_ID', 'n/a'));

        if (property_exists($transactionData->entryDetails->transactionDetails->relatedParties, 'counterParty')) {
            $counterAccount = $transactionData->entryDetails->transactionDetails->relatedParties->counterParty;

            if (property_exists($transactionData->entryDetails->transactionDetails->relatedParties->counterParty, 'name')) {
                // TODO                $this->setDataValue('nazFirmy', $transactionData->entryDetails->transactionDetails->relatedParties->counterParty->name);
            }

            $counterAccountNumber = $counterAccount->account->accountNumber;

            if (property_exists($counterAccount->account, 'accountNumberPrefix')) {
                $accountNumber = $counterAccount->account->accountNumberPrefix . '-' . $counterAccountNumber;
            } else {
                $accountNumber = $counterAccountNumber;
            }

            $this->setDataValue('paymentAccount', ['accountNo' => $accountNumber, 'bankCode' => $counterAccount->organisationIdentification->bankCode]);

            $amount = (string) abs($transactionData->amount->value);

            if ($transactionData->amount->currency === 'CZK') {
                $this->setDataValue('homeCurrency', ['priceNone' => $amount]);
            } else {
                $this->setDataValue('foreginCurrency', ['priceNone' => $amount]); // TODO: Not tested
            }
        }

        //        $this->setDataValue('source', $this->sourceString());
        //        echo $this->getJsonizedData() . "\n";
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
                $this->until = (new \DateTime('yesterday'))->setTime(23, 59, 59, 999);

            case 'last_week':
                $this->since = new \DateTime('first day of last week');
                $this->until = new \DateTime('last day of last week');

                break;
            case 'auto':
                $latestRecord = $this->getColumnsFromPohoda(['id', 'lastUpdate'], ['limit' => 1, 'order' => 'lastUpdate@A', 'source' => $this->sourceString(), 'banka' => $this->bank]);

                if (\array_key_exists(0, $latestRecord) && \array_key_exists('lastUpdate', $latestRecord[0])) {
                    $this->since = $latestRecord[0]['lastUpdate'];
                } else {
                    $this->addStatusMessage('Previous record for "auto since" not found. Defaulting to today\'s 00:00', 'warning');
                    $this->since = (new \DateTime('yesterday'))->setTime(0, 0);
                }

                $this->until = (new \DateTime('two days ago'))->setTime(0, 0); // Now

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
}
