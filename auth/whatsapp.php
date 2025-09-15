<?php
// whatsapp.php - WhatsApp OTP authentication page
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

// Redirect if already logged in
if (Session::isLoggedIn()) {
    header('Location: /generate.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Login - <?= Config::get('app_name') ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 25%, #f093fb 50%, #f5576c 75%, #4facfe 100%);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        .auth-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(20px);
            border-radius: 30px;
            padding: 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 30px 60px rgba(0, 0, 0, 0.2);
            border: 1px solid rgba(255, 255, 255, 0.3);
            text-align: center;
        }

        .logo {
            font-size: 2.5rem;
            font-weight: 700;
            background: linear-gradient(45deg, #f093fb, #f5576c, #4facfe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #666;
            margin-bottom: 30px;
            font-size: 1.1rem;
        }

        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
            gap: 10px;
        }

        .step {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #e0e0e0;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.3s ease;
        }

        .step.active {
            background: linear-gradient(45deg, #f093fb, #f5576c);
            color: white;
        }

        .step.completed {
            background: #4caf50;
            color: white;
        }

        .auth-step {
            display: none;
        }

        .auth-step.active {
            display: block;
            animation: slideIn 0.3s ease;
        }

        @keyframes slideIn {
            from { opacity: 0; transform: translateX(20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .form-group {
            margin-bottom: 20px;
            text-align: left;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #333;
        }

        .form-group input {
            width: 100%;
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }

        .form-group input:focus {
            outline: none;
            border-color: #f093fb;
            box-shadow: 0 0 0 3px rgba(240, 147, 251, 0.1);
        }

        .btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(45deg, #f093fb, #f5576c);
            color: white;
            border: none;
            border-radius: 15px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 15px;
        }

        .btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(240, 147, 251, 0.4);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-secondary {
            background: transparent;
            color: #666;
            border: 2px solid #e0e0e0;
        }

        .btn-secondary:hover {
            background: #f5f5f5;
            box-shadow: none;
        }

        .whatsapp-icon {
            font-size: 3rem;
            margin-bottom: 20px;
            color: #25d366;
        }

        .error-message {
            background: rgba(244, 67, 54, 0.1);
            color: #d32f2f;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid rgba(244, 67, 54, 0.2);
        }

        .success-message {
            background: rgba(76, 175, 80, 0.1);
            color: #2e7d32;
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        .loading {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin: 10px 0;
        }

        .loading.show {
            display: flex;
        }

        .spinner {
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #f093fb;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .back-link {
            color: #666;
            text-decoration: none;
            font-size: 0.9rem;
            margin-top: 20px;
            display: inline-block;
        }

        .back-link:hover {
            color: #f093fb;
        }

        @media (max-width: 480px) {
            .auth-container {
                padding: 30px 20px;
                margin: 10px;
            }

            .logo {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="auth-container">
        <div class="logo">PicFit.ai</div>
        <div class="subtitle">Login with WhatsApp</div>

        <!-- Step Indicator -->
        <div class="step-indicator">
            <div class="step active" id="step1">1</div>
            <div class="step" id="step2">2</div>
        </div>

        <!-- Error/Success Messages -->
        <div id="message" class="error-message" style="display: none;"></div>

        <!-- Step 1: Phone Number -->
        <div class="auth-step active" id="phoneStep">
            <div class="whatsapp-icon">📱</div>
            <h3 style="margin-bottom: 20px; color: #333;">Enter your WhatsApp number</h3>

            <form id="phoneForm">
                <div class="form-group">
                    <label for="phoneNumber">Phone Number</label>
                    <input
                        type="tel"
                        id="phoneNumber"
                        name="phone_number"
                        placeholder="+1 (555) 123-4567"
                        required
                    >
                </div>

                <button type="submit" class="btn" id="sendOtpBtn">
                    Send OTP
                </button>
            </form>

            <div class="loading" id="sendingLoading">
                <div class="spinner"></div>
                <span>Sending OTP...</span>
            </div>
        </div>

        <!-- Step 2: OTP Verification -->
        <div class="auth-step" id="otpStep">
            <div class="whatsapp-icon">💬</div>
            <h3 style="margin-bottom: 10px; color: #333;">Enter verification code</h3>
            <p style="color: #666; margin-bottom: 20px; font-size: 0.9rem;">
                We sent a 6-digit code to your WhatsApp
            </p>

            <form id="otpForm">
                <div class="form-group">
                    <label for="otpCode">Verification Code</label>
                    <input
                        type="text"
                        id="otpCode"
                        name="otp_code"
                        placeholder="123456"
                        maxlength="6"
                        pattern="[0-9]{6}"
                        required
                    >
                </div>

                <button type="submit" class="btn" id="verifyOtpBtn">
                    Verify & Login
                </button>

                <button type="button" class="btn btn-secondary" id="backBtn">
                    Change Number
                </button>
            </form>

            <div class="loading" id="verifyingLoading">
                <div class="spinner"></div>
                <span>Verifying...</span>
            </div>
        </div>

        <a href="/auth/login.php" class="back-link">← Back to other login options</a>
    </div>

    <script>
        let currentStep = 1;
        let phoneNumber = '';

        // Phone number formatting
        document.getElementById('phoneNumber').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length >= 10) {
                value = value.substring(0, 11); // Limit to 11 digits (1 + 10)
                if (value.length === 10) {
                    value = '1' + value; // Add US country code if not present
                }
                // Format as +1 (XXX) XXX-XXXX
                const formatted = `+${value.charAt(0)} (${value.substring(1, 4)}) ${value.substring(4, 7)}-${value.substring(7)}`;
                e.target.value = formatted;
            }
        });

        // Phone form submission
        document.getElementById('phoneForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const phoneInput = document.getElementById('phoneNumber');
            phoneNumber = phoneInput.value.replace(/\D/g, ''); // Remove formatting

            if (phoneNumber.length < 10) {
                showMessage('Please enter a valid phone number', 'error');
                return;
            }

            // Add country code if needed
            if (phoneNumber.length === 10) {
                phoneNumber = '1' + phoneNumber;
            }

            await sendOTP();
        });

        // OTP form submission
        document.getElementById('otpForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const otpCode = document.getElementById('otpCode').value;

            if (!/^\d{6}$/.test(otpCode)) {
                showMessage('Please enter a valid 6-digit code', 'error');
                return;
            }

            await verifyOTP(otpCode);
        });

        // Back button
        document.getElementById('backBtn').addEventListener('click', function() {
            goToStep(1);
        });

        async function sendOTP() {
            try {
                document.getElementById('sendOtpBtn').disabled = true;
                document.getElementById('sendingLoading').classList.add('show');
                hideMessage();

                const response = await fetch('/api/whatsapp_send_otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        phone_number: '+' + phoneNumber
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showMessage(`OTP sent to your WhatsApp! Expires in ${result.expires_in} minutes.`, 'success');
                    goToStep(2);
                } else {
                    showMessage(result.error, 'error');
                }

            } catch (error) {
                showMessage('Failed to send OTP. Please try again.', 'error');
            } finally {
                document.getElementById('sendOtpBtn').disabled = false;
                document.getElementById('sendingLoading').classList.remove('show');
            }
        }

        async function verifyOTP(otpCode) {
            try {
                document.getElementById('verifyOtpBtn').disabled = true;
                document.getElementById('verifyingLoading').classList.add('show');
                hideMessage();

                const response = await fetch('/api/whatsapp_verify_otp.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        phone_number: '+' + phoneNumber,
                        otp_code: otpCode
                    })
                });

                const result = await response.json();

                if (result.success) {
                    showMessage('Authentication successful! Redirecting...', 'success');
                    setTimeout(() => {
                        window.location.href = result.redirect || '/generate.php';
                    }, 1500);
                } else {
                    showMessage(result.error, 'error');
                }

            } catch (error) {
                showMessage('Failed to verify OTP. Please try again.', 'error');
            } finally {
                document.getElementById('verifyOtpBtn').disabled = false;
                document.getElementById('verifyingLoading').classList.remove('show');
            }
        }

        function goToStep(step) {
            // Update step indicator
            document.getElementById('step1').className = step >= 1 ? (step > 1 ? 'step completed' : 'step active') : 'step';
            document.getElementById('step2').className = step >= 2 ? 'step active' : 'step';

            // Show/hide steps
            document.getElementById('phoneStep').className = step === 1 ? 'auth-step active' : 'auth-step';
            document.getElementById('otpStep').className = step === 2 ? 'auth-step active' : 'auth-step';

            currentStep = step;
            hideMessage();

            // Focus appropriate input
            if (step === 1) {
                document.getElementById('phoneNumber').focus();
            } else if (step === 2) {
                document.getElementById('otpCode').focus();
            }
        }

        function showMessage(message, type) {
            const messageEl = document.getElementById('message');
            messageEl.textContent = message;
            messageEl.className = type === 'success' ? 'success-message' : 'error-message';
            messageEl.style.display = 'block';
        }

        function hideMessage() {
            document.getElementById('message').style.display = 'none';
        }

        // Auto-focus first input
        document.getElementById('phoneNumber').focus();
    </script>
</body>
</html>