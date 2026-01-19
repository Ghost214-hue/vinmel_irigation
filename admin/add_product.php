<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';
require_once 'security.php'; // Include security functions

// Set security headers
setSecurityHeaders();

// Initialize secure session
initSecureSession();

// Generate CSRF token for forms
$csrf_token = generateCSRFToken();

// Check if user is logged in and is admin
checkAuth();

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';
$edit_mode = false;
$edit_product = null;
$category_message = '';
$category_error = '';
$selected_period_id = isset($_GET['period_id']) ? intval($_GET['period_id']) : null;

// Rate limiting check for form submissions
$rateLimitKey = "product_form_" . $user_id;
if (!checkRateLimit($rateLimitKey, 10, 60)) {
    $error = "Too many requests. Please wait a moment before trying again.";
    securityLog("Rate limit exceeded for product form submissions", "WARNING", $user_id);
}

/**
 * Check if current user can modify data based on period status
 */
function canModifyData($user_id, $db) {
    // Admin can always modify data regardless of period status
    // but we'll check for warnings
    $current_period = getCurrentTimePeriod($db);
    
    if (!$current_period) {
        return ['allowed' => true, 'warning' => 'No active time period!', 'message' => 'No active time period! Please create or activate a period first.'];
    }
    
    // Check if current period is locked
    if ($current_period['is_locked']) {
        return [
            'allowed' => true, // Admin can override
            'warning' => 'Current period is locked!', 
            'message' => 'Current period is locked! Modifications will use admin override.'
        ];
    }
    
    return ['allowed' => true, 'warning' => '', 'message' => ''];
}

/**
 * Check if period is locked (Admin can override for viewing)
 */
function isPeriodLockedForAdmin($period_id, $db) {
    if (!$period_id) return false;
    
    $sql = "SELECT is_locked FROM time_periods WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $period_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $period = $result->fetch_assoc();
    
    return $period && $period['is_locked'] == 1;
}

/**
 * Check if product can be modified (Admin version - can override locks)
 */
function canModifyProductAdmin($product_id, $user_id, $db) {
    // Get product period info
    $sql = "SELECT period_id, created_by FROM products WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();
    
    if (!$product) {
        return ['allowed' => false, 'message' => 'Product not found!'];
    }
    
    // Admin can modify any product, but check period lock status for warning
    if ($product['period_id'] && isPeriodLockedForAdmin($product['period_id'], $db)) {
        return [
            'allowed' => true, // Admin can override
            'message' => 'Warning: This period is locked. Modifications are restricted.',
            'locked' => true
        ];
    }
    
    return ['allowed' => true, 'message' => '', 'locked' => false];
}

/**
 * Validate form submission with period security (Admin version)
 */
function validateFormSubmissionAdmin($user_id, $db, $period_id = null) {
    // Admin can always submit forms, but check period status
    if ($period_id && isPeriodLockedForAdmin($period_id, $db)) {
        return [
            'allowed' => true, // Admin can override
            'message' => 'Warning: Period is locked. Changes may be restricted.',
            'locked' => true
        ];
    }
    
    return ['allowed' => true, 'message' => '', 'locked' => false];
}

/**
 * Display period security warning
 */
function displayPeriodSecurityWarning($user_id, $db) {
    $period_check = canModifyData($user_id, $db);
    if (!empty($period_check['warning'])) {
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">';
        echo '<i class="fas fa-exclamation-triangle me-2"></i>';
        echo '<strong>Admin Override:</strong> ' . $period_check['message'];
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }
}

// Get current period for tracking - FIXED CALL
$current_period = getCurrentTimePeriod($db);
$period_check = canModifyData($user_id, $db);

// Debug current period
error_log("Current Period: " . print_r($current_period, true));

/* -------------------------------------------------------
   DATABASE SCHEMA CHECK FUNCTIONS
-------------------------------------------------------- */

/**
 * Check if period tracking columns exist
 */
function checkPeriodColumnsExist($db) {
    $sql = "SHOW COLUMNS FROM products LIKE 'period_id'";
    $result = $db->query($sql);
    return $result && $result->num_rows > 0;
}

// Check if period tracking is enabled
$period_tracking_enabled = checkPeriodColumnsExist($db);

/* -------------------------------------------------------
   AUTO-GENERATE SKU FUNCTION
-------------------------------------------------------- */

/**
 * Generate unique SKU for product
 */
function generateUniqueSKU($name, $category_id, $db) {
    // Get category abbreviation
    $category_sql = "SELECT name FROM categories WHERE id = ?";
    $category_stmt = $db->prepare($category_sql);
    $category_stmt->bind_param("i", $category_id);
    $category_stmt->execute();
    $category_result = $category_stmt->get_result();
    $category = $category_result->fetch_assoc();
    
    $category_abbr = '';
    if ($category) {
        $words = explode(' ', $category['name']);
        foreach ($words as $word) {
            $category_abbr .= strtoupper(substr($word, 0, 1));
        }
        $category_abbr = substr($category_abbr, 0, 3);
    } else {
        $category_abbr = 'GEN';
    }
    
    // Get product name abbreviation
    $name_words = explode(' ', $name);
    $name_abbr = '';
    foreach ($name_words as $word) {
        $name_abbr .= strtoupper(substr($word, 0, 1));
    }
    $name_abbr = substr($name_abbr, 0, 3);
    
    // Generate base SKU
    $base_sku = $category_abbr . '-' . $name_abbr;
    
    // Check if SKU exists and find next available number
    $counter = 1;
    $sku = $base_sku . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
    
    while (true) {
        $check_sql = "SELECT id FROM products WHERE sku = ?";
        $check_stmt = $db->prepare($check_sql);
        $check_stmt->bind_param("s", $sku);
        $check_stmt->execute();
        
        if ($check_stmt->get_result()->num_rows === 0) {
            break; // SKU is unique
        }
        
        $counter++;
        $sku = $base_sku . '-' . str_pad($counter, 3, '0', STR_PAD_LEFT);
        
        // Safety break
        if ($counter > 999) {
            $sku = $base_sku . '-' . time(); // Fallback with timestamp
            break;
        }
    }
    
    return $sku;
}

/* -------------------------------------------------------
   CATEGORIES HANDLING
-------------------------------------------------------- */

// Fetch categories from database
function fetchCategories($db) {
    $categories = [];
    $category_sql = "SELECT id, name, type, parent_id FROM categories ORDER BY name";
    $category_result = $db->query($category_sql);

    if ($category_result && $category_result->num_rows > 0) {
        while ($row = $category_result->fetch_assoc()) {
            $categories[$row['id']] = $row['name'];
        }
    }
    return $categories;
}

