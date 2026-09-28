# Installation in Dolibarr 24

## Installable ZIP

Build the package from the repository root:

```bash
./scripts/build-module-package.sh
```

The command creates `dist/module_bankconnect-<version>.zip`. Upload that file in
**Home → Setup → Modules/Applications → Deploy/install external app/module**.
Dolibarr extracts the ZIP as `htdocs/custom/bankconnect`.

Then enable **BankConnect** in **Modules/Applications**. Enabling the module:

- checks PHP 8.1+ and Dolibarr 24+;
- requires Dolibarr's Banks and Cash module;
- creates the BankConnect sidecar tables through Dolibarr's SQL loader;
- creates the module document directory;
- installs the read/write permissions and the reconciliation menu.

Disabling the module removes its configuration, permissions and menu entries but
does not delete imported or audit data.

## Development checkout

For development, place or symlink `htdocs/custom/bankconnect` into the Dolibarr
`htdocs/custom` directory. The repository root is intentionally not the ZIP root;
the build script produces the layout expected by Dolibarr.

## Current accounting boundary

The package installs the BankConnect transport, import, reconciliation and
supplier-payment UI. Approved matches may be linked to Dolibarr payments and
existing bank entries; transferring those entries to accounting still uses
Dolibarr's bank financial journal. The module does not post ledger entries.

Payment submission uses an active BankConnect agreement with its mapped open
bank account and verified certificates. Before any live payment, complete the
Dolibarr and BankConnect system-test gates in [RELEASE_CHECKLIST.md](RELEASE_CHECKLIST.md).
