<?php

/**
 * RaiffeisenBank - Statements handler class
 *
 * @author     Vítězslav Dvořák <info@vitexsoftware.com>
 * @copyright  (C) 2023-2024 Spoje.Net
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

    /**
     *
     * @var string
     */
    private $statementsDir;

    /**
     *
     * @var string
     */
    public $account;

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

    /**
     *
     * @return array
     */
    public function import()
    {
        $statementsXML = $this->obtainer->download($this->statementsDir, $this->obtainer->getStatements(), 'xml');
//        $statementsPDF = $this->obtainer->download($this->statementsDir, $this->obtainer->getStatements(), 'pdf');
        $this->account = \Ease\Shared::cfg('POHODA_BANK_IDS', 'RB'); //TODO!!!
        $success = 0;
        foreach ($statementsXML as $pos => $statement) {
            $statementXML = new \SimpleXMLElement(file_get_contents($statement));
            foreach ($statementXML->BkToCstmrStmt->Stmt->Ntry as $entry) {
                $this->dataReset();
                $this->setData($this->entryToPohoda($entry));
                list($statementNumber, $statementYear, ) = explode('_', $pos);
                $this->setDataValue('statementNumber', ['statementNumber' => $statementNumber . '/' . $statementYear]);
                $this->setDataValue('account', current((array) $entry->NtryRef));
//                $this->setDataValue('vypisCisDokl', $statementXML->BkToCstmrStmt->Stmt->Id);
//                $this->setDataValue('cisSouhrnne', $statementXML->BkToCstmrStmt->Stmt->LglSeqNb);
                $success = $this->insertTransactionToPohoda($success);
                if ($success && property_exists($this->response, 'producedDetails')) {
                    $inserted[$this->response->producedDetails['id']] = $this->response->producedDetails;
                }
            }
            $this->addStatusMessage('Import done. ' . $success . ' of ' . count($statementsXML) . ' imported');
            return $inserted;
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
        $data['intNote'] = 'Import Job ' . \Ease\Shared::cfg('JOB_ID', 'n/a');
        $data['note'] = 'Imported by ' . \Ease\Shared::AppName() . ' ' . \Ease\Shared::AppVersion();
        $data['datePayment'] = current((array) $entry->BookgDt->DtTm);
        $data['dateStatement'] = current((array) $entry->ValDt->DtTm);
        $moveTrans = ['DBIT' => 'expense', 'CRDT' => 'receipt'];
        $data['bankType'] = $moveTrans[trim($entry->CdtDbtInd)];
//        $data['cisDosle', strval($entry->NtryRef));
//        $data['datVyst', new \DateTime($entry->BookgDt->DtTm));
        $data['homeCurrency'] = ['priceNone' => abs(floatval($entry->Amt))]; // "price3", "price3Sum", "price3VAT", "priceHigh", "priceHighSum", "priceHighVAT", "priceLow", "priceLowSum", "priceLowVAT", "priceNone", "round"
        //TODO $data['foreignCurrency', abs(floatval($entry->Amt)));
//        $data['account', $this->bank);
//        $data['mena', \Pohoda\RO::code($entry->Amt->attributes()->Ccy));
        if (property_exists($entry, 'NtryDtls')) {
            if (property_exists($entry->NtryDtls, 'TxDtls')) {
                $transactionData = [];
                if (property_exists($entry->NtryDtls->TxDtls, 'AddtlTxInf')) {
                    $data['text'] = current((array) $entry->NtryDtls->TxDtls->AddtlTxInf);
                }
                if ($entry->NtryDtls->TxDtls->Refs->InstrId) {
                    $data['symConst'] = current((array) $entry->NtryDtls->TxDtls->Refs->InstrId);
                }
                if (property_exists($entry->NtryDtls->TxDtls->Refs, 'EndToEndId')) {
                    $data['symVar'] = current((array) $entry->NtryDtls->TxDtls->Refs->EndToEndId);
                }
                $paymentAccount = [];
                if (property_exists($entry->NtryDtls->TxDtls, 'RltdPties')) {
                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'DbtrAcct')) {
                        $paymentAccount['accountNo'] = current((array) $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->Othr->Id);
                    }
                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'DbtrAcct')) {
                        $data['partnerIdentity'] = [//"address", "addressLinkToAddress", "extId", "id", "shipToAddress"
                            'address' => [// "VATPayerType", "city", "company", "country", "dic", "division", "email", "fax", "icDph", "ico", "mobilPhone", "name", "phone", "street", "zip"
                                'name' => current((array) $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Nm)]];
                    }
                }
                if (property_exists($entry->NtryDtls->TxDtls, 'RltdAgts')) {
                    if (property_exists($entry->NtryDtls->TxDtls->RltdAgts->DbtrAgt, 'FinInstnId')) {
                        $paymentAccount['bankCode'] = current((array) $entry->NtryDtls->TxDtls->RltdAgts->DbtrAgt->FinInstnId->Othr->Id);
                    }
                }

//                if (count($paymentAccount)) {
//                    $data['paymentAccount'] = current((array) $paymentAccount['accountNo']);
//                }
//                accountNo, bankCode
                $data['paymentAccount'] = $paymentAccount;
            }
        }
//
//        $data['source'] = $this->sourceString());
        return $data;
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
