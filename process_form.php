<?php
/**
 * Westcon Comstor Middle East
 * Credit Assessment Form Processing Script
 * Created: April 2025
 * 
 * This script handles form submissions from all three credit assessment forms:
 * - Complete information form
 * - Partial information form
 * - Minimal information form
 */

// Error reporting for development (disable in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Start session for potential future use
session_start();

// Database configuration
$config = [
    'db_host' => 'localhost',
    'db_name' => 'westcon_credit_assessment',
    'db_user' => 'root', // Replace with actual database username
    'db_pass' => '', // Replace with actual database password
    'upload_dir' => '../uploads/', // Directory to store uploaded files (outside web root)
    'log_file' => '../logs/form_processing.log' // Log file location
];

// Ensure upload directory exists and is writable
if (!file_exists($config['upload_dir'])) {
    mkdir($config['upload_dir'], 0755, true);
}

// Ensure log directory exists and is writable
$log_dir = dirname($config['log_file']);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

// Initialize response array
$response = [
    'success' => false,
    'message' => '',
    'referenceNumber' => '',
    'errors' => []
];

// Log function
function logMessage($message, $level = 'INFO') {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    file_put_contents($config['log_file'], $logEntry, FILE_APPEND);
}

// Connect to database
function connectDB() {
    global $config, $response;
    
    try {
        $dsn = "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ];
        
        $pdo = new PDO($dsn, $config['db_user'], $config['db_pass'], $options);
        return $pdo;
    } catch (PDOException $e) {
        logMessage("Database connection failed: " . $e->getMessage(), 'ERROR');
        $response['message'] = "Database connection error. Please try again later.";
        sendResponse($response);
        exit;
    }
}

// Sanitize input data
function sanitizeInput($data) {
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $data[$key] = sanitizeInput($value);
        }
    } else {
        $data = trim($data);
        $data = stripslashes($data);
        $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    return $data;
}

// Validate required fields
function validateRequiredFields($data, $requiredFields) {
    $errors = [];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $errors[] = "Field '$field' is required";
        }
    }
    
    return $errors;
}

// Generate unique reference number
function generateReferenceNumber() {
    $prefix = 'WC';
    $timestamp = date('YmdHis');
    $random = mt_rand(1000, 9999);
    return "{$prefix}-{$timestamp}-{$random}";
}

// Handle file upload
function handleFileUpload($fileField, $referenceNumber) {
    global $config, $response;
    
    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    
    $file = $_FILES[$fileField];
    
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errorMessages = [
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload'
        ];
        
        $errorMessage = isset($errorMessages[$file['error']]) ? 
                        $errorMessages[$file['error']] : 
                        'Unknown upload error';
        
        logMessage("File upload error for {$fileField}: {$errorMessage}", 'ERROR');
        $response['errors'][] = "Failed to upload {$fileField}: {$errorMessage}";
        return null;
    }
    
    // Validate file type (basic validation, should be enhanced in production)
    $allowedTypes = [
        'application/pdf', 
        'image/jpeg', 
        'image/png', 
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];
    
    if (!in_array($file['type'], $allowedTypes)) {
        logMessage("Invalid file type for {$fileField}: {$file['type']}", 'ERROR');
        $response['errors'][] = "Invalid file type for {$fileField}. Allowed types: PDF, JPEG, PNG, DOC, DOCX, XLS, XLSX";
        return null;
    }
    
    // Generate safe filename
    $fileInfo = pathinfo($file['name']);
    $safeFilename = $referenceNumber . '_' . $fileField . '_' . time() . '.' . $fileInfo['extension'];
    $uploadPath = $config['upload_dir'] . $safeFilename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
        logMessage("Failed to move uploaded file {$fileField} to {$uploadPath}", 'ERROR');
        $response['errors'][] = "Failed to save uploaded file {$fileField}";
        return null;
    }
    
    logMessage("File uploaded successfully: {$uploadPath}", 'INFO');
    
    return [
        'document_type' => $fileField,
        'file_name' => $file['name'],
        'file_path' => $uploadPath,
        'file_size' => $file['size'],
        'mime_type' => $file['type']
    ];
}

