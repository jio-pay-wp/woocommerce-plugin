# Jio Pay SDK → plugin integration contract

**For: the Jio Pay SDK team.** This is everything the SDK needs in order to
stop holding the merchant secret. Two events, two calls. The plugin does all
signing and all talking to Jio.

## Why this change

The current SDK computes `secureHash` in the browser:

```js
HmacSHA256(sortedPayloadValues, secretKey)
```

That requires the merchant secret to be present in JavaScript, so it appears
in checkout page source on every page load. Our merchants' live keys are
publicly readable, and `secureHash` stops proving anything — any visitor can
compute a valid one.

From plugin v1.4.0 the secret stays on the merchant's server. The SDK no
longer signs, and no longer calls Jio directly. It calls us; we sign and call
Jio; we hand back Jio's response unchanged.

**`secretKey` is no longer provided to the SDK and must not be required.**

## Who calls what

Of the actions published on the page, the SDK implements **one** (plus one
optional). The rest are the plugin's own business.

| Action | Called by | SDK must implement |
|--------|-----------|--------------------|
| `jio_pay_init_checkout` | The merchant's checkout page, before the SDK is constructed | no |
| `jio_pay_initiate_sale` | **The SDK**, when the shopper picks a payment option | **yes** |
| `jio_pay_txn_status` | The SDK, for reconciliation or an unclear outcome | optional |
| `jio_pay_verify_payment` | The plugin, from inside its own `onSuccess` / `onFailure` handler | no |

`verify_payment` is worth calling out: the SDK *triggers* it by firing
`onSuccess`, but the plugin makes the call. Nothing for Jio to build there
beyond returning `merchantTxnNo` in the callback result.

## Transport

All calls are `POST`, `application/x-www-form-urlencoded`, to the WordPress
AJAX endpoint on the merchant's own origin:

```
POST /wp-admin/admin-ajax.php
```

The exact URL and the action names are published on the checkout page as
`window.jioPayVars`:

```js
jioPayVars.ajax_url              // "https://store.example.com/wp-admin/admin-ajax.php"
jioPayVars.nonce                 // send as `nonce`
jioPayVars.endpoints.init_checkout   // "jio_pay_init_checkout"  (page, not SDK)
jioPayVars.endpoints.initiate_sale   // "jio_pay_initiate_sale"  <- the SDK's call
jioPayVars.endpoints.txn_status      // "jio_pay_txn_status"
jioPayVars.endpoints.verify          // "jio_pay_verify_payment" (plugin, not SDK)
```

There is one HTTP endpoint, not four. WordPress routes every AJAX call
through `admin-ajax.php`; the `action` field selects the handler. So the URL
is the same for all of these and only `action` changes.

Read them from there rather than hard-coding — the names are stable but the
host is not. Send cookies (`credentials: 'same-origin'`); the shopper's
session is how we authorise the call.

Every reply is WordPress's standard AJAX envelope:

```json
{ "success": true,  "data": { ... } }
{ "success": false, "data": { "message": "...", "code": "..." } }
```

---

## Event 1 — shopper selects Jio Pay

Call this when Jio Pay is chosen, **before** opening the popup. It returns the
`checkoutRef` that identifies this checkout for the rest of the flow, plus
everything needed to render the payment options.

```
action=jio_pay_init_checkout
nonce=<jioPayVars.nonce>
```

### Response

```json
{
  "success": true,
  "data": {
    "checkoutRef": "JPC7F3K9QX2M8VB4NDJW6HLP",
    "amount": "554.24",
    "currency": "INR",
    "currencyCode": "356",
    "env": "prod",
    "testMode": false,
    "merchantName": "Techiesms",
    "merchantLogo": "https://store.example.com/logo.png",
    "allowedPaymentTypes": ["NB", "UPI_QR", "CREDIT_CARD", "DEBIT_CARD"],
    "theme": "light",
    "timeOut": 300,
    "customerEmailID": "shopper@example.com",
    "userName": "Jane Doe"
  }
}
```

Notes:

- **Render only `allowedPaymentTypes`.** It is already filtered to what the
  merchant enabled *and* what the server can process. Do not show anything
  else — it will be rejected at event 2.
