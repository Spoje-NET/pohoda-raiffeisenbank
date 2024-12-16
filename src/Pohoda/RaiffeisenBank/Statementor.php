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
 * Description of Statementor.
 *
 * @author vitex
 */
class Statementor extends PohodaBankClient
{
    public string $scope = '';
    public string $statementsDir;
    public string $currency = '';
    public string $account;
    public string $statementLine = 'MAIN';
    private \VitexSoftware\Raiffeisenbank\Statementor $obtainer;

    /**
     * Downloaded XML statements.
     *
     * @var array<string, string>
     */
    private array $statementsXML = [];

    /**
     * Downloaded PDF statements.
     *
     * @var array<string, string>
     */
    private array $statementsPDF = [];

    /**
     * Bank Statement Helper.
     *
     * @param array<string, string> $options
     */
    public function __construct(string $bankAccount, array $options = [])
    {
        $this->account = $bankAccount;
        parent::__construct($bankAccount, $options);
        $this->setObjectName($bankAccount.'@'.$this->getObjectName());
        $this->obtainer = new \VitexSoftware\Raiffeisenbank\Statementor($bankAccount);

        $this->statementsDir = \Ease\Shared::cfg('STATEMENT_SAVE_DIR', sys_get_temp_dir().'/rb');

        if (file_exists($this->statementsDir) === false) {
            mkdir($this->statementsDir, 0777, true);
        }
    }

    /**
     * @return array
     */
    public function importXML(string $xmlFile)
    {
        $this->statementsXML[basename($xmlFile)] = $xmlFile;
        $pdfFile = str_replace('.xml', '.pdf', $xmlFile);

        if (file_exists($pdfFile)) {
            $this->statementsPDF[basename($pdfFile)] = $pdfFile;
        }

        return $this->import();
    }

    /**
     * Get List of Statement files.
     *
     * @param string $format xml|pdf
     *
     * @return array<string>
     */
    public function getStatementFilenames(string $format): array
    {
        $statementFilenames = [];

        foreach ($this->getStatements() as $statementFilePath) {
            $statementFilenames[] = str_replace('/', '_', $statementFilePath->statementNumber).'_'.
                    $statementFilePath->accountNumber.'_'.
                    $statementFilePath->accountId.'_'.
                    $statementFilePath->currency.'_'.$statementFilePath->dateFrom.'.'.$format;
        }

        return $statementFilenames;
    }

    public function getStatements(): array
    {
        return $this->obtainer->getStatements($this->currency, $this->statementLine);
    }

    public function download(string $format): array
    {
        return $this->obtainer->download($this->statementsDir, $this->getStatements(), $format);
    }

    public function downloadOne($statement, $format)
    {
        return $this->obtainer->download($this->statementsDir, [$statement], $format);
    }

    /**
     * Download Raiffeisen bank XML statement.
     *
     * @return array<string, string> List of downloaded XML files
     */
    public function downloadXML(): array
    {
        $this->statementsXML = $this->download('xml');

        return $this->statementsXML;
    }

    /**
     * Download Raiffeisen bank PDF statement.
     *
     * @return array<string, string> List of downloaded PDF files
     */
    public function downloadPDF(): array
    {
        $this->statementsPDF = $this->download('pdf');

        return $this->statementsPDF;
    }

    /**
     * @return array<array<string, string>> List of inserted records
     */
    public function importOnline()
    {
        $this->downloadXML();
        $this->downloadPDF();

        return $this->import();
    }

