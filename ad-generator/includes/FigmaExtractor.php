<?php
// ad-generator/includes/FigmaExtractor.php - Enhanced Figma extraction
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/Logger.php';

class FigmaExtractor {
    private string $figmaApiKey;
    private array $extractedData = [
        'colors' => [],
        'fonts' => [],
        'textStyles' => [],
        'effects' => [],
        'components' => [],
        'images' => []
    ];

    public function __construct() {
        $this->figmaApiKey = Config::get('figma_api_key', '');
    }

    /**
     * Extract comprehensive style guide from Figma file
     */
    public function extractFromUrl(string $figmaUrl): array {
        // Parse Figma URL
        $pattern = '/figma\.com\/(?:file|design)\/([a-zA-Z0-9]+)/';
        if (!preg_match($pattern, $figmaUrl, $matches)) {
            throw new Exception('Invalid Figma URL format');
        }

        $fileKey = $matches[1];

        if (empty($this->figmaApiKey)) {
            return $this->getTemplateStyleGuide();
        }

        // Step 1: Duplicate the file for access
        $duplicatedKey = $this->duplicateFile($fileKey);
        if (!$duplicatedKey) {
            $duplicatedKey = $fileKey; // Try direct access
        }

        try {
            // Step 2: Get file data
            $fileData = $this->getFileData($duplicatedKey);

            // Step 3: Get styles
            $styles = $this->getFileStyles($duplicatedKey);

            // Step 4: Extract everything
            $this->extractFromNode($fileData['document']);

            // Step 5: Process styles metadata
            $this->processStyles($styles);

            // Step 6: Get images/assets
            $this->extractImages($duplicatedKey);

            return $this->formatStyleGuide();

        } catch (Exception $e) {
            Logger::error('Figma extraction failed', [
                'error' => $e->getMessage(),
                'file_key' => $fileKey
            ]);
            throw $e;
        }
    }

    /**
     * Duplicate Figma file for access
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
                'title' => 'Ad Generator Extract - ' . date('Y-m-d H:i:s')
            ]),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['key'] ?? null;
        }

        return null;
    }

    /**
     * Get complete file data
     */
    private function getFileData(string $fileKey): array {
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
            throw new Exception('Failed to fetch Figma file');
        }

