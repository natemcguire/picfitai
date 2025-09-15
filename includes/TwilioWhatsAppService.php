<?php
// TwilioWhatsAppService.php - WhatsApp OTP authentication via Twilio
declare(strict_types=1);

class TwilioWhatsAppService {
    private string $accountSid;
    private string $authToken;
    private string $fromNumber;
    private string $contentSid; // Template ID for authentication messages

    public function __construct() {
        $this->accountSid = Config::get('twilio_account_sid');
        $this->authToken = Config::get('twilio_auth_token');
        $this->fromNumber = Config::get('twilio_whatsapp_from'); // e.g., "whatsapp:+14155238886"
        $this->contentSid = Config::get('twilio_auth_template_sid'); // Template ID you'll provide

        if (empty($this->accountSid) || empty($this->authToken) || empty($this->fromNumber)) {
            throw new Exception('Twilio WhatsApp configuration missing');
        }
    }

    /**
     * Send OTP via WhatsApp using Twilio Content API
     */
    public function sendOTP(string $phoneNumber, string $otpCode): bool {
        try {
            // Normalize phone number (ensure it starts with + and country code)
            $normalizedNumber = $this->normalizePhoneNumber($phoneNumber);
            $whatsappNumber = "whatsapp:" . $normalizedNumber;

            $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->accountSid}/Messages.json";

            $data = [
                'From' => $this->fromNumber,
                'To' => $whatsappNumber,
                'ContentSid' => $this->contentSid,
                'ContentVariables' => json_encode([
                    '1' => $otpCode // OTP code variable for template
                ])
            ];

            $response = $this->makeTwilioRequest($url, $data);

            if ($response && isset($response['sid'])) {
                Logger::info('WhatsApp OTP sent successfully', [
                    'phone' => $this->maskPhoneNumber($phoneNumber),
                    'message_sid' => $response['sid']
                ]);
                return true;
            }

            Logger::error('Failed to send WhatsApp OTP', [
                'phone' => $this->maskPhoneNumber($phoneNumber),
                'response' => $response
            ]);
            return false;

        } catch (Exception $e) {
            Logger::error('WhatsApp OTP send error', [
                'phone' => $this->maskPhoneNumber($phoneNumber),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Normalize phone number to international format
     */
    private function normalizePhoneNumber(string $phoneNumber): string {
        // Remove all non-digit characters
        $phoneNumber = preg_replace('/[^0-9]/', '', $phoneNumber);

        // If it doesn't start with country code, assume US (+1)
        if (!str_starts_with($phoneNumber, '1') && strlen($phoneNumber) === 10) {
            $phoneNumber = '1' . $phoneNumber;
        }

        return '+' . $phoneNumber;
    }

    /**
     * Mask phone number for logging (security)
     */
    private function maskPhoneNumber(string $phoneNumber): string {
        if (strlen($phoneNumber) > 4) {
            return substr($phoneNumber, 0, 2) . str_repeat('*', strlen($phoneNumber) - 4) . substr($phoneNumber, -2);
        }
        return '***';
    }

    /**
     * Make authenticated request to Twilio API
     */
    private function makeTwilioRequest(string $url, array $data): ?array {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->accountSid . ':' . $this->authToken,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Curl error: ' . $curlError);
        }

        if ($httpCode !== 201) {
            Logger::error('Twilio API error', [
                'http_code' => $httpCode,
                'response' => $response
            ]);
            return null;
        }

        return json_decode($response, true);
    }
}