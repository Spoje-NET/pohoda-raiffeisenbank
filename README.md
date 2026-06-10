# Raiffeisenbank for Stormware Pohoda

![](pohoda-raiffeisenbank.svg?raw=true)

It downloads PDF and XML statements from the Raiffeisen Premium API for a specified period and uploads both to SharePoint.

It parses the XML statements and imports the bank movements into Stormware Pohoda via mServer.

After importing all items, document liquidation is initiated and they are automatically matched.

After uploading to SharePoint, links to the PDF statements are attached to all imported bank movements in Pohoda via a direct SQL insert into the DOC table (requires a SQLServer credential with access to the Pohoda database).

[![wakatime](https://wakatime.com/badge/user/5abba9ca-813e-43ac-9b5f-b1cfdf3dc1c7/project/018b7d35-a10b-4f4b-ba78-241d1c79b4e6.svg)](https://wakatime.com/badge/user/5abba9ca-813e-43ac-9b5f-b1cfdf3dc1c7/project/018b7d35-a10b-4f4b-ba78-241d1c79b4e6)

## Requirements

* php 8.1+
* Pohoda + [mServer](https://www.stormware.cz/pohoda/xml/mserver/)
* Sharepoint User or Application Account

## Setup command

Check certificate presence yet.

## Transactions tool

Import Bank movements from RaiffeisenBank (using [getTransactionList](https://developers.rb.cz/premium/documentation/01rbczpremiumapi#/Get%20Transaction%20List/getTransactionList) as source)
to Pohoda using mServer

![Transactions](transactions.png?raw=true)

## Configuration

Configuration is stored in `.env` file in the working directory. You can use `.env.example` as template.
When the configuration file is missing, the application will try to use environment variables.

```env
EASE_LOGGER=syslog|console
CERT_FILE='RAIFF_CERT.p12'
CERT_PASS=CertPass
XIBMCLIENTID=PwX4bOQLWGiuoErv6I
ACCOUNT_NUMBER=666666666
ACCOUNT_CURRENCY=CZK

IMPORT_SCOPE=last_two_months
IMPORT_SCOPE=yesterday

API_DEBUG=True
APP_DEBUG=True
STATEMENT_LINE=ADDITIONAL
STATEMENT_SAVE_DIR=/tmp/rb

CNB_CACHE=http://localhost/cnb-cache/
RATE_OFFSET=today
FIXED_RATE=25.1
FIXED_RATE_AMOUNT=1


POHODA_ICO=12345678
POHODA_URL=http://10.11.25.25:10010
POHODA_USERNAME=mServerXXX
POHODA_PASSWORD=mServerXXX
POHODA_TIMEOUT=60
POHODA_COMPRESS=false
POHODA_DEBUG=true
POHODA_BANK_IDS=RB
```

## Import Scopes

* `today`
* `yesterday`
* `last_week`
* `last_month`
* `last_two_months`
* `previous_month`
* `two_months_ago`
* `this_year` (statements only)
* `January`  (statements only)
* `February` (statements only)
* `March` (statements only)
* `April` (statements only)
* `May` (statements only)
* `June` (statements only)
* `July` (statements only)
* `August` (statements only)
* `September` (statements only)
* `October` (statements only)
* `November` (statements only)
* `December` (statements only)
* `auto`
* `2024-08-05>2024-08-11` - custom scope
* `2024-10-11` - only specific day

## Foregin Currency Transactions

If you have transactions in foreign currency, you can use `FIXED_RATE` to convert them to CZK using preconfigured fixed rate.
(for some currencies eg. 💴 you can use FIXED_RATE_AMOUNT=100)

Otherwise you can use `CNB_CACHE` to get actual rate from CNB.
In this case you can also need to specify `RATE_OFFSET`=yesterday to get rate for previous day.

[For CNB currency rates you need to have running CNB cache server!](https://github.com/Spoje-NET/CNB-Cache)

## Sharepoint Integration

Login based auth

```env
OFFICE365_USERNAME=me@company.tld
OFFICE365_PASSWORD=xxxxxxxxxxxxxx
```

ClientID based auth

```env
OFFICE365_CLIENTID=78842b49-651d-516e-0f2g-f979956aa620
OFFICE365_SECRET=09f04vbd-cfbc-5d78-afb7-2dfbebc4c385
OFFICE365_CLSECRET=8FR8Q~3Rab4-5o8dVd~1vDRId9oYiqEtMJB.Ucb2
```

Destination options

```env
OFFICE365_TENANT=yourcomapny
OFFICE365_SITE=YourSite
OFFICE365_PATH='Shared documents/statements'
```

Into configuration file .env please put ClientID **OR** Login/Password values.

## Error Handling

All scripts perform certificate validation before attempting API calls. If the certificate cannot be read or validated, a detailed error report is generated including:

* Certificate file path
* File existence status
* File readability status
* File permissions (if file exists)
* File owner and group (if file exists)

## ExitCodes

* `0` - Success, all operations completed without errors
* `1` - Generic API error (when no specific error code is available)
* `2` - Certificate validation failed (file not found, not readable, or invalid password)
* `3` - Error accessing Pohoda mServer
* `4` - Cannot link to Sharepoint
* `145` - API authentication error (HTTP 401 Unauthorized - typically certificate blocked or invalid)
* `254` - Another exception without numeric code occurred

**Note on Exit Codes:** Unix/Linux exit codes are limited to 0-255. When an HTTP error code like 401 (Unauthorized) is used as an exit code, it may be truncated by the shell to its modulo 256 value. For example:
* HTTP 401 → Unix exit code 145 (401 % 256 = 145)
* The full HTTP status code and error details are always available in the JSON report's `message` field

**Important:** Exit code `0` is only returned when the entire script completes successfully. Any error during execution (API failures, certificate issues, etc.) will result in a non-zero exit code.

## Powered by

* <https://github.com/VitexSoftware/php-vitexsoftware-rbczpremiumapi>
* <https://github.com/VitexSoftware/PHP-Pohoda-Connector>

## See also

* <https://github.com/Spoje-NET/pohoda-client-checker>
* <https://github.com/Spoje-NET/raiffeisenbank-statement-tools>

## MultiFlexi

Pohoda RaiffeisenBank is ready for run as [MultiFlexi](https://multiflexi.eu) application.
See the full list of ready-to-run applications within the MultiFlexi platform on the [application list page](https://www.multiflexi.eu/apps.php).

[![MultiFlexi App](https://github.com/VitexSoftware/MultiFlexi/blob/main/doc/multiflexi-app.svg)](https://www.multiflexi.eu/apps.php)

## Debian/Ubuntu installation

Please use the .deb packages. The repository is availble:

 ```shell
    echo "deb http://repo.vitexsoftware.com $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/vitexsoftware.list
    sudo wget -O /etc/apt/trusted.gpg.d/vitexsoftware.gpg http://repo.vitexsoftware.com/keyring.gpg
    sudo apt update
    sudo apt install pohoda-raiffeisenbank
```

Po instalaci balíku jsou v systému k dispozici tyto nové příkazy:

* **pohoda-raiffeisenbank-setup**         - check and/or prepare Bank account setup in Pohoda
* **pohoda-raiffeisenbank-transactions**  - Import transactions. From latest imported or within the given scope
* **pohoda-raiffeisenbank-statements**    - Import transactions from Account Statements.
* **pohoda-raiffeisenbank-offline-statement-importer** - Import transactions from XML Statements file.
* **pohoda-raiffeisenbank-xml-statement** - Import transactions from XML Statements file.
* **pohodasql-raiffeisenbank-statements-sharepoint** - Download PDF+XML statements, upload both to SharePoint, import into Pohoda via mServer, attach SharePoint PDF link to each bank record via direct SQL (DOC table)

* **pohoda-sharepoint-link-fixer** - Retroactively attach missing SharePoint PDF links to Pohoda bank records for a given period. Scoped to the configured bank account; supports IMPORT_SCOPE.

* **pohoda-bank-transaction-report** - Generate a JSON report of Pohoda bank transactions for a specified period. The output format matches the RaiffeisenBank statement reporter, including totals and transaction breakdowns.

### pohodasql-raiffeisenbank-statements-sharepoint

Executes the following pipeline in order:

1. Download PDF statements from Raiffeisen Bank
2. Download XML statements from Raiffeisen Bank
3. Upload PDF and XML statements to SharePoint
4. Import XML statements into Pohoda via mServer
5. Attach SharePoint PDF link to each imported bank record (direct SQL — DOC table, requires SQLServer credential)
6. Write JSON report to `RESULT_FILE`

The JSON report contains the result of every stage:

```json
{
  "certificate_valid": true,
  "raiffeisenbank": {
    "pdf": ["/tmp/147_2026_5230011111_4243005_CZK_2026-05-27.pdf"],
    "xml": ["/tmp/147_2026_5230011111_4243005_CZK_2026-05-27.xml"]
  },
  "sharepoint": {
    "pdf": {
      "147_2026_5230011111_4243005_CZK_2026-05-27.pdf": "https://tenant.sharepoint.com/..."
    },
    "xml": {
      "147_2026_5230011111_4243005_CZK_2026-05-27.xml": "https://tenant.sharepoint.com/..."
    }
  },
  "pohoda": {
    "1779931074_9022160411": {"success": true, "message": "..."}
  },
  "pohodaSQL": {
    "30850": {"status": "success", "linkedTo": 12345}
  },
  "messages": [],
  "exitcode": 0
}
```

On upload failure, the value is `{"error": "exception message"}` instead of the URL.

### pohoda-sharepoint-link-fixer

Retroactively attaches missing SharePoint PDF links to Pohoda bank records.
Use when records were imported into Pohoda but the SharePoint URL attachment was not written (e.g. after a period when MSSQL support was temporarily broken).

Executes the following pipeline in order:

1. List PDF statement files in SharePoint for the configured period, filtered by `ACCOUNT_NUMBER`
2. Query Pohoda MSSQL for bank records (`BV`) in the period that have no URL attachment, filtered by `POHODA_BANK_IDS` when set
3. Attach the matching SharePoint link to each record and write a JSON report

**Date range** is resolved by `IMPORT_SCOPE` (default: `last_month`). `DATE_FROM` / `DATE_TO` override `IMPORT_SCOPE` when explicitly set.

**Multi-bank behaviour**: When `POHODA_BANK_IDS` is set (e.g. `RB`), only records belonging to that bank account are processed. When unset, all bank records are considered. SharePoint files are always filtered by `ACCOUNT_NUMBER` embedded in the filename to prevent cross-account link attachment.

Example JSON report:

```json
{
  "account": "1234567890/0100",
  "bank_ids": "RB",
  "since": "2026-05-01",
  "until": "2026-05-31",
  "sharepoint_files": ["2026-05-31"],
  "bank_records_checked": 3,
  "fixed": [
    {"pohodaId": 12345, "cislo": "VBÚ001", "date": "2026-05-31", "filename": "001_1234567890_0100_4243005_CZK_2026-05-31.pdf", "url": "https://tenant.sharepoint.com/..."}
  ],
  "skipped": [],
  "errors": [],
  "exitcode": 0
}
```

### pohoda-bank-transaction-report

This tool generates a JSON report of bank transactions imported in Pohoda for a given period.

**Usage:**

```shell
pohoda-bank-transaction-report --scope="last_month" --output="report.json"
```

**Options:**

* `--scope`   Specify the period (e.g. `yesterday`, `last_month`, custom range)
* `--output`  Output file (default: stdout)
* `--environment`  Path to .env file (default: `../.env`)

The report includes:

* Incoming and outgoing transactions
* Totals and sums
* IBAN and account info (if available)
* Date range

Example output:

```json
{
  "source": "Statementor",
  "account": "666666666",
  "status": "statement 2024-07-01_666666666_CZK_2024-07-01.xml",
  "in": {"2024-07-01": 1000.00},
  "out": {"2024-07-02": 500.00},
  "in_total": 1,
  "out_total": 1,
  "in_sum_total": 1000.00,
  "out_sum_total": 500.00,
  "from": "2024-07-01",
  "to": "2024-07-31",
  "iban": "CZ1234567890123456789012"
}
```

## Exit Codes

Applications in this package use the following exit codes:

* `0`: Success
* `1`: General error
* `2`: Misuse of shell command
* `3`: Application-specific error
* `4`: Application-specific error
* `401`: Unauthorized - authentication failed