    /**
     * Import Raiffeisen bank XML statement into Pohoda.
     *
     * @return list<array<string, string>>
     */
    public function import(string $bankIds = ''): array
    {
        $inserted = [];
        $success = 0;

        foreach ($this->statementsXML as $statementFileName => $statementFilePath) {
            $statementXML = new \SimpleXMLElement(file_get_contents($statementFilePath));
            $statementNumberLong = current((array) $statementXML->BkToCstmrStmt->Stmt->Id);
            $entries = 0;

            foreach ($statementXML->BkToCstmrStmt->Stmt->Ntry as $entry) {
                ++$entries;
                $this->dataReset();
                $this->setData($this->entryToPohoda($entry));
                [$statementNumber, $statementYear] = explode('_', $statementFileName);
                $this->setDataValue('statementNumber', ['statementNumber' => $statementNumber.'/'.$statementYear]); // TODO: Optional by switch ?
                //                $this->setDataValue('account', current((array) $entry->NtryRef));
                //                $this->setDataValue('vypisCisDokl', $statementXML->BkToCstmrStmt->Stmt->Id);
                //                $this->setDataValue('cisSouhrnne', $statementXML->BkToCstmrStmt->Stmt->LglSeqNb);

                try {
                    $lastInsert = $this->insertTransactionToPohoda($bankIds);

                    if ($lastInsert) {
                        $inserted[key($lastInsert)] = current($lastInsert);
                        ++$success;
                    }
                } catch (\Exception $exc) {
                    $this->addStatusMessage('Error Inserting Record', 'error');
                }
            }

            $this->addStatusMessage($statementNumberLong.' Import done. '.$success.' of '.$entries.' imported');
        }

        return $inserted;
    }

    public static function simpleXmlAttributes(\SimpleXMLElement $item): array
    {
        $attributes = [];

        foreach ($item->attributes() as $key => $value) {
            $attributes[$key] = (string) $value;
        }

        return $attributes;
    }

    /**
     * Parse Ntry element and convert into \Pohoda\Banka data.
     *
     * @see https://cbaonline.cz/upload/1425-standard-xml-cba-listopad-2020.pdf
     * @see https://www.stormware.cz/xml/schema/version_2/bank.xsd
     *
     * @return array<string, array<string, string>|string>
     */
    public function entryToPohoda(\SimpleXMLElement $entry): array
    {
        $data['symPar'] = current((array) $entry->NtryRef);
        $data['intNote'] = 'Imported by '.\Ease\Shared::AppName().' '.\Ease\Shared::AppVersion().' Import Job '.\Ease\Shared::cfg('MULTIFLEXI_JOB_ID', \Ease\Shared::cfg('JOB_ID', 'n/a'));
        $data['note'] = '';
        $data['datePayment'] = current((array) $entry->BookgDt->DtTm); // current((array) $entry->ValDt->DtTm);
        $data['dateStatement'] = current((array) $entry->BookgDt->DtTm);
        $moveTrans = ['DBIT' => 'expense', 'CRDT' => 'receipt'];
        $data['bankType'] = $moveTrans[trim((string) $entry->CdtDbtInd)];
        //        $data['cisDosle', strval($entry->NtryRef));
        //        $data['datVyst', new \DateTime($entry->BookgDt->DtTm));

        $amountAttributes = self::simpleXmlAttributes($entry->Amt);

        if (\array_key_exists('Ccy', $amountAttributes) && $amountAttributes['Ccy'] !== 'CZK') {
            $data['foreignCurrency'] = ['priceSum' => abs((float) $entry->Amt)]; // "price3", "price3Sum", "price3VAT", "priceHigh", "priceHighSum", "priceHighVAT", "priceLow", "priceLowSum", "priceLowVAT", "priceNone", "round"
            $data['foreignCurrency']['currency'] = $amountAttributes['Ccy'];
        } else {
            $data['homeCurrency'] = ['priceNone' => abs((float) $entry->Amt)]; // "price3", "price3Sum", "price3VAT", "priceHigh", "priceHighSum", "priceHighVAT", "priceLow", "priceLowSum", "priceLowVAT", "priceNone", "round"
        }

        // TODO $data['foreignCurrency', abs(floatval($entry->Amt)));
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

                        $data['partnerIdentity'] = [// "address", "addressLinkToAddress", "extId", "id", "shipToAddress"
                            'address' => [// "VATPayerType", "city", "company", "country", "dic", "division", "email", "fax", "icDph", "ico", "mobilPhone", "name", "phone", "street", "zip"
                                'name' => current((array) $entry->NtryDtls->TxDtls->RltdPties->DbtrAcct->Nm)]];
                    }