// Handle multiple file uploads
function handleMultipleFileUploads($fileField, $referenceNumber) {
    global $config, $response;
    
    $uploadedFiles = [];
    
    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'][0] === UPLOAD_ERR_NO_FILE) {
        return $uploadedFiles;
    }
    
    $files = $_FILES[$fileField];
    $fileCount = count($files['name']);
    
    for ($i = 0; $i < $fileCount; $i++) {
        if ($files['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }
        
        // Validate file type
        $allowedTypes = [
            'application/pdf', 
            'image/jpeg', 
            'image/png', 
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        ];
        
        if (!in_array($files['type'][$i], $allowedTypes)) {
            logMessage("Invalid file type for {$fileField}[{$i}]: {$files['type'][$i]}", 'ERROR');
            continue;
        }
        
        // Generate safe filename
        $fileInfo = pathinfo($files['name'][$i]);
        $safeFilename = $referenceNumber . '_' . $fileField . '_' . $i . '_' . time() . '.' . $fileInfo['extension'];
        $uploadPath = $config['upload_dir'] . $safeFilename;
        
        // Move uploaded file
        if (!move_uploaded_file($files['tmp_name'][$i], $uploadPath)) {
            logMessage("Failed to move uploaded file {$fileField}[{$i}] to {$uploadPath}", 'ERROR');
            continue;
        }
        
        logMessage("File uploaded successfully: {$uploadPath}", 'INFO');
        
        $uploadedFiles[] = [
            'document_type' => $fileField,
            'file_name' => $files['name'][$i],
            'file_path' => $uploadPath,
            'file_size' => $files['size'][$i],
            'mime_type' => $files['type'][$i]
        ];
    }
    
    return $uploadedFiles;
}

// Process customer information
function processCustomerInfo($data, $pdo) {
    $sql = "INSERT INTO customers (
                company_name, trading_as, registration_number, tax_number, 
                years_in_business, employees_count, company_type, business_category, 
                other_business_category
            ) VALUES (
                :company_name, :trading_as, :registration_number, :tax_number, 
                :years_in_business, :employees_count, :company_type, :business_category, 
                :other_business_category
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'company_name' => $data['companyName'],
        'trading_as' => $data['tradingAs'] ?? null,
        'registration_number' => $data['registrationNumber'],
        'tax_number' => $data['taxNumber'],
        'years_in_business' => $data['yearsInBusiness'],
        'employees_count' => $data['employees'],
        'company_type' => $data['companyType'],
        'business_category' => $data['businessCategory'],
        'other_business_category' => ($data['businessCategory'] === 'other') ? $data['otherBusinessCategory'] : null
    ]);
    
    return $pdo->lastInsertId();
}

// Process contact information
function processContactInfo($data, $customerId, $pdo) {
    $sql = "INSERT INTO contact_information (
                customer_id, business_address, country, post_code, main_phone, 
                invoice_email, primary_contact_name, contact_position, 
                contact_phone, contact_email
            ) VALUES (
                :customer_id, :business_address, :country, :post_code, :main_phone, 
                :invoice_email, :primary_contact_name, :contact_position, 
                :contact_phone, :contact_email
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'customer_id' => $customerId,
        'business_address' => $data['businessAddress'],
        'country' => $data['country'],
        'post_code' => $data['postCode'] ?? null,
        'main_phone' => $data['mainPhone'],
        'invoice_email' => $data['invoiceEmail'],
        'primary_contact_name' => $data['primaryContact'],
        'contact_position' => $data['position'],
        'contact_phone' => $data['contactPhone'],
        'contact_email' => $data['contactEmail']
    ]);
    
    return $pdo->lastInsertId();
}

// Process credit request
function processCreditRequest($data, $customerId, $assessmentType, $referenceNumber, $pdo) {
    $sql = "INSERT INTO credit_requests (
                customer_id, assessment_type, reference_number, 
                requested_credit_limit, requested_payment_terms, 
                monthly_purchase_volume, products_of_interest, status
            ) VALUES (
                :customer_id, :assessment_type, :reference_number, 
                :requested_credit_limit, :requested_payment_terms, 
                :monthly_purchase_volume, :products_of_interest, :status
            )";
    
    $paymentTerms = $data['requestedPaymentTerms'];
    if ($paymentTerms === 'other') {
        $paymentTerms = $data['otherPaymentTerms'];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'customer_id' => $customerId,
        'assessment_type' => $assessmentType,
        'reference_number' => $referenceNumber,
        'requested_credit_limit' => $data['requestedCreditLimit'],
        'requested_payment_terms' => $paymentTerms,
        'monthly_purchase_volume' => $data['monthlyPurchaseVolume'],
        'products_of_interest' => $data['productsOfInterest'],
        'status' => 'pending'
    ]);
    
    return $pdo->lastInsertId();
}