- `amount` is authoritative and is recomputed on every call. Display it, but
  do not send it back; see "What the SDK must not send".
- `timeOut` is in **seconds**.
- Keep `checkoutRef` for the rest of the session. It is unguessable and bound
  to the shopper's session, so it cannot be reused from another browser.
- **`merchantId`, `aggregatorID`, the MCC and the environment name are not
  returned, and are no longer on the checkout page at all.** The SDK does not
  build Jio requests any more, so it has no use for them, and there is no
  reason to hand every visitor the merchant's identity. `testMode` is there if
  the popup wants to badge itself during certification.

---

## What the checkout page hands the SDK

The merchant's checkout page constructs the SDK with this, and nothing more:

```js
{
  // How to reach the plugin.
  ajaxUrl:  "https://store.example.com/wp-admin/admin-ajax.php",
  nonce:    "a1b2c3d4e5",
  endpoints: { init_checkout, initiate_sale, txn_status, ... },

  // Issued by event 1; replay it on every later call.
  checkoutRef: "JPC7F3K9QX2M8VB4NDJW6HLP",

  // Already authoritative, straight from event 1. Provided so the SDK need
  // not call init_checkout again -- though calling it again with this
  // checkoutRef is safe and returns the same reference.
  amount:              "554.24",
  currency:            "INR",
  allowedPaymentTypes: ["NB", "UPI_QR", "CREDIT_CARD", "DEBIT_CARD"],
  merchantName:        "Techiesms",
  merchantLogo:        "https://store.example.com/logo.png",
  theme:               "light",
  timeOut:             300,
  env:                 "prod",
  testMode:            false,

  // Prefill only, for the popup's own fields.
  customerEmailID: "shopper@example.com",
  email:           "shopper@example.com",
  userName:        "Jane Doe",

  onSuccess, onFailure, onClose
}
```

### What is no longer passed, and must not be required

| Was passed | Now |
|------------|-----|
| `secretKey` | Gone. Signing is server side. |
| `merchantId`, `aggId`, `mcc_code`, `env` | Gone. The server supplies its own identifiers when it builds the payload. Not on the page at all. |
| `merchantTrId` | Gone. **The SDK must not generate a transaction number.** The server mints it at event 2 and returns it as `merchantTxnNo`. A browser-generated id used to be POSTed back and would overwrite the number the server had sent to Jio, leaving the order tagged with a reference Jio never saw. |
| `returnURL` | Gone. The plugin sets its own handler. |

### What the SDK must return in its callbacks

Echo `merchantTxnNo` (from the event 2 response) back in the `onSuccess` and
`onFailure` result objects, alongside Jio's own fields (`txnAuthID`,
`txnResponseCode`, `txnRespDescription`, `secureHash`, `amount`,
`txnDateTime`). The plugin matches the result to the order by that number.

---

## Event 2 — shopper selects a payment option

One call for every payment type. We route internally — UPI QR goes to Jio's
`generateQR`, the rest to `initiateSale`. The SDK does not need to know which.

```
action=jio_pay_initiate_sale
nonce=<jioPayVars.nonce>
checkout_ref=JPC7F3K9QX2M8VB4NDJW6HLP
payment_type=NB
```

`payment_type` is the code as displayed — send back exactly what came in
`allowedPaymentTypes`:

| `payment_type` | Routed to | Extra fields required |
|----------------|-----------|-----------------------|
| `NB`           | `initiateSale` (`paymentMode=NB`, `payType=0`) | — |
| `UPI_QR`       | `generateQR` (`requestType=UPIQR`) | — |
| `CREDIT_CARD`  | `initiateSale` (`paymentMode=CARD`, `paymentOptionCodes=CC`, `payType=1`) | card fields below |
| `DEBIT_CARD`   | `initiateSale` (`paymentMode=CARD`, `paymentOptionCodes=DC`, `payType=1`) | card fields below |

For the two card types, add:

```
cardNo=4280912015088213
cvv=123
cardExpiry=203207
nameOnCard=John Doe
```

`cardExpiry` is `YYYYMM`. Card fields are passed straight to Jio; they are
never stored, and the server log keeps only the last four digits of the PAN.

### Response

