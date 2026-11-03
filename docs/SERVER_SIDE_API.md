# Server-side Jio API (1.4.0)

## Why this exists

Up to 1.3.5 the plugin published the merchant secret in checkout page source:

```php
'secret_key' => $secret_key,   // woo-jiopay.php, wp_localize_script
```

```js
secretKey: jioPayVars.secret_key,   // jio-pay-integration.js
```

It was there because Jio's browser SDK signs every request itself:

```js
// jio-pay-sdk.js, de-minified
function Cv(payload = {}, secret = "") {
  const sorted  = Object.keys(payload).sort().reduce(...);
  const message = Object.keys(sorted).map(k => String(sorted[k] ?? "").trim()).join("");
  sorted.secureHash = CryptoJS.enc.Hex.stringify(
    CryptoJS.HmacSHA256(message, secret.trim())   // merchant secret as the HMAC key
  );
}
```

Anyone could read the live key with View Source, which also made `secureHash`
worthless as a signature: a value anyone can compute proves nothing.

`includes/class-jio-pay-service.php` takes signing back onto the server. The
secret now lives only in the `woocommerce_jio_pay_settings` option row.

## The service

`WC_Jio_Pay_Service::from_settings()` is the only place credentials are
sourced. Nothing in the request can influence which merchant or secret is
used.

```php
$service = WC_Jio_Pay_Service::from_settings();

$result = $service->initiate_sale('NB', [
    'amount'          => '1.00',
    'email'           => 'shopper@example.com',
    'merchant_txn_no' => WC_Jio_Pay_Service::generate_merchant_txn_no(),
    'return_url'      => $return_url,
]);
```

Every call returns either a `WP_Error` or:

```php
['success' => bool, 'http_code' => int, 'data' => array|null, 'raw' => string]
```

### secureHash

Drop any existing `secureHash`, sort keys alphabetically, concatenate the
**values** (no keys, no separators), then hex HMAC-SHA256 keyed by the secret.

Verified byte-identical to Jio's Postman pre-request script and to the SDK's
`Cv()` — same digest for their documented sample payload.

### Payment modes

| Mode | `paymentMode` | `payType` | `paymentOptionCodes` | Extra fields |
|------|---------------|-----------|----------------------|--------------|
| `NB` | `NB`   | `0` | —    | — |
| `CC` | `CARD` | `1` | `CC` | `cardNo`, `cvv`, `cardExpiry`, `nameOnCard`, `customerName` |
| `DC` | `CARD` | `1` | `DC` | as above |

`UPI_VPA` and `UPI_INTENT` are **not** implemented. Jio's integration note
documents NB, CARD, generateQR and STATUS only; guessing their field names
would produce a payload that hashes to something their validator rejects. Add
them to `WC_Jio_Pay_Service::sale_modes()` once Jio supplies the contract.

### Endpoints

| Call | UAT | Production |
|------|-----|------------|
| `initiateSale` | `uat.jiopay.co.in/tsp/pg/api/v2/initiateSale` | `jiopay.co.in/pg/api/v2/initiateSale` |
| `generateQR`   | `uat.jiopay.co.in/tsp/pg/api/generateQR`      | `jiopay.co.in/pg/api/generateQR` |
| `command`      | `uat.jiopay.co.in/tsp/pg/api/command`         | `jiopay.co.in/tsp/pg/api/command` |

Note the inconsistency in Jio's own docs: UAT carries `/tsp/` throughout,
production drops it for `initiateSale` and `generateQR` but keeps it for
`/command`. The `/command` production URL is left as the refund handler has
had it since 1.1 rather than "corrected" on a guess. **Confirm all three with
Jio before the production cutover.**

Two quirks worth confirming with Jio at the same time:

- `generateQR` uses `currency=365` in their sample, while `initiateSale` uses
  `currencyCode=356`. 365 is not INR in ISO 4217. Kept as
  `QR_CURRENCY_CODE` and filterable via `jio_pay_qr_currency_code`.
- `STATUS` is sent unsigned, matching their sample and the refund handler's
  production behaviour. Filter `jio_pay_sign_status_request` to turn signing on.

## Browser endpoints

Exposed as `admin-ajax.php` actions, names published in `jioPayVars.endpoints`:

| Action | Purpose |
|--------|---------|
| `jio_pay_initiate_sale` | Start an NB/CC/DC sale |
| `jio_pay_generate_qr`   | Generate a UPI QR |
| `jio_pay_txn_status`    | Query a transaction's status |

Use the `window.JioPayApi` client in `assets/jio-pay-integration.js`:

```js
JioPayApi.initiateSale('NB').then(r => /* r.response, r.merchantTxnNo */);
JioPayApi.generateQr().then(r => /* r.response */);
JioPayApi.checkStatus().then(r => /* r.response */);
```

### What the browser is not allowed to decide

- **Amount** — read from the order (or a freshly recalculated cart) server
  side. A tampered amount in the POST cannot change what Jio is asked to
  charge. This also closes the stale-total bug fixed in 1.3.5.
- **merchantTxnNo** — minted server side and stored on the order as
  `_jio_pay_merchant_txn_no`, so a status reply can be trusted.
- **Which order** — `order_belongs_to_shopper()` checks customer id, the
  session's `order_awaiting_payment`, or a matching order key. `txn_status`
  reads the transaction number off the order rather than the request, so it
  cannot be used to enumerate other people's transactions.
- **Email** — taken from the order when there is one.

## Logging

`logs/api.log`. `cardNo` is reduced to its last four digits; `cvv`,
`secureHash` and the secret are replaced with `[redacted]`. Disable with the
`jio_pay_api_logging_enabled` filter.

## Outstanding

1. **Rotate the live secret.** It was public in page source on every
   checkout. Rotation only helps once 1.4.0 is deployed.
2. **The shipped SDK bundle cannot use these endpoints.** It has no hook for
   externally-supplied signing — grep found one `baseUrl` and no
   `onSign`/`signUrl` equivalent — so it still expects `secretKey` and builds
   its own requests. A build that delegates signing is needed from Jio, or the
   popup UI has to be driven by plugin code against `JioPayApi`.
3. **Card modes put this server in PCI DSS scope.** `CC`/`DC` route the PAN
   and CVV through WordPress. That moves the store from SAQ-A to SAQ-D. Ask
   Jio for hosted card capture or tokenisation so card data never touches the
   store; NB and UPI QR carry no cardholder data and are unaffected.
4. **`logs/refund-audit.log`** holds real customer PII and is committed to a
   public repository.
