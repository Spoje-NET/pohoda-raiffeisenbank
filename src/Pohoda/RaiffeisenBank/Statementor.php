<?php

/**
 * RaiffeisenBank - Statements handler class
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023 Spoje.Net
 */

namespace Pohoda\RaiffeisenBank;

/**
 * Description of Statementor
 *
 * @author vitex
 */
class Statementor extends PohodaBankClient
{
    /**
     *
     * @var \VitexSoftware\Raiffeisenbank\Statementor
     */
    private $obtainer;
    private $statementsDir;

    /**
     *
     * @param string $bankAccount
     * @param array  $options
     */
    public function __construct($bankAccount, $options = [])
    {
        parent::__construct($bankAccount, $options);
        $this->obtainer = new \VitexSoftware\Raiffeisenbank\Statementor($bankAccount);

        $this->obtainer->setScope(\Ease\Shared::cfg('STATEMENT_IMPORT_SCOPE', 'last_month'));

        $this->statementsDir = \Ease\Functions::cfg('STATEMENT_SAVE_DIR', sys_get_temp_dir() . '/rb');

        if (file_exists($this->statementsDir) === false) {
            mkdir($this->statementsDir, 0777, true);
        }
    }

    public function import()
    {
        $statementsXML = $this->obtainer->download($this->statementsDir, $this->obtainer->getStatements(), 'xml');
//        $statementsPDF = $this->obtainer->download($this->statementsDir, $this->obtainer->getStatements(), 'pdf');
        $this->account = 'RB'; //TODO!!!
        $success = 0;
        foreach ($statementsXML as $pos => $statement) {
            $statementXML = new \SimpleXMLElement(file_get_contents($statement));
            foreach ($statementXML->BkToCstmrStmt->Stmt->Ntry as $entry) {
                $this->dataReset();
                list($statementNumber,$statementYear,) = explode('_', $pos);
                $this->setDataValue('statementNumber', ['statementNumber' => $statementNumber . '/' . $statementYear]);
                $this->setDataValue('account', current($entry->NtryRef));
                [
                    "MOSS",
//                    "account",
                    "accounting",
                    "accountingPeriodMOSS",
                    "activity",
//                    "bankType",
                    "centre",
                    "classificationKVDPH",
                    "classificationVAT",
                    "contract",
//                    "datePayment",
//                    "dateStatement",
                    "evidentiaryResourcesMOSS",
                    "intNote",
                    "myIdentity",
                    "note",
                    "partnerIdentity",
                    "paymentAccount",
                    "statementNumber",
//                    "symConst",
                    "symPar",
//                    "symSpec",
//                    "symVar",
                    "text"
                    ];

                $this->entryToPohoda($entry);
//                $this->setDataValue('vypisCisDokl', $statementXML->BkToCstmrStmt->Stmt->Id);
//                $this->setDataValue('cisSouhrnne', $statementXML->BkToCstmrStmt->Stmt->LglSeqNb);
                $success = $this->insertTransactionToPohoda($success);
            }
            $this->addStatusMessage('Import done. ' . $success . ' of ' . count($statements) . ' imported');
        }
    }

    /**
     * Parse Ntry element into \Pohoda\Banka data
     *
     * @param SimpleXMLElement $entry
     *
     * @return array
     */
    public function entryToPohoda($entry)
    {
        $this->setDataValue('intNote', 'Import Job ' . \Ease\Shared::cfg('JOB_ID', 'n/a'));
        $this->setDataValue('note', 'Imported by ' . \Ease\Shared::AppName() . ' ' . \Ease\Shared::AppVersion());
        $this->setDataValue('datePayment', current($entry->BookgDt->DtTm));
        $this->setDataValue('dateStatement', current($entry->ValDt->DtTm));
        $moveTrans = ['DBIT' => 'expense', 'CRDT' => 'receipt'];
        $this->setDataValue('bankType', $moveTrans[trim($entry->CdtDbtInd)]);
//        $this->setDataValue('cisDosle', strval($entry->NtryRef));
//        $this->setDataValue('datVyst', new \DateTime($entry->BookgDt->DtTm));
        $this->setDataValue('homeCurrency', ['priceNone' => abs(floatval($entry->Amt))]); // "price3", "price3Sum", "price3VAT", "priceHigh", "priceHighSum", "priceHighVAT", "priceLow", "priceLowSum", "priceLowVAT", "priceNone", "round"

        //TODO $this->setDataValue('foreignCurrency', abs(floatval($entry->Amt)));


//        $this->setDataValue('account', $this->bank);
//        $this->setDataValue('mena', \Pohoda\RO::code($entry->Amt->attributes()->Ccy));
        if (property_exists($entry, 'NtryDtls')) {
            if (property_exists($entry->NtryDtls, 'TxDtls')) {
                $this->setDataValue('symConst', current($entry->NtryDtls->TxDtls->Refs->InstrId));

                if (property_exists($entry->NtryDtls->TxDtls->Refs, 'EndToEndId')) {
                    $this->setDataValue('symVar', current($entry->NtryDtls->TxDtls->Refs->EndToEndId));
                }
                $transactionData['text'] = $entry->NtryDtls->TxDtls->AddtlTxInf;
                if (property_exists($entry->NtryDtls->TxDtls, 'RltdPties')) {
                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'DbtrAcct')) {
                        $this->setDataValue('paymentAccount', $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->Othr->Id);
                    }
                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'DbtrAcct')) {
                        $this->setDataValue('partnerIdentity', $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Nm);
                    }
                }
                if (property_exists($entry->NtryDtls->TxDtls, 'RltdAgts')) {
                    if (property_exists($entry->NtryDtls->TxDtls->RltdAgts->DbtrAgt, 'FinInstnId')) {
                        $this->setDataValue('bankCode', $entry->NtryDtls->TxDtls->RltdAgts->DbtrAgt->FinInstnId->Othr->Id);
                    }
                }
            }
        }
//
//        $this->setDataValue('source', $this->sourceString());
        return $transactionData;
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
}