// Process financial information (for complete and partial forms)
function processFinancialInfo($data, $requestId, $pdo) {
    $sql = "INSERT INTO financial_information (
                request_id, current_ratio, quick_ratio, debt_to_equity_ratio, 
                gross_profit_margin, net_profit_margin, return_on_assets, 
                return_on_equity, inventory_turnover, accounts_receivable_turnover, 
                days_sales_outstanding, revenue_growth_rate, profit_growth_rate, 
                cash_flow_trend, working_capital_trend, financial_statements_type, 
                accounting_standards, audit_opinion, auditor_firm, 
                significant_notes, qualifications_concerns
            ) VALUES (
                :request_id, :current_ratio, :quick_ratio, :debt_to_equity_ratio, 
                :gross_profit_margin, :net_profit_margin, :return_on_assets, 
                :return_on_equity, :inventory_turnover, :accounts_receivable_turnover, 
                :days_sales_outstanding, :revenue_growth_rate, :profit_growth_rate, 
                :cash_flow_trend, :working_capital_trend, :financial_statements_type, 
                :accounting_standards, :audit_opinion, :auditor_firm, 
                :significant_notes, :qualifications_concerns
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'current_ratio' => $data['currentRatio'] ?? null,
        'quick_ratio' => $data['quickRatio'] ?? null,
        'debt_to_equity_ratio' => $data['debtToEquityRatio'] ?? null,
        'gross_profit_margin' => $data['grossProfitMargin'] ?? null,
        'net_profit_margin' => $data['netProfitMargin'] ?? null,
        'return_on_assets' => $data['returnOnAssets'] ?? null,
        'return_on_equity' => $data['returnOnEquity'] ?? null,
        'inventory_turnover' => $data['inventoryTurnover'] ?? null,
        'accounts_receivable_turnover' => $data['accountsReceivableTurnover'] ?? null,
        'days_sales_outstanding' => $data['daysSalesOutstanding'] ?? null,
        'revenue_growth_rate' => $data['revenueGrowthRate'] ?? null,
        'profit_growth_rate' => $data['profitGrowthRate'] ?? null,
        'cash_flow_trend' => $data['cashFlowTrend'] ?? null,
        'working_capital_trend' => $data['workingCapitalTrend'] ?? null,
        'financial_statements_type' => $data['financialStatementsType'] ?? null,
        'accounting_standards' => $data['accountingStandards'] ?? null,
        'audit_opinion' => $data['auditOpinion'] ?? null,
        'auditor_firm' => $data['auditorFirm'] ?? null,
        'significant_notes' => $data['significantNotes'] ?? null,
        'qualifications_concerns' => $data['qualificationsConcerns'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process bank statement analysis (for complete and partial forms)
function processBankStatementAnalysis($data, $requestId, $pdo) {
    $sql = "INSERT INTO bank_statement_analysis (
                request_id, bank_name, period_from, period_to, 
                average_monthly_balance, frequency_of_deposits, frequency_of_withdrawals, 
                overdraft_instances, returned_checks, cash_flow_pattern, 
                seasonality_observed, major_transactions
            ) VALUES (
                :request_id, :bank_name, :period_from, :period_to, 
                :average_monthly_balance, :frequency_of_deposits, :frequency_of_withdrawals, 
                :overdraft_instances, :returned_checks, :cash_flow_pattern, 
                :seasonality_observed, :major_transactions
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'bank_name' => $data['bankName'] ?? null,
        'period_from' => $data['periodFrom'] ?? null,
        'period_to' => $data['periodTo'] ?? null,
        'average_monthly_balance' => $data['averageMonthlyBalance'] ?? null,
        'frequency_of_deposits' => $data['frequencyOfDeposits'] ?? null,
        'frequency_of_withdrawals' => $data['frequencyOfWithdrawals'] ?? null,
        'overdraft_instances' => $data['overdraftInstances'] ?? null,
        'returned_checks' => $data['returnedChecks'] ?? null,
        'cash_flow_pattern' => $data['cashFlowPattern'] ?? null,
        'seasonality_observed' => $data['seasonalityObserved'] ?? null,
        'major_transactions' => $data['majorTransactions'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process business verification (for minimal form)
function processBusinessVerification($data, $requestId, $pdo) {
    $sql = "INSERT INTO business_verification (
                request_id, registration_authority, verification_date, verification_method, 
                registration_status, other_registration_status, registration_date, 
                expiration_date, verification_notes, site_visit_conducted, 
                visit_date, conducted_by, business_premises, premises_type, 
                other_premises_type, premises_size, staff_observed, 
                equipment_observed, activity_level, business_verification_notes
            ) VALUES (
                :request_id, :registration_authority, :verification_date, :verification_method, 
                :registration_status, :other_registration_status, :registration_date, 
                :expiration_date, :verification_notes, :site_visit_conducted, 
                :visit_date, :conducted_by, :business_premises, :premises_type, 
                :other_premises_type, :premises_size, :staff_observed, 
                :equipment_observed, :activity_level, :business_verification_notes
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'registration_authority' => $data['registrationAuthority'] ?? null,
        'verification_date' => $data['verificationDate'] ?? null,
        'verification_method' => $data['verificationMethod'] ?? null,
        'registration_status' => $data['registrationStatus'] ?? null,
        'other_registration_status' => ($data['registrationStatus'] === 'other') ? $data['otherRegistrationStatus'] : null,
        'registration_date' => $data['registrationDate'] ?? null,
        'expiration_date' => $data['expirationDate'] ?? null,
        'verification_notes' => $data['verificationNotes'] ?? null,
        'site_visit_conducted' => isset($data['siteVisitConducted']) && $data['siteVisitConducted'] === 'yes',
        'visit_date' => $data['visitDate'] ?? null,
        'conducted_by' => $data['conductedBy'] ?? null,
        'business_premises' => $data['businessPremises'] ?? null,
        'premises_type' => $data['premisesType'] ?? null,
        'other_premises_type' => ($data['premisesType'] === 'other') ? $data['otherPremisesType'] : null,
        'premises_size' => $data['premisesSize'] ?? null,
        'staff_observed' => $data['staffObserved'] ?? null,
        'equipment_observed' => $data['equipmentObserved'] ?? null,
        'activity_level' => $data['activityLevel'] ?? null,
        'business_verification_notes' => $data['businessVerificationNotes'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process market research (for minimal form)
function processMarketResearch($data, $requestId, $pdo) {
    $sql = "INSERT INTO market_research (
                request_id, market_share, years_in_market, market_growth, 
                competitive_pressure, barriers_to_entry, technology_adoption, 
                industry_outlook, website_quality, social_media_presence, 
                online_reviews, digital_marketing, ecommerce_capability, 
                online_reputation_score
            ) VALUES (
                :request_id, :market_share, :years_in_market, :market_growth, 
                :competitive_pressure, :barriers_to_entry, :technology_adoption, 
                :industry_outlook, :website_quality, :social_media_presence, 
                :online_reviews, :digital_marketing, :ecommerce_capability, 
                :online_reputation_score
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'market_share' => $data['marketShare'] ?? null,
        'years_in_market' => $data['yearsInMarket'] ?? null,
        'market_growth' => $data['marketGrowth'] ?? null,
        'competitive_pressure' => $data['competitivePressure'] ?? null,
        'barriers_to_entry' => $data['barriersToEntry'] ?? null,
        'technology_adoption' => $data['technologyAdoption'] ?? null,
        'industry_outlook' => $data['industryOutlook'] ?? null,
        'website_quality' => $data['website'] ?? null,
        'social_media_presence' => $data['socialMedia'] ?? null,
        'online_reviews' => $data['onlineReviews'] ?? null,
        'digital_marketing' => $data['digitalMarketing'] ?? null,
        'ecommerce_capability' => $data['ecommerce'] ?? null,
        'online_reputation_score' => $data['onlineReputationScore'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process public records check (for minimal form)
function processPublicRecordsCheck($data, $requestId, $pdo) {
    $sql = "INSERT INTO public_records_check (
                request_id, legal_actions, tax_liens, bankruptcy_history, 
                regulatory_issues, other_public_records
            ) VALUES (
                :request_id, :legal_actions, :tax_liens, :bankruptcy_history, 
                :regulatory_issues, :other_public_records
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'legal_actions' => $data['legalActions'] ?? null,
        'tax_liens' => $data['taxLiens'] ?? null,
        'bankruptcy_history' => $data['bankruptcyHistory'] ?? null,
        'regulatory_issues' => $data['regulatoryIssues'] ?? null,
        'other_public_records' => $data['otherPublicRecords'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process management assessment
function processManagementAssessment($data, $requestId, $pdo) {
    $sql = "INSERT INTO management_assessment (
                request_id, key_management_personnel, years_of_experience, 
                previous_business_success, educational_background, 
                industry_recognition, professional_associations, 
                public_profile, management_stability
            ) VALUES (
                :request_id, :key_management_personnel, :years_of_experience, 
                :previous_business_success, :educational_background, 
                :industry_recognition, :professional_associations, 
                :public_profile, :management_stability
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'key_management_personnel' => $data['keyManagementPersonnel'] ?? null,
        'years_of_experience' => $data['yearsOfExperience'] ?? null,
        'previous_business_success' => $data['previousBusinessSuccess'] ?? null,
        'educational_background' => $data['educationalBackground'] ?? null,
        'industry_recognition' => $data['industryRecognition'] ?? null,
        'professional_associations' => $data['professionalAssociations'] ?? null,
        'public_profile' => $data['publicProfile'] ?? null,
        'management_stability' => $data['managementStability'] ?? null
    ]);
    
    return $pdo->lastInsertId();
}

// Process trade references
function processTradeReferences($data, $requestId, $pdo) {
    // Process reference 1
    if (!empty($data['ref1Company'])) {
        $sql = "INSERT INTO trade_references (
                    request_id, company_name, contact_person, relationship_duration, 
                    credit_limit, payment_terms, payment_history, relationship_nature, comments
                ) VALUES (
                    :request_id, :company_name, :contact_person, :relationship_duration, 
                    :credit_limit, :payment_terms, :payment_history, :relationship_nature, :comments
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'request_id' => $requestId,
            'company_name' => $data['ref1Company'],
            'contact_person' => $data['ref1ContactPerson'],
            'relationship_duration' => $data['ref1Duration'],
            'credit_limit' => $data['ref1CreditLimit'] ?? null,
            'payment_terms' => $data['ref1PaymentTerms'] ?? null,
            'payment_history' => $data['ref1PaymentHistory'] ?? null,
            'relationship_nature' => $data['ref1Relationship'] ?? null,
            'comments' => $data['ref1Comments'] ?? null
        ]);
    }
    
    // Process reference 2
    if (!empty($data['ref2Company'])) {
        $sql = "INSERT INTO trade_references (
                    request_id, company_name, contact_person, relationship_duration, 
                    credit_limit, payment_terms, payment_history, relationship_nature, comments
                ) VALUES (
                    :request_id, :company_name, :contact_person, :relationship_duration, 
                    :credit_limit, :payment_terms, :payment_history, :relationship_nature, :comments
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'request_id' => $requestId,
            'company_name' => $data['ref2Company'],
            'contact_person' => $data['ref2ContactPerson'],
            'relationship_duration' => $data['ref2Duration'],
            'credit_limit' => $data['ref2CreditLimit'] ?? null,
            'payment_terms' => $data['ref2PaymentTerms'] ?? null,
            'payment_history' => $data['ref2PaymentHistory'] ?? null,
            'relationship_nature' => $data['ref2Relationship'] ?? null,
            'comments' => $data['ref2Comments'] ?? null
        ]);
    }
    
    // Process reference 3 if exists
    if (!empty($data['ref3Company'])) {
        $sql = "INSERT INTO trade_references (
                    request_id, company_name, contact_person, relationship_duration, 
                    credit_limit, payment_terms, payment_history, relationship_nature, comments
                ) VALUES (
                    :request_id, :company_name, :contact_person, :relationship_duration, 
                    :credit_limit, :payment_terms, :payment_history, :relationship_nature, :comments
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'request_id' => $requestId,
            'company_name' => $data['ref3Company'],
            'contact_person' => $data['ref3ContactPerson'],
            'relationship_duration' => $data['ref3Duration'],
            'credit_limit' => $data['ref3CreditLimit'] ?? null,
            'payment_terms' => $data['ref3PaymentTerms'] ?? null,
            'payment_history' => $data['ref3PaymentHistory'] ?? null,
            'relationship_nature' => $data['ref3Relationship'] ?? null,
            'comments' => $data['ref3Comments'] ?? null
        ]);
    }
}

// Process uploaded documents
function processUploadedDocuments($uploadedFiles, $requestId, $pdo) {
    foreach ($uploadedFiles as $file) {
        $sql = "INSERT INTO uploaded_documents (
                    request_id, document_type, file_name, file_path, file_size, mime_type
                ) VALUES (
                    :request_id, :document_type, :file_name, :file_path, :file_size, :mime_type
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'request_id' => $requestId,
            'document_type' => $file['document_type'],
            'file_name' => $file['file_name'],
            'file_path' => $file['file_path'],
            'file_size' => $file['file_size'],
            'mime_type' => $file['mime_type']
        ]);
    }
}

