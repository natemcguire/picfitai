<?php
// includes/DocumentParserService.php - Parse brand docs using ChatGPT
declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

class DocumentParserService {
    private string $openaiApiKey;

    public function __construct() {
        $this->openaiApiKey = Config::get('openai_api_key', '');
        if (empty($this->openaiApiKey)) {
            throw new Exception('OpenAI API key not configured');
        }
    }

    /**
     * Parse uploaded document to extract brand guidelines
     */
    public function parseDocument(string $filePath, string $mimeType): array {
        $content = $this->extractTextContent($filePath, $mimeType);

        if (empty($content)) {
            throw new Exception('Could not extract content from document');
        }

        // Use ChatGPT to analyze and extract brand guidelines
        return $this->analyzeWithChatGPT($content);
    }

    /**
     * Extract text content from various file types
     */
    private function extractTextContent(string $filePath, string $mimeType): string {
        switch ($mimeType) {
            case 'application/pdf':
                return $this->extractFromPDF($filePath);

            case 'text/plain':
                return file_get_contents($filePath);

            case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                return $this->extractFromDocx($filePath);

            case 'application/msword':
                return $this->extractFromDoc($filePath);

            case 'text/html':
                return strip_tags(file_get_contents($filePath));

            default:
                // Try to read as plain text
                $content = file_get_contents($filePath);
                // Remove non-printable characters
                return preg_replace('/[\x00-\x1F\x7F-\xFF]/', '', $content);
        }
    }

    /**
     * Extract text from PDF using simple PHP method
     */
    private function extractFromPDF(string $filePath): string {
        // Simple PDF text extraction (basic implementation)
        $content = file_get_contents($filePath);

        // Try to extract text between stream and endstream
        $pattern = '/stream(.*?)endstream/s';
        preg_match_all($pattern, $content, $matches);

        $text = '';
        foreach ($matches[1] as $match) {
            // Decode if needed
            $decoded = @gzuncompress($match);
            if ($decoded !== false) {
                $match = $decoded;
            }

            // Extract readable text
            $match = preg_replace('/[^[:print:][:space:]]/', '', $match);
            $text .= $match . ' ';
        }

        // Also try to find text in parentheses (common in PDFs)
        preg_match_all('/\((.*?)\)/', $content, $textMatches);
        foreach ($textMatches[1] as $match) {
            $text .= $match . ' ';
        }

        return trim($text);
    }

    /**
     * Extract text from DOCX files
     */
    private function extractFromDocx(string $filePath): string {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return '';
        }

        $content = '';

        // Read main document
        $xml = $zip->getFromName('word/document.xml');
        if ($xml !== false) {
            $doc = new DOMDocument();
            @$doc->loadXML($xml);
            $paragraphs = $doc->getElementsByTagName('t');

            foreach ($paragraphs as $p) {
                $content .= $p->nodeValue . ' ';
            }
        }

