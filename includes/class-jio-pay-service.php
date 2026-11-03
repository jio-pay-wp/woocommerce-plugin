<?php
/**
 * Jio Pay API service.
 *
 * Every request to Jio is made from here, server side. The merchant id,
 * secret key, aggregator id and MCC are read out of the WooCommerce settings
 * row in the database and never leave the server. The browser talks only to
 * the AJAX endpoints in class-jio-pay-gateway.php, and those call into this
 * class.
 *
 * Why this class exists: up to 1.3.5 the browser held the merchant secret,
 * because Jio's JS SDK computes secureHash itself with
 * HmacSHA256(sortedValues, secretKey). That published the live merchant
 * secret in checkout page source on every page load, which also made
 * secureHash worthless as a signature -- anyone could compute a valid one.
 * This class takes the signing job back onto the server.
 *
 * @package WooJioPay
 * @since   1.4.0
 */

if (!defined('ABSPATH')) exit;

class WC_Jio_Pay_Service
{
    /**
     * ISO 4217 numeric code for INR, used by initiateSale.
     */
    const CURRENCY_CODE = '356';

    /**
     * Currency value generateQR expects.
     *
     * Jio's integration note uses 365 for generateQR while initiateSale uses
     * 356. 365 is not INR in ISO 4217, so this looks like a slip on their
     * side -- but secureHash is computed over the values we actually send, so
     * this has to match what their validator expects or the hash is rejected.
     * Kept as its own constant, and filterable, so it can be corrected
     * without disturbing initiateSale.
     */
    const QR_CURRENCY_CODE = '365';

    /**
     * Jio timestamps are Indian local time, not site time and not UTC.
     */
    const API_TIMEZONE = 'Asia/Kolkata';

    /** Seconds to wait on a Jio HTTP call. */
    const HTTP_TIMEOUT = 30;

    private $merchant_id;
    private $secret_key;
    private $aggregator_id;
    private $mcc_code;
    private $environment;
    private $log_file;

    /**
     * @param string $merchant_id
     * @param string $secret_key
     * @param string $aggregator_id
     * @param string $mcc_code
     * @param string $environment 'prod' or 'uat'.
     */
    public function __construct($merchant_id, $secret_key, $aggregator_id = '', $mcc_code = '', $environment = 'uat')
    {
        $this->merchant_id   = (string) $merchant_id;
        $this->secret_key    = (string) $secret_key;
        $this->aggregator_id = (string) $aggregator_id;
        $this->mcc_code      = (string) $mcc_code;
        $this->environment   = $environment === 'prod' ? 'prod' : 'uat';
        $this->log_file      = JIO_PAY_PLUGIN_DIR . 'logs/api.log';
    }

    /**
     * Build the service from the gateway settings stored in the database.
     *
     * This is the only place credentials are sourced. Nothing in the request
     * can influence which merchant id or secret is used.
     *
     * @return self
     */
    public static function from_settings()
    {
        $options     = get_option('woocommerce_jio_pay_settings', []);
        $environment = ($options['environment'] ?? 'uat') === 'prod' ? 'prod' : 'uat';
        $prefix      = $environment === 'prod' ? 'live_' : 'uat_';

        return new self(
            $options[$prefix . 'merchant_id'] ?? '',
            $options[$prefix . 'secret_key'] ?? '',
            $options[$prefix . 'agregator_id'] ?? '',
            $options[$prefix . 'mcc_code'] ?? '',
            $environment
        );
    }

    /**
     * True when the service has enough configuration to talk to Jio.
     *
     * @return bool
     */
    public function is_configured()
    {
        return $this->merchant_id !== '' && $this->secret_key !== '';
    }

    public function get_environment()
    {
        return $this->environment;
    }

    public function get_merchant_id()
    {
        return $this->merchant_id;
    }

    /* ---------------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------------- */