// Process additional information requests
function processAdditionalInfoRequests($data, $requestId, $pdo) {
    if (!empty($data['informationRequested'])) {
        $sql = "INSERT INTO additional_information_requests (
                    request_id, information_requested, date_requested, 
                    response_received, followup_required
                ) VALUES (
                    :request_id, :information_requested, :date_requested, 
                    :response_received, :followup_required
                )";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'request_id' => $requestId,
            'information_requested' => $data['informationRequested'],
            'date_requested' => $data['dateRequested'] ?? date('Y-m-d'),
            'response_received' => $data['responseReceived'] ?? 'no',
            'followup_required' => isset($data['followupRequired']) && $data['followupRequired'] === 'yes'
        ]);
    }
}

// Add audit log entry
function addAuditLog($requestId, $action, $pdo) {
    $sql = "INSERT INTO audit_log (
                request_id, user_id, action_type, action_details, ip_address
            ) VALUES (
                :request_id, :user_id, :action_type, :action_details, :ip_address
            )";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'request_id' => $requestId,
        'user_id' => 'web_form', // In a real system, this would be the logged-in user
        'action_type' => 'form_submission',
        'action_details' => $action,
        'ip_address' => $_SERVER['REMOTE_ADDR']
    ]);
}

// Send email notification (placeholder function)
function sendEmailNotification($referenceNumber, $data) {
    // In a real implementation, this would send an email
    // For now, we just log it
    logMessage("Email notification would be sent for reference number: $referenceNumber", 'INFO');
    return true;
}

