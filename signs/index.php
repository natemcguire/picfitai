<?php
require_once '../bootstrap.php';

// Ensure user is logged in
if (!Session::isLoggedIn()) {
    header('Location: /auth/login.php');
    exit;
}

$user = Session::getCurrentUser();

// Get user's credit balance
$credits = $user['credits_remaining'];

$pageTitle = 'AI Image Generator';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - PicFit.ai</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }
        .container {
            max-width: 900px;
            padding: 2rem 1rem;
        }
        .generator-card {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            padding: 2rem;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.1);
        }
        .credit-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 50px;
            display: inline-block;
            margin-bottom: 1rem;
        }
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 2rem;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
            position: relative;
            min-height: 200px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .upload-area:hover {
            border-color: #667eea;
            background: #f0f4ff;
            transform: scale(1.02);
        }
        .upload-area.active {
            border-color: #667eea;
            background: #e8eeff;
            border-style: solid;
            box-shadow: 0 0 20px rgba(102, 126, 234, 0.2);
        }
        .upload-area.dragging {
            border-color: #28a745;
            background: #e8f5e8;
            transform: scale(1.05);
        }
        .preview-image {
            max-width: 100%;
            max-height: 300px;
            border-radius: 10px;
            margin-top: 1rem;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }
        .dimension-presets {
            display: flex;
            gap: 0.5rem;
            flex-wrap: wrap;
            margin-bottom: 1rem;
        }
        .preset-btn {
            padding: 0.5rem 1rem;
            border: 2px solid #dee2e6;
            background: white;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .preset-btn:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .preset-btn.active {
            border-color: #667eea;
            background: #667eea;
            color: white;
        }
        .ai-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .ai-option {
            padding: 1rem;
            border: 2px solid #dee2e6;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        .ai-option:hover {
            border-color: #667eea;
            background: #f0f4ff;
        }
        .ai-option input[type="radio"] {
            margin-right: 0.5rem;
        }
        .ai-option.selected {
            border-color: #667eea;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
        }
        .generate-btn {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            padding: 1rem 2rem;
            border-radius: 50px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        .generate-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .generate-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .result-container {
            margin-top: 2rem;
            text-align: center;
        }
        .result-image {
            max-width: 100%;
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
            margin-bottom: 1rem;
        }
        .spinner-border {
            width: 3rem;
            height: 3rem;
            margin: 2rem auto;
        }
        .dimension-inputs {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        .dimension-inputs input {
            width: 100px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="generator-card">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1 class="h3 mb-0"><?php echo $pageTitle; ?></h1>
                <div>
                    <span class="credit-badge">
                        <i class="bi bi-coin"></i> <?php echo $credits; ?> Credits
                    </span>
                </div>
            </div>

            <!-- Tab Navigation -->
            <ul class="nav nav-tabs justify-content-center mb-4" id="generatorTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active fw-bold" id="image-tab" data-bs-toggle="tab" data-bs-target="#image-upload" type="button" role="tab">
                        📷 Upload & Transform
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link fw-bold" id="scratch-tab" data-bs-toggle="tab" data-bs-target="#from-scratch" type="button" role="tab">
                        ✨ Generate from Scratch
                    </button>
                </li>
            </ul>

            <!-- Tab Content -->
            <div class="tab-content" id="generatorTabContent">
                <!-- Image Upload & Transform Tab -->
                <div class="tab-pane fade show active" id="image-upload" role="tabpanel">
                    <h5 class="text-center mb-4 text-muted">Upload an image and transform it with AI</h5>

                    <form id="generateForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo Session::generateCSRFToken(); ?>">

                <!-- Image Upload -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Upload Image</label>
                    <div class="upload-area" onclick="document.getElementById('imageFile').click()">
                        <div id="uploadContent">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="text-secondary mb-3">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                                <polyline points="17 8 12 3 7 8"></polyline>
                                <line x1="12" y1="3" x2="12" y2="15"></line>
                            </svg>
                            <p class="mb-2 fw-bold">Drop image here or click to upload</p>
                            <small class="text-muted">PNG, JPG, WebP up to 10MB</small>
                        </div>
                        <input type="file" id="imageFile" name="image" accept="image/*" style="display: none;" required>
                        <img id="imagePreview" class="preview-image d-none" alt="Preview">
                        <div id="changeImageBtn" class="d-none position-absolute top-0 end-0 m-2">
                            <button type="button" class="btn btn-sm btn-light" onclick="changeImage(event)">
                                Change Image
                            </button>
                        </div>
                    </div>
                </div>

                <!-- AI Model Selection -->
                <div class="mb-4">
                    <label class="form-label fw-bold">AI Model</label>
                    <div class="ai-selector">
                        <label class="ai-option">
                            <input type="radio" name="ai_model" value="gemini" checked>
                            <strong>Gemini</strong>
                            <small class="d-block text-muted">Image-to-image generation</small>
                        </label>
                        <label class="ai-option">
                            <input type="radio" name="ai_model" value="openai">
                            <strong>DALL-E</strong>
                            <small class="d-block text-muted">Text-to-image generation</small>
                        </label>
                        <label class="ai-option">
                            <input type="radio" name="ai_model" value="hybrid">
                            <strong>Hybrid</strong>
                            <small class="d-block text-muted">Gemini analysis + DALL-E generation</small>
                        </label>
                    </div>
                </div>

                <!-- Extracted Text Display -->
                <div id="extractedTextSection" class="mb-4 d-none">
                    <label class="form-label fw-bold">Extracted Text from Image</label>
                    <div id="extractedTextDisplay" class="form-control" style="background-color: #f8f9fa; min-height: 60px; white-space: pre-wrap;"></div>
                    <small class="text-muted">This text was automatically extracted from your uploaded image</small>
                </div>

                <!-- Text Overlay Input -->
                <div class="mb-4">
                    <label for="textOverlay" class="form-label fw-bold">Text for Image (Optional)</label>
                    <textarea id="textOverlay" name="text_overlay" class="form-control" rows="2"
                              placeholder="Enter text you want to appear on the generated image..."></textarea>
                    <small class="text-muted">This text will be overlaid on the generated image</small>
                </div>


                <!-- Generation Prompt -->
                <div class="mb-4">
                    <label for="prompt" class="form-label fw-bold">Generation Instructions</label>
                    <textarea class="form-control" id="prompt" name="prompt" rows="3"
                              placeholder="Describe how you want to modify or transform the image..." required></textarea>
                    <small class="text-muted">Describe the changes you want to make to the image</small>
                </div>

                <!-- Orientation Selection -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Orientation</label>
                    <div class="dimension-presets">
                        <button type="button" class="preset-btn" data-orientation="square" data-width="1024" data-height="1024">
                            Square (1:1)
                        </button>
                        <button type="button" class="preset-btn" data-orientation="portrait" data-width="768" data-height="1024">
                            Portrait (3:4)
                        </button>
                        <button type="button" class="preset-btn" data-orientation="landscape" data-width="1024" data-height="768">
                            Landscape (4:3)
                        </button>
                        <button type="button" class="preset-btn" data-orientation="custom">
                            Custom
                        </button>
                    </div>
                </div>

                <!-- Custom Dimensions -->
                <div class="mb-4">
                    <label class="form-label fw-bold">Dimensions</label>
                    <div class="dimension-inputs">
                        <input type="number" class="form-control" id="width" name="width"
                               placeholder="Width" min="256" max="2048" value="1024">
                        <span>×</span>
                        <input type="number" class="form-control" id="height" name="height"
                               placeholder="Height" min="256" max="2048" value="1024">
                        <span class="text-muted">pixels</span>
                    </div>
                </div>

                <!-- Generate Button -->
                <button type="submit" class="generate-btn" id="generateBtn">
                    Generate Image (Free)
                </button>
            </form>

            <!-- Result Container -->
            <div id="resultContainer" class="result-container d-none">
                <div id="loading" class="d-none">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Generating...</span>
                    </div>
                    <p class="mt-3">Generating your image...</p>
                </div>
                <div id="result" class="d-none">
                    <img id="resultImage" class="result-image" alt="Generated image">
                    <div class="mt-3">
                        <a id="downloadBtn" class="btn btn-primary" download>Download Image</a>
                        <button type="button" class="btn btn-outline-primary" onclick="resetForm()">Generate Another</button>
                    </div>
                </div>
                <div id="error" class="alert alert-danger d-none" role="alert"></div>
            </div>
                </div>
                <!-- End Image Upload Tab -->

                <!-- Generate from Scratch Tab -->
                <div class="tab-pane fade" id="from-scratch" role="tabpanel">
                    <h5 class="text-center mb-4 text-muted">Create new images using text prompts only</h5>

                    <form id="scratchForm">
                        <input type="hidden" name="csrf_token" value="<?php echo Session::generateCSRFToken(); ?>">
                        <input type="hidden" name="generation_type" value="scratch">

                        <!-- Text Prompt -->
                        <div class="mb-4">
                            <label for="scratchPrompt" class="form-label fw-bold">Describe Your Image</label>
                            <textarea id="scratchPrompt" name="prompt" class="form-control" rows="4"
                                      placeholder="Describe the image you want to create in detail..." required></textarea>
                            <small class="text-muted">Be specific about style, colors, composition, and any text you want included</small>
                        </div>

                        <!-- Text to Include -->
                        <div class="mb-4">
                            <label for="scratchText" class="form-label fw-bold">Text to Include (Optional)</label>
                            <textarea id="scratchText" name="text_overlay" class="form-control" rows="2"
                                      placeholder="Enter any text you want to appear in the image..."></textarea>
                            <small class="text-muted">Specify text that should be visible in the generated image</small>
                        </div>

                        <!-- Reference Image -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Reference Image (Optional)</label>
                            <div class="row">
                                <div class="col-12">
                                    <input type="file" id="referenceImages" name="reference_images[]" class="form-control" accept="image/*" multiple onchange="handleReferenceImages(this)">
                                    <small class="text-muted">Upload reference images for the AI to consider (max 5 images)</small>
                                    <small id="referenceImagesInfo" class="text-info d-none"></small>

                                    <!-- Preview container for uploaded images -->
                                    <div id="referencePreviewContainer" class="mt-3 d-none">
                                        <div class="row" id="referencePreviewGrid"></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="includeReference" name="include_reference" checked>
                                        <label class="form-check-label" for="includeReference">
                                            <strong>Include reference images in generation</strong>
                                        </label>
                                        <small class="d-block text-muted">When checked, the AI will use these images as reference</small>
                                    </div>
                                </div>
                            </div>
                        </div>


                        <!-- AI Model Selection (simpler for scratch) -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">AI Model</label>
                            <div class="ai-selector">
                                <label class="ai-option">
                                    <input type="radio" name="ai_model" value="gemini" checked>
                                    <strong>Gemini</strong>
                                    <small class="d-block text-muted">Advanced text-to-image with high-fidelity text rendering</small>
                                </label>
                                <label class="ai-option">
                                    <input type="radio" name="ai_model" value="openai">
                                    <strong>DALL-E</strong>
                                    <small class="d-block text-muted">Classic text-to-image generation</small>
                                </label>
                            </div>
                        </div>

                        <!-- Orientation Selection -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Orientation</label>
                            <div class="orientation-selector">
                                <button type="button" class="orientation-btn" data-orientation="portrait" data-width="1024" data-height="1792">
                                    Portrait
                                </button>
                                <button type="button" class="orientation-btn" data-orientation="landscape" data-width="1792" data-height="1024">
                                    Landscape
                                </button>
                                <button type="button" class="orientation-btn active" data-orientation="square" data-width="1024" data-height="1024">
                                    Square
                                </button>
                            </div>
                        </div>

                        <!-- Custom Dimensions -->
                        <div class="mb-4">
                            <label class="form-label fw-bold">Custom Dimensions (Optional)</label>
                            <div class="dimension-inputs">
                                <div class="d-flex gap-3 align-items-center">
                                    <div>
                                        <label for="scratchWidth" class="form-label small">Width</label>
                                        <input type="number" id="scratchWidth" name="width" class="form-control"
                                               min="256" max="2048" value="1024">
                                    </div>
                                    <span class="text-muted">×</span>
                                    <div>
                                        <label for="scratchHeight" class="form-label small">Height</label>
                                        <input type="number" id="scratchHeight" name="height" class="form-control"
                                               min="256" max="2048" value="1024">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Generate Button -->
                        <button type="submit" class="generate-btn" id="scratchGenerateBtn">
                            Generate Image (Free)
                        </button>
                    </form>

                    <!-- Scratch Result Container -->
                    <div id="scratchResultContainer" class="result-container d-none">
                        <div id="scratchLoading" class="d-none">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Generating...</span>
                            </div>
                            <p class="mt-3">Creating your image...</p>
                        </div>
                        <div id="scratchResult" class="d-none">
                            <img id="scratchResultImage" class="result-image" alt="Generated image">
                            <div class="mt-3">
                                <a id="scratchDownloadBtn" class="btn btn-primary" download>Download Image</a>
                                <button type="button" class="btn btn-outline-primary" onclick="resetScratchForm()">Generate Another</button>
                            </div>
                        </div>
                        <div id="scratchError" class="alert alert-danger d-none" role="alert"></div>
                    </div>
                </div>
                <!-- End Generate from Scratch Tab -->
            </div>
            <!-- End Tab Content -->
        </div>
    </div>

    <script>
        // Drag and drop functionality
        const uploadArea = document.querySelector('.upload-area');
        const fileInput = document.getElementById('imageFile');
        const imagePreview = document.getElementById('imagePreview');

        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });

        // Highlight drop area when item is dragged over it
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, highlight, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, unhighlight, false);
        });

        // Handle dropped files
        uploadArea.addEventListener('drop', handleDrop, false);

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        function highlight(e) {
            uploadArea.classList.add('dragging');
        }

        function unhighlight(e) {
            uploadArea.classList.remove('dragging');
        }

        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;

            if (files.length > 0) {
                fileInput.files = files;
                handleFiles(files);
            }
        }

        function handleFiles(files) {
            const file = files[0];
            if (file && file.type.startsWith('image/')) {
                displayPreview(file);
            } else {
                alert('Please upload an image file (PNG, JPG, WebP)');
            }
        }

        function displayPreview(file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.src = e.target.result;
                imagePreview.classList.remove('d-none');
                uploadArea.classList.add('active');

                // Hide the upload content and show change button
                document.getElementById('uploadContent').style.display = 'none';
                document.getElementById('changeImageBtn').classList.remove('d-none');

                // Extract text from the uploaded image
                extractTextFromImage(file);
            };
            reader.readAsDataURL(file);
        }

        // Extract text from uploaded image using OCR
        async function extractTextFromImage(file) {
            try {
                const formData = new FormData();
                formData.append('image', file);
                formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);

                console.log('🔍 Extracting text from uploaded image...');

                const response = await fetch('/signs/extract-text.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                const data = await response.json();

                if (data.success) {
                    console.log('✅ Text extracted:', data.extracted_text);

                    // Show extracted text section
                    const extractedTextSection = document.getElementById('extractedTextSection');
                    const extractedTextDisplay = document.getElementById('extractedTextDisplay');

                    if (data.extracted_text && data.extracted_text !== 'No text found') {
                        extractedTextDisplay.textContent = data.extracted_text;
                        extractedTextSection.classList.remove('d-none');

                        // Pre-fill the text overlay field with extracted text
                        document.getElementById('textOverlay').value = data.extracted_text;
                    } else {
                        extractedTextSection.classList.add('d-none');
                    }
                } else {
                    console.error('❌ Text extraction failed:', data.error);
                    // Don't show error to user for OCR failure, just log it
                }
            } catch (error) {
                console.error('❌ OCR request failed:', error);
                // Silent failure for OCR - don't interrupt user flow
            }
        }

        // Change image function
        function changeImage(e) {
            e.stopPropagation();
            fileInput.click();
        }

        // Reset form function
        function resetUpload() {
            fileInput.value = '';
            imagePreview.classList.add('d-none');
            uploadArea.classList.remove('active');
            document.getElementById('uploadContent').style.display = 'block';
            document.getElementById('changeImageBtn').classList.add('d-none');

            // Clear extracted text
            document.getElementById('extractedTextSection').classList.add('d-none');
            document.getElementById('extractedTextDisplay').textContent = '';
            document.getElementById('textOverlay').value = '';
        }

        // File input change handler
        fileInput.addEventListener('change', function(e) {
            if (e.target.files.length > 0) {
                handleFiles(e.target.files);
            }
        });

        // AI model selection
        document.querySelectorAll('input[name="ai_model"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('.ai-option').forEach(option => {
                    option.classList.remove('selected');
                });
                this.closest('.ai-option').classList.add('selected');
            });
        });

        // Set initial selected state
        document.querySelector('input[name="ai_model"]:checked').closest('.ai-option').classList.add('selected');

        // Dimension presets
        document.querySelectorAll('.preset-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('.preset-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const orientation = this.dataset.orientation;
                if (orientation !== 'custom') {
                    document.getElementById('width').value = this.dataset.width;
                    document.getElementById('height').value = this.dataset.height;
                }
            });
        });

        // Set initial preset
        document.querySelector('[data-orientation="square"]').classList.add('active');

        // Form submission
        document.getElementById('generateForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            console.log('🚀 Form submission started');

            const formData = new FormData(this);
            const generateBtn = document.getElementById('generateBtn');
            const resultContainer = document.getElementById('resultContainer');
            const loading = document.getElementById('loading');
            const result = document.getElementById('result');
            const error = document.getElementById('error');

            // Log form data
            console.log('📝 Form data contents:');
            for (let [key, value] of formData.entries()) {
                if (key === 'image') {
                    console.log(`  ${key}: File - ${value.name} (${value.size} bytes, ${value.type})`);
                } else {
                    console.log(`  ${key}: ${value}`);
                }
            }

            // Show loading
            resultContainer.classList.remove('d-none');
            loading.classList.remove('d-none');
            result.classList.add('d-none');
            error.classList.add('d-none');
            generateBtn.disabled = true;

            try {
                console.log('🌐 Making API request to /signs/generate.php');
                const response = await fetch('/signs/generate.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                console.log('📡 Response received:', {
                    status: response.status,
                    statusText: response.statusText,
                    headers: Object.fromEntries(response.headers.entries())
                });

                const responseText = await response.text();
                console.log('📄 Raw response text:', responseText);

                let data;
                try {
                    data = JSON.parse(responseText);
                    console.log('✅ Parsed JSON response:', data);
                } catch (parseError) {
                    console.error('❌ JSON parse error:', parseError);
                    console.log('Raw response that failed to parse:', responseText);
                    throw new Error('Invalid JSON response from server');
                }

                if (data.success) {
                    loading.classList.add('d-none');

                    // Show image result for all AI models (Gemini, DALL-E, and Hybrid all generate images)
                    result.innerHTML = `
                        <img id="resultImage" class="result-image" src="${data.image_url}" alt="Generated image">
                        <div class="mt-3">
                            <a id="downloadBtn" class="btn btn-primary" href="${data.image_url}" download>Download Image</a>
                            <button type="button" class="btn btn-outline-primary" onclick="resetForm()">Generate Another</button>
                        </div>
                    `;

                    result.classList.remove('d-none');

                    // Update credits display
                    document.querySelector('.credit-badge').innerHTML =
                        `<i class="bi bi-coin"></i> ${data.credits_remaining} Credits`;
                } else {
                    throw new Error(data.error || 'Generation failed');
                }
            } catch (err) {
                loading.classList.add('d-none');
                error.classList.remove('d-none');
                error.textContent = err.message;
            } finally {
                generateBtn.disabled = false;
            }
        });

        function resetForm() {
            document.getElementById('generateForm').reset();
            resetUpload();
            document.getElementById('resultContainer').classList.add('d-none');
            document.querySelector('[data-orientation="square"]').click();
        }

        // Handle multiple reference images
        function handleReferenceImages(input) {
            const previewContainer = document.getElementById('referencePreviewContainer');
            const previewGrid = document.getElementById('referencePreviewGrid');
            const infoElement = document.getElementById('referenceImagesInfo');

            // Clear previous previews
            previewGrid.innerHTML = '';

            if (input.files && input.files.length > 0) {
                // Limit to 5 images
                const maxImages = 5;
                const filesToProcess = Math.min(input.files.length, maxImages);

                if (input.files.length > maxImages) {
                    infoElement.textContent = `Only first ${maxImages} images will be used (${input.files.length} selected)`;
                    infoElement.classList.remove('d-none');
                } else {
                    infoElement.textContent = `${filesToProcess} reference image(s) loaded`;
                    infoElement.classList.remove('d-none');
                }

                // Process each file
                for (let i = 0; i < filesToProcess; i++) {
                    const file = input.files[i];
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        const img = new Image();
                        img.onload = function() {
                            // Create preview element
                            const previewCol = document.createElement('div');
                            previewCol.className = 'col-md-2 col-4 mb-2';

                            const previewWrapper = document.createElement('div');
                            previewWrapper.className = 'position-relative';
                            previewWrapper.style.cssText = 'border: 2px solid #ddd; border-radius: 8px; overflow: hidden; aspect-ratio: 1;';

                            const previewImg = document.createElement('img');
                            previewImg.src = e.target.result;
                            previewImg.className = 'img-fluid';
                            previewImg.style.cssText = 'width: 100%; height: 100%; object-fit: cover;';

                            const removeBtn = document.createElement('button');
                            removeBtn.type = 'button';
                            removeBtn.innerHTML = '×';
                            removeBtn.className = 'btn btn-sm btn-danger position-absolute top-0 end-0';
                            removeBtn.style.cssText = 'width: 25px; height: 25px; line-height: 1; padding: 0; margin: 2px; border-radius: 50%;';
                            removeBtn.onclick = function() {
                                previewCol.remove();
                                updateReferenceImageCount();
                            };

                            const sizeLabel = document.createElement('small');
                            sizeLabel.className = 'position-absolute bottom-0 start-0 bg-dark text-white px-1';
                            sizeLabel.textContent = `${this.width}×${this.height}`;
                            sizeLabel.style.fontSize = '10px';

                            previewWrapper.appendChild(previewImg);
                            previewWrapper.appendChild(removeBtn);
                            previewWrapper.appendChild(sizeLabel);
                            previewCol.appendChild(previewWrapper);
                            previewGrid.appendChild(previewCol);
                        };
                        img.src = e.target.result;
                    };

                    reader.readAsDataURL(file);
                }

                previewContainer.classList.remove('d-none');
            } else {
                previewContainer.classList.add('d-none');
                infoElement.classList.add('d-none');
            }
        }

        function updateReferenceImageCount() {
            const previewGrid = document.getElementById('referencePreviewGrid');
            const infoElement = document.getElementById('referenceImagesInfo');
            const imageCount = previewGrid.children.length;

            if (imageCount > 0) {
                infoElement.textContent = `${imageCount} reference image(s) loaded`;
                infoElement.classList.remove('d-none');
            } else {
                infoElement.classList.add('d-none');
                document.getElementById('referencePreviewContainer').classList.add('d-none');
            }
        }

        // Detect overlay image size (keeping for potential future use)
        let overlayAspectRatio = 1;
        function detectOverlaySize(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const img = new Image();
                    img.onload = function() {
                        const width = this.width;
                        const height = this.height;
                        overlayAspectRatio = width / height;

                        // Set the dimensions in the input fields
                        document.getElementById('overlayWidth').value = width;
                        document.getElementById('overlayHeight').value = height;

                        // Show original size info
                        const sizeInfo = document.getElementById('overlayOriginalSize');
                        sizeInfo.textContent = `Original: ${width}×${height}px`;
                        sizeInfo.classList.remove('d-none');
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Maintain aspect ratio when changing dimensions
        document.getElementById('overlayWidth')?.addEventListener('input', function() {
            if (document.getElementById('maintainAspectRatio').checked) {
                const width = parseInt(this.value) || 0;
                document.getElementById('overlayHeight').value = Math.round(width / overlayAspectRatio);
            }
        });

        document.getElementById('overlayHeight')?.addEventListener('input', function() {
            if (document.getElementById('maintainAspectRatio').checked) {
                const height = parseInt(this.value) || 0;
                document.getElementById('overlayWidth').value = Math.round(height * overlayAspectRatio);
            }
        });

        // Scratch form functionality
        function resetScratchForm() {
            document.getElementById('scratchForm').reset();
            document.getElementById('scratchResultContainer').classList.add('d-none');
            document.querySelectorAll('#from-scratch .orientation-btn').forEach(btn => btn.classList.remove('active'));
            document.querySelector('#from-scratch [data-orientation="square"]').classList.add('active');
        }

        // Scratch AI model selection
        document.querySelectorAll('#from-scratch input[name="ai_model"]').forEach(radio => {
            radio.addEventListener('change', function() {
                document.querySelectorAll('#from-scratch .ai-option').forEach(option => {
                    option.classList.remove('selected');
                });
                this.closest('.ai-option').classList.add('selected');
            });
        });

        // Set initial selected state for scratch form
        document.querySelector('#from-scratch input[name="ai_model"]:checked').closest('.ai-option').classList.add('selected');

        // Scratch orientation buttons
        document.querySelectorAll('#from-scratch .orientation-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                document.querySelectorAll('#from-scratch .orientation-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                const orientation = this.dataset.orientation;
                if (orientation !== 'custom') {
                    document.getElementById('scratchWidth').value = this.dataset.width;
                    document.getElementById('scratchHeight').value = this.dataset.height;
                }
            });
        });

        // Scratch form submission
        document.getElementById('scratchForm').addEventListener('submit', async function(e) {
            e.preventDefault();

            const scratchResultContainer = document.getElementById('scratchResultContainer');
            const scratchLoading = document.getElementById('scratchLoading');
            const scratchResult = document.getElementById('scratchResult');
            const scratchError = document.getElementById('scratchError');
            const scratchGenerateBtn = document.getElementById('scratchGenerateBtn');

            const formData = new FormData(this);

            console.log('🚀 Scratch form submission started');

            // Show loading
            scratchResultContainer.classList.remove('d-none');
            scratchLoading.classList.remove('d-none');
            scratchResult.classList.add('d-none');
            scratchError.classList.add('d-none');
            scratchGenerateBtn.disabled = true;

            try {
                console.log('🌐 Making API request to /signs/generate.php');
                const response = await fetch('/signs/generate.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                const responseText = await response.text();
                console.log('📄 Raw response text:', responseText);

                let data;
                try {
                    data = JSON.parse(responseText);
                    console.log('✅ Parsed JSON response:', data);
                } catch (parseError) {
                    console.error('❌ JSON parse error:', parseError);
                    throw new Error('Invalid JSON response from server');
                }

                if (data.success) {
                    scratchLoading.classList.add('d-none');

                    // Show image result
                    scratchResult.innerHTML = `
                        <img id="scratchResultImage" class="result-image" src="${data.image_url}" alt="Generated image">
                        <div class="mt-3">
                            <a id="scratchDownloadBtn" class="btn btn-primary" href="${data.image_url}" download>Download Image</a>
                            <button type="button" class="btn btn-outline-primary" onclick="resetScratchForm()">Generate Another</button>
                        </div>
                    `;

                    scratchResult.classList.remove('d-none');

                    // Update credits display
                    document.querySelector('.credit-badge').innerHTML =
                        `<i class="bi bi-coin"></i> ${data.credits_remaining} Credits`;
                } else {
                    throw new Error(data.error || 'Generation failed');
                }
            } catch (err) {
                scratchLoading.classList.add('d-none');
                scratchError.classList.remove('d-none');
                scratchError.textContent = err.message;
            } finally {
                scratchGenerateBtn.disabled = false;
            }
        });
    </script>

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>