```json
{
  "success": true,
  "data": {
    "paymentType": "NB",
    "merchantTxnNo": "8441342264",
    "amount": "554.24",
    "http_code": 200,
    "response": { "...": "Jio's reply, verbatim" }
  }
}
```

`response` is Jio's own JSON, unmodified — handle it exactly as the SDK
handles a direct reply today. If Jio returns something unparseable, `response`
is `null` and the body arrives as `raw` instead.

`merchantTxnNo` is minted by us and already recorded against the order, so it
needs no echo.

---

## Event 3 (optional) — confirm status

For reconciliation, or after a timeout where the outcome is unclear.

```
action=jio_pay_txn_status
nonce=<jioPayVars.nonce>
checkout_ref=JPC7F3K9QX2M8VB4NDJW6HLP
```

Returns Jio's `STATUS` reply under `response`. The transaction number is read
from the order rather than the request, so this cannot be used to look up
another shopper's transaction.

---

## What the SDK must not send

These are deliberately ignored if present. They are decided server side, and
accepting them from the browser is what made the old flow unsafe:

| Field | Why |
|-------|-----|
| `secretKey` | Never leaves the server. Signing happens in PHP. |
| `secureHash` | Computed server side over the final payload. |
| `amount` | Read from the order. A tampered amount cannot change the charge, and this also fixes a real bug where a shipping change left the popup showing a stale total. |
| `merchantTxnNo` | Minted server side so a status reply can be trusted. |
| `returnURL` | Set to the plugin's own handler. |
| `txnDate`, `currencyCode`, `aggregatorID`, `merchantId`, MCC | Server side, from stored settings. Not published to the page, so the SDK cannot read them even if it wanted to. |

## Errors

`success: false` with a `code`:

Every error from these endpoints carries a `code`. Branch on the code, never
on the message text -- the messages are shopper-facing and may be reworded or
translated.

| Code | Meaning | SDK should |
|------|---------|------------|
| `jio_pay_expired_ref` | `checkoutRef` expired (2h TTL) | Close, ask shopper to reload |
| `jio_pay_ref_not_yours` | Reference does not match session | Close; do not retry |
| `jio_pay_bad_ref` | No reference sent | Fix the call |
| `jio_pay_missing_payment_type` | No `payment_type` sent | Fix the call |
| `jio_pay_payment_type_disabled` | Merchant has that method switched off | Should not happen if `allowedPaymentTypes` is respected |
| `jio_pay_unsupported_payment_type` | Type not supported server side (`UPI_VPA`, `UPI_INTENT`) | As above |
| `jio_pay_missing_field` | A required card field is absent | Re-prompt |
| `jio_pay_bad_amount` | Cart/order priced at zero or less | Close |
| `jio_pay_not_configured` | Merchant has no credentials saved | Close |
| `jio_pay_no_order_yet` | `txn_status` called before any payment started | Nothing to check; not an error worth showing |
| `jio_pay_no_transaction` | Order exists but has no Jio transaction | As above |
| `jio_pay_not_your_order` | Order does not belong to this session | Close; do not retry |
| `jio_pay_internal_error` | Something failed on the store | Treat the outcome as UNCONFIRMED -- see below |

**On a transport failure or an unparseable reply, do not tell the shopper the
payment failed.** The charge may have succeeded. Say the outcome is
unconfirmed, tell them not to pay again, and call event 3.

---

## A case-sensitivity trap in the current SDK

`env` is sent **lower case** (`"uat"` / `"prod"`) and must be compared that
way. The bundle validates case-insensitively:

```js
if (!d || !["uat","prod"].includes(d.toLowerCase())) return error("Invalid environment!")
```

but every branch that acts on it uses strict equality:

```js
Ve === "uat" ? "uat" : "prod"                        // base URL
Ve === "prod" && (Le.returnURL = Qe || xt)           // returnURL only on prod
Ve !== "prod" ? je(fn) : window.open(fn, "_blank")   // redirect style
```

So `"PROD"` passes validation and then fails every comparison: production
URLs, but with `returnURL` unset and the non-production redirect path taken.
`"UAT"` is worse -- it misses the `=== "uat"` branch and so sends UAT traffic
to production URLs. Please make these comparisons case-insensitive, or
document the requirement; the plugin sends lower case either way.

## Reading the response (the one thing to get right)