// Send JSON response
function sendResponse($response) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// Main processing logic
try {
    // Check if this is a POST request
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $response['message'] = "Invalid request method";
        sendResponse($response);
    }
    
    // Determine form type
    $formType = '';
    if (isset($_POST['formType'])) {
        $formType = $_POST['formType'];
    } else {
        // Try to determine form type from the form ID
        if (isset($_POST['formId'])) {
            if ($_POST['formId'] === 'completeInfoCreditForm') {
                $formType = 'complete';
            } elseif ($_POST['formId'] === 'partialInfoCreditForm') {
                $formType = 'partial';
            } elseif ($_POST['formId'] === 'minimalInfoCreditForm') {
                $formType = 'minimal';
            }
        }
    }
    
    if (empty($formType)) {
        $response['message'] = "Form type not specified";
        sendResponse($response);
    }
    
    // Sanitize all input data
    $data = sanitizeInput($_POST);
    
    // Validate required fields (common to all forms)
    $requiredFields = [
        'companyName', 'registrationNumber', 'taxNumber', 'yearsInBusiness', 
        'employees', 'companyType', 'businessCategory', 'businessAddress', 
        'country', 'mainPhone', 'invoiceEmail', 'primaryContact', 'position', 
        'contactPhone', 'contactEmail', 'requestedCreditLimit', 
        'requestedPaymentTerms', 'monthlyPurchaseVolume', 'productsOfInterest',
        'termsAgreement'
    ];
    
    $errors = validateRequiredFields($data, $requiredFields);
    
    if (!empty($errors)) {
        $response['errors'] = $errors;
        $response['message'] = "Please fill in all required fields";
        sendResponse($response);
    }
    
    // Generate reference number
    $referenceNumber = generateReferenceNumber();
    
    // Connect to database
    $pdo = connectDB();
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Process customer information
        $customerId = processCustomerInfo($data, $pdo);
        
        // Process contact information
        $contactId = processContactInfo($data, $customerId, $pdo);
        
        // Process credit request
        $requestId = processCreditRequest($data, $customerId, $formType, $referenceNumber, $pdo);
        
        // Process form-specific information
        if ($formType === 'complete' || $formType === 'partial') {
            // Process financial information
            processFinancialInfo($data, $requestId, $pdo);
            
            // Process bank statement analysis
            processBankStatementAnalysis($data, $requestId, $pdo);
        }
        
        if ($formType === 'minimal') {
            // Process business verification
            processBusinessVerification($data, $requestId, $pdo);
            
            // Process market research
            processMarketResearch($data, $requestId, $pdo);
            
            // Process public records check
            processPublicRecordsCheck($data, $requestId, $pdo);
        }
        
        // Process management assessment (common to all forms)
        processManagementAssessment($data, $requestId, $pdo);
        
        // Process trade references (common to all forms)
        processTradeReferences($data, $requestId, $pdo);
        
        // Process additional information requests (if any)
        processAdditionalInfoRequests($data, $requestId, $pdo);
        
        // Process file uploads
        $uploadedFiles = [];
        
        // Common document types for all forms
        $singleFileFields = [
            'businessRegistration', 'taxRegistration', 'companyProfile', 
            'personalGuarantee', 'siteVisitReport', 'marketResearchReport'
        ];
        
        $multipleFileFields = [
            'managementProfiles', 'referenceLetters', 'otherDocuments'
        ];
        
        // Form-specific document types
        if ($formType === 'complete' || $formType === 'partial') {
            $singleFileFields[] = 'auditOpinionDocument';
            $multipleFileFields[] = 'financialStatements';
            $multipleFileFields[] = 'bankStatements';
            $multipleFileFields[] = 'tradeReferences';
        }
        
        // Process single file uploads
        foreach ($singleFileFields as $field) {
            $file = handleFileUpload($field, $referenceNumber);
            if ($file) {
                $uploadedFiles[] = $file;
            }
        }
        
        // Process multiple file uploads
        foreach ($multipleFileFields as $field) {
            $files = handleMultipleFileUploads($field, $referenceNumber);
            if (!empty($files)) {
                $uploadedFiles = array_merge($uploadedFiles, $files);
            }
        }
        
        // Store uploaded documents in database
        if (!empty($uploadedFiles)) {
            processUploadedDocuments($uploadedFiles, $requestId, $pdo);
        }
        
        // Add audit log entry
        addAuditLog($requestId, "Form submission for $formType information assessment", $pdo);
        
        // Commit transaction
        $pdo->commit();
        
        // Send email notification
        sendEmailNotification($referenceNumber, $data);
        
        // Prepare success response
        $response['success'] = true;
        $response['message'] = "Form submitted successfully";
        $response['referenceNumber'] = $referenceNumber;
        
        // Log success
        logMessage("Form submission successful. Reference Number: $referenceNumber", 'INFO');
        
        // Send response
        sendResponse($response);
        
    } catch (Exception $e) {
        // Roll back transaction on error
        $pdo->rollBack();
        
        logMessage("Error processing form: " . $e->getMessage(), 'ERROR');
        
        $response['message'] = "An error occurred while processing your submission. Please try again later.";
        sendResponse($response);
    }
    
} catch (Exception $e) {
    logMessage("Critical error: " . $e->getMessage(), 'ERROR');
    
    $response['message'] = "A system error occurred. Please try again later.";
    sendResponse($response);
}
?>
