<?php
// includes/FigmaService.php - Figma file parsing and style extraction
declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

class FigmaService {
    private string $figmaApiKey;

    public function __construct() {
        $this->figmaApiKey = Config::get('figma_api_key', '');
    }

    /**
     * Extract style guide from Figma file URL
     */
    public function extractStyleFromFigmaUrl(string $figmaUrl): array {
        // Parse Figma URL to get file key
        // Format: https://www.figma.com/file/FILE_KEY/FILE_NAME
        $pattern = '/figma\.com\/(?:file|design)\/([a-zA-Z0-9]+)/';
        if (!preg_match($pattern, $figmaUrl, $matches)) {
            throw new Exception('Invalid Figma URL format');
        }

        $fileKey = $matches[1];

        // If we have API key, duplicate and extract
        if (!empty($this->figmaApiKey)) {
            return $this->duplicateAndExtract($fileKey);
        } else {
            return $this->extractFromViewLink($figmaUrl);
        }
    }

    /**
     * Duplicate Figma file to our account then extract
     */
    private function duplicateAndExtract(string $originalFileKey): array {
        // Step 1: Duplicate the file
        $duplicatedFileKey = $this->duplicateFile($originalFileKey);

        if (!$duplicatedFileKey) {
            // If duplication fails, try direct access (might be our own file)
            return $this->extractViaApi($originalFileKey);
        }

        try {
            // Step 2: Extract from our duplicate
            $styleGuide = $this->extractViaApi($duplicatedFileKey);

            // Step 3: Clean up - delete the duplicate
            // Note: Figma API doesn't have delete endpoint, so files accumulate
            // Could implement a cleanup job later

            return $styleGuide;
        } catch (Exception $e) {
            Logger::error('Failed to extract from duplicated Figma file', [
                'original_key' => $originalFileKey,
                'duplicate_key' => $duplicatedFileKey,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Duplicate a Figma file to our account
     */
    private function duplicateFile(string $fileKey): ?string {
        $ch = curl_init("https://api.figma.com/v1/files/{$fileKey}/duplicate");

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Figma-Token: ' . $this->figmaApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'title' => 'Ad Generator Copy - ' . date('Y-m-d H:i:s')
            ]),
            CURLOPT_TIMEOUT => 60 // Duplication can take time
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            if (isset($data['key'])) {
                Logger::info('Successfully duplicated Figma file', [
                    'original' => $fileKey,
                    'duplicate' => $data['key']
                ]);
                return $data['key'];
            }
        } elseif ($httpCode === 403) {
            // File is private or we don't have access
            Logger::warning('Cannot duplicate private Figma file', ['file_key' => $fileKey]);
            return null;
        }

        Logger::warning('Failed to duplicate Figma file', [
            'file_key' => $fileKey,
            'http_code' => $httpCode,
            'response' => $response
        ]);
        return null;
    }

    /**
     * Extract styles using Figma API
     */
    private function extractViaApi(string $fileKey): array {
        $ch = curl_init("https://api.figma.com/v1/files/{$fileKey}");

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Figma-Token: ' . $this->figmaApiKey
            ],
            CURLOPT_TIMEOUT => 30
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::warning('Figma API call failed', ['http_code' => $httpCode]);
            throw new Exception('Failed to fetch Figma file');
        }