// Handle category creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $category_error = "Security token validation failed. Please try again.";
        securityLog("CSRF token validation failed for category creation", "SECURITY", $user_id);
    } else {
        $category_name = sanitizeInput(trim($_POST['category_name']), 'string');
        $category_type = sanitizeInput(trim($_POST['category_type']), 'string');
        $parent_id = !empty($_POST['parent_id']) ? intval($_POST['parent_id']) : NULL;

        // Input validation rules
        $validationRules = [
            'category_name' => 'required|min:2|max:100',
            'category_type' => 'max:10'
        ];
        
        $validationErrors = validateInput($_POST, $validationRules);
        
        if (!empty($validationErrors)) {
            $category_error = implode('<br>', $validationErrors);
        } else {
            // Check if category already exists
            $check_sql = "SELECT id FROM categories WHERE name = ?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->bind_param("s", $category_name);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                $category_error = "Category '$category_name' already exists!";
            } else {
                // Insert new category
                $insert_sql = "INSERT INTO categories (name, type, parent_id, created_at) VALUES (?, ?, ?, NOW())";
                $insert_stmt = $db->prepare($insert_sql);
                
                $safe_type = substr($category_type, 0, 10);
                $insert_stmt->bind_param("ssi", $category_name, $safe_type, $parent_id);
                
                if ($insert_stmt->execute()) {
                    $category_message = "Category '$category_name' added successfully!";
                    $_POST = array(); // Clear all POST data to prevent resubmission
                    securityLog("Category created: $category_name", "INFO", $user_id);
                } else {
                    $category_error = "Failed to add category: " . $db->error;
                    securityLog("Failed to create category: " . $db->error, "ERROR", $user_id);
                }
            }
        }
    }
}

// Handle category deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $category_error = "Security token validation failed. Please try again.";
        securityLog("CSRF token validation failed for category deletion", "SECURITY", $user_id);
    } else {
        $category_id = intval($_POST['category_id']);
        
        // Validate integer
        if (!validateInt($category_id, 1)) {
            $category_error = "Invalid category ID";
        } else {
            // Check if category is used by any products
            $check_sql = "SELECT COUNT(*) as product_count FROM products WHERE category_id = ?";
            $check_stmt = $db->prepare($check_sql);
            $check_stmt->bind_param("i", $category_id);
            $check_stmt->execute();
            $result = $check_stmt->get_result();
            $product_count = $result->fetch_assoc()['product_count'];
            
            if ($product_count > 0) {
                $category_error = "Cannot delete category: It is used by $product_count product(s).";
            } else {
                $delete_sql = "DELETE FROM categories WHERE id = ?";
                $delete_stmt = $db->prepare($delete_sql);
                $delete_stmt->bind_param("i", $category_id);
                
                if ($delete_stmt->execute()) {
                    $category_message = "Category deleted successfully!";
                    securityLog("Category deleted ID: $category_id", "INFO", $user_id);
                } else {
                    $category_error = "Failed to delete category: " . $db->error;
                    securityLog("Failed to delete category ID: $category_id - " . $db->error, "ERROR", $user_id);
                }
            }
        }
    }
}

// Fetch current categories
$categories = fetchCategories($db);

// If no categories exist, create default ones
if (empty($categories)) {
    $default_categories = [
        'Irrigation Equipment',
        'Pipes & Fittings',
        'Sprinklers',
        'Pumps',
        'Controllers',
        'Fertilizers',
        'Tools',
        'Other'
    ];
    
    foreach ($default_categories as $name) {
        $insert_sql = "INSERT INTO categories (name, type) VALUES (?, 'product')";
        $insert_stmt = $db->prepare($insert_sql);
        $insert_stmt->bind_param("s", $name);
        $insert_stmt->execute();
    }
    
    $categories = fetchCategories($db);
}

/* -------------------------------------------------------
   FETCH ALL PERIODS FOR FILTERING - FIXED QUERY
-------------------------------------------------------- */

$periods = [];
if ($period_tracking_enabled) {
    // Fixed query to match your database structure
    $period_sql = "SELECT id, period_name, year, month, start_date, is_active, end_date, is_locked 
                   FROM time_periods 
                   ORDER BY start_date DESC, year DESC, month DESC";
    $period_result = $db->query($period_sql);
    if ($period_result) {
        while ($period = $period_result->fetch_assoc()) {
            $periods[$period['id']] = $period;
        }
    }
}

/* -------------------------------------------------------
   HANDLE PRODUCT FORM SUBMISSIONS - ADMIN VERSION WITH RESTRICTIONS
-------------------------------------------------------- */