    /**
     * Resolve a Jio endpoint URL for the current environment.
     *
     * Note the path inconsistency in Jio's own documentation: UAT carries a
     * /tsp/ prefix throughout, production drops it for initiateSale and
     * generateQR but keeps it for /command (which is what the refund handler
     * has been calling successfully in production since 1.1). The /command
     * production URL is therefore left as the refund handler has it rather
     * than "corrected" on a guess.
     *
     * @param string $which 'initiateSale', 'generateQR' or 'command'.
     * @return string
     */
    public function endpoint($which)
    {
        $prod = [
            'initiateSale' => 'https://jiopay.co.in/pg/api/v2/initiateSale',
            'generateQR'   => 'https://jiopay.co.in/pg/api/generateQR',
            'command'      => 'https://jiopay.co.in/tsp/pg/api/command',
        ];

        $uat = [
            'initiateSale' => 'https://uat.jiopay.co.in/tsp/pg/api/v2/initiateSale',
            'generateQR'   => 'https://uat.jiopay.co.in/tsp/pg/api/generateQR',
            'command'      => 'https://uat.jiopay.co.in/tsp/pg/api/command',
        ];

        $map = $this->environment === 'prod' ? $prod : $uat;
        $url = $map[$which] ?? '';

        /**
         * Filter a resolved Jio endpoint, for stores Jio has pointed at a
         * different host during certification.
         */
        return apply_filters('jio_pay_api_endpoint', $url, $which, $this->environment);
    }

    /* ---------------------------------------------------------------------
     * Signing
     * ------------------------------------------------------------------- */

    /**
     * Compute secureHash for a payload.
     *
     * The recipe, which must match Jio's validator byte for byte: drop any
     * existing secureHash, sort the remaining keys alphabetically, append the
     * values (values only, no keys, no separators) in that order, then take
     * the hex HMAC-SHA256 of that string keyed by the merchant secret.
     *
     * @param array $payload
     * @return string Lower-case hex digest.
     */
    public function generate_secure_hash(array $payload)
    {
        unset($payload['secureHash']);
        ksort($payload);

        $value_string = '';
        foreach ($payload as $value) {
            $value_string .= (string) $value;
        }

        return hash_hmac('sha256', $value_string, $this->secret_key);
    }

    /**
     * Add secureHash to a payload and return it re-sorted.
     *
     * @param array $payload
     * @return array
     */
    public function sign(array $payload)
    {
        $payload['secureHash'] = $this->generate_secure_hash($payload);
        ksort($payload);

        return $payload;
    }

    /* ---------------------------------------------------------------------
     * Payload builders, per payment mode
     * ------------------------------------------------------------------- */

    /**
     * The payment modes this service can build a payload for.
     *
     * Keyed by the code the checkout sends us. 'requires' lists the
     * caller-supplied fields that must be present and non-empty.
     *
     * UPI_VPA and UPI_INTENT are deliberately absent: Jio's integration note
     * documents NB, CARD, generateQR and STATUS only. Guessing their field
     * names would produce a payload that hashes to something their validator
     * rejects, so they are added once Jio supplies the contract.
     *
     * @return array
     */
    public static function sale_modes()
    {
        return [
            'NB' => [
                'paymentMode' => 'NB',
                'payType'     => '0',
                'extra'       => [],
                'requires'    => [],
            ],
            'CC' => [
                'paymentMode'        => 'CARD',
                'payType'            => '1',
                'paymentOptionCodes' => 'CC',
                'extra'              => ['cardNo', 'cvv', 'cardExpiry', 'nameOnCard', 'customerName'],
                'requires'           => ['cardNo', 'cvv', 'cardExpiry', 'nameOnCard'],
            ],
            'DC' => [
                'paymentMode'        => 'CARD',
                'payType'            => '1',
                'paymentOptionCodes' => 'DC',
                'extra'              => ['cardNo', 'cvv', 'cardExpiry', 'nameOnCard', 'customerName'],
                'requires'           => ['cardNo', 'cvv', 'cardExpiry', 'nameOnCard'],
            ],
        ];
    }

