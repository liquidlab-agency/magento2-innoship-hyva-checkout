# `InnoShip_InnoShip` — Upstream Audit

> **Verdict.** Functional but architecturally rough. The module ships orders and prints AWBs, but every consumer pays a tax in raw SQL, untestable god-classes, and a 1.6k-line jQuery checkout that does not run in Hyvä. We wrap it; we do not extend it.

## Severity at a glance

| Critical | High | Medium | Low |
|:---:|:---:|:---:|:---:|
| 1 | 5 | 6 | 4 |

---

## Critical

**1. Zero test coverage.**
No `Test/Unit`, no `Test/Integration` anywhere across the 84 PHP files. AWB generation, courier selection, external API sync, cron PUDO sync — none are covered. Every change is a regression risk.

---

## High

**2. No service contract for `innoship_pudo`.**
Every consumer reads the table via raw `ResourceConnection`. No `Api/Data` interface, no Resource Model, no Repository.
- `Controller/Pudo/Getpudo.php:68`, `Controller/Pudo/Getmap.php:181`
- `Block/Frontend/OrderViewAccount.php:32, 60`
- `Cron/Pudoinnoship.php:151, 217`
- `Plugin/Order/Email/Container/Template/EmailCode.php:26`

**Why it bit us.** Forced our compat module to introduce the contract ourselves at `Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface` rather than reuse upstream code.

**3. God-class shipping carriers.**
- `Model/Carrier.php` — **619 lines**, mixing rate calculation, REST API I/O, tracking, and shipping logic in a single class.
- `Model/Carriergo.php` — same shape, **470 lines**.
Single Responsibility is violated; mocking in isolation is impractical.

**4. `ObjectManager::getInstance()` in business logic.**
- `Model/Carrier.php:453` — `$objectManager = \Magento\Framework\App\ObjectManager::getInstance();` *inside* tracking logic, not in a factory.
- `Model/Carrier.php:153` and `Model/Carriergo.php:138` — same pattern as a constructor fallback.
- `Console/Initialimport.php:19` — first line of the command.
Bypasses DI, hides dependencies, breaks tests.

**5. AJAX endpoints implemented as custom controllers instead of `webapi.xml` REST routes.**
Magento 2's standard pattern for checkout-side AJAX is REST (`etc/webapi.xml`) backed by service contracts (`Api/*Interface`); the upstream module ignores this entirely and ships eight frontend controllers that build JSON from raw SQL inside `execute()`:
- `Controller/Pudo/Getpudo.php`, `Controller/Pudo/Getmap.php`, `Controller/Pudo/GetPudoFromLocation.php`, `Controller/Pudo/Setpudo.php`
- `Controller/Courier/Listcouriers.php`, `Controller/Courier/Setcourierid.php`
- `Controller/City/Getcity.php`, `Controller/Account/SaveLocker.php`

