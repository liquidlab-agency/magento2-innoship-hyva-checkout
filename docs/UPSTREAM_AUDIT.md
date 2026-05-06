# `InnoShip_InnoShip` — Upstream Audit

> **Verdict.** Functional but architecturally rough. The module ships orders and prints AWBs, but every consumer pays a tax in raw SQL, untestable god-classes, and a 1.6k-line jQuery checkout that does not run in Hyvä. We wrap it; we do not extend it.

## Severity at a glance

| Critical | High | Medium | Low |
|:---:|:---:|:---:|:---:|
| 1 | 3 | 6 | 4 |

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

---

## Medium

**5. Schema without referential integrity.**
`etc/db_schema.xml:7` — `innoship_pudo` has indexes but no foreign keys to `quote_address` / `sales_order_address`, despite those tables storing `innoship_pudo_id`. Orphans go undetected.

**6. Inconsistent / missing return types.**
~30 controller `execute()` methods declare no return type — e.g. `Controller/City/Getcity.php:39`. `Model/Config.php` public methods (`getSpecificCountries`, `getSpecificCountriesGo` near line 269) are also untyped.

**7. Sparse strict-type adoption.**
`declare(strict_types=1)` appears in 6 of 84 files (≈7%). Core models — `Model/Table.php`, `Model/Carrier.php` — opt out entirely.

**8. Frontend = one massive vanilla-JS file.**
- `view/frontend/web/js/checkout.js` — **1,637 lines**.
- `view/frontend/web/js/customer-address-city.js` — 240 lines.
- `view/frontend/web/js/set-city-mixin.js` — 192 lines.
No bundling, no modules, jQuery + Knockout + raw DOM mixed together.

**Why it bit us.** Not portable to Hyvä. We rebuilt the picker UI from scratch in `Liquidlab_InnoShipHyva` rather than try to port it.

**9. i18n half-finished.**
`i18n/` contains `en_US.csv` and `ro_RO.csv`, but several user-facing strings skip `__()`. `Observer/DeleteAwb.php:101` and `:106` hardcode `'Error on deleting AWB ...'` and `'The AWB %s has ben deleted successfully!'` — note the typo `"ben"` instead of `"been"` on line 106, visible to merchants in shipment comments.

**10. CSRF coverage is inconsistent.**
- `Controller/Account/SaveLocker.php:17` — correctly implements `CsrfAwareActionInterface`.
- Other POST controllers (e.g. `Controller/Pudo/Setpudo.php`) implement `HttpPostActionInterface` only, with no explicit CSRF declaration. Either the protection is defaulted away or there is real exposure here — worth a deeper read before relying on either.

---

## Low

**11. Public properties on controllers.**
- `Controller/Courier/Listcouriers.php:23` — `public $quoteRepository;`
- `Controller/City/Getcity.php:18` — same.
Encapsulation broken; downstream code can poke at the repository directly.

**12. Hardcoded courier table.**
`Model/Table.php:12` — `const COURIER_TABLES = ['Cargus', 'DPD', 'FanCourier', 'GLS', 'Nemo', 'Sameday', 'ExpressOne', 'Econt', 'TeamCourier', 'DHL'];`. Adding a courier requires a code change.

**13. Empty `RegionBackup/` directory in module root.**
Contains only a `.gitignore`. Looks like an abandoned migration artifact sitting next to legitimate folders.

**14. Stale composer constraints.**
`composer.json:5` — `"php": "~7.3.0|~7.4.0"`. Allows installation on EOL PHP and does not reflect the PHP 8.x runtime everything actually targets.

---

## What this means for us

- `Liquidlab_InnoShipHyva` exists *because* upstream's frontend cannot run in Hyvä and its data layer offers nothing reusable.
- Our compat module deliberately reads `innoship_pudo` through its own `Liquidlab\InnoShipHyva\Api\PudoRepositoryInterface` rather than calling into upstream code.
- Every "Why didn't you reuse X from `InnoShip_InnoShip`?" answer is on this page.