    /**
     * Map the payment-type codes the popup displays to an internal route.
     *
     * The admin screen and the SDK both speak in CREDIT_CARD / DEBIT_CARD /
     * UPI_QR; initiateSale speaks in CC / DC and QR is a different endpoint
     * altogether. The SDK sends back whichever code it displayed, so the
     * translation belongs here rather than in the SDK team's integration.
     *
     * @param string $code Code as sent by the popup.
     * @return array{route: string, mode: string}|WP_Error
     */
    public static function resolve_payment_type($code)
    {
        $map = [
            'NB'          => ['route' => 'sale', 'mode' => 'NB'],
            'NETBANKING'  => ['route' => 'sale', 'mode' => 'NB'],
            'CREDIT_CARD' => ['route' => 'sale', 'mode' => 'CC'],
            'CC'          => ['route' => 'sale', 'mode' => 'CC'],
            'DEBIT_CARD'  => ['route' => 'sale', 'mode' => 'DC'],
            'DC'          => ['route' => 'sale', 'mode' => 'DC'],
            'UPI_QR'      => ['route' => 'qr',   'mode' => 'UPI_QR'],
            'UPIQR'       => ['route' => 'qr',   'mode' => 'UPI_QR'],
        ];

        $code = strtoupper(trim((string) $code));

        if (isset($map[$code])) {
            return $map[$code];
        }

        // UPI_VPA and UPI_INTENT land here on purpose: Jio has not documented
        // their initiateSale fields, and a guessed payload would hash to
        // something their validator rejects. Fail loudly instead.
        return new WP_Error(
            'jio_pay_unsupported_payment_type',
            sprintf('Payment type "%s" is not supported server side yet.', $code)
        );
    }

    /**
     * The payment-type codes this plugin can actually process.
     *
     * Used to filter the configured list before the popup renders it, so a
     * shopper is never offered a method that will fail at initiate.
     *
     * @return string[]
     */
    public static function supported_payment_types()
    {
        return ['NB', 'UPI_QR', 'CREDIT_CARD', 'DEBIT_CARD'];
    }

    /**
     * Build a signed initiateSale payload.
     *
     * Amount, merchantTxnNo and returnURL are supplied by the caller because
     * the caller is server side and derives them from the order. They are
     * never taken from the browser.
     *
     * @param string $mode One of the keys of sale_modes().
     * @param array  $args amount, email, merchant_txn_no, return_url, plus
     *               mode-specific card fields.
     * @return array|WP_Error Signed payload.
     */
    public function build_sale_payload($mode, array $args)
    {
        $modes = self::sale_modes();

        if (!isset($modes[$mode])) {
            return new WP_Error(
                'jio_pay_unsupported_mode',
                sprintf('Payment mode "%s" is not supported by this plugin.', $mode)
            );
        }

        $spec = $modes[$mode];

        foreach ($spec['requires'] as $field) {
            if (empty($args[$field])) {
                return new WP_Error(
                    'jio_pay_missing_field',
                    sprintf('Payment mode %s requires %s.', $mode, $field)
                );
            }
        }

        $amount = $this->format_amount($args['amount'] ?? null);
        if (is_wp_error($amount)) {
            return $amount;
        }

        $payload = [
            'amount'          => $amount,
            'currencyCode'    => self::CURRENCY_CODE,
            'customerEmailID' => (string) ($args['email'] ?? ''),
            'merchantId'      => $this->merchant_id,
            'aggregatorID'    => $this->aggregator_id,
            'merchantTxnNo'   => (string) ($args['merchant_txn_no'] ?? ''),
            'paymentMode'     => $spec['paymentMode'],
            'payType'         => $spec['payType'],
            'returnURL'       => (string) ($args['return_url'] ?? ''),
            'transactionType' => 'SALE',
            'txnDate'         => $this->txn_date(),
        ];

        if (!empty($spec['paymentOptionCodes'])) {
            $payload['paymentOptionCodes'] = $spec['paymentOptionCodes'];
        }

        foreach ($spec['extra'] as $field) {
            if (isset($args[$field]) && $args[$field] !== '') {
                $payload[$field] = (string) $args[$field];
            }
        }

        /**
         * Filter the unsigned initiateSale payload. Anything added here is
         * covered by secureHash, since signing happens after this.
         */
        $payload = apply_filters('jio_pay_sale_payload', $payload, $mode, $args, $this->environment);

        return $this->sign($payload);
    }

    /* ---------------------------------------------------------------------
     * API calls
     * ------------------------------------------------------------------- */

    /**
     * Initiate a sale. JSON body, per Jio's v2 contract.
     *
     * @param string $mode
     * @param array  $args
     * @return array|WP_Error
     */
    public function initiate_sale($mode, array $args)
    {
        if (!$this->is_configured()) {
            return new WP_Error('jio_pay_not_configured', 'Jio Pay credentials are not configured.');
        }

        $payload = $this->build_sale_payload($mode, $args);
        if (is_wp_error($payload)) {
            return $payload;
        }

        $this->log('initiateSale request', ['mode' => $mode, 'payload' => $this->redact($payload)]);

        return $this->request($this->endpoint('initiateSale'), $payload, 'json', 'initiateSale');
    }

    /**
     * Generate a UPI QR. Form-encoded body, per Jio's contract.
     *
     * @param array $args amount, email, merchant_ref_no, invoice_no.
     * @return array|WP_Error
     */
    public function generate_qr(array $args)
    {
        if (!$this->is_configured()) {
            return new WP_Error('jio_pay_not_configured', 'Jio Pay credentials are not configured.');
        }

        $amount = $this->format_amount($args['amount'] ?? null);
        if (is_wp_error($amount)) {
            return $amount;
        }

        $reference = (string) ($args['merchant_ref_no'] ?? '');
        if ($reference === '') {
            return new WP_Error('jio_pay_missing_field', 'generateQR requires a merchant reference number.');
        }

        $payload = [
            'aggregatorID'  => $this->aggregator_id,
            'amount'        => $amount,
            'currency'      => apply_filters('jio_pay_qr_currency_code', self::QR_CURRENCY_CODE, $this->environment),
            'emailID'       => (string) ($args['email'] ?? ''),
            'invoiceDate'   => $this->invoice_date(),
            'invoiceNo'     => (string) ($args['invoice_no'] ?? $reference),
            'merchantId'    => $this->merchant_id,
            'merchantRefNo' => $reference,
            'requestType'   => 'UPIQR',
        ];

        $payload = apply_filters('jio_pay_qr_payload', $payload, $args, $this->environment);
        $payload = $this->sign($payload);

        $this->log('generateQR request', ['payload' => $this->redact($payload)]);

        return $this->request($this->endpoint('generateQR'), $payload, 'form', 'generateQR');
    }

    /**
     * Query the status of a transaction.
     *
     * STATUS is not signed. That matches how the refund handler has queried
     * production since 1.1, and Jio's own sample STATUS call carries no
     * secureHash. Pass $sign = true if Jio later requires one.
     *
     * @param string      $merchant_txn_no
     * @param string|null $original_txn_no Defaults to $merchant_txn_no.
     * @param bool        $sign
     * @return array|WP_Error
     */
    public function check_status($merchant_txn_no, $original_txn_no = null, $sign = false)
    {
        if (!$this->is_configured()) {
            return new WP_Error('jio_pay_not_configured', 'Jio Pay credentials are not configured.');
        }

        $merchant_txn_no = (string) $merchant_txn_no;
        if ($merchant_txn_no === '') {
            return new WP_Error('jio_pay_missing_field', 'A merchant transaction number is required.');
        }

        $payload = [
            'merchantId'      => $this->merchant_id,
            'merchantTxnNo'   => $merchant_txn_no,
            'originalTxnNo'   => (string) ($original_txn_no !== null && $original_txn_no !== '' ? $original_txn_no : $merchant_txn_no),
            'transactionType' => 'STATUS',
        ];

        if ($sign || apply_filters('jio_pay_sign_status_request', false, $this->environment)) {
            $payload = $this->sign($payload);
        }

        $this->log('STATUS request', ['payload' => $this->redact($payload)]);

        return $this->request($this->endpoint('command'), $payload, 'form', 'STATUS');
    }

    /* ---------------------------------------------------------------------
     * Transport
     * ------------------------------------------------------------------- */