// Handle product creation/update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['add_product']) || isset($_POST['update_product']))) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security token validation failed. Please try again.";
        securityLog("CSRF token validation failed for product form", "SECURITY", $user_id);
    } else {
        // Sanitize all inputs
        $name = sanitizeInput(trim($_POST['name']), 'string');
        $sku = sanitizeInput(trim($_POST['sku']), 'string');
        $category_id = intval($_POST['category_id']);
        $cost_price = floatval($_POST['cost_price']);
        $selling_price = floatval($_POST['selling_price']);
        $stock_quantity = intval($_POST['stock_quantity']);
        $min_stock = intval($_POST['min_stock']);
        $supplier = sanitizeInput(trim($_POST['supplier']), 'string');
        $description = sanitizeInput(trim($_POST['description']), 'string');
        $created_by_user = !empty($_POST['created_by']) ? intval($_POST['created_by']) : $user_id;
        $is_update = isset($_POST['update_product']);
        $product_id = $is_update ? intval($_POST['product_id']) : null;

        // Input validation rules
        $validationRules = [
            'name' => 'required|min:2|max:200',
            'sku' => 'max:50',
            'category_id' => 'required|numeric',
            'cost_price' => 'required|numeric',
            'selling_price' => 'required|numeric',
            'stock_quantity' => 'required|numeric',
            'min_stock' => 'numeric',
            'supplier' => 'max:200',
            'description' => 'max:1000'
        ];
        
        $validationErrors = validateInput($_POST, $validationRules);
        
        // Custom validations
        if (!validateInt($category_id, 1)) {
            $validationErrors['category_id'] = 'Invalid category selected';
        }
        
        if (!validateFloat($cost_price, 0)) {
            $validationErrors['cost_price'] = 'Cost price must be a positive number';
        }
        
        if (!validateFloat($selling_price, 0)) {
            $validationErrors['selling_price'] = 'Selling price must be a positive number';
        }
        
        if (!validateInt($stock_quantity, 0)) {
            $validationErrors['stock_quantity'] = 'Stock quantity must be a positive integer';
        }
        
        if ($min_stock < 0) {
            $validationErrors['min_stock'] = 'Minimum stock cannot be negative';
        }

        if (!empty($validationErrors)) {
            $error = implode('<br>', $validationErrors);
        } elseif (empty($name)) {
            $error = "Product name is required!";
        } elseif (!array_key_exists($category_id, $categories)) {
            $error = "Invalid category selected!";
        } elseif ($selling_price < $cost_price) {
            $error = "Selling price cannot be less than cost price!";
        } else {
            // Check if we're updating existing product or adding to inventory
            if ($is_update) {
                // UPDATE EXISTING PRODUCT
                $product_check = canModifyProductAdmin($product_id, $user_id, $db);
                
                // Validate product_id
                if (!validateInt($product_id, 1)) {
                    $error = "Invalid product ID";
                } else {
                    // Check if SKU already exists (excluding current product)
                    $check_sql = "SELECT id FROM products WHERE sku = ? AND id != ?";
                    $check_stmt = $db->prepare($check_sql);
                    $check_stmt->bind_param("si", $sku, $product_id);
                    $check_stmt->execute();
                    $check_stmt->store_result();

                    if ($check_stmt->num_rows > 0) {
                        $error = "Another product with SKU '$sku' already exists!";
                    } else {
                        // Update product (admin can update any product)
                        $sql = "UPDATE products SET name = ?, sku = ?, category_id = ?, cost_price = ?, 
                                selling_price = ?, stock_quantity = ?, min_stock = ?, supplier = ?, 
                                description = ?, updated_at = NOW() 
                                WHERE id = ?";

                        $stmt = prepareStatement($db, $sql, [
                            $name, $sku, $category_id, $cost_price, 
                            $selling_price, $stock_quantity, $min_stock, $supplier, 
                            $description, $product_id
                        ], "ssiddiissi");

                        if ($stmt && $stmt->execute()) {
                            $message = "Product updated successfully!";
                            if ($product_check['locked']) {
                                $message .= " (Period was locked - admin override used)";
                            }
                            $edit_mode = false;
                            $edit_product = null;
                            securityLog("Product updated ID: $product_id - Name: $name", "INFO", $user_id);
                        } else {
                            $error = "Failed to update product: " . $db->error;
                            securityLog("Failed to update product ID: $product_id - " . $db->error, "ERROR", $user_id);
                        }
                    }
                }
            } else {
                // ADD NEW PRODUCT OR UPDATE INVENTORY
                // First check if product with same name and category exists
                $check_sql = "SELECT id, sku, stock_quantity FROM products WHERE name = ? AND category_id = ? AND supplier = ?";
                $check_stmt = $db->prepare($check_sql);
                $check_stmt->bind_param("sis", $name, $category_id, $supplier);
                $check_stmt->execute();
                $existing_product = $check_stmt->get_result()->fetch_assoc();
                
                if ($existing_product) {
                    // PRODUCT EXISTS - UPDATE INVENTORY
                    $new_stock_quantity = $existing_product['stock_quantity'] + $stock_quantity;
                    
                    $update_sql = "UPDATE products SET stock_quantity = ?, updated_at = NOW() WHERE id = ?";
                    $update_stmt = $db->prepare($update_sql);
                    $update_stmt->bind_param("ii", $new_stock_quantity, $existing_product['id']);
                    
                    if ($update_stmt->execute()) {
                        $message = "Inventory updated successfully! Stock for '{$name}' increased from {$existing_product['stock_quantity']} to {$new_stock_quantity}.";
                        $_POST = array(); // Clear form
                        securityLog("Inventory updated for product ID: {$existing_product['id']} - New stock: $new_stock_quantity", "INFO", $user_id);
                    } else {
                        $error = "Failed to update inventory: " . $db->error;
                        securityLog("Failed to update inventory for product ID: {$existing_product['id']} - " . $db->error, "ERROR", $user_id);
                    }
                } else {
                    // NEW PRODUCT - CREATE WITH AUTO-GENERATED SKU
                    if (empty($sku)) {
                        $sku = generateUniqueSKU($name, $category_id, $db);
                    } else {
                        // Check if manually entered SKU already exists
                        $check_sku_sql = "SELECT id FROM products WHERE sku = ?";
                        $check_sku_stmt = $db->prepare($check_sku_sql);
                        $check_sku_stmt->bind_param("s", $sku);
                        $check_sku_stmt->execute();
                        
                        if ($check_sku_stmt->get_result()->num_rows > 0) {
                            $error = "Product with SKU '$sku' already exists!";
                        }
                    }
                    
                    if (!$error) {
                        // Build SQL based on whether period tracking is enabled
                        if ($period_tracking_enabled && $current_period) {
                            $sql = "INSERT INTO products (name, sku, category_id, cost_price, selling_price, 
                                    stock_quantity, min_stock, supplier, description, created_by,
                                    period_id, period_year, period_month, added_date, added_by) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)";
                            
                            $params = [
                                $name, $sku, $category_id, $cost_price, 
                                $selling_price, $stock_quantity, $min_stock, $supplier, 
                                $description, $created_by_user, $current_period['id'], 
                                $current_period['year'], $current_period['month'], $user_id
                            ];
                            $types = "ssiddiissiiiii";
                        } else {
                            // Fallback without period tracking
                            $sql = "INSERT INTO products (name, sku, category_id, cost_price, selling_price, 
                                    stock_quantity, min_stock, supplier, description, created_by) 
                                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                            
                            $params = [
                                $name, $sku, $category_id, $cost_price, 
                                $selling_price, $stock_quantity, $min_stock, $supplier, 
                                $description, $created_by_user
                            ];
                            $types = "ssiddiissi";
                        }
                        
                        $stmt = prepareStatement($db, $sql, $params, $types);
                        
                        if ($stmt && $stmt->execute()) {
                            if ($period_tracking_enabled && $current_period) {
                                $message = "Product added successfully for period: " . $current_period['period_name'] . "! SKU: {$sku}";
                            } else {
                                $message = "Product added successfully! SKU: {$sku}";
                            }
                            // Clear form
                            $_POST = array();
                            $new_product_id = $stmt->insert_id;
                            securityLog("Product created ID: $new_product_id - Name: $name - SKU: $sku", "INFO", $user_id);
                        } else {
                            $error = "Failed to add product: " . $db->error;
                            securityLog("Failed to create product - " . $db->error, "ERROR", $user_id);
                        }
                    }
                }
            }
        }
    }
}

