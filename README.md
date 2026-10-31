# Zero_Brands

Shop by brand. Brands are the options of a dropdown product attribute
(`manufacturer` by default: Stores > Configuration > Zero > Brands); the
module gives each a page, an entry in the A–Z of brands, and a link on its
products' pages.

## Install

Requires Magento Open Source 2.4.7–2.4.9 and PHP 8.2–8.4 with the GD extension built with WebP (`php -r 'var_dump(function_exists("imagewebp"));'` says `true`; Magento's own PHP requirements cover the rest). Without WebP in GD, Zero skips its WebP image copies and serves the originals.

```bash
composer require zerofront/module-zero-brands
bin/magento setup:upgrade
bin/magento setup:di:compile           # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

Composer installs `zerofront/module-zero-layered-navigation` with it.

Part of [`zerofront/zero`](https://zerofront.dev), which installs the Zero theme and every Zero module at once.

## Storefront

- **/brands**: every brand with products, by first letter (letter links),
  the featured ones first as logos.
- **/brands/{url key}**: the brand's logo and description over its
  products, with the category page's filters, price slider, sorting and
  pages (Zero_LayeredNavigation). The brand is fixed (`zero_fixed`): not a
  filter group, no chip. Its own catalog layer (`zero_brand`: the search
  engine's collection under no category, `Model\Layer\ItemCollectionProvider`)
  so it covers the whole catalog. Filter and page links stay on its address
  (the request path is kept as the rewrite alias, `Controller\Router`); title,
  meta description and canonical are the brand's.
- **Product pages**: the brand's logo (or name) above the name, linking to
  its page.

## Admin

Catalog > Brands (resource `Zero_Brands::brands`): every brand with its
products, page and logo; a brand's form: name (the attribute option's admin
label), URL key, logo (JPEG, PNG or WebP, into pub/media/zero/brand; SVG is
refused because it can carry script, logos stored before keep showing),
description, meta title and description, featured and its position.
Deleting a brand deletes the option (its products lose it). A product's
brand is set on the product, as any attribute.

`Setup\Patch\Data\ShowBrandAttribute` makes `manufacturer` a filter
(category and search pages), searchable, shown on product pages and in
listings, in every attribute set.

## Tests

`dev/tests/zero-e2e/tests/brands.spec.ts` (the A–Z, a brand page, a product's
brand link, an unknown brand), `brands-admin.spec.ts` (create with a logo,
the page, delete; writes, so local or ZERO_E2E_PROD_WRITES=1).

## Disabling or removing

Its data lives in its own tables (`zero_brand`). With the module's code still in
place, `bin/magento module:disable Zero_Brands` followed by `setup:upgrade`
**drops those tables** (Magento's declarative schema), and enabling the module
again brings them back empty. To keep the data, switch the feature off in
Stores > Configuration where it has a setting, or back up first:
`bin/magento setup:upgrade --safe-mode=1` dumps the data before anything is
dropped (`--data-restore=1` puts it back). Deleting the module's code instead
leaves the tables in place.

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

## License

OSL-3.0 or AFL-3.0, your choice: see `NOTICE.md`.

---

<a href="https://vilmate.com"><picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo-dark.svg">
  <img src="https://raw.githubusercontent.com/zerofrontdev/zero/main/docs/images/vilmate-logo.svg" alt="Vilmate" height="34" align="right">
</picture></a>

Zero is made by [Fixstacks](https://fixstacks.com) in partnership with [Vilmate](https://vilmate.com), a software development company.
Documentation: https://zerofront.dev/docs. Demo store: https://demo.zerofront.dev.
