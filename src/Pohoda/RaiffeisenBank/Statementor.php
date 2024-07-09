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
class Statementor extends PohodaBankClient {

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
     * Downloaded XML statements
     * @var array
     */
    private $statementsXML = [];

    /**
     * Downloaded PDF statements
     * @var array
     */
    private $statementsPDF = [];
    
    /**
     * 
     * @var string
     */
    public $scope = '';

    /**
     *
     * @param string $bankAccount
     * @param array  $options
     */
    public function __construct($bankAccount, $options = []) {
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
     * @param string $xmlFile
     * 
     * @return array
     */
    public function importXML(string $xmlFile) {
        $this->statementsXML[basename($xmlFile)] = $xmlFile;
        $pdfFile = str_replace('.xml', '.pdf', $xmlFile);
        if (file_exists($pdfFile)) {
            $this->statementsPDF[basename($pdfFile)] = $pdfFile;
        }
        return $this->import();
    }

    /**
     * 
     * @return array
     */
    public function importOnline() {
        $this->statementsXML = $this->obtainer->download($this->statementsDir, $this->obtainer->getStatements(), 'xml');
        $this->statementsPDF = $this->obtainer->download($this->statementsDir, $this->obtainer->getStatements(), 'pdf');
        return $this->import();
    }

    /**
     *
     * @return array
     */
    public function import() {
        $inserted = [];
        $this->account = \Ease\Shared::cfg('POHODA_BANK_IDS', 'RB'); //TODO!!!
        $success = 0;
        foreach ($this->statementsXML as $pos => $statement) {
            $statementXML = new \SimpleXMLElement(file_get_contents($statement));
            $statementNumberLong = current((array) $statementXML->BkToCstmrStmt->Stmt->Id);
            foreach ($statementXML->BkToCstmrStmt->Stmt->Ntry as $entry) {
                $this->dataReset();
                $this->setData($this->entryToPohoda($entry));
                list($statementNumber, $statementYear, ) = explode('_', $pos);
                $this->setDataValue('statementNumber', ['statementNumber' => $statementNumber . '/' . $statementYear]);
//                $this->setDataValue('account', current((array) $entry->NtryRef));
//                $this->setDataValue('vypisCisDokl', $statementXML->BkToCstmrStmt->Stmt->Id);
//                $this->setDataValue('cisSouhrnne', $statementXML->BkToCstmrStmt->Stmt->LglSeqNb);
                $success = $this->insertTransactionToPohoda($success);
                if (property_exists($this->response, 'producedDetails') && is_array($this->response->producedDetails)) {
                    if (array_key_exists('id', $this->response->producedDetails)) {
                        $inserted[$this->response->producedDetails['id']] = $this->response->producedDetails;
                    } else {
                        echo ''; // WTF?
                    }
                }
            }
            $this->addStatusMessage($statementNumberLong . ' Import done. ' . $success . ' of ' . count($this->statementsXML) . ' imported');
            return $inserted;
        }
    }

    /**
     * Parse Ntry element and convert into \Pohoda\Banka data
     *
     * @see https://cbaonline.cz/upload/1425-standard-xml-cba-listopad-2020.pdf
     * @see https://www.stormware.cz/xml/schema/version_2/bank.xsd
     *
     * @param SimpleXMLElement $entry
     *
     * @return array
     */
    public function entryToPohoda($entry) {
        $data['symPar'] = current((array) $entry->NtryRef);
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
//                if ($entry->NtryDtls->TxDtls->Refs->MsgId) {
//                    $data['numberMovement'] = current((array) $entry->NtryDtls->TxDtls->Refs->MsgId);
//                }
                if ($entry->NtryDtls->TxDtls->Refs->InstrId) {
                    // ZPS: Platební titul,
                    // SEPA: Identifikace platby Dříve i pro TPS: Konstantní symbol
                    $data['symConst'] = current((array) $entry->NtryDtls->TxDtls->Refs->InstrId);
                }
                if (property_exists($entry->NtryDtls->TxDtls->Refs, 'EndToEndId')) {
                    // ZPS: Klientská reference,
                    // SEPA: Reference, Karetní operace: Číslo dobíjeného mobilu, případně číslo faktury, Klientská reference Dříve i pro TPS: Variabilní symbol
                    $data['symVar'] = current((array) $entry->NtryDtls->TxDtls->Refs->EndToEndId);
                }
                $paymentAccount = [];
                if (property_exists($entry->NtryDtls->TxDtls, 'RltdPties')) {
                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'DbtrAcct')) {
                        $paymentAccount['accountNo'] = current((array) $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Id->Othr->Id);

                        $data['partnerIdentity'] = [//"address", "addressLinkToAddress", "extId", "id", "shipToAddress"
                            'address' => [// "VATPayerType", "city", "company", "country", "dic", "division", "email", "fax", "icDph", "ico", "mobilPhone", "name", "phone", "street", "zip"
                                'name' => current((array) $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Nm)]];
                    }

                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'CdtrAcct')) {
                        $paymentAccount['accountNo'] = current((array) $entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->Othr->Id);

                        if (property_exists($entry->NtryDtls->TxDtls->RltdPties->CdtrAcct, 'Nm')) {
                            $data['partnerIdentity'] = [
                                'address' => [
                                    'name' => current((array) $entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Nm)
                                ]
                            ];
                        } else {
                            $this->addStatusMessage(sprintf(_('%s payment without partnerIdentity name'), $paymentAccount['accountNo']));
                        }
                    }
                } else {
                    echo ''; // No Related party ?
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
                if (empty($paymentAccount) === false) {
                    $data['paymentAccount'] = $paymentAccount;
                }
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
    function setScope($scope) {
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
            case 'last_week':
                $this->since = new \DateTime("first day of last week");
                $this->until = new \DateTime("last day of last week");
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
        $this->scope = $scope;
    }

    /**
     * List of downloaded PDF statements
     * @return array
     */
    public function getPdfStatements() {
        return $this->statementsPDF;
    }

    /**
     * List of downloaded XML statements
     * @return array
     */
    public function getXmlStatements() {
        return $this->statementsXML;
    }
}