        return json_decode($response, true);
    }

    /**
     * Get file styles (text styles, color styles, etc)
     */
    private function getFileStyles(string $fileKey): array {
        $ch = curl_init("https://api.figma.com/v1/files/{$fileKey}/styles");

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

        if ($httpCode === 200) {
            return json_decode($response, true);
        }

        return ['meta' => ['styles' => []]];
    }

    /**
     * Recursively extract all design elements from nodes
     */
    private function extractFromNode($node, int $depth = 0): void {
        if ($depth > 50) return; // Prevent infinite recursion

        // Extract fills (colors)
        if (isset($node['fills']) && is_array($node['fills'])) {
            foreach ($node['fills'] as $fill) {
                if ($fill['type'] === 'SOLID' && isset($fill['color'])) {
                    $this->addColor($fill['color'], $node['name'] ?? 'Unknown');
                } elseif ($fill['type'] === 'GRADIENT_LINEAR' || $fill['type'] === 'GRADIENT_RADIAL') {
                    foreach ($fill['gradientStops'] ?? [] as $stop) {
                        if (isset($stop['color'])) {
                            $this->addColor($stop['color'], $node['name'] ?? 'Gradient');
                        }
                    }
                }
            }
        }

        // Extract strokes (border colors)
        if (isset($node['strokes']) && is_array($node['strokes'])) {
            foreach ($node['strokes'] as $stroke) {
                if ($stroke['type'] === 'SOLID' && isset($stroke['color'])) {
                    $this->addColor($stroke['color'], $node['name'] ?? 'Stroke');
                }
            }
        }

        // Extract text styles
        if ($node['type'] === 'TEXT' && isset($node['style'])) {
            $this->addTextStyle($node['style'], $node['characters'] ?? '');
        }

        // Extract effects (shadows, blurs, etc)
        if (isset($node['effects']) && is_array($node['effects'])) {
            foreach ($node['effects'] as $effect) {
                $this->addEffect($effect);
            }
        }

        // Extract components
        if ($node['type'] === 'COMPONENT' || $node['type'] === 'COMPONENT_SET') {
            $this->extractedData['components'][] = [
                'name' => $node['name'] ?? 'Component',
                'description' => $node['description'] ?? '',
                'type' => $node['type']
            ];
        }

        // Recurse through children
        if (isset($node['children']) && is_array($node['children'])) {
            foreach ($node['children'] as $child) {
                $this->extractFromNode($child, $depth + 1);
            }
        }
    }

    /**
     * Add color with context
     */
    private function addColor(array $color, string $context): void {
        $hex = $this->rgbToHex($color);
        $key = $hex . '_' . $context;

        if (!isset($this->extractedData['colors'][$key])) {
            $this->extractedData['colors'][$key] = [
                'hex' => $hex,
                'rgb' => [
                    'r' => round($color['r'] * 255),
                    'g' => round($color['g'] * 255),
                    'b' => round($color['b'] * 255)
                ],
                'context' => $context,
                'usage_count' => 1
            ];
        } else {
            $this->extractedData['colors'][$key]['usage_count']++;
        }
    }

    /**
     * Add text style
     */
    private function addTextStyle(array $style, string $sampleText): void {
        $key = ($style['fontFamily'] ?? 'Unknown') . '_' . ($style['fontSize'] ?? 0);

        if (!isset($this->extractedData['textStyles'][$key])) {
            $this->extractedData['textStyles'][$key] = [
                'fontFamily' => $style['fontFamily'] ?? 'Unknown',
                'fontSize' => $style['fontSize'] ?? 0,
                'fontWeight' => $style['fontWeight'] ?? 400,
                'lineHeight' => $style['lineHeightPx'] ?? null,
                'letterSpacing' => $style['letterSpacing'] ?? 0,
                'textCase' => $style['textCase'] ?? 'ORIGINAL',
                'sampleText' => substr($sampleText, 0, 50),
                'usage_count' => 1
            ];
        } else {
            $this->extractedData['textStyles'][$key]['usage_count']++;
        }

        // Track font families
        $fontFamily = $style['fontFamily'] ?? 'Unknown';
        if (!in_array($fontFamily, $this->extractedData['fonts'])) {
            $this->extractedData['fonts'][] = $fontFamily;
        }
    }

    /**
     * Add effect (shadow, blur, etc)
     */
    private function addEffect(array $effect): void {
        $this->extractedData['effects'][] = [
            'type' => $effect['type'] ?? 'Unknown',
            'visible' => $effect['visible'] ?? true,
            'radius' => $effect['radius'] ?? 0,
            'color' => isset($effect['color']) ? $this->rgbToHex($effect['color']) : null
        ];
    }

    /**
     * Convert RGB to hex
     */
    private function rgbToHex(array $color): string {
        return sprintf('#%02x%02x%02x',
            round(($color['r'] ?? 0) * 255),
            round(($color['g'] ?? 0) * 255),
            round(($color['b'] ?? 0) * 255)
        );
    }

    /**
     * Process styles metadata
     */
    private function processStyles(array $styles): void {
        foreach ($styles['meta']['styles'] ?? [] as $style) {
            // Additional style processing if needed
        }
    }

    /**
     * Extract images/assets
     */
    private function extractImages(string $fileKey): void {
        // Get image URLs for any assets
        $ch = curl_init("https://api.figma.com/v1/files/{$fileKey}/images");

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

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $this->extractedData['images'] = $data['meta']['images'] ?? [];
        }
    }

    /**
     * Format extracted data into comprehensive style guide
     */
    private function formatStyleGuide(): array {
        // Sort colors by usage
        uasort($this->extractedData['colors'], function($a, $b) {
            return $b['usage_count'] - $a['usage_count'];
        });

        // Get top colors
        $colorArray = array_values($this->extractedData['colors']);
        $primaryColors = array_slice($colorArray, 0, 10);

        // Determine primary and secondary colors
        $primaryColor = $primaryColors[0]['hex'] ?? '#2196F3';
        $secondaryColor = $primaryColors[1]['hex'] ?? '#FF9800';

        // Find background and text colors
        $backgroundColor = '#FFFFFF';
        $textColor = '#000000';

        foreach ($colorArray as $color) {
            $brightness = $this->getColorBrightness($color['rgb']);
            if ($brightness > 240 && $backgroundColor === '#FFFFFF') {
                $backgroundColor = $color['hex'];
            } elseif ($brightness < 30 && $textColor === '#000000') {
                $textColor = $color['hex'];
            }
        }

        // Get font hierarchy
        $textStyles = array_values($this->extractedData['textStyles']);
        usort($textStyles, function($a, $b) {
            return $b['fontSize'] - $a['fontSize'];
        });

        return [
            // Core colors
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'background_color' => $backgroundColor,
            'text_color' => $textColor,

            // All colors with context
            'all_colors' => array_map(function($color) {
                return [
                    'hex' => $color['hex'],
                    'context' => $color['context'],
                    'usage' => $color['usage_count']
                ];
            }, array_slice($colorArray, 0, 20)),

            // Typography
            'fonts' => $this->extractedData['fonts'],
            'primary_font' => $this->extractedData['fonts'][0] ?? 'Inter',
            'text_styles' => array_slice($textStyles, 0, 10),

            // Effects
            'has_shadows' => !empty(array_filter($this->extractedData['effects'],
                fn($e) => $e['type'] === 'DROP_SHADOW')),
            'shadow_style' => $this->extractedData['effects'][0] ?? null,

            // Components
            'components' => $this->extractedData['components'],

            // Metadata
            'extracted_from' => 'figma',
            'extraction_date' => date('Y-m-d H:i:s'),
            'total_colors_found' => count($this->extractedData['colors']),
            'total_fonts_found' => count($this->extractedData['fonts']),
            'total_text_styles' => count($this->extractedData['textStyles'])
        ];
    }

    /**
     * Get color brightness (0-255)
     */
    private function getColorBrightness(array $rgb): int {
        return intval(($rgb['r'] * 299 + $rgb['g'] * 587 + $rgb['b'] * 114) / 1000);
    }

    /**
     * Get template style guide when no API key
     */
    private function getTemplateStyleGuide(): array {
        // Provide sample colors for testing when no API key is configured
        $sampleColors = [
            '#2196F3', '#1976D2', '#1565C0', '#0D47A1', // Blues
            '#FF9800', '#FB8C00', '#F57C00', '#EF6C00', // Oranges
            '#4CAF50', '#43A047', '#388E3C', '#2E7D32', // Greens
            '#F44336', '#E53935', '#D32F2F', '#C62828', // Reds
            '#9C27B0', '#8E24AA', '#7B1FA2', '#6A1B9A', // Purples
            '#00BCD4', '#00ACC1', '#0097A7', '#00838F', // Cyans
            '#FFC107', '#FFB300', '#FFA000', '#FF8F00', // Ambers
            '#607D8B', '#546E7A', '#455A64', '#37474F', // Blue Grays
            '#795548', '#6D4C41', '#5D4037', '#4E342E', // Browns
            '#212121', '#424242', '#616161', '#757575', // Grays
            '#FFFFFF', '#FAFAFA', '#F5F5F5', '#EEEEEE'  // Light Grays
        ];

        return [
            'primary_color' => '#2196F3',
            'secondary_color' => '#FF9800',
            'accent_color' => '#4CAF50',
            'background_color' => '#FFFFFF',
            'text_color' => '#212121',
            'colors' => $sampleColors, // For color selection
            'all_colors' => array_map(function($color) {
                return [
                    'hex' => $color,
                    'context' => 'Sample Color',
                    'usage' => rand(1, 10)
                ];
            }, $sampleColors),
            'fonts' => ['Inter', 'Roboto', 'Open Sans', 'Lato', 'Montserrat'],
            'primary_font' => 'Inter',
            'text_styles' => [
                ['name' => 'Heading 1', 'size' => 48, 'weight' => 'bold'],
                ['name' => 'Heading 2', 'size' => 32, 'weight' => 'bold'],
                ['name' => 'Body', 'size' => 16, 'weight' => 'normal']
            ],
            'has_shadows' => true,
            'components' => [],
            'extracted_from' => 'template',
            'extraction_date' => date('Y-m-d H:i:s'),
            'note' => 'Sample colors provided - Configure Figma API key for actual extraction'
        ];
    }
}