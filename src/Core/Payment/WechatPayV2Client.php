<?php

declare(strict_types=1);

namespace Qiling\Core\Payment;

use Qiling\Core\Config;

final class WechatPayV2Client
{
    private string $appId;
    private string $mchId;
    private string $apiKey;
    private string $unifiedorderUrl;
    private string $orderqueryUrl;
    private string $closeorderUrl;
    private string $refundUrl;
    private string $refundNotifyUrl;
    private string $certPath;
    private string $keyPath;
    private string $certPassphrase;
    private string $certContent;
    private string $keyContent;
    private bool $enabled;
    private bool $jsapiEnabled;
    private bool $h5Enabled;
    private ?string $tempCertPath = null;
    private ?string $tempKeyPath = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->appId = trim((string) ($config['wechat_app_id'] ?? Config::get('WECHAT_APP_ID', '')));
        $this->mchId = trim((string) ($config['wechat_mch_id'] ?? Config::get('WECHAT_MCH_ID', '')));
        $this->apiKey = trim((string) ($config['wechat_api_key'] ?? Config::get('WECHAT_API_KEY', '')));
        $this->unifiedorderUrl = trim((string) ($config['wechat_unifiedorder_url'] ?? Config::get('WECHAT_UNIFIEDORDER_URL', 'https://api.mch.weixin.qq.com/pay/unifiedorder')));
        $this->orderqueryUrl = trim((string) ($config['wechat_orderquery_url'] ?? Config::get('WECHAT_ORDERQUERY_URL', 'https://api.mch.weixin.qq.com/pay/orderquery')));
        $this->closeorderUrl = trim((string) ($config['wechat_closeorder_url'] ?? Config::get('WECHAT_CLOSEORDER_URL', 'https://api.mch.weixin.qq.com/pay/closeorder')));
        $this->refundUrl = trim((string) ($config['wechat_refund_url'] ?? Config::get('WECHAT_REFUND_URL', 'https://api.mch.weixin.qq.com/secapi/pay/refund')));
        $this->refundNotifyUrl = trim((string) ($config['wechat_refund_notify_url'] ?? Config::get('WECHAT_REFUND_NOTIFY_URL', '')));
        $this->certPath = trim((string) ($config['wechat_cert_path'] ?? Config::get('WECHAT_CERT_PATH', '')));
        $this->keyPath = trim((string) ($config['wechat_key_path'] ?? Config::get('WECHAT_KEY_PATH', '')));
        $this->certPassphrase = trim((string) ($config['wechat_cert_passphrase'] ?? Config::get('WECHAT_CERT_PASSPHRASE', '')));
        $this->certContent = $this->normalizePem((string) ($config['wechat_cert_content'] ?? ''));
        $this->keyContent = $this->normalizePem((string) ($config['wechat_key_content'] ?? ''));
        $this->enabled = $this->isTruthy((string) ($config['wechat_enabled'] ?? Config::get('WECHAT_ENABLED', 'false')));
        $this->jsapiEnabled = $this->isTruthy((string) ($config['wechat_jsapi_enabled'] ?? Config::get('WECHAT_JSAPI_ENABLED', 'true')));
        $this->h5Enabled = $this->isTruthy((string) ($config['wechat_h5_enabled'] ?? Config::get('WECHAT_H5_ENABLED', 'true')));
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function assertConfigured(): void
    {
        if ($this->appId === '' || $this->mchId === '' || $this->apiKey === '') {
            throw new \RuntimeException('微信支付配置不完整，请填写 AppID / 商户号 / 商户密钥');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function create(
        string $scene,
        string $outTradeNo,
        int $amountFen,
        string $subject,
        string $notifyUrl,
        string $clientIp,
        string $openid = ''
    ): array {
        $scene = strtolower(trim($scene));
        if (!in_array($scene, ['native', 'jsapi', 'h5'], true)) {
            $scene = 'native';
        }

        $params = [
            'appid' => $this->appId,
            'mch_id' => $this->mchId,
            'nonce_str' => $this->nonceStr(),
            'body' => $subject,
            'out_trade_no' => $outTradeNo,
            'total_fee' => $amountFen,
            'notify_url' => $notifyUrl,
            'spbill_create_ip' => $clientIp !== '' ? $clientIp : '127.0.0.1',
            'trade_type' => 'NATIVE',
        ];

        if ($scene === 'jsapi') {
            if (!$this->jsapiEnabled) {
                return [
                    'ok' => false,
                    'error' => '微信 JSAPI 支付已关闭',
                ];
            }
            if ($openid === '') {
                return [
                    'ok' => false,
                    'error' => 'JSAPI 支付必须传入 openid',
                ];
            }
            $params['trade_type'] = 'JSAPI';
            $params['openid'] = $openid;
        } elseif ($scene === 'h5') {
            if (!$this->h5Enabled) {
                return [
                    'ok' => false,
                    'error' => '微信 H5 支付已关闭',
                ];
            }
            $params['trade_type'] = 'MWEB';
            $params['scene_info'] = (string) json_encode([
                'h5_info' => [
                    'type' => 'Wap',
                    'wap_url' => (string) Config::get('APP_URL', ''),
                    'wap_name' => (string) Config::get('APP_NAME', 'Qiling'),
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
            $params['product_id'] = $outTradeNo;
        }

        $params['sign'] = $this->generateSign($params);
        $response = $this->postXml($this->unifiedorderUrl, $this->arrayToXml($params));
        $result = $this->xmlToArray((string) $response['body']);

        if (!is_array($result)) {
            return ['ok' => false, 'error' => 'wechat response parse failed', 'gateway_response' => $response];
        }

        $returnCode = (string) ($result['return_code'] ?? '');
        $resultCode = (string) ($result['result_code'] ?? '');
        if ($returnCode !== 'SUCCESS' || $resultCode !== 'SUCCESS') {
            return [
                'ok' => false,
                'error' => (string) ($result['err_code_des'] ?? $result['return_msg'] ?? 'wechat unifiedorder failed'),
                'gateway_response' => $result,
            ];
        }

        $payload = [
            'ok' => true,
            'scene' => $scene,
            'gateway_response' => $result,
            'prepay_id' => (string) ($result['prepay_id'] ?? ''),
        ];

        if ($scene === 'native') {
            $payload['qr_code'] = (string) ($result['code_url'] ?? '');
        } elseif ($scene === 'jsapi') {
            $payload['jsapi'] = $this->buildJsapiPayParams((string) ($result['prepay_id'] ?? ''));
        } elseif ($scene === 'h5') {
            $payload['pay_url'] = (string) ($result['mweb_url'] ?? '');
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(string $outTradeNo): array
    {
        $params = [
            'appid' => $this->appId,
            'mch_id' => $this->mchId,
            'nonce_str' => $this->nonceStr(),
            'out_trade_no' => $outTradeNo,
        ];
        $params['sign'] = $this->generateSign($params);

        $response = $this->postXml($this->orderqueryUrl, $this->arrayToXml($params));
        $result = $this->xmlToArray((string) $response['body']);
        if (!is_array($result)) {
            return ['ok' => false, 'error' => 'wechat query response parse failed', 'gateway_response' => $response];
        }

        $returnCode = (string) ($result['return_code'] ?? '');
        $resultCode = (string) ($result['result_code'] ?? '');
        if ($returnCode !== 'SUCCESS' || $resultCode !== 'SUCCESS') {
            return [
                'ok' => false,
                'error' => (string) ($result['err_code_des'] ?? $result['return_msg'] ?? 'wechat orderquery failed'),
                'gateway_response' => $result,
            ];
        }

        $tradeState = (string) ($result['trade_state'] ?? '');
        $totalFee = is_numeric($result['total_fee'] ?? null) ? (int) $result['total_fee'] : 0;

        return [
            'ok' => true,
            'trade_state' => $tradeState,
            'transaction_id' => (string) ($result['transaction_id'] ?? ''),
            'total_amount' => round($totalFee / 100, 2),
            'is_paid' => in_array($tradeState, ['SUCCESS', 'REFUND'], true),
            'is_closed' => in_array($tradeState, ['CLOSED', 'REVOKED', 'PAYERROR'], true),
            'gateway_response' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function close(string $outTradeNo): array
    {
        $params = [
            'appid' => $this->appId,
            'mch_id' => $this->mchId,
            'nonce_str' => $this->nonceStr(),
            'out_trade_no' => $outTradeNo,
        ];
        $params['sign'] = $this->generateSign($params);

        $response = $this->postXml($this->closeorderUrl, $this->arrayToXml($params));
        $result = $this->xmlToArray((string) $response['body']);
        if (!is_array($result)) {
            return ['ok' => false, 'error' => 'wechat close response parse failed', 'gateway_response' => $response];
        }

        $returnCode = (string) ($result['return_code'] ?? '');
        $resultCode = (string) ($result['result_code'] ?? '');
        if ($returnCode !== 'SUCCESS' || $resultCode !== 'SUCCESS') {
            return [
                'ok' => false,
                'error' => (string) ($result['err_code_des'] ?? $result['return_msg'] ?? 'wechat closeorder failed'),
                'gateway_response' => $result,
            ];
        }

        return [
            'ok' => true,
            'gateway_response' => $result,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function refund(string $outTradeNo, string $outRefundNo, int $totalFeeFen, int $refundFeeFen): array
    {
        if (!$this->hasClientCert()) {
            return [
                'ok' => false,
                'error' => '微信退款必须配置商户证书与私钥',
            ];
        }

        $params = [
            'appid' => $this->appId,
            'mch_id' => $this->mchId,
            'nonce_str' => $this->nonceStr(),
            'out_trade_no' => $outTradeNo,
            'out_refund_no' => $outRefundNo,
            'total_fee' => $totalFeeFen,
            'refund_fee' => $refundFeeFen,
            'notify_url' => $this->refundNotifyUrl,
        ];
        if ($params['notify_url'] === '') {
            unset($params['notify_url']);
        }
        $params['sign'] = $this->generateSign($params);

        $response = $this->postXml($this->refundUrl, $this->arrayToXml($params), 18, true);
        $result = $this->xmlToArray((string) $response['body']);
        if (!is_array($result)) {
            return ['ok' => false, 'error' => 'wechat refund response parse failed', 'gateway_response' => $response];
        }

        $returnCode = (string) ($result['return_code'] ?? '');
        $resultCode = (string) ($result['result_code'] ?? '');
        if ($returnCode !== 'SUCCESS' || $resultCode !== 'SUCCESS') {
            return [
                'ok' => false,
                'error' => (string) ($result['err_code_des'] ?? $result['return_msg'] ?? 'wechat refund failed'),
                'gateway_response' => $result,
            ];
        }

        return [
            'ok' => true,
            'refund_id' => (string) ($result['refund_id'] ?? ''),
            'gateway_response' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyNotify(array $payload): bool
    {
        if (!isset($payload['sign'])) {
            return false;
        }

        $sign = (string) $payload['sign'];
        unset($payload['sign']);

        return strtoupper($sign) === $this->generateSign($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function parseNotifyXml(string $xml): array
    {
        $data = $this->xmlToArray($xml);
        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function generateSign(array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $k => $v) {
            if ($k === 'sign' || is_array($v)) {
                continue;
            }
            $value = (string) $v;
            if ($value === '') {
                continue;
            }
            $pairs[] = $k . '=' . $value;
        }

        $pairs[] = 'key=' . $this->apiKey;
        return strtoupper(md5(implode('&', $pairs)));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function arrayToXml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $k => $v) {
            if (is_numeric($v)) {
                $xml .= '<' . $k . '>' . $v . '</' . $k . '>';
            } else {
                $xml .= '<' . $k . '><![CDATA[' . $v . ']]></' . $k . '>';
            }
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function xmlToArray(string $xml): ?array
    {
        if (trim($xml) === '') {
            return null;
        }

        libxml_use_internal_errors(true);
        $obj = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        if ($obj === false) {
            return null;
        }

        $decoded = json_decode((string) json_encode($obj, JSON_UNESCAPED_UNICODE), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildJsapiPayParams(string $prepayId): array
    {
        $params = [
            'appId' => $this->appId,
            'timeStamp' => (string) time(),
            'nonceStr' => $this->nonceStr(),
            'package' => 'prepay_id=' . $prepayId,
            'signType' => 'MD5',
        ];
        $params['paySign'] = $this->generateSign($params);

        return $params;
    }

    private function nonceStr(): string
    {
        return md5(uniqid((string) random_int(1000, 9999), true));
    }

    private function isTruthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    private function hasClientCert(): bool
    {
        if ($this->certPath !== '' && $this->keyPath !== '' && is_file($this->certPath) && is_file($this->keyPath)) {
            return true;
        }

        if ($this->certContent === '' || $this->keyContent === '') {
            return false;
        }

        return $this->materializeTempCertFiles();
    }

    /**
     * @return array<string, mixed>
     */
    private function postXml(string $url, string $xml, int $timeoutSeconds = 12, bool $withClientCert = false): array
    {
        $headerLines = [
            'Content-Type: text/xml; charset=utf-8',
            'Content-Length: ' . strlen($xml),
        ];

        $http = [
            'method' => 'POST',
            'header' => implode("\r\n", $headerLines),
            'content' => $xml,
            'timeout' => max(1, $timeoutSeconds),
            'ignore_errors' => true,
        ];
        $contextOptions = [
            'http' => $http,
        ];

        if ($withClientCert) {
            $ssl = [
                'local_cert' => $this->certPath,
                'local_pk' => $this->keyPath,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ];
            if ($this->certPassphrase !== '') {
                $ssl['passphrase'] = $this->certPassphrase;
            }
            $contextOptions['ssl'] = $ssl;
        }

        $context = stream_context_create([
            'http' => $contextOptions['http'],
            'ssl' => $contextOptions['ssl'] ?? [],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        $responseBody = is_string($responseBody) ? $responseBody : '';

        return [
            'status_code' => $this->parseStatusCode($http_response_header ?? []),
            'body' => $responseBody,
        ];
    }

    /**
     * @param array<int, string> $headers
     */
    private function parseStatusCode(array $headers): int
    {
        if (empty($headers)) {
            return 0;
        }

        $first = (string) ($headers[0] ?? '');
        if (preg_match('/\s(\d{3})\s?/', $first, $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private function materializeTempCertFiles(): bool
    {
        if ($this->tempCertPath !== null && $this->tempKeyPath !== null) {
            return is_file($this->tempCertPath) && is_file($this->tempKeyPath);
        }

        $tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'qiling_paycert';
        if (!is_dir($tmpDir) && !@mkdir($tmpDir, 0700, true) && !is_dir($tmpDir)) {
            return false;
        }

        $certFile = $tmpDir . DIRECTORY_SEPARATOR . 'wechat_cert_' . md5($this->mchId . $this->appId . 'cert') . '.pem';
        $keyFile = $tmpDir . DIRECTORY_SEPARATOR . 'wechat_key_' . md5($this->mchId . $this->appId . 'key') . '.pem';

        if (@file_put_contents($certFile, $this->certContent) === false) {
            return false;
        }
        if (@file_put_contents($keyFile, $this->keyContent) === false) {
            return false;
        }
        @chmod($certFile, 0600);
        @chmod($keyFile, 0600);

        $this->tempCertPath = $certFile;
        $this->tempKeyPath = $keyFile;
        $this->certPath = $certFile;
        $this->keyPath = $keyFile;

        return true;
    }

    private function normalizePem(string $text): string
    {
        $v = str_replace(["\r\n", "\r"], "\n", $text);
        $v = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $v);
        return trim($v);
    }
}