        $zip->close();
        return trim($content);
    }

    /**
     * Extract text from DOC files (fallback to basic extraction)
     */
    private function extractFromDoc(string $filePath): string {
        $content = file_get_contents($filePath);
        // Remove binary data and keep only readable text
        $content = preg_replace('/[^[:print:][:space:]]/', '', $content);
        return trim($content);
    }

    /**
     * Analyze document content with ChatGPT
     */
    private function analyzeWithChatGPT(string $content): array {
        // Truncate content if too long
        $maxLength = 8000;
        if (strlen($content) > $maxLength) {
            $content = substr($content, 0, $maxLength) . '...';
        }

        $prompt = "Analyze this brand/marketing document and extract key information for creating advertising materials.

Document content:
{$content}

Extract and return as JSON:
1. Brand colors (hex codes if mentioned, otherwise descriptive)
2. Target audience demographics and psychographics
3. Brand personality and tone of voice
4. Key messaging and value propositions
5. Product/service description
6. Visual style preferences (modern, classic, playful, professional, etc.)
7. Competitor differentiation
8. Call-to-action phrases
9. Any specific guidelines or restrictions

Return a JSON object with these keys:
- primary_color (hex or description)
- secondary_color (hex or description)
- target_audience
- brand_personality
- tone_of_voice
- key_messages (array)
- value_propositions (array)
- product_description
- visual_style
- headline_suggestions (array of 3-5 headlines)
- body_text_suggestions (array of 2-3 body texts)
- cta_text (call to action)
- restrictions (array of things to avoid)";

        $ch = curl_init('https://api.openai.com/v1/chat/completions');

        $payload = [
            'model' => 'gpt-4-turbo-preview',
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a brand strategist and marketing expert. Extract brand guidelines and create actionable insights for ad generation.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.3,
            'max_tokens' => 2000,
            'response_format' => ['type' => 'json_object']
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->openaiApiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error('ChatGPT API error', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            throw new Exception('Failed to analyze document with ChatGPT');
        }

        $data = json_decode($response, true);

        if (!isset($data['choices'][0]['message']['content'])) {
            throw new Exception('Invalid response from ChatGPT');
        }

        $extractedData = json_decode($data['choices'][0]['message']['content'], true);

        // Ensure we have all required fields
        return $this->normalizeExtractedData($extractedData);
    }

    /**
     * Normalize and validate extracted data
     */
    private function normalizeExtractedData(array $data): array {
        $normalized = [
            'primary_color' => $data['primary_color'] ?? '#2196F3',
            'secondary_color' => $data['secondary_color'] ?? '#FF9800',
            'target_audience' => $data['target_audience'] ?? 'General audience',
            'brand_personality' => $data['brand_personality'] ?? 'Professional',
            'tone_of_voice' => $data['tone_of_voice'] ?? 'Friendly and approachable',
            'key_messages' => $data['key_messages'] ?? [],
            'value_propositions' => $data['value_propositions'] ?? [],
            'product_description' => $data['product_description'] ?? '',
            'visual_style' => $data['visual_style'] ?? 'modern',
            'headline' => $data['headline_suggestions'][0] ?? 'Your Brand Message Here',
            'headline_suggestions' => $data['headline_suggestions'] ?? [],
            'body_text' => $data['body_text_suggestions'][0] ?? '',
            'body_text_suggestions' => $data['body_text_suggestions'] ?? [],
            'cta_text' => $data['cta_text'] ?? 'Learn More',
            'restrictions' => $data['restrictions'] ?? [],
            'extracted_from' => 'document'
        ];

        // Convert color descriptions to hex if needed
        $normalized['primary_color'] = $this->colorToHex($normalized['primary_color']);
        $normalized['secondary_color'] = $this->colorToHex($normalized['secondary_color']);

        return $normalized;
    }

    /**
     * Convert color name/description to hex
     */
    private function colorToHex(string $color): string {
        // If already hex, return as is
        if (preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            return $color;
        }

        // Common color mappings
        $colorMap = [
            'red' => '#FF0000',
            'blue' => '#0000FF',
            'green' => '#00FF00',
            'yellow' => '#FFFF00',
            'orange' => '#FF9800',
            'purple' => '#9C27B0',
            'pink' => '#E91E63',
            'black' => '#000000',
            'white' => '#FFFFFF',
            'gray' => '#9E9E9E',
            'grey' => '#9E9E9E',
            'brown' => '#795548',
            'navy' => '#000080',
            'teal' => '#008080',
            'gold' => '#FFD700',
            'silver' => '#C0C0C0'
        ];

        $lowerColor = strtolower(trim($color));

        // Check for exact match
        if (isset($colorMap[$lowerColor])) {
            return $colorMap[$lowerColor];
        }

        // Check for partial match
        foreach ($colorMap as $name => $hex) {
            if (stripos($color, $name) !== false) {
                return $hex;
            }
        }

        // Default to blue if no match
        return '#2196F3';
    }

    /**
     * Combine multiple documents into a single style guide
     */
    public function combineDocuments(array $documents): array {
        $combined = [
            'primary_color' => '',
            'secondary_color' => '',
            'target_audience' => [],
            'key_messages' => [],
            'value_propositions' => [],
            'visual_styles' => [],
            'headlines' => [],
            'body_texts' => [],
            'cta_texts' => []
        ];

        foreach ($documents as $doc) {
            if (!empty($doc['primary_color']) && empty($combined['primary_color'])) {
                $combined['primary_color'] = $doc['primary_color'];
            }
            if (!empty($doc['secondary_color']) && empty($combined['secondary_color'])) {
                $combined['secondary_color'] = $doc['secondary_color'];
            }

            if (!empty($doc['target_audience'])) {
                $combined['target_audience'][] = $doc['target_audience'];
            }

            $combined['key_messages'] = array_merge(
                $combined['key_messages'],
                $doc['key_messages'] ?? []
            );

            $combined['value_propositions'] = array_merge(
                $combined['value_propositions'],
                $doc['value_propositions'] ?? []
            );

            if (!empty($doc['visual_style'])) {
                $combined['visual_styles'][] = $doc['visual_style'];
            }

            $combined['headlines'] = array_merge(
                $combined['headlines'],
                $doc['headline_suggestions'] ?? []
            );

            $combined['body_texts'] = array_merge(
                $combined['body_texts'],
                $doc['body_text_suggestions'] ?? []
            );

            if (!empty($doc['cta_text'])) {
                $combined['cta_texts'][] = $doc['cta_text'];
            }
        }

        // Deduplicate and format
        return [
            'primary_color' => $combined['primary_color'] ?: '#2196F3',
            'secondary_color' => $combined['secondary_color'] ?: '#FF9800',
            'target_audience' => implode(', ', array_unique($combined['target_audience'])),
            'key_messages' => array_unique($combined['key_messages']),
            'value_propositions' => array_unique($combined['value_propositions']),
            'visual_style' => $combined['visual_styles'][0] ?? 'modern',
            'headline_suggestions' => array_unique($combined['headlines']),
            'body_text_suggestions' => array_unique($combined['body_texts']),
            'cta_text' => $combined['cta_texts'][0] ?? 'Learn More',
            'product_description' => $documents[0]['product_description'] ?? '',
            'extracted_from' => 'multiple_documents'
        ];
    }
}