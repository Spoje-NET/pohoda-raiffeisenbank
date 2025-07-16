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
    public string $currency = 'CZK';
    public string $account;
    public string $statementLine = 'MAIN';
    protected string $cnbCache = '';
    protected float $fixedRate = 0;
    protected int $fixedRateAmount = 1;
    protected int $rateOffset = 0;
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
     * @param array<string, string> $options cnbCache,fixedRate,currency
     */
    public function __construct(string $bankAccount, array $options = [])
    {
        $this->account = $bankAccount;
        parent::__construct($bankAccount, $options);
        $this->setObjectName($bankAccount.'@'.$this->getObjectName());
        $this->obtainer = new \VitexSoftware\Raiffeisenbank\Statementor($bankAccount);
        $this->setupProperty($options, 'currency', 'ACCOUNT_CURRENCY');
        $this->setupProperty($options, 'cnbCache', 'CNB_CACHE');
        $this->setupFloatProperty($options, 'fixedRate', 'FIXED_RATE');
        $this->setupIntProperty($options, 'fixedRateAmount', 'FIXED_RATE_AMOUNT');
        $this->rateOffset = \Ease\Shared::cfg('RATE_OFFSET') === 'yesterday' ? 1 : 0;

        if (($this->currency !== 'CZK') && empty($this->fixedRate) && empty($this->cnbCache)) {
            throw new \InvalidArgumentException(_('No FIXED_RATE or CNB_CACHE specified for foregin currency'));
        }

        $this->statementsDir = \Ease\Shared::cfg('STATEMENT_SAVE_DIR', sys_get_temp_dir());

        if (file_exists($this->statementsDir) === false) {
            $this->addStatusMessage(sprintf(_('Creating Statements directory'), $this->statementsDir, mkdir($this->statementsDir, 0o777, true) ? 'success' : 'error'));
        }
    }

    /**
     * @return array<string, string>
     */
    public function importXML(string $xmlFile): array
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
            $statementFilenames[] = str_replace('/', '_', $statementFilePath['statementNumber']).'_'.
                    $this->account.'_'.
                    $statementFilePath['accountId'].'_'.
                    $statementFilePath['currency'].'_'.$statementFilePath['dateFrom'].'.'.$format;
        }

        return $statementFilenames;
    }

    /**
     * Get List of Statement files.
     *
     * @return array<string, string>
     */
    public function getStatements(): array
    {
        return $this->obtainer->getStatements($this->currency, $this->statementLine);
    }

    /**
     * Download Raiffeisen bank statement.
     *
     * @return null|array<string, string>
     */
    public function download(string $format): ?array
    {
        return $this->obtainer->download($this->statementsDir, $this->getStatements(), $format, $this->currency);
    }

    /**
     * Download one Raiffeisen bank statement.
     *
     * @return array<string, string>
     */
    public function downloadOne(string $statement, string $format)
    {
        return $this->obtainer->download($this->statementsDir, [$statement], $format, $this->currency);
    }

    /**
     * Download Raiffeisen bank XML statement.
     *
     * @return array<string, string> List of downloaded XML files
     */
    public function downloadXML(): ?array
    {
        $this->statementsXML = $this->download('xml');

        return $this->statementsXML;
    }

    /**
     * Download Raiffeisen bank PDF statement.
     *
     * @return array<string, string> List of downloaded PDF files
     */
    public function downloadPDF(): ?array
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
     * @return array<array<string, string>>
     */
    public function import(string $bankIds = ''): array
    {
        $inserted = [];
        $success = 0;

        foreach ($this->statementsXML as $statementFileName => $statementFilePath) {
            $statementXML = new \SimpleXMLElement(file_get_contents($statementFilePath));
            $statementNumberLong = current((array) $statementXML->BkToCstmrStmt->Stmt->Id);
            $entries = 0;

            $statementNumber = $statementXML->BkToCstmrStmt->Stmt->LglSeqNb;
            $statementCreated = new \DateTime((string) $statementXML->BkToCstmrStmt->Stmt->CreDtTm);
            $paymentDate = new \DateTime((string) $statementXML->BkToCstmrStmt->Stmt->FrToDt->ToDtTm);

            $this->addStatusMessage(sprintf('Parsing statement %s no. %d created %s', $statementNumberLong, $statementNumber, $statementCreated->format('c')), 'debug');

            foreach ($statementXML->BkToCstmrStmt->Stmt->Ntry as $entry) {
                ++$entries;
                $this->dataReset();
                $this->setData($this->entryToPohoda($entry));

                $transactionId = PohodaBankClient::intNote2TransactionId($this->getDataValue('intNote'));

                $this->setDataValue('statementNumber', ['statementNumber' => sprintf('%03d/%d', (int) $statementNumber, (int) $statementCreated->format('Y'))]);
                //                $this->setDataValue('account', current((array) $entry->NtryRef));
                //                $this->setDataValue('vypisCisDokl', $statementXML->BkToCstmrStmt->Stmt->Id);
                //                $this->setDataValue('cisSouhrnne', $statementXML->BkToCstmrStmt->Stmt->LglSeqNb);

                try {
                    if ($this->currency === 'CZK') {
                        $amount = current($this->getDataValue('homeCurrency'));
                    } else {
                        $amount = current($this->getDataValue('foreignCurrency'));
                    }

                    $this->addStatusMessage(sprintf('💸 %s [%s] %s', $transactionId, ($this->getDataValue('bankType') === 'receipt' ? '+' : '-').$amount.$this->currency, (string) $this->getDataValue('text')));

                    if ($this->checkForTransactionPresence($transactionId)) {
                        $this->addStatusMessage("Transaction with ID '{$transactionId}' already present in Pohoda", 'warning');
                        $result['message'] = "Duplicate transaction: {$transactionId}";
                        $result['success'] = true;
                    } else {
                        $lastInsert = $this->insertTransactionToPohoda($bankIds);

                        $this->messages[$lastInsert['id']] = \array_key_exists('message', $lastInsert) ? $lastInsert['message'] : $lastInsert['messages'];
                        unset($lastInsert['messages']);
                        $lastInsert['details']['amount'] = $amount;
                        $lastInsert['details']['currency'] = $this->currency;
                        $lastInsert['details']['date'] = $paymentDate->format('Y-m-d');

                        if ($lastInsert['success']) {
                            $inserted[$lastInsert['id']] = $lastInsert;
                            ++$success;
                        }
                    }
                } catch (\Exception $exc) {
                    $this->addStatusMessage('Error Inserting Record'.$exc->getMessage(), 'error');
                }
            }

            $this->addStatusMessage($statementNumberLong.' Import done. '.$success.' of '.$entries.' imported');
        }

        return $inserted;
    }

    /**
     * Parse SimpleXMLElement attributes into array.
     *
     * @return array<string, string>
     */
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
        $data['intNote'] = sprintf(_('Imported by %s %s  Import Job: %s TransactionID: #%s#'), \Ease\Shared::AppName(), \Ease\Shared::AppVersion(), \Ease\Shared::cfg('MULTIFLEXI_JOB_ID', \Ease\Shared::cfg('JOB_ID', 'n/a')), current((array) $entry->NtryRef));
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
            $rateInfo = $this->getRateInfo(new \DateTime($data['datePayment']));

            $data['foreignCurrency']['rate'] = $rateInfo['rate'];
            $data['foreignCurrency']['amount'] = $rateInfo['amount'];
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
     * @throws \Exception
     */
    public function setScope(string $scope): \DatePeriod
    {
        switch ($scope) {
            case 'yesterday':
                $this->since = (new \DateTime('yesterday'))->setTime(0, 0);
                $this->until = (new \DateTime('yesterday'))->setTime(23, 59, 59, 999);

                break;
            case 'current_month':
                $this->since = (new \DateTime('first day of this month'))->setTime(0, 0);
                $this->until = (new \DateTime())->setTime(23, 59, 59, 999);

                break;
            case 'last_month':
                $this->since = (new \DateTime('first day of last month'))->setTime(0, 0);
                $this->until = (new \DateTime('last day of last month'))->setTime(23, 59, 59, 999);

                break;
            case 'last_week':
                $this->since = (new \DateTime('monday last week'))->setTime(0, 0);
                $this->until = (new \DateTime('sunday last week'))->setTime(23, 59, 59, 999);

                break;
            case 'last_two_months':
                $this->since = (new \DateTime('first day of -2 months'))->setTime(0, 0);
                $this->until = (new \DateTime('last day of last month'))->setTime(23, 59, 59, 999);

                break;
            case 'previous_month':
                $this->since = (new \DateTime('first day of -2 months'))->setTime(0, 0);
                $this->until = (new \DateTime('last day of -2 months'))->setTime(23, 59, 59, 999);

                break;
            case 'two_months_ago':
                $this->since = (new \DateTime('first day of -3 months'))->setTime(0, 0);
                $this->until = (new \DateTime('last day of -3 months'))->setTime(23, 59, 59, 999);

                break;
            case 'this_year':
                $this->since = (new \DateTime('first day of January '.date('Y')))->setTime(0, 0);
                $this->until = (new \DateTime('last day of December '.date('Y')))->setTime(23, 59, 59, 999);

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

        return new \DatePeriod($this->since, new \DateInterval('P1D'), $this->until);
    }

    /**
     * List of downloaded PDF statements.
     *
     * @return array<string, string>
     */
    public function getPdfStatements(): array
    {
        return $this->statementsPDF;
    }

    /**
     * List of downloaded XML statements.
     *
     * @return array<string, string>
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
        if (file_exists($xmlFilePath) && is_readable($xmlFilePath)) {
            $this->statementsXML[basename($xmlFilePath)] = $xmlFilePath;
        } else {
            throw new \Exception(sprintf('File %s is not readable', $xmlFilePath));
        }
    }

    public function setCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Set default Statement Line.
     */
    public function setStatementLine(string $line): self
    {
        switch ($line) {
            case 'MAIN':
            case 'ADDITIONAL':
                $this->statementLine = $line;

                break;

            default:
                throw new \InvalidArgumentException('Wrong statement line: '.$line);
        }

        return $this;
    }

    public function getAccount(): string
    {
        return $this->account;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function getMessages(): array
    {
        return $this->messages;
    }

    /**
     * Summary of getRateInfo.
     *
     * @throws \RuntimeException
     *
     * @return array<string, float|int>
     */
    public function getRateInfo(\DateTime $movementDate): array
    {
        if ($this->fixedRate) {
            $rateInfo = ['rate' => $this->fixedRate, 'amount' => $this->fixedRateAmount];
        } else {
            if ($this->rateOffset) {
                $date = $movementDate->modify('-'.$this->rateOffset.' day')->format('Y-m-d');
            } else {
                $date = $movementDate->format('Y-m-d');
            }

            $rateUrl = \Ease\Functions::addUrlParams($this->cnbCache, ['currency' => $this->currency, 'date' => $date]);

            try {
                $ch = curl_init();
                curl_setopt($ch, \CURLOPT_URL, $rateUrl);
                curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, \CURLOPT_FOLLOWLOCATION, true); // Allow HTTP redirects
                $rateInfoRaw = curl_exec($ch);

                if (curl_errno($ch)) {
                    throw new \RuntimeException('Curl error: '.curl_error($ch));
                }

                $httpCode = curl_getinfo($ch, \CURLINFO_HTTP_CODE);

                if ($httpCode !== 200) {
                    throw new \RuntimeException('HTTP error: '.$httpCode);
                }

                curl_close($ch);
            } catch (\Exception $e) {
                throw new \RuntimeException('Error fetching rate info: '.$rateUrl.': '.$e->getMessage());
            }

            if (self::isJson($rateInfoRaw)) {
                $rateInfo = \json_decode($rateInfoRaw, true);
            } else {
                throw new \RuntimeException(sprintf(_('No ČNB Cache Json on %s: %s'), $this->cnbCache, $rateInfoRaw));
            }
        }

        return $rateInfo;
    }

    /**
     * Validates a JSON string.
     *
     * @param string $json  the JSON string to validate
     * @param int    $depth Maximum depth. Must be greater than zero.
     * @param int    $flags bitmask of JSON decode options
     *
     * @return bool returns true if the string is a valid JSON, otherwise false
     */
    public static function isJson(?string $json, int $depth = 512, int $flags = 0): bool
    {
        $isJson = false;

        if (\function_exists('json_validate')) {
            $isJson = \json_validate($json);
        } else {
            if (!\is_string($json)) {
                $isJson = false;
            }

            try {
                json_decode($json, false, $depth, $flags | \JSON_THROW_ON_ERROR);
                $isJson = true;
            } catch (\JsonException $e) {
                $isJson = false;
            }
        }

        return $isJson;
    }

    public static function statementFilename($filenameRaw)
    {
        return preg_replace_callback('/^(\d+)_/', static function ($matches) {
            return str_pad($matches[1], 3, '0', \STR_PAD_LEFT).'_';
        }, basename($filenameRaw));
    }
}