                    if (property_exists($entry->NtryDtls->TxDtls->RltdPties, 'CdtrAcct')) {
                        $paymentAccount['accountNo'] = current((array) $entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Id->Othr->Id);

                        if (property_exists($entry->NtryDtls->TxDtls->RltdPties->CdtrAcct, 'Nm')) {
                            $data['partnerIdentity'] = [
                                'address' => [
                                    'name' => current((array) $entry->NtryDtls->TxDtls->RltdPties->CdtrAcct->Nm),
                                ],
                            ];
                        } else {
                            $this->addStatusMessage(sprintf(_('%s payment without partnerIdentity name'), $paymentAccount['accountNo']));
                        }
                    }
                } else {
                    echo ''; // No Related party ?
                }

                if (property_exists($entry->NtryDtls->TxDtls, 'RltdAgts')) {
                    if (property_exists($entry->NtryDtls->TxDtls->RltdAgts, 'DbtrAgt')) {
                        if (property_exists($entry->NtryDtls->TxDtls->RltdAgts->DbtrAgt, 'FinInstnId')) {
                            $paymentAccount['bankCode'] = current((array) $entry->NtryDtls->TxDtls->RltdAgts->DbtrAgt->FinInstnId->Othr->Id);
                        }
                    }

                    if (property_exists($entry->NtryDtls->TxDtls->RltdAgts, 'CdtrAgt')) {
                        if (property_exists($entry->NtryDtls->TxDtls->RltdAgts->CdtrAgt, 'FinInstnId')) {
                            $paymentAccount['bankCode'] = current((array) $entry->NtryDtls->TxDtls->RltdAgts->CdtrAgt->FinInstnId->Othr->Id);
                        }
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
     * Prepare processing interval.
     *
     * @param string $scope
     *
     * @throws \Exception
     */
    public function setScope($scope): void
    {
        switch ($scope) {
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
            case 'last_week':
                $this->since = new \DateTime('first day of last week');
                $this->until = new \DateTime('last day of last week');

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
            case 'September':// 9
            case 'October':  // 10
            case 'November': // 11
            case 'December': // 12
                $this->since = new \DateTime('first day of '.$scope.' '.date('Y'));
                $this->until = new \DateTime('last day of '.$scope.' '.date('Y'));

                break;
            case 'auto':
                //  "EAN", "code", "company", "dateFrom", "dateTill", "dic", "ico", "id", "internet", "lastChanges", "name", "storage", "store".
                $latestRecord = $this->getColumnsFromPohoda();

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
                        $this->since = new \DateTime($scope);
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

        $this->obtainer->since = $this->since;
        $this->obtainer->until = $this->until;
        $this->scope = $scope;
        //        $this->obtainer->setScope(\Ease\Shared::cfg('STATEMENT_IMPORT_SCOPE', 'last_month'));
    }

    /**
     * List of downloaded PDF statements.
     */
    public function getPdfStatements(): array
    {
        return $this->statementsPDF;
    }

    /**
     * List of downloaded XML statements.
     *
     * @return array
     */
    public function getXmlStatements()
    {
        return $this->statementsXML;
    }

    public function getSince(): \DateTime
    {
        return $this->since;
    }

    public function getUntil(): \DateTime
    {
        return $this->until;
    }

    public function takeXmlStatementFile(string $xmlFilePath): void
    {
        $this->statementsXML[basename($xmlFilePath)] = $xmlFilePath;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }

    public function setStatementLine(string $line): void
    {
        switch ($line) {
            case 'MAIN':
            case 'ADDITIONAL':
                $this->statementLine = $line;

                break;

            default:
                throw new \InvalidArgumentException('Wrong statement line: '.$line);
        }
    }

    public function getAccount()
    {
        return $this->account;
    }
}