// Handle product deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_product'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $error = "Security token validation failed. Please try again.";
        securityLog("CSRF token validation failed for product deletion", "SECURITY", $user_id);
    } else {
        $product_id = intval($_POST['product_id']);
        
        // Validate product_id
        if (!validateInt($product_id, 1)) {
            $error = "Invalid product ID";
        } else {
            // Check if product can be modified (admin version)
            $product_check = canModifyProductAdmin($product_id, $user_id, $db);
            
            // Delete product (admin can delete any product)
            $sql = "DELETE FROM products WHERE id = ?";
            $stmt = prepareStatement($db, $sql, [$product_id], "i");
            
            if ($stmt && $stmt->execute()) {
                $message = "Product deleted successfully!";
                if ($product_check['locked']) {
                    $message .= " (Period was locked - admin override used)";
                }
                securityLog("Product deleted ID: $product_id", "INFO", $user_id);
            } else {
                $error = "Failed to delete product: " . $db->error;
                securityLog("Failed to delete product ID: $product_id - " . $db->error, "ERROR", $user_id);
            }
        }
    }
}

// Handle edit request
if (isset($_GET['edit'])) {
    $product_id = intval($_GET['edit']);
    
    // Validate product_id from URL
    if (!validateInt($product_id, 1)) {
        $error = "Invalid product ID";
        securityLog("Invalid product ID in URL: $product_id", "WARNING", $user_id);
    } else {
        // Get product (admin can view any product)
        $sql = "SELECT p.*, u.name as creator_name, u.email as creator_email 
                FROM products p 
                LEFT JOIN users u ON p.created_by = u.id 
                WHERE p.id = ?";
        $stmt = prepareStatement($db, $sql, [$product_id], "i");
        
        if ($stmt) {
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $edit_product = $result->fetch_assoc();
                $edit_mode = true;
            } else {
                $error = "Product not found!";
            }
        }
    }
}

// Handle cancel edit
if (isset($_GET['cancel_edit'])) {
    $edit_mode = false;
    $edit_product = null;
}

/* -------------------------------------------------------
   FETCH PRODUCTS AND CALCULATE STATISTICS - ADMIN VERSION
-------------------------------------------------------- */

// Get all users for filter
$users_sql = "SELECT id, name, email FROM users ORDER BY name";
$users_result = $db->query($users_sql);
$all_users = [];
while ($user = $users_result->fetch_assoc()) {
    $all_users[$user['id']] = [
        'id' => $user['id'],
        'name' => sanitizeInput($user['name'], 'string'),
        'email' => sanitizeInput($user['email'], 'string')
    ];
}

// Build query for products based on period filter
$where_conditions = [];
$params = [];
$param_types = "";

if ($selected_period_id) {
    if (validateInt($selected_period_id, 1)) {
        $where_conditions[] = "p.period_id = ?";
        $params[] = $selected_period_id;
        $param_types .= "i";
    }
}

if ($period_tracking_enabled) {
    $sql = "SELECT p.*, u.name as creator_name, u.email as creator_email,
                   tp.period_name, tp.is_locked as period_locked 
            FROM products p 
            LEFT JOIN users u ON p.created_by = u.id 
            LEFT JOIN time_periods tp ON p.period_id = tp.id";
} else {
    $sql = "SELECT p.*, u.name as creator_name, u.email as creator_email,
                   NULL as period_name, NULL as period_locked 
            FROM products p 
            LEFT JOIN users u ON p.created_by = u.id";
}

if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(" AND ", $where_conditions);
}

if ($period_tracking_enabled) {
    $sql .= " ORDER BY COALESCE(p.period_year, 0) DESC, COALESCE(p.period_month, 0) DESC, p.name ASC";
} else {
    $sql .= " ORDER BY p.name ASC";
}

$stmt = prepareStatement($db, $sql, $params, $param_types);
if ($stmt) {
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $products = [];
    $error = "Failed to fetch products: " . $db->error;
    securityLog("Failed to fetch products - " . $db->error, "ERROR", $user_id);
}

// Calculate statistics
$total_products = count($products);
$low_stock_count = 0;
$total_stock_value = 0;
$products_by_period = [];
$products_by_user = [];

foreach ($products as $product) {
    // Sanitize product data for display
    $product['name'] = sanitizeInput($product['name'], 'string');
    $product['sku'] = sanitizeInput($product['sku'], 'string');
    $product['supplier'] = sanitizeInput($product['supplier'], 'string');
    $product['description'] = sanitizeInput($product['description'], 'string');
    
    if (isset($product['creator_name'])) {
        $product['creator_name'] = sanitizeInput($product['creator_name'], 'string');
    }
    if (isset($product['creator_email'])) {
        $product['creator_email'] = sanitizeInput($product['creator_email'], 'string');
    }
    if (isset($product['period_name'])) {
        $product['period_name'] = sanitizeInput($product['period_name'], 'string');
    }
    
    if ($product['stock_quantity'] <= $product['min_stock']) {
        $low_stock_count++;
    }
    $total_stock_value += $product['stock_quantity'] * $product['cost_price'];
    
    // Group by period if available
    if ($period_tracking_enabled) {
        $period_key = $product['period_name'] ?: 'No Period';
        if (!isset($products_by_period[$period_key])) {
            $products_by_period[$period_key] = 0;
        }
        $products_by_period[$period_key]++;
    }
    
    // Group by user
    $user_key = $product['creator_name'] ?: 'User ' . $product['created_by'];
    if (!isset($products_by_user[$user_key])) {
        $products_by_user[$user_key] = 0;
    }
    $products_by_user[$user_key]++;
}

