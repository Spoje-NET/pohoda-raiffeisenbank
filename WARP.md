# WARP.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

This is a PHP application that integrates RaiffeisenBank Premium API with Stormware Pohoda accounting system. It downloads PDF and XML bank statements, imports bank transactions, and provides SharePoint integration for document management.

Key features:

- Downloads bank statements from RaiffeisenBank Premium API
- Imports transactions into Pohoda via mServer
- Uploads statements to SharePoint
- Handles foreign currency conversion
- Prevents duplicate imports
- Supports multiple import scopes and date ranges

## Core Architecture

### Main Components

- **PohodaBankClient** (`src/Pohoda/RaiffeisenBank/PohodaBankClient.php`): Abstract base class handling common bank operations, certificate validation, and date scope management
- **Transactor** (`src/Pohoda/RaiffeisenBank/Transactor.php`): Handles individual transaction imports from RaiffeisenBank API to Pohoda
- **Statementor** (`src/Pohoda/RaiffeisenBank/Statementor.php`): Manages bank statement downloads (XML/PDF) and batch imports

### Binary Commands

All executable scripts are in the `src/` directory:

- `pohoda-raiffeisenbank-transactions.php`: Import individual transactions
- `pohoda-raiffeisenbank-statements.php`: Import from XML statements
- `pohoda-raiffeisenbank-setup.php`: Setup bank accounts in Pohoda
- `pohoda-raiffeisenbank-offline-statement-importer.php`: Import from offline XML files
- `pohodasql-raiffeisenbank-statements-sharepoint.php`: Import with SharePoint links
- `pohoda-bank-transaction-report.php`: Generate JSON transaction reports

## Development Commands

### Dependencies and Setup

```bash
# Install PHP dependencies
make vendor

# Run with custom environment file
./src/pohoda-raiffeisenbank-transactions.php path/to/.env
```

### Testing

```bash
# Run PHPUnit tests
make phpunit
# or
make tests
```

### Code Quality

```bash
# Fix coding standards (PSR-12)
make cs

# Run static analysis with PHPStan
make static-code-analysis

# Generate PHPStan baseline
make static-code-analysis-baseline
```

## Configuration

Configuration is managed via `.env` files. Use `.env.example` as template. Key configuration categories:

### RaiffeisenBank API

- `CERT_FILE`: Path to .p12 certificate file
- `CERT_PASS`: Certificate password
- `XIBMCLIENTID`: API Client ID
- `ACCOUNT_NUMBER`: Bank account number
- `ACCOUNT_CURRENCY`: Currency code (default: CZK)

### Pohoda Integration

- `POHODA_URL`: mServer URL (e.g., <http://10.11.25.25:10010>)
- `POHODA_USERNAME`/`POHODA_PASSWORD`: mServer credentials
- `POHODA_ICO`: Company tax ID
- `POHODA_BANK_IDS`: Bank identifier in Pohoda

### Database (for PohodaSQL)

- `DB_CONNECTION=sqlsrv`
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`: MSSQL connection details
- `DB_USERNAME`, `DB_PASSWORD`: Database credentials

### SharePoint Integration

Either login-based or ClientID-based authentication:

```env
# Login-based
OFFICE365_USERNAME=user@company.com
OFFICE365_PASSWORD=password

# ClientID-based  
OFFICE365_CLIENTID=...
OFFICE365_SECRET=...
OFFICE365_CLSECRET=...
```

## Import Scopes

The application supports various time scopes for importing transactions:

- `today`, `yesterday`
- `last_week`, `last_month`, `last_two_months`
- `previous_month`, `two_months_ago`
- `this_year`
- Month names: `January`, `February`, etc.
- `auto`: Import from last imported transaction
- Custom ranges: `2024-08-05>2024-08-11`
- Single day: `2024-10-11`

## Foreign Currency Handling

For non-CZK transactions:

- Use `FIXED_RATE` for fixed conversion rate
- Use `CNB_CACHE` for dynamic CNB rates (requires CNB cache server)
- Set `RATE_OFFSET=yesterday` for previous day rates
- Use `FIXED_RATE_AMOUNT=100` for currencies like JPY

## MultiFlexi Integration

The project is configured as MultiFlexi applications. JSON configuration files in `multiflexi/` define application metadata, environment variables, and deployment options for the MultiFlexi platform.

All `*.app.json` files must conform to the MultiFlexi schema at: <https://raw.githubusercontent.com/VitexSoftware/php-vitexsoftware-multiflexi-core/refs/heads/main/multiflexi.app.schema.json>

Validate MultiFlexi JSON files:

```bash
multiflexi-cli application validate-json --json multiflexi/[filename].app.json
```

## Coding Standards

- **PHP Version**: 8.1+ (preferably 8.4+)
- **Standard**: PSR-12 coding standard
- **Language**: All code comments and messages in English
- **Documentation**: DocBlocks for all functions and classes with parameters and return types
- **Type Hints**: Always include type hints for parameters and return types
- **Security**: Never expose sensitive information in code or commits
- **Testing**: Create/update PHPUnit tests when creating/updating classes
- **Performance**: Consider optimization and compatibility with latest PHP versions

## Error Handling

The application uses specific exit codes:

- `2`: Error obtaining PDF statements
- `3`: Error accessing Pohoda mServer  
- `4`: SharePoint connection problem
- `254`: Generic exception without numeric code
- `401`: User not authorized to import XML files to Pohoda

## Duplicate Prevention

The system prevents duplicate imports by checking transaction IDs in Pohoda using filters like `Pozn2 like '%#transactionId#%'`. If a transaction already exists, it's skipped to maintain data integrity.

## Dependencies

Key external libraries:

- `vitexsoftware/rbczpremiumapi`: RaiffeisenBank Premium API client
- `vitexsoftware/pohoda-connector`: Pohoda mServer integration
- `spojenet/pohoda-sql`: Direct PohodaSQL database operations
- `vgrem/php-spo`: SharePoint integration

## Testing Environment

Use the test certificate and credentials from the vendor directory for development:

```env
CERT_FILE=vendor/vitexsoftware/rbczpremiumapi/examples/test_cert.p12
CERT_PASS=test12345678
```

## See Also

Related projects:

- [pohoda-client-checker](https://github.com/Spoje-NET/pohoda-client-checker)
- [raiffeisenbank-statement-tools](https://github.com/Spoje-NET/raiffeisenbank-statement-tools)
- [CNB-Cache](https://github.com/Spoje-NET/CNB-Cache) - Required for dynamic currency rates