Every reply is the WordPress envelope. Jio's own reply is nested inside it,
at `data.response` -- it is **not** at the top level:

```js
const body = (await axios.post(ajaxUrl, payload)).data;

body.success                        // true / false
body.data.merchantTxnNo             // minted by the server
body.data.amount                    // what is actually being charged
body.data.response.responseCode     // Jio's code, e.g. "R1000"
body.data.response.redirectURI      // Jio's fields, verbatim
body.data.response.tranCtx
body.data.code                      // on failure, e.g. "jio_pay_expired_ref"
```

So a check written against the old direct-to-Jio flow needs one extra hop:

```js
// before (talking to Jio directly)
if (res?.responseCode === "R1000") { … }

// now
if (body?.data?.response?.responseCode === "R1000") { … }
```

Same for status: `body.data.response.txnResponseCode`, not
`body.txnResponseCode`.

The nesting is deliberate. Jio's reply is passed through untouched, so
flattening it into the envelope would let a field named `success` or `data`
in a future Jio response silently corrupt the envelope.

## Polling `txn_status`

Two things the plugin needs the SDK to do:

1. **Have a terminal condition for every outcome, not just success and
   failure.** A poll that clears its interval only on specific response codes
   runs forever when the payment is still pending, or when the response
   cannot be read. The plugin caches status replies for 5 seconds so a
   runaway poll cannot multiply into outbound Jio traffic, but the loop
   itself still runs in the shopper's browser until the tab closes.
2. **Cap it.** Stop after a bounded number of attempts, or after the
   configured `timeOut`, and then tell the shopper the outcome is
   unconfirmed rather than polling silently.

A `cached: true` field on the reply means it was served from that 5 second
cache rather than freshly fetched from Jio. It is informational; treat the
payload the same either way.

## Open items we need from Jio

1. **`UPI_VPA` and `UPI_INTENT` are not implemented.** Your integration note
   documents NB, CARD, generateQR and STATUS only. We will not guess field
   names, because a wrong payload hashes to a `secureHash` your validator
   rejects. Please send the `initiateSale` field list for both and we will add
   them.
2. **`generateQR` currency.** Your sample uses `currency=365`, while
   `initiateSale` uses `currencyCode=356`. 365 is not INR in ISO 4217. Which
   is correct? We currently send 365 for QR as documented.
3. **Endpoint paths.** UAT carries `/tsp/` throughout; production drops it for
   `initiateSale` and `generateQR` but appears to keep it for `/command`.
   Please confirm all three production URLs.
4. **Is `STATUS` signed?** Your sample `STATUS` call carries no `secureHash`
   and our production refunds have worked without one. Confirm, so we can turn
   signing on if needed.
5. **Is the MCC meant to be in the payload?** The plugin has an MCC setting
   per environment, and the old SDK config carried `mcc_code`, but none of
   your documented `initiateSale` or `generateQR` samples include it. We
   currently do not send it. Confirm whether it belongs in either payload --
   and therefore in the `secureHash`.
6. **Does `primaryMethod` still apply?** It is a config prop the SDK reads,
   and the plugin has a "Payment Method" setting that looks intended for it,
   but nothing has ever passed it. Should it be sent, and what does it change?
7. **What are `clientKey` and `clientSecret` for?** The bundle destructures
   both, alongside `secretKey`. If they are an alternative credential pair --
   particularly one that can be exchanged server side for a short-lived
   browser token -- that may be the answer to the whole problem above. Please
   confirm what they do and whether they are usable today.
8. **Card data and PCI scope.** Routing `cardNo`/`cvv` through the merchant's
   WordPress server puts every store in PCI DSS scope (SAQ-A to SAQ-D). This
   is unavoidable while `secureHash` covers the card fields. Can you offer
   hosted card capture or tokenisation, so card data goes browser-to-Jio and
   never touches the merchant server? NB and UPI QR carry no cardholder data
   and are unaffected.

## Reference

Signing recipe, verified byte-identical to your Postman pre-request script and
to the SDK's `Cv()`: drop `secureHash`, sort keys alphabetically, concatenate
the values only, hex HMAC-SHA256 keyed by the secret. Implemented in
`includes/class-jio-pay-service.php`.