// If no period tracking, create a dummy entry
if (!$period_tracking_enabled) {
    $products_by_period['All Products'] = $total_products;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Products Management - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .admin-badge {
            background: linear-gradient(45deg, #dc3545, #c82333);
            color: white;
            font-size: 0.7rem;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 5px;
        }
        .user-filter {
            max-width: 200px;
        }
        .creator-info {
            font-size: 0.8rem;
            color: #6c757d;
        }
        .period-filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .sku-badge {
            background-color: #e9ecef;
            color: #495057;
            font-family: 'Courier New', monospace;
            font-size: 0.75rem;
        }
        .override-warning {
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
        .period-status-active {
            background-color: #28a745 !important;
            color: white !important;
        }
        .period-status-open {
            background-color: #17a2b8 !important;
            color: white !important;
        }
        .period-status-locked {
            background-color: #6c757d !important;
            color: white !important;
        }
    </style>
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-boxes me-2"></i>Admin Products Management
                            <span class="admin-badge">ADMIN</span>
                        </h1>
                        <p class="lead mb-0">Manage all products across all users and periods</p>
                    </div>
                    <div class="text-end">
                        <?php if ($period_tracking_enabled && $current_period): ?>
                            <div class="badge bg-primary fs-6 p-2">
                                <i class="fas fa-calendar me-1"></i>
                                Adding to: <?= htmlspecialchars($current_period['period_name']) ?>
                                <?= $current_period['is_locked'] ? ' (Locked - Admin Override)' : '' ?>
                            </div>
                        <?php elseif ($period_tracking_enabled): ?>
                            <div class="badge bg-warning fs-6 p-2">
                                <i class="fas fa-exclamation-triangle me-1"></i>
                                No Active Period
                            </div>
                        <?php else: ?>
                            <div class="badge bg-secondary fs-6 p-2">
                                <i class="fas fa-info-circle me-1"></i>
                                Basic Mode
                            </div>
                        <?php endif; ?>
                        <div class="btn-group ms-2">
                            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                                <i class="fas fa-plus me-2"></i>Add/Update Product
                            </button>
                            <button type="button" class="btn manage-categories-btn" data-bs-toggle="modal" data-bs-target="#categoryModal">
                                <i class="fas fa-tags me-2"></i>Manage Categories
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Database Update Alert -->
                <?php if (!$period_tracking_enabled): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="fas fa-database me-2"></i>
                        <strong>Period Tracking Not Enabled</strong> - To enable period tracking for products, 
                        run the database update script.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Category Alerts -->
                <?php if ($category_message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($category_message) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($category_error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($category_error) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Period Security Warning -->
                <?php displayPeriodSecurityWarning($user_id, $db); ?>

                <!-- Debug Current Period Info -->
                <?php if ($current_period): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Active Period Detected:</strong> <?= htmlspecialchars($current_period['period_name']) ?> 
                        (Start: <?= date('M j, Y', strtotime($current_period['start_date'])) ?>, 
                        End: <?= date('M j, Y', strtotime($current_period['end_date'])) ?>,
                        Locked: <?= $current_period['is_locked'] ? 'Yes' : 'No' ?>)
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Period Filter Card -->
                <?php if ($period_tracking_enabled && !empty($periods)): ?>
                <div class="card period-filter-card mb-4">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <h5 class="card-title text-white mb-0">
                                    <i class="fas fa-filter me-2"></i>Filter by Period
                                </h5>
                                <p class="text-white-50 mb-0">View products from specific time periods</p>
                            </div>
                            <div class="col-md-6">
                                <form method="GET" class="d-flex gap-2">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <select name="period_id" class="form-select" onchange="this.form.submit()">
                                        <option value="">All Periods</option>
                                        <?php foreach ($periods as $period): 
                                            $status_class = '';
                                            if ($period['status'] == 'active') {
                                                $status_class = 'period-status-active';
                                            } elseif ($period['status'] == 'open') {
                                                $status_class = 'period-status-open';
                                            } elseif ($period['is_locked']) {
                                                $status_class = 'period-status-locked';
                                            }
                                        ?>
                                            <option value="<?= $period['id'] ?>" 
                                                <?= $selected_period_id == $period['id'] ? 'selected' : '' ?>
                                                class="<?= $status_class ?>">
                                                <?= htmlspecialchars($period['period_name']) ?>
                                                - <?= date('M j, Y', strtotime($period['start_date'])) ?> to <?= date('M j, Y', strtotime($period['end_date'])) ?>
                                                (<?= ucfirst($period['is_active']) ?><?= $period['is_locked'] ? ', Locked' : '' ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($selected_period_id): ?>
                                        <a href="?" class="btn btn-light">
                                            <i class="fas fa-times me-1"></i>Clear
                                        </a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Statistics Cards -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card card-primary h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Total Products</h5>
                                        <h2 class="display-6 fw-bold"><?= $total_products ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-primary">
                                        <i class="fas fa-boxes fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">
                                    <?php if ($selected_period_id && isset($periods[$selected_period_id])): ?>
                                        In <?= htmlspecialchars($periods[$selected_period_id]['period_name']) ?>
                                    <?php else: ?>
                                        Across all users
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-success h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Stock Value</h5>
                                        <h2 class="display-6 fw-bold">KSH <?= number_format($total_stock_value, 2) ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-success">
                                        <i class="fas fa-coins fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Total inventory value</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-warning h-100">
                            <div class="card-body">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <h5 class="card-title">Low Stock</h5>
                                        <h2 class="display-6 fw-bold"><?= $low_stock_count ?></h2>
                                    </div>
                                    <div class="icon-bg icon-bg-warning">
                                        <i class="fas fa-exclamation-triangle fa-lg"></i>
                                    </div>
                                </div>
                                <p class="card-text mb-0">Need restocking</p>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-3 mb-3">
                        <div class="card card-info h-100">
                            <div class="card-body">
                                <h5 class="card-title">Users Summary</h5>
                                <div class="period-summary">
                                    <?php foreach ($products_by_user as $user => $count): ?>
                                        <div class="d-flex justify-content-between small mb-1">
                                            <span><?= htmlspecialchars($user) ?></span>
                                            <strong><?= $count ?> products</strong>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Products Table -->
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>
                                    <?php if ($selected_period_id && isset($periods[$selected_period_id])): ?>
                                        Products in <?= htmlspecialchars($periods[$selected_period_id]['period_name']) ?>
                                    <?php else: ?>
                                        All Products (Admin View)
                                    <?php endif; ?>
                                </h5>
                                <div class="d-flex gap-2">
                                    <select id="userFilter" class="form-select form-select-sm user-filter">
                                        <option value="">All Users</option>
                                        <?php foreach ($all_users as $user): ?>
                                            <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <?php if ($period_tracking_enabled && !empty($products_by_period)): ?>
                                        <select id="periodFilter" class="form-select form-select-sm" style="max-width: 200px;">
                                            <option value="">All Periods</option>
                                            <?php foreach (array_keys($products_by_period) as $period): ?>
                                                <option value="<?= htmlspecialchars($period) ?>"><?= htmlspecialchars($period) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                    <input type="text" id="searchProducts" class="form-control form-control-sm" placeholder="Search products..." style="max-width: 200px;">
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($products)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="products-table">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>SKU</th>
                                                    <th>Creator</th>
                                                    <?php if ($period_tracking_enabled): ?>
                                                        <th>Period</th>
                                                    <?php endif; ?>
                                                    <th>Category</th>
                                                    <th>Cost Price</th>
                                                    <th>Selling Price</th>
                                                    <th>Stock</th>
                                                    <th>Status</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $product): 
                                                    $is_low_stock = $product['stock_quantity'] <= $product['min_stock'];
                                                    $is_locked = $period_tracking_enabled && ($product['is_locked'] || $product['period_locked']);
                                                    $profit_margin = $product['selling_price'] - $product['cost_price'];
                                                    $margin_percentage = $product['cost_price'] > 0 ? ($profit_margin / $product['cost_price']) * 100 : 0;
                                                    $category_name = $categories[$product['category_id']] ?? 'Unknown Category';
                                                ?>
                                                    <tr class="<?= $is_low_stock ? 'table-warning' : '' ?> <?= $is_locked ? 'table-secondary' : '' ?>" 
                                                        data-user="<?= $product['created_by'] ?>"
                                                        <?php if ($period_tracking_enabled): ?>
                                                            data-period="<?= htmlspecialchars($product['period_name'] ?: 'No Period') ?>"
                                                        <?php endif; ?>>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="product-icon bg-primary text-white rounded-circle d-flex align-items-center justify-content-center me-3">
                                                                    <i class="fas fa-box"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= htmlspecialchars($product['name']) ?></strong>
                                                                    <?php if ($is_locked): ?>
                                                                        <br><small class="text-danger"><i class="fas fa-lock me-1"></i>Locked Period</small>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($product['description'])): ?>
                                                                        <br><small class="text-muted"><?= htmlspecialchars(substr($product['description'], 0, 50)) ?>...</small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="sku-badge badge"><?= htmlspecialchars($product['sku']) ?></span>
                                                        </td>
                                                        <td>
                                                            <div class="creator-info">
                                                                <strong><?= htmlspecialchars($product['creator_name'] ?? 'Unknown User') ?></strong>
                                                                <?php if ($product['creator_email']): ?>
                                                                    <br><small><?= htmlspecialchars($product['creator_email']) ?></small>
                                                                <?php endif; ?>
                                                            </div>
                                                        </td>
                                                        <?php if ($period_tracking_enabled): ?>
                                                            <td>
                                                                <?php if ($product['period_name']): ?>
                                                                    <span class="badge bg-light text-dark">
                                                                        <i class="fas fa-calendar me-1"></i>
                                                                        <?= htmlspecialchars($product['period_name']) ?>
                                                                    </span>
                                                                    <?php if ($product['added_date']): ?>
                                                                        <br><small class="text-muted">Added: <?= date('M j, Y', strtotime($product['added_date'])) ?></small>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="badge bg-secondary">No Period</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        <?php endif; ?>
                                                        <td>
                                                            <span class="badge bg-info category-badge"><?= htmlspecialchars($category_name) ?></span>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['cost_price'], 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <strong class="text-success">KSH <?= number_format($product['selling_price'], 2) ?></strong>
                                                            <br><small class="text-muted"><?= number_format($margin_percentage, 1) ?>% margin</small>
                                                        </td>
                                                        <td>
                                                            <div class="progress mb-1" style="height: 8px;">
                                                                <?php 
                                                                $max_stock = max($product['stock_quantity'], $product['min_stock'] * 2);
                                                                $progress = $max_stock > 0 ? ($product['stock_quantity'] / $max_stock) * 100 : 0;
                                                                $progress_class = $is_low_stock ? 'bg-danger' : ($progress < 50 ? 'bg-warning' : 'bg-success');
                                                                ?>
                                                                <div class="progress-bar <?= $progress_class ?>" style="width: <?= $progress ?>%"></div>
                                                            </div>
                                                            <div class="d-flex justify-content-between">
                                                                <span class="<?= $is_low_stock ? 'text-danger fw-bold' : '' ?>">
                                                                    <?= $product['stock_quantity'] ?>
                                                                </span>
                                                                <small class="text-muted">min: <?= $product['min_stock'] ?></small>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <?php if ($is_locked): ?>
                                                                <span class="badge bg-secondary">
                                                                    <i class="fas fa-lock me-1"></i>Locked
                                                                </span>
                                                            <?php elseif ($is_low_stock): ?>
                                                                <span class="badge bg-danger">
                                                                    <i class="fas fa-exclamation-triangle me-1"></i>Low Stock
                                                                </span>
                                                            <?php elseif ($product['stock_quantity'] == 0): ?>
                                                                <span class="badge bg-secondary">Out of Stock</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">In Stock</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <div class="btn-group btn-group-sm">
                                                                <a href="?edit=<?= $product['id'] ?>&csrf_token=<?= $csrf_token ?>" class="btn btn-outline-primary" 
                                                                   title="<?= $is_locked ? 'Edit (Admin Override)' : 'Edit' ?>">
                                                                    <i class="fas fa-edit"></i>
                                                                </a>
                                                                <form method="POST" class="d-inline" 
                                                                      onsubmit="return confirm('<?= $is_locked ? 'This period is locked. Are you sure you want to delete this product? (Admin Override)' : 'Are you sure you want to delete this product?' ?>')">
                                                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                                    <button type="submit" name="delete_product" class="btn btn-outline-danger"
                                                                            title="<?= $is_locked ? 'Delete (Admin Override)' : 'Delete' ?>">
                                                                        <i class="fas fa-trash"></i>
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                                        <h4 class="text-muted">No Products Found</h4>
                                        <p class="text-muted">
                                            <?php if ($selected_period_id): ?>
                                                No products found for the selected period.
                                            <?php else: ?>
                                                No products have been added by any users yet.
                                            <?php endif; ?>
                                        </p>
                                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#productModal">
                                            <i class="fas fa-plus me-2"></i>Add First Product
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Product Modal -->
    <div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="productModalLabel">
                        <i class="fas <?= $edit_mode ? 'fa-edit' : 'fa-plus' ?> me-2"></i>
                        <?= $edit_mode ? 'Edit Product' : 'Add/Update Product' ?>
                        <?php if (!$edit_mode && $period_tracking_enabled && $current_period): ?>
                            <small class="text-muted">(Period: <?= htmlspecialchars($current_period['period_name']) ?>)</small>
                        <?php endif; ?>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                    <div class="modal-body">
                        <?php if ($edit_mode): ?>
                            <input type="hidden" name="product_id" value="<?= $edit_product['id'] ?>">
                            <?php 
                            $product_check = canModifyProductAdmin($edit_product['id'], $user_id, $db);
                            if ($product_check['locked']): ?>
                                <div class="alert alert-warning override-warning p-3">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Period Locked - Admin Override Active:</strong> This product belongs to a locked period. 
                                    Changes will be made using admin override privileges.
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <!-- Creator Selection (Admin Only) -->
                        <?php if (!$edit_mode): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="created_by" class="form-label">Product Owner</label>
                                    <select class="form-select" id="created_by" name="created_by">
                                        <option value="<?= $user_id ?>">Myself (Admin)</option>
                                        <?php foreach ($all_users as $user): ?>
                                            <?php if ($user['id'] != $user_id): ?>
                                                <option value="<?= $user['id'] ?>"><?= htmlspecialchars($user['name']) ?> (<?= htmlspecialchars($user['email']) ?>)</option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </select>
                                    <div class="form-text">Select which user should own this product</div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="name" class="form-label">Product Name <span class="text-danger">*</span></label>
                                    <input type="text" class="form-control" id="name" name="name" 
                                           value="<?= isset($edit_product) ? htmlspecialchars($edit_product['name']) : (isset($_POST['name']) ? htmlspecialchars($_POST['name']) : '') ?>" 
                                           required maxlength="200">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="sku" class="form-label">SKU</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="sku" name="sku" 
                                               value="<?= isset($edit_product) ? htmlspecialchars($edit_product['sku']) : (isset($_POST['sku']) ? htmlspecialchars($_POST['sku']) : '') ?>" 
                                               <?= $edit_mode ? 'required' : '' ?> maxlength="50">
                                        <?php if (!$edit_mode): ?>
                                            <button type="button" class="btn btn-outline-secondary" id="generateSKU">
                                                <i class="fas fa-magic me-1"></i>Auto Generate
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-text">
                                        <?php if ($edit_mode): ?>
                                            Unique product identifier
                                        <?php else: ?>
                                            Leave empty for auto-generation or enter custom SKU (max 50 chars)
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="category_id" class="form-label">Category <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <select class="form-select" id="category_id" name="category_id" required>
                                            <option value="">Select Category</option>
                                            <?php foreach ($categories as $id => $name): ?>
                                                <option value="<?= $id ?>" 
                                                    <?= (isset($edit_product) && $edit_product['category_id'] == $id) ? 'selected' : '' ?>
                                                    <?= (isset($_POST['category_id']) && $_POST['category_id'] == $id) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($name) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#categoryModal" data-bs-dismiss="modal">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                    <div class="form-text">Can't find a category? <a href="#" data-bs-toggle="modal" data-bs-target="#categoryModal" data-bs-dismiss="modal">Add new category</a></div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="supplier" class="form-label">Supplier</label>
                                    <input type="text" class="form-control" id="supplier" name="supplier" 
                                           value="<?= isset($edit_product) ? htmlspecialchars($edit_product['supplier']) : (isset($_POST['supplier']) ? htmlspecialchars($_POST['supplier']) : '') ?>"
                                           maxlength="200">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="cost_price" class="form-label">Cost Price (KSH) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" class="form-control" id="cost_price" name="cost_price" 
                                           value="<?= isset($edit_product) ? $edit_product['cost_price'] : (isset($_POST['cost_price']) ? $_POST['cost_price'] : '0.00') ?>" 
                                           required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="selling_price" class="form-label">Selling Price (KSH) <span class="text-danger">*</span></label>
                                    <input type="number" step="0.01" min="0" class="form-control" id="selling_price" name="selling_price" 
                                           value="<?= isset($edit_product) ? $edit_product['selling_price'] : (isset($_POST['selling_price']) ? $_POST['selling_price'] : '0.00') ?>" 
                                           required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="stock_quantity" class="form-label">Stock Quantity <span class="text-danger">*</span></label>
                                    <input type="number" min="0" class="form-control" id="stock_quantity" name="stock_quantity" 
                                           value="<?= isset($edit_product) ? $edit_product['stock_quantity'] : (isset($_POST['stock_quantity']) ? $_POST['stock_quantity'] : '0') ?>" 
                                           required>
                                    <div class="form-text">
                                        <?php if (!$edit_mode): ?>
                                            If product exists, this quantity will be added to current stock
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="min_stock" class="form-label">Minimum Stock Level</label>
                                    <input type="number" min="0" class="form-control" id="min_stock" name="min_stock" 
                                           value="<?= isset($edit_product) ? $edit_product['min_stock'] : (isset($_POST['min_stock']) ? $_POST['min_stock'] : '5') ?>">
                                    <div class="form-text">Alert when stock falls below this level</div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea class="form-control" id="description" name="description" rows="3" maxlength="1000"><?= isset($edit_product) ? htmlspecialchars($edit_product['description']) : (isset($_POST['description']) ? htmlspecialchars($_POST['description']) : '') ?></textarea>
                        </div>

                        <?php if ($period_tracking_enabled && !$current_period && !$edit_mode): ?>
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                No active time period! Please create or activate a period before adding products.
                                <br>
                                <a href="admin_periods.php" class="alert-link">Manage Periods</a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                        <?php if ($edit_mode): ?>
                            <a href="?cancel_edit" class="btn btn-secondary">Cancel</a>
                            <button type="submit" name="update_product" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>
                                <?php 
                                $product_check = canModifyProductAdmin($edit_product['id'], $user_id, $db);
                                echo $product_check['locked'] ? 'Update (Admin Override)' : 'Update Product';
                                ?>
                            </button>
                        <?php else: ?>
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            <button type="submit" name="add_product" class="btn btn-primary" 
                                <?= $period_tracking_enabled && !$current_period ? 'disabled' : '' ?>>
                                <i class="fas fa-plus me-2"></i>
                                <?php if ($period_tracking_enabled && $current_period): ?>
                                    Add to <?= htmlspecialchars($current_period['period_name']) ?>
                                    <?= $current_period['is_locked'] ? ' (Admin Override)' : '' ?>
                                <?php else: ?>
                                    Add/Update Product
                                <?php endif; ?>
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Manage Categories Modal -->
    <div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalLabel">
                        <i class="fas fa-tags me-2"></i>Manage Categories
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <!-- Add Category Form -->
                    <div class="mb-4">
                        <h6 class="border-bottom pb-2">Add New Category</h6>
                        <form method="POST" id="addCategoryForm">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                            <div class="mb-3">
                                <label for="category_name" class="form-label">Category Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="category_name" name="category_name" 
                                       value="<?= isset($_POST['category_name']) ? htmlspecialchars($_POST['category_name']) : '' ?>" 
                                       required maxlength="100">
                            </div>
                            <div class="mb-3">
                                <label for="category_type" class="form-label">Type</label>
                                <input type="text" class="form-control" id="category_type" name="category_type" 
                                       value="product" placeholder="e.g., product, service" maxlength="10">
                                <div class="form-text">Optional: Specify category type</div>
                            </div>
                            <div class="mb-3">
                                <label for="parent_id" class="form-label">Parent Category</label>
                                <select class="form-select" id="parent_id" name="parent_id">
                                    <option value="">None (Main Category)</option>
                                    <?php foreach ($categories as $id => $name): ?>
                                        <option value="<?= $id ?>"><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button type="submit" name="add_category" class="btn btn-success">
                                <i class="fas fa-plus me-2"></i>Add Category
                            </button>
                        </form>
                    </div>

                    <!-- Existing Categories -->
                    <div>
                        <h6 class="border-bottom pb-2">Existing Categories</h6>
                        <?php if (!empty($categories)): ?>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Products</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($categories as $id => $name): 
                                            // Count products in this category
                                            $count_sql = "SELECT COUNT(*) as product_count FROM products WHERE category_id = ?";
                                            $count_stmt = $db->prepare($count_sql);
                                            $count_stmt->bind_param("i", $id);
                                            $count_stmt->execute();
                                            $count_result = $count_stmt->get_result();
                                            $product_count = $count_result->fetch_assoc()['product_count'];
                                        ?>
                                            <tr>
                                                <td><?= htmlspecialchars($name) ?></td>
                                                <td>
                                                    <span class="badge bg-primary"><?= $product_count ?></span>
                                                </td>
                                                <td>
                                                    <?php if ($product_count == 0): ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Are you sure you want to delete this category?')">
                                                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                                            <input type="hidden" name="category_id" value="<?= $id ?>">
                                                            <button type="submit" name="delete_category" class="btn btn-sm btn-outline-danger">
                                                                <i class="fas fa-trash"></i>
                                                            </button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="text-muted small">In use</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted">No categories found.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-show modal if in edit mode or if there are validation errors
        <?php if ($edit_mode || $error): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const modal = new bootstrap.Modal(document.getElementById('productModal'));
                modal.show();
            });
        <?php endif; ?>

        <?php if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) && ($category_message || $category_error)): ?>
            document.addEventListener('DOMContentLoaded', function() {
                const modal = new bootstrap.Modal(document.getElementById('categoryModal'));
                modal.show();
            });
        <?php endif; ?>

        // Auto-generate SKU functionality
        document.getElementById('generateSKU')?.addEventListener('click', function() {
            const name = document.getElementById('name').value;
            const categoryId = document.getElementById('category_id').value;
            
            if (!name || !categoryId) {
                alert('Please enter product name and select category first');
                return;
            }
            
            // Generate SKU based on name and category (you might want to make this an AJAX call)
            const nameAbbr = name.split(' ').map(word => word.charAt(0).toUpperCase()).join('').substring(0, 3);
            const timestamp = Date.now().toString().slice(-4);
            const sku = nameAbbr + '-' + timestamp;
            
            document.getElementById('sku').value = sku;
        });

        // Filter functionality
        document.getElementById('userFilter')?.addEventListener('change', function(e) {
            filterProducts();
        });

        <?php if ($period_tracking_enabled): ?>
        document.getElementById('periodFilter')?.addEventListener('change', function(e) {
            filterProducts();
        });
        <?php endif; ?>

        document.getElementById('searchProducts')?.addEventListener('input', function(e) {
            filterProducts();
        });

        function filterProducts() {
            const userFilter = document.getElementById('userFilter')?.value || '';
            <?php if ($period_tracking_enabled): ?>
            const periodFilter = document.getElementById('periodFilter')?.value || '';
            <?php endif; ?>
            const searchTerm = document.getElementById('searchProducts')?.value.toLowerCase() || '';
            
            const rows = document.querySelectorAll('#products-table tbody tr');
            
            rows.forEach(row => {
                const rowUser = row.getAttribute('data-user');
                <?php if ($period_tracking_enabled): ?>
                const rowPeriod = row.getAttribute('data-period');
                <?php endif; ?>
                const text = row.textContent.toLowerCase();
                
                const userMatch = !userFilter || rowUser === userFilter;
                <?php if ($period_tracking_enabled): ?>
                const periodMatch = !periodFilter || rowPeriod === periodFilter;
                <?php else: ?>
                const periodMatch = true;
                <?php endif; ?>
                const searchMatch = text.includes(searchTerm);
                
                row.style.display = (userMatch && periodMatch && searchMatch) ? '' : 'none';
            });
        }

        // Price validation
        document.getElementById('cost_price')?.addEventListener('change', validatePrices);
        document.getElementById('selling_price')?.addEventListener('change', validatePrices);

        function validatePrices() {
            const costPrice = parseFloat(document.getElementById('cost_price')?.value) || 0;
            const sellingPrice = parseFloat(document.getElementById('selling_price')?.value) || 0;
            const sellingPriceField = document.getElementById('selling_price');
            
            if (sellingPrice < costPrice) {
                sellingPriceField.setCustomValidity('Selling price cannot be less than cost price');
                sellingPriceField.classList.add('is-invalid');
            } else {
                sellingPriceField.setCustomValidity('');
                sellingPriceField.classList.remove('is-invalid');
            }
        }

        // Category modal handling - FIXED VERSION
        document.addEventListener('DOMContentLoaded', function() {
            const categoryModal = document.getElementById('categoryModal');
            const addCategoryForm = document.getElementById('addCategoryForm');
            
            if (categoryModal) {
                // Only refresh if form was submitted successfully
                categoryModal.addEventListener('hidden.bs.modal', function (event) {
                    // Check if the modal was hidden due to form submission
                    if (categoryModal.dataset.submitted === 'true') {
                        // Clear the flag
                        delete categoryModal.dataset.submitted;
                        // Reload to update categories dropdown
                        window.location.reload();
                    }
                });
                
                // Handle form submission
                if (addCategoryForm) {
                    addCategoryForm.addEventListener('submit', function(e) {
                        // Set flag to indicate form was submitted
                        categoryModal.dataset.submitted = 'true';
                    });
                }
                
                // Reset form when modal is shown
                categoryModal.addEventListener('show.bs.modal', function() {
                    // Clear any existing submitted flag
                    delete categoryModal.dataset.submitted;
                });
            }
            
            // Handle close buttons
            const closeButtons = document.querySelectorAll('[data-bs-dismiss="modal"]');
            closeButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const modal = this.closest('.modal');
                    if (modal && modal.id === 'categoryModal') {
                        delete modal.dataset.submitted;
                    }
                });
            });
        });
    </script>
</body>
</html>