        $data = json_decode($response, true);
        return $this->parseFigmaData($data);
    }

    /**
     * Parse Figma API response to extract style guide
     */
    private function parseFigmaData(array $data): array {
        $styleGuide = [
            'colors' => [],
            'fonts' => [],
            'spacing' => [],
            'components' => []
        ];

        // Extract document colors
        if (isset($data['document']['children'])) {
            foreach ($data['document']['children'] as $page) {
                $this->extractColors($page, $styleGuide['colors']);
                $this->extractTypography($page, $styleGuide['fonts']);
            }
        }

        // Get unique values
        $styleGuide['colors'] = array_unique($styleGuide['colors']);
        $styleGuide['fonts'] = array_unique($styleGuide['fonts']);

        // Convert to our format
        return $this->convertToStyleGuideFormat($styleGuide);
    }

    /**
     * Extract colors from Figma node
     */
    private function extractColors($node, array &$colors): void {
        // Extract fills
        if (isset($node['fills'])) {
            foreach ($node['fills'] as $fill) {
                if ($fill['type'] === 'SOLID' && isset($fill['color'])) {
                    $color = $fill['color'];
                    $hex = sprintf('#%02x%02x%02x',
                        round($color['r'] * 255),
                        round($color['g'] * 255),
                        round($color['b'] * 255)
                    );
                    $colors[] = $hex;
                }
            }
        }

        // Extract strokes
        if (isset($node['strokes'])) {
            foreach ($node['strokes'] as $stroke) {
                if ($stroke['type'] === 'SOLID' && isset($stroke['color'])) {
                    $color = $stroke['color'];
                    $hex = sprintf('#%02x%02x%02x',
                        round($color['r'] * 255),
                        round($color['g'] * 255),
                        round($color['b'] * 255)
                    );
                    $colors[] = $hex;
                }
            }
        }

        // Recurse through children
        if (isset($node['children'])) {
            foreach ($node['children'] as $child) {
                $this->extractColors($child, $colors);
            }
        }
    }

    /**
     * Extract typography from Figma node
     */
    private function extractTypography($node, array &$fonts): void {
        if (isset($node['style']) && isset($node['style']['fontFamily'])) {
            $fonts[] = $node['style']['fontFamily'];
        }

        // Recurse through children
        if (isset($node['children'])) {
            foreach ($node['children'] as $child) {
                $this->extractTypography($child, $fonts);
            }
        }
    }

    /**
     * Extract from view-only link (fallback method)
     */
    private function extractFromViewLink(string $figmaUrl): array {
        // For view-only links, we'll return a basic template
        // that the user can customize
        return [
            'primary_color' => '#2196F3',
            'secondary_color' => '#FF9800',
            'accent_color' => '#4CAF50',
            'background_color' => '#FFFFFF',
            'text_color' => '#212121',
            'font_family' => 'Roboto, sans-serif',
            'visual_style' => 'modern',
            'extracted_from' => 'figma_template',
            'note' => 'Basic style guide - customize based on your Figma design',
            // Include sample arrays for display
            'colors' => ['#2196F3', '#FF9800', '#4CAF50', '#FFFFFF', '#212121'],
            'fonts' => ['Roboto', 'Inter', 'Open Sans']
        ];
    }

    /**
     * Convert extracted data to our style guide format
     */
    private function convertToStyleGuideFormat(array $rawData): array {
        $guide = [
            'primary_color' => $rawData['colors'][0] ?? '#2196F3',
            'secondary_color' => $rawData['colors'][1] ?? '#FF9800',
            'accent_color' => $rawData['colors'][2] ?? '#4CAF50',
            'background_color' => '#FFFFFF',
            'text_color' => '#212121'
        ];

        // Find light/dark colors
        foreach ($rawData['colors'] as $color) {
            $rgb = sscanf($color, "#%02x%02x%02x");
            $brightness = ($rgb[0] * 299 + $rgb[1] * 587 + $rgb[2] * 114) / 1000;

            if ($brightness > 200 && $guide['background_color'] === '#FFFFFF') {
                $guide['background_color'] = $color;
            } elseif ($brightness < 50 && $guide['text_color'] === '#212121') {
                $guide['text_color'] = $color;
            }
        }

        // Add fonts
        if (!empty($rawData['fonts'])) {
            $guide['font_family'] = implode(', ', array_slice($rawData['fonts'], 0, 2)) . ', sans-serif';
        } else {
            $guide['font_family'] = 'Roboto, sans-serif';
        }

        $guide['visual_style'] = 'modern';
        $guide['extracted_from'] = 'figma';

        // Include raw data for display purposes
        $guide['colors'] = $rawData['colors'];
        $guide['fonts'] = $rawData['fonts'];

        return $guide;
    }
}