Raiffeisenbank for Stormware Pohoda
===================================

![](pohoda-raiffeisenbank.svg?raw=true)

Configuration
-------------

```env
EASE_LOGGER=syslog|console
CERT_FILE='RAIFF_CERT.p12'
CERT_PASS=CertPass
XIBMCLIENTID=PwX4bOQLWGiuoErv6I
ACCOUNT_NUMBER=666666666
ACCOUNT_CURRENCY=CZK

STATEMENT_IMPORT_SCOPE=last_two_months
TRANSACTION_IMPORT_SCOPE=yesterday

API_DEBUG=True
APP_DEBUG=True
STATEMENT_LINE=ADDITIONAL

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

Sharepoint Integration
----------------------

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