Costs: not consumable from PWA / mobile / headless without a session+form-key dance; no Swagger/OpenAPI introspection; no `SearchCriteria` filter/sort/pagination support; CSRF and auth implemented by hand (inconsistently — see #12); persistence endpoints (`Setpudo`, `Setcourierid`, `SaveLocker`) duplicate functionality already covered by Magento's own REST surface — `Setpudo` / `Setcourierid` could ride on the existing `/rest/V1/carts/mine/shipping-information` via the address `extension_attributes` the module already declares, and `SaveLocker` could use `CustomerRepositoryInterface::save()`.

The right shape would be a single `webapi.xml` exposing `getList(SearchCriteria)` / `getByPudoId(int)` on a real repository, plus extension attributes on the cart address for selection state. Our compat module already wrote the repository half (`Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface`); upstream still has none of this.

**6. Per-shipping-method payment restriction is broken and architecturally incomplete.**
The module ships *one* admin field that says "Allow Payment Methods" for the locker carrier — `carriers/innoshipcargusgo/innoship_cargus_go_payment_restriction` ([`etc/adminhtml/system.xml:303`](../../../app/code/InnoShip/InnoShip/etc/adminhtml/system.xml#L303)) — and a plugin that's supposed to enforce it. Three defects make the feature unreliable:

- **No equivalent field for the full courier.** Only the locker carrier (`innoshipcargusgo`) has a payment-restriction config. The other carrier (`innoship`) has no admin path at all, so any "card-only for full courier" behavior the merchant observes is coming from somewhere else (e.g. Magento's per-method `allowspecific`/`specificcountry` config or another extension), not from this module.

- **Brittle `===` comparison.** [`Plugin/Model/PaymentMethodAvailable.php:31`](../../../app/code/InnoShip/InnoShip/Plugin/Model/PaymentMethodAvailable.php#L31) hardcodes `if ($shippingMethod === "innoshipcargusgo_innoshipcargusgo_1")` against the saved `shipping_method` string on the quote address. Any extension that normalizes or rewrites the method code breaks the restriction silently. There's no logging, no fallback — if the literal mismatches, payments are unrestricted.

- **Empty-config strips every payment method.** Same plugin, line 34–35:
  ```php
  $methods = explode(',', (string)$this->config->getPaymentRestriction($currentStoreId));
  if (!empty($methods)) { /* intersect availableMethods with $methods */ }
  ```
  When the admin saves an empty multi-select: `(string)null === ''` → `explode(',', '')` → `['']` → `!empty([''])` is **true**, so the loop runs with `['']` as the allowlist and removes every real payment method. An unconfigured field bricks locker checkout entirely.

- **Dead-code carrier-code comparisons in the sibling plugin.** [`Plugin/Frontend/Magento/Checkout/Model/ShippingInformationManagement.php:37, 42`](../../../app/code/InnoShip/InnoShip/Plugin/Frontend/Magento/Checkout/Model/ShippingInformationManagement.php#L37) does `if ($addressInformation->getShippingCarrierCode() === "innoshipcargusgo_innoshipcargusgo_1")`. `getShippingCarrierCode()` returns just the carrier (`innoshipcargusgo`), never the full method string, so both branches are **always false** — `$_pudoID` and `$_courierID` are never assigned and `beforeSaveAddressInformation` is a no-op. The author confused two getters.

**Why it bit us.** Our compat module disables this plugin (`<plugin name="restrict_payment_on_shippingmethod" disabled="true"/>` in our `etc/di.xml`) and ships its own `Liquidlab\InnoShipHyva\Plugin\PaymentMethodRestriction`. To avoid forcing the merchant to reconfigure anything, we **reuse the same admin field upstream already exposes** (`carriers/innoshipcargusgo/innoship_cargus_go_payment_restriction`) — read via `Liquidlab\InnoShipHyva\Model\Config\PaymentRestrictionConfig` — but with robust carrier-code extraction via `strpos` and a real empty-config guard.

---

## Medium

**7. Schema without referential integrity.**
`etc/db_schema.xml:7` — `innoship_pudo` has indexes but no foreign keys to `quote_address` / `sales_order_address`, despite those tables storing `innoship_pudo_id`. Orphans go undetected.

**8. Inconsistent / missing return types.**
~30 controller `execute()` methods declare no return type — e.g. `Controller/City/Getcity.php:39`. `Model/Config.php` public methods (`getSpecificCountries`, `getSpecificCountriesGo` near line 269) are also untyped.

**9. Sparse strict-type adoption.**
`declare(strict_types=1)` appears in 6 of 84 files (≈7%). Core models — `Model/Table.php`, `Model/Carrier.php` — opt out entirely.

**10. Frontend = one massive vanilla-JS file.**
- `view/frontend/web/js/checkout.js` — **1,637 lines**.
- `view/frontend/web/js/customer-address-city.js` — 240 lines.
- `view/frontend/web/js/set-city-mixin.js` — 192 lines.
No bundling, no modules, jQuery + Knockout + raw DOM mixed together.

**Why it bit us.** Not portable to Hyvä. We rebuilt the picker UI from scratch in `Liquidlab_InnoShipHyva` rather than try to port it.

**11. i18n half-finished.**
`i18n/` contains `en_US.csv` and `ro_RO.csv`, but several user-facing strings skip `__()`. `Observer/DeleteAwb.php:101` and `:106` hardcode `'Error on deleting AWB ...'` and `'The AWB %s has ben deleted successfully!'` — note the typo `"ben"` instead of `"been"` on line 106, visible to merchants in shipment comments.

**12. CSRF coverage is inconsistent.**
- `Controller/Account/SaveLocker.php:17` — correctly implements `CsrfAwareActionInterface`.
- Other POST controllers (e.g. `Controller/Pudo/Setpudo.php`) implement `HttpPostActionInterface` only, with no explicit CSRF declaration. Either the protection is defaulted away or there is real exposure here — worth a deeper read before relying on either.

---

## Low

**13. Public properties on controllers.**
- `Controller/Courier/Listcouriers.php:23` — `public $quoteRepository;`
- `Controller/City/Getcity.php:18` — same.
Encapsulation broken; downstream code can poke at the repository directly.

**14. Hardcoded courier table.**
`Model/Table.php:12` — `const COURIER_TABLES = ['Cargus', 'DPD', 'FanCourier', 'GLS', 'Nemo', 'Sameday', 'ExpressOne', 'Econt', 'TeamCourier', 'DHL'];`. Adding a courier requires a code change.

**15. Empty `RegionBackup/` directory in module root.**
Contains only a `.gitignore`. Looks like an abandoned migration artifact sitting next to legitimate folders.

**16. Stale composer constraints.**
`composer.json:5` — `"php": "~7.3.0|~7.4.0"`. Allows installation on EOL PHP and does not reflect the PHP 8.x runtime everything actually targets.

---

## What this means for us

- `Liquidlab_InnoShipHyva` exists *because* upstream's frontend cannot run in Hyvä and its data layer offers nothing reusable.
- Our compat module deliberately reads `innoship_pudo` through its own `Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface` rather than calling into upstream code.
- We disable `InnoShip\InnoShip\Plugin\Model\PaymentMethodAvailable` and `Liquidlab\InnoShipHyva\Plugin\PaymentMethodRestriction` takes over — **reading the same upstream admin field**, with robust matching and a real empty-config guard. Adding support for the full courier later is one entry in `PaymentRestrictionConfig::CONFIG_PATHS`.
- Every "Why didn't you reuse X from `InnoShip_InnoShip`?" answer is on this page.
