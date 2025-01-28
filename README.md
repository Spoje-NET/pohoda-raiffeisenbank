# Raiffeisenbank for Stormware Pohoda

![](pohoda-raiffeisenbank.svg?raw=true)

It downloads PDF statements from Raiffeisen Premium API for a specified period and uploads them to Sharepoint.

It downloads the corresponding XML statements and parses them. It imports the bank movements obtained in this way via mServer into Stormware Pohoda.

After importing all items, document liquidation is initiated and they are automatically matched.

When using PohodaSQL, links to PDF statements are attached to all movements.

[![wakatime](https://wakatime.com/badge/user/5abba9ca-813e-43ac-9b5f-b1cfdf3dc1c7/project/018b7d35-a10b-4f4b-ba78-241d1c79b4e6.svg)](https://wakatime.com/badge/user/5abba9ca-813e-43ac-9b5f-b1cfdf3dc1c7/project/018b7d35-a10b-4f4b-ba78-241d1c79b4e6)

## Requirements

* php 8.1+
* Pohoda (Pohoda SQL for full functionality) + [mServer](https://www.stormware.cz/pohoda/xml/mserver/)
* Sharepoint User or Application Account
* MSSQL login and password
* [php-sqlsrv](https://learn.microsoft.com/en-us/sql/connect/php/microsoft-php-driver-for-sql-server?view=sql-server-ver16)

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

POHODA_ICO=12345678
POHODA_URL=http://10.11.25.25:10010
POHODA_USERNAME=mServerXXX
POHODA_PASSWORD=mServerXXX
POHODA_TIMEOUT=60
POHODA_COMPRESS=false
POHODA_DEBUG=true
POHODA_BANK_IDS=RB

DB_CONNECTION=sqlsrv
DB_HOST=192.168.25.23
DB_PORT=1433
DB_DATABASE=StwPh_12345678_2023
DB_USERNAME=pohodaSQLuser
DB_PASSWORD=pohodaSQLpassword
DB_SETTINGS=encrypt=false
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
Otherwise you can use `CNB_CACHE` to get actual rate from CNB.
Then you can also need to specify `RATE_OFFSET`=yesterday to get rate for previous day.

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

## ExitCodes

254 - Another Exception without numeric code Ocurred

## Powered by

* <https://github.com/VitexSoftware/php-vitexsoftware-rbczpremiumapi>
* <https://github.com/Spoje-NET/PohodaSQL>
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
* **pohodasql-raiffeisenbank-statements-sharepoint** - Import transactions from Account Statements with link to Sharepoint
