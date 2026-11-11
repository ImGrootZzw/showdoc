<?php

namespace App\Common\Helper;

/**
 * JWT / 签名相关工具
 *
 * 用于 OnlyOffice 文档服务器集成：
 * - OnlyOffice 要求把编辑器配置整体签名成 JWT（HS256），防止前端篡改
 * - 文档下载 URL 与回调 URL 需要能脱离登录态使用（文档服务器无法携带用户 Cookie），
 *   因此用 HMAC 签名一个短时效令牌
 *
 * 说明：项目没有引入 JWT 依赖库，这里按 RFC 7519 实现最小可用的 HS256。
 */
class JwtHelper
{
    /**
     * base64url 编码（无填充，+ / 替换为 - _）
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * base64url 解码
     */
    public static function base64UrlDecode(string $data): string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode(strtr($data, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * 生成 HS256 JWT
     *
     * @param array $payload 载荷
     * @param string $secret 密钥
     * @return string
     */
    public static function encode(array $payload, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $segments = [
            self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];

        $signingInput = implode('.', $segments);
        $signature = hash_hmac('sha256', $signingInput, $secret, true);
        $segments[] = self::base64UrlEncode($signature);

        return implode('.', $segments);
    }

    /**
     * 校验并解析 HS256 JWT
     *
     * @param string $token
     * @param string $secret
     * @return array|null 校验失败返回 null
     */
    public static function decode(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $expected = self::base64UrlEncode(
            hash_hmac('sha256', $headerB64 . '.' . $payloadB64, $secret, true)
        );

        // 恒定时间比较，避免时序攻击
        if (!hash_equals($expected, $signatureB64)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($payloadB64), true);
        return is_array($payload) ? $payload : null;
    }

    /**
     * 生成带时效的访问令牌（用于文档下载 / 保存回调）
     *
     * @param array $data 业务数据（如 page_id）
     * @param string $secret 密钥
     * @param int $ttl 有效期（秒）
     * @return string
     */
    public static function signPayload(array $data, string $secret, int $ttl = 86400): string
    {
        $data['exp'] = time() + $ttl;
        $payloadB64 = self::base64UrlEncode(json_encode($data, JSON_UNESCAPED_UNICODE));
        $signature = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
        return $payloadB64 . '.' . $signature;
    }

    /**
     * 校验访问令牌
     *
     * @param string $token
     * @param string $secret
     * @return array|null 校验失败或过期返回 null
     */
    public static function verifyPayload(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadB64, $signatureB64] = $parts;

        $expected = self::base64UrlEncode(hash_hmac('sha256', $payloadB64, $secret, true));
        if (!hash_equals($expected, $signatureB64)) {
            return null;
        }

        $data = json_decode(self::base64UrlDecode($payloadB64), true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            return null;
        }

        return $data;
    }
}
