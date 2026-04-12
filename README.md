# KREABANK FOR [DOLIBARR ERP & CRM](https://www.dolibarr.org)

## Features

- Assistant UX over native bank module (`/compta/bank`)
- Import wizard with field mapping confirmation (CSV, OFX, CAMT.053 XML, XLS, XLSX)
- Format/profile recognition per bank layout (saved mapping profiles)
- Idempotent import (hash + account + date + amount + reference/description)
- Matching suggestions for open customer/supplier invoices
- Reconciliation persisted in native Dolibarr domain (bank/payment links + native conciliation)
- Quick-entry helper + audit telemetry (`llx_kreabank_recon_audit`)
- Multi-entity aware and permission-gated with native bank rights
- Cron-ready scaffold for bank feed sync settings

## Native-first architecture

- Official source of truth for statements/reconciliation:
  - `llx_bank`
  - `llx_bank_url`
  - native payment tables linked by `update_fk_bank(...)`
- KreaBank auxiliary tables are metadata only:
  - `llx_kreabank_bankmeta`
  - `llx_kreabank_import_profile`
  - `llx_kreabank_pattern`
  - `llx_kreabank_quick_entry`
  - `llx_kreabank_recon_audit`

<!--
![Screenshot kreabank](img/screenshot_kreabank.png?raw=true "KreaBank"){imgmd}
-->

Other external modules are available on [Dolistore.com](https://www.dolistore.com).

## Translations

Translations can be completed manually by editing files in the module directories under `langs`.

<!--
This module contains also a sample configuration for Transifex, under the hidden directory [.tx](.tx), so it is possible to manage translation using this service.

For more information, see the [translator's documentation](https://wiki.dolibarr.org/index.php/Translator_documentation).

There is a [Transifex project](https://transifex.com/projects/p/dolibarr-module-template) for this module.
-->


## Installation

Prerequisites: You must have Dolibarr ERP & CRM software installed. You can download it from [Dolistore.org](https://www.dolibarr.org).
You can also get a ready-to-use instance in the cloud from https://saas.dolibarr.org


### From the ZIP file and GUI interface

If the module is a ready-to-deploy zip file, so with a name `module_xxx-version.zip` (e.g., when downloading it from a marketplace like [Dolistore](https://www.dolistore.com)),
go to menu `Home> Setup> Modules> Deploy external module` and upload the zip file.

<!--

Note: If this screen tells you that there is no "custom" directory, check that your setup is correct:

- In your Dolibarr installation directory, edit the `htdocs/conf/conf.php` file and check that following lines are not commented:

    ```php
    //$dolibarr_main_url_root_alt ...
    //$dolibarr_main_document_root_alt ...
    ```

- Uncomment them if necessary (delete the leading `//`) and assign the proper value according to your Dolibarr installation

    For example :

    - UNIX:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = '/var/www/Dolibarr/htdocs/custom';
        ```

    - Windows:
        ```php
        $dolibarr_main_url_root_alt = '/custom';
        $dolibarr_main_document_root_alt = 'C:/My Web Sites/Dolibarr/htdocs/custom';
        ```
-->

<!--

### From a GIT repository

Clone the repository in `$dolibarr_main_document_root_alt/kreabank`

```shell
cd ....../custom
git clone git@github.com:gitlogin/kreabank.git kreabank
```

-->

### Final steps

Using your browser:

  - Log into Dolibarr as a super-administrator
  - Go to "Setup"> "Modules"
  - You should now be able to find and enable the module

### Module-local ML dependency

KreaBank uses its own local `vendor` directory for ML-assisted header mapping.
Install dependencies inside the module root:

```bash
cd custom/kreabank
composer install --no-dev --prefer-dist
```

## Regression checks

Run quick checks:

```bash
bash custom/kreabank/test/run_regression_native.sh
```

## Native assistant documentation

Short architecture guide (PT):

```text
custom/kreabank/doc/KREABANK_NATIVE_ASSISTANT.md
```



## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readme's are licensed under [GFDL](https://www.gnu.org/licenses/fdl-1.3.en.html).
