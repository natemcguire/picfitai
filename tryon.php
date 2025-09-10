<?php
// tryon.php - Reusable AI Try-On Module
declare(strict_types=1);

// This module can be included anywhere to provide try-on functionality
// It handles authentication, file uploads, and generation

class TryOnModule {
    private $user;
    private $error = '';
    private $success = '';
    private $moduleId;
    
    public function __construct(string $moduleId = 'tryon') {
        $this->moduleId = $moduleId;
        $this->user = Session::getCurrentUser();
        
        // Handle form submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['csrf_token']) && isset($_POST['module_id']) && $_POST['module_id'] === $this->moduleId) {
            $this->handleSubmission();
        }
    }
    
    public function requireAuth(): bool {
        return $this->user !== null;
    }
    
    public function getUser() {
        return $this->user;
    }
    
    public function getError(): string {
        return $this->error;
    }
    
    public function getSuccess(): string {
        return $this->success;
    }
    
    private function handleSubmission(): void {
        // Validate CSRF token
        if (!Session::validateCSRFToken($_POST['csrf_token'] ?? '')) {
            $this->error = 'Invalid request. Please try again.';
            return;
        }
        
        if (!$this->user) {
            $this->error = 'Please log in to use this feature.';
            return;
        }
        
        try {
            // Check if user has credits
            if ($this->user['credits_remaining'] <= 0) {
                header('Location: /pricing.php?reason=no_credits');
                exit;
            }
            
            // Log request details
            Logger::logRequest('POST', $_SERVER['REQUEST_URI'] ?? '/tryon', [
                'post' => $_POST,
                'files' => $_FILES
            ]);
            
            Logger::info('TryOn Module - PHP upload settings', [
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_file_uploads' => ini_get('max_file_uploads'),
                'file_uploads' => ini_get('file_uploads'),
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time')
            ]);
            
            Logger::info('TryOn Module - API configuration', [
                'gemini_configured' => !empty(Config::get('gemini_api_key')),
                'openai_configured' => !empty(Config::get('openai_api_key'))
            ]);
            
            // Log file upload details
            if (!empty($_FILES)) {
                Logger::logFileUpload($_FILES);
            }
            
            // Validate uploaded files FIRST (before charging credits)
            $validationErrors = AIService::validateUploadedFiles($_FILES['standing_photos'] ?? [], $_FILES['outfit_photo'] ?? []);
            
            if (!empty($validationErrors)) {
                Logger::error('TryOn Module - Validation failed (no credits charged)', [
                    'errors' => $validationErrors,
                    'files_received' => array_keys($_FILES)
                ]);
                $this->error = implode('<br>', $validationErrors);
                return;
            }
            
            // Process standing photos
            $standingPhotos = [];
            if (isset($_FILES['standing_photos']['tmp_name'])) {
                for ($i = 0; $i < count($_FILES['standing_photos']['tmp_name']); $i++) {
                    if (!empty($_FILES['standing_photos']['tmp_name'][$i])) {
                        $standingPhotos[] = [
                            'tmp_name' => $_FILES['standing_photos']['tmp_name'][$i],
                            'name' => $_FILES['standing_photos']['name'][$i],
                            'type' => $_FILES['standing_photos']['type'][$i],
                            'size' => $_FILES['standing_photos']['size'][$i]
                        ];
                    }
                }
            }
            
            $outfitPhoto = $_FILES['outfit_photo'] ?? null;
            
            // Debit credits ONLY after validation passes
            if (!StripeService::debitUserCredits($this->user['id'], 1, 'AI outfit generation')) {
                $this->error = 'Failed to process credits. Please try again.';
                return;
            }
            
            // Generate the fit
            Logger::info('TryOn Module - Starting AI generation', [
                'user_id' => $this->user['id'],
                'standing_photos_count' => count($standingPhotos),
                'has_outfit_photo' => !empty($outfitPhoto)
            ]);
            
            $aiService = new AIService();
            $result = $aiService->generateFit($this->user['id'], $standingPhotos, $outfitPhoto);
            
            if ($result['success']) {
                Logger::info('TryOn Module - Generation successful', [
                    'generation_id' => $result['generation_id'],
                    'processing_time' => $result['processing_time'] ?? 'unknown'
                ]);
                
                Session::refreshUserData(); // Refresh to show updated credits
                $this->user = Session::getCurrentUser();
                
                // Redirect to dashboard to see result
                header('Location: /dashboard.php?generated=' . $result['generation_id']);
                exit;
            } else {
                Logger::error('TryOn Module - Generation failed, refunding credits', [
                    'result' => $result
                ]);
                
                // Refund credits since generation failed
                StripeService::addUserCredits($this->user['id'], 1, 'Refund for failed generation');
                
                $this->error = 'Generation failed. Credits have been refunded. Please try again.';
            }
            
        } catch (Exception $e) {
            Logger::error('TryOn Module - Exception occurred, refunding credits', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Refund credits since an exception occurred
            StripeService::addUserCredits($this->user['id'], 1, 'Refund for exception: ' . $e->getMessage());
            
            $this->error = 'Generation failed: ' . $e->getMessage() . ' Credits have been refunded.';
        }
    }
    
    public function renderHTML(): string {
        if (!$this->user) {
            return $this->renderLoginPrompt();
        }
        
        $csrfToken = Session::generateCSRFToken();
        $moduleId = htmlspecialchars($this->moduleId);
        
        ob_start();
        ?>
        
        <?php if ($this->error): ?>
            <div class="alert alert-error">
                <p><?= $this->error ?></p>
            </div>
        <?php endif; ?>
        
        <?php if ($this->success): ?>
            <div class="alert alert-success">
                <p><?= $this->success ?></p>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <form method="POST" enctype="multipart/form-data" id="<?= $moduleId ?>Form" class="tryon-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="module_id" value="<?= $moduleId ?>">
                
                <div class="grid grid-2">
                    <!-- Standing Photos -->
                    <div>
                        <h3 class="mb-md">Your Photos</h3>
                        <p class="text-secondary mb-md">Upload 3-10 clear, full-body photos from different angles.</p>
                        
                        <div class="form-group">
                            <label for="<?= $moduleId ?>StandingPhotos" class="form-label">
                                📸 Select Your Photos (Multiple)
                            </label>
                            <input type="file" 
                                   id="<?= $moduleId ?>StandingPhotos" 
                                   name="standing_photos[]" 
                                   multiple 
                                   accept="image/*" 
                                   class="form-input"
                                   required>
                            <p class="text-muted text-xs mt-sm">Max 10MB per file, 50MB total. Up to 10 photos.</p>
                        </div>
                        
                        <div id="<?= $moduleId ?>StandingPreview" class="preview-grid"></div>
                        <div id="<?= $moduleId ?>StandingCount" class="text-muted mt-sm" style="font-size: 0.9rem;">0 photos selected</div>
                    </div>

                    <!-- Outfit Photo -->
                    <div>
                        <h3 class="mb-md">Outfit Photo</h3>
                        <p class="text-secondary mb-md">Upload a flat-lay photo of the outfit on a clean surface.</p>
                        
                        <div class="form-group">
                            <label for="<?= $moduleId ?>OutfitPhoto" class="form-label">
                                👕 Select Outfit Photo
                            </label>
                            <input type="file" 
                                   id="<?= $moduleId ?>OutfitPhoto" 
                                   name="outfit_photo" 
                                   accept="image/*" 
                                   class="form-input"
                                   required>
                            <p class="text-muted text-xs mt-sm">Max 10MB per file.</p>
                        </div>
                        
                        <div id="<?= $moduleId ?>OutfitPreview" class="mt-md"></div>
                    </div>
                </div>
                
                <!-- Generate Button -->
                <div class="mt-xl text-center">
                    <div class="text-secondary mb-md" style="font-size: 0.9rem;">
                        <p>This will cost <strong>1 credit</strong> from your account (<?= $this->user['credits_remaining'] ?> remaining).</p>
                    </div>
                    <button type="submit" 
                            id="<?= $moduleId ?>GenerateBtn"
                            class="btn btn-accent btn-lg"
                            <?= $this->user['credits_remaining'] <= 0 ? 'disabled' : '' ?>>
                        ✨ Generate My Fit
                    </button>
                </div>
            </form>
        </div>
        
        <?php
        return ob_get_clean();
    }
    
    private function renderLoginPrompt(): string {
        ob_start();
        ?>
        <div class="card text-center">
            <h3 class="mb-md">Sign In to Try On Outfits</h3>
            <p class="text-secondary mb-lg">Create a free account to start generating your personalized outfit fits with AI.</p>
            <a href="/auth/login.php" class="btn btn-accent btn-lg">
                Sign In with Google
            </a>
        </div>
        <?php
        return ob_get_clean();
    }
    
    public function renderJS(): string {
        if (!$this->user) {
            return '';
        }
        
        $moduleId = $this->moduleId;
        
        ob_start();
        ?>
        <script>
        (function() {
            // TryOn Module JavaScript for <?= $moduleId ?> - Simple version
            console.log('TryOn Module <?= $moduleId ?> loaded at:', new Date());
            
            const standingPhotos = document.getElementById('<?= $moduleId ?>StandingPhotos');
            const outfitPhoto = document.getElementById('<?= $moduleId ?>OutfitPhoto');
            const standingPreview = document.getElementById('<?= $moduleId ?>StandingPreview');
            const outfitPreview = document.getElementById('<?= $moduleId ?>OutfitPreview');
            const standingCount = document.getElementById('<?= $moduleId ?>StandingCount');
            const form = document.getElementById('<?= $moduleId ?>Form');
            const generateBtn = document.getElementById('<?= $moduleId ?>GenerateBtn');
            
            if (!standingPhotos || !outfitPhoto) {
                console.error('TryOn Module elements not found');
                return;
            }
            
            console.log('Elements found successfully');
            
            // File size limits (in bytes)
            const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB per file
            const MAX_TOTAL_SIZE = 50 * 1024 * 1024; // 50MB total
            
            // Simple file change handlers with validation
            standingPhotos.addEventListener('change', function() {
                console.log('Standing photos changed:', this.files.length);
                if (validateFiles(this.files, 'standing')) {
                    updateStandingPreview();
                } else {
                    this.value = ''; // Clear invalid selection
                }
            });
            
            outfitPhoto.addEventListener('change', function() {
                console.log('Outfit photo changed:', this.files.length);
                if (validateFiles(this.files, 'outfit')) {
                    updateOutfitPreview();
                } else {
                    this.value = ''; // Clear invalid selection
                }
            });
            
            function validateFiles(files, type) {
                const fileArray = Array.from(files);
                let totalSize = 0;
                
                for (let file of fileArray) {
                    // Check individual file size
                    if (file.size > MAX_FILE_SIZE) {
                        alert(`File "${file.name}" is too large. Maximum size is 10MB per file.`);
                        return false;
                    }
                    
                    // Check file type
                    if (!file.type.startsWith('image/')) {
                        alert(`File "${file.name}" is not a valid image file.`);
                        return false;
                    }
                    
                    totalSize += file.size;
                }
                
                // Check total size
                if (totalSize > MAX_TOTAL_SIZE) {
                    const totalMB = Math.round(totalSize / 1024 / 1024);
                    alert(`Total file size (${totalMB}MB) exceeds the limit of 50MB. Please select smaller files or fewer files.`);
                    return false;
                }
                
                // Check file count for standing photos
                if (type === 'standing' && fileArray.length > 10) {
                    alert('You can upload a maximum of 10 standing photos.');
                    return false;
                }
                
                return true;
            }
            
            function updateStandingPreview() {
                const files = Array.from(standingPhotos.files);
                standingPreview.innerHTML = '';
                standingCount.textContent = files.length + ' photos selected';
                
                console.log('Updating standing preview with', files.length, 'files');
                
                files.slice(0, 10).forEach((file, index) => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        div.innerHTML = `
                            <img src="${e.target.result}" class="preview-image" alt="Standing photo ${index + 1}">
                        `;
                        standingPreview.appendChild(div);
                    };
                    reader.readAsDataURL(file);
                });
            }
            
            function updateOutfitPreview() {
                outfitPreview.innerHTML = '';
                if (outfitPhoto.files[0]) {
                    console.log('Updating outfit preview');
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const div = document.createElement('div');
                        div.className = 'preview-item';
                        div.style.maxWidth = '300px';
                        div.innerHTML = `
                            <img src="${e.target.result}" class="preview-image" alt="Outfit photo">
                        `;
                        outfitPreview.appendChild(div);
                    };
                    reader.readAsDataURL(outfitPhoto.files[0]);
                }
            }
            
            // Form submission validation
            form.addEventListener('submit', function(e) {
                console.log('Form submitting - Standing photos:', standingPhotos.files.length, 'Outfit photos:', outfitPhoto.files.length);
                
                // Debug: Log all form data
                const formData = new FormData(form);
                console.log('FormData contents:');
                for (let [key, value] of formData.entries()) {
                    if (value instanceof File) {
                        console.log(key, 'File:', value.name, value.size, value.type);
                    } else if (value instanceof FileList) {
                        console.log(key, 'FileList:', value.length, 'files');
                        for (let i = 0; i < value.length; i++) {
                            console.log('  File', i, ':', value[i].name, value[i].size, value[i].type);
                        }
                    } else {
                        console.log(key, value);
                    }
                }
                
                if (standingPhotos.files.length === 0) {
                    e.preventDefault();
                    alert('Please select at least one standing photo.');
                    return;
                }
                
                if (outfitPhoto.files.length === 0) {
                    e.preventDefault();
                    alert('Please select an outfit photo.');
                    return;
                }
                
                // Show loading state
                generateBtn.disabled = true;
                generateBtn.textContent = '⏳ Generating...';
                
                // Re-enable after timeout in case of error
                setTimeout(() => {
                    generateBtn.disabled = false;
                    generateBtn.textContent = '✨ Generate My Fit';
                }, 10000);
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }
}
