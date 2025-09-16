<?php
// includes/CDNService.php - CloudFlare CDN integration for fast image delivery
declare(strict_types=1);

class CDNService {
    private static ?string $cdnDomain = null;
    private static bool $cdnEnabled = false; // Disabled until CDN is properly configured

    public static function init(): void {
        // Check if CDN is configured
        self::$cdnDomain = Config::get('cdn_domain', 'cdn.picfit.ai');
        self::$cdnEnabled = (bool) Config::get('cdn_enabled', true);
    }

    /**
     * Get CDN URL for a generated image
     */
    public static function getImageUrl(string $imagePath): string {
        self::init();

        // If CDN is disabled, return direct URL
        if (!self::$cdnEnabled) {
            return $imagePath;
        }

        // If already a full URL, don't modify
        if (str_starts_with($imagePath, 'http')) {
            return $imagePath;
        }

        // For generated images, use CDN
        if (str_contains($imagePath, '/generated/')) {
            return 'https://' . self::$cdnDomain . $imagePath;
        }

        // For other images, return as-is
        return $imagePath;
    }

    /**
     * Purge image from CloudFlare cache
     */
    public static function purgeImage(string $imagePath): bool {
        $cfZoneId = Config::get('cloudflare_zone_id', '');
        $cfApiKey = Config::get('cloudflare_api_key', '');

        if (empty($cfZoneId) || empty($cfApiKey)) {
            return false;
        }

        $url = 'https://' . self::$cdnDomain . $imagePath;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => "https://api.cloudflare.com/client/v4/zones/{$cfZoneId}/purge_cache",
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode(['files' => [$url]]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $cfApiKey,
                'Content-Type: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Add cache headers for optimal CDN performance
     */
    public static function setCacheHeaders(int $maxAge = 31536000): void {
        // 1 year cache for generated images (they never change)
        header("Cache-Control: public, max-age={$maxAge}, immutable");
        header('Expires: ' . gmdate('D, d M Y H:i:s', time() + $maxAge) . ' GMT');
        header('X-Content-Type-Options: nosniff');

        // Add CloudFlare specific headers
        header('CF-Cache-Status: HIT');
        header('CF-Cache-Tag: generated-image');
    }

    /**
     * Get optimized image URL based on device/context
     */
    public static function getOptimizedUrl(string $imagePath, array $options = []): string {
        $baseUrl = self::getImageUrl($imagePath);

        // If CloudFlare Image Resizing is enabled
        if (Config::get('cf_image_resizing', false)) {
            $params = [];

            if (isset($options['width'])) {
                $params[] = "width={$options['width']}";
            }

            if (isset($options['quality'])) {
                $params[] = "quality={$options['quality']}";
            }

            if (isset($options['format'])) {
                $params[] = "format={$options['format']}";
            }

            if (!empty($params)) {
                // CloudFlare Image Resizing URL format
                return str_replace('cdn.picfit.ai', 'cdn.picfit.ai/cdn-cgi/image/' . implode(',', $params), $baseUrl);
            }
        }

        return $baseUrl;
    }
}