    /**
     * POST to Jio and normalise the reply.
     *
     * Returns a predictable shape regardless of what comes back, so callers
     * never have to guess: success, http_code, data (parsed), raw (body).
     *
     * @param string $url
     * @param array  $payload
     * @param string $encoding 'json' or 'form'.
     * @param string $label    For logs.
     * @return array|WP_Error
     */
    private function request($url, array $payload, $encoding, $label)
    {
        if ($url === '') {
            return new WP_Error('jio_pay_no_endpoint', 'No Jio endpoint is configured for this call.');
        }

        if ($encoding === 'json') {
            $body         = wp_json_encode($payload);
            $content_type = 'application/json';
        } else {
            $body         = $this->build_form_body($payload);
            $content_type = 'application/x-www-form-urlencoded';
        }

        $response = wp_remote_post($url, [
            'method'    => 'POST',
            'headers'   => ['Content-Type' => $content_type],
            'body'      => $body,
            'timeout'   => self::HTTP_TIMEOUT,
            'sslverify' => true,
        ]);

        if (is_wp_error($response)) {
            $this->log($label . ' transport error', ['error' => $response->get_error_message()]);
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $raw  = (string) wp_remote_retrieve_body($response);
        $data = $this->parse_body($raw);

        $this->log($label . ' response', ['http' => $code, 'body' => $this->redact(is_array($data) ? $data : ['raw' => $raw])]);

        return [
            'success'   => $code >= 200 && $code < 300,
            'http_code' => $code,
            'data'      => $data,
            'raw'       => $raw,
        ];
    }

    /**
     * Build a form body preserving key order.
     *
     * Order matters for readability in Jio's logs when they debug a hash
     * mismatch with us; it does not affect the hash itself.
     *
     * @param array $payload
     * @return string
     */
    private function build_form_body(array $payload)
    {
        $pairs = [];
        foreach ($payload as $key => $value) {
            $pairs[] = urlencode($key) . '=' . urlencode((string) $value);
        }

        return implode('&', $pairs);
    }

    /**
     * Parse a Jio response body, which may be JSON or form-encoded.
     *
     * @param string $raw
     * @return array|null
     */
    private function parse_body($raw)
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        // Not JSON. If it looks like a query string, treat it as one.
        if (strpos($raw, '=') !== false && strpos($raw, '<') === false) {
            $parsed = [];
            parse_str($raw, $parsed);
            if (!empty($parsed)) {
                return $parsed;
            }
        }

        return null;
    }

    /* ---------------------------------------------------------------------
     * Helpers
     * ------------------------------------------------------------------- */

    /**
     * Normalise an amount to the "1.00" string Jio expects.
     *
     * @param mixed $amount
     * @return string|WP_Error
     */
    private function format_amount($amount)
    {
        if ($amount === null || $amount === '') {
            return new WP_Error('jio_pay_missing_field', 'An amount is required.');
        }

        if (!is_numeric($amount)) {
            return new WP_Error('jio_pay_bad_amount', 'The amount is not a number.');
        }

        $amount = round((float) $amount, 2);
        if ($amount <= 0) {
            return new WP_Error('jio_pay_bad_amount', 'The amount must be greater than zero.');
        }

        return number_format($amount, 2, '.', '');
    }

    /**
     * txnDate, as Jio wants it: YmdHis in Indian local time.
     *
     * @return string
     */
    private function txn_date()
    {
        return $this->api_now()->format('YmdHis');
    }

    /**
     * invoiceDate for generateQR: Ymd in Indian local time.
     *
     * @return string
     */
    private function invoice_date()
    {
        return $this->api_now()->format('Ymd');
    }

    /**
     * @return DateTime
     */
    private function api_now()
    {
        return new DateTime('now', new DateTimeZone(self::API_TIMEZONE));
    }

    /**
     * Generate a merchant transaction number.
     *
     * Ten digits, matching the shape in Jio's sample requests. Generated
     * server side so it can be trusted when a status query comes back.
     *
     * @return string
     */
    public static function generate_merchant_txn_no()
    {
        return (string) wp_rand(1000000000, 9999999999);
    }

    /**
     * Strip anything that must not reach a log file.
     *
     * Card numbers, CVVs and the secret itself are removed outright; the PAN
     * is reduced to its last four digits so a transaction stays traceable
     * without the log becoming cardholder data.
     *
     * @param array $data
     * @return array
     */
    private function redact(array $data)
    {
        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);

            if ($lower === 'cardno') {
                $digits      = preg_replace('/\D/', '', (string) $value);
                $data[$key]  = strlen($digits) > 4 ? '****' . substr($digits, -4) : '****';
                continue;
            }

            if (in_array($lower, ['cvv', 'secretkey', 'secret_key', 'securehash'], true)) {
                $data[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }

    /**
     * Append to the API log.
     *
     * @param string $message
     * @param array  $context
     */
    private function log($message, array $context = [])
    {
        if (!apply_filters('jio_pay_api_logging_enabled', true, $this->environment)) {
            return;
        }

        $dir = dirname($this->log_file);
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $line = sprintf(
            "[%s] [%s] %s %s\n",
            current_time('mysql'),
            $this->environment,
            $message,
            $context ? wp_json_encode($context) : ''
        );

        error_log($line, 3, $this->log_file);
    }
}
