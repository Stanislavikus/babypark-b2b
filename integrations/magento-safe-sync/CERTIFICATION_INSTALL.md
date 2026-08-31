# Safe Sync certification distribution mechanism

The module is not currently published by this repository to Packagist, Adobe
Marketplace, Satis, Private Packagist, or a split repository. The repository also
contains no package publication workflow. For real-target certification only,
build the exact committed module as a Composer dist artifact:

```bash
bash scripts/package-magento-safe-sync.sh \
  build/magento-safe-sync \
  https://TEMPORARY-HTTPS-LOCATION/b2b-platform-magento-safe-sync-0.2.1.zip
```

Publish the generated zip and `packages.json` together at the temporary HTTPS
location. On the disposable/reference Magento project:

```bash
composer config repositories.b2b-safe-sync composer https://TEMPORARY-HTTPS-LOCATION
composer require b2b-platform/magento-safe-sync:0.2.1
bin/magento module:enable B2BPlatform_MagentoSafeSync --clear-static-content
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Verify the artifact SHA-256 against the packaging command output before making
it available to the target. This is a **certification distribution mechanism**,
not the final customer distribution channel. Selecting that production channel
remains a separate delivery decision.
