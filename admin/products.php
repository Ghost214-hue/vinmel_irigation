<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use your existing config structure
require_once 'config.php'; // This contains Database class and session start
require_once 'security.php'; // Include security functions

// Set security headers
setSecurityHeaders();

// Check authentication using your existing function
requireLogin(); // From your config.php

// Generate CSRF token for forms
$csrf_token = generateCSRFToken();

// Rate limiting for page access
if (!checkRateLimit('inventory_view_' . $_SESSION['user_id'], 30, 300)) {
    securityLog("Rate limit exceeded for inventory page access", "WARNING", $_SESSION['user_id']);
    die("Too many requests. Please try again later.");
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'admin';

$message = '';
$error = '';

// Get all inventory periods with proper period names
function getAllInventoryPeriods($db) {
    $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month, tp.start_date, tp.end_date, tp.is_active, tp.is_locked
            FROM inventory_periods ip
            LEFT JOIN time_periods tp ON ip.time_period_id = tp.id
            ORDER BY tp.year DESC, tp.month DESC";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        securityLog("Failed to prepare statement for getAllInventoryPeriods: " . $db->error, "ERROR", $_SESSION['user_id']);
        return [];
    }
    $stmt->execute();
    
    $result = $stmt->get_result();
    $periods = [];
    while ($row = $result->fetch_assoc()) {
        $periods[] = $row;
    }
    $stmt->close();
    return $periods;
}

// Get previous inventory period
function getPreviousInventoryPeriod($db, $current_time_period_id) {
    if (!validateInt($current_time_period_id)) {
        return null;
    }
    
    $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month, tp.start_date, tp.end_date, tp.is_active, tp.is_locked
            FROM inventory_periods ip
            LEFT JOIN time_periods tp ON ip.time_period_id = tp.id
            WHERE ip.time_period_id < ?
            ORDER BY ip.time_period_id DESC LIMIT 1";
    $stmt = prepareStatement($db, $sql, [$current_time_period_id], "i");
    if (!$stmt) {
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

// Get next inventory period
function getNextInventoryPeriod($db, $current_time_period_id) {
    if (!validateInt($current_time_period_id)) {
        return null;
    }
    
    $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month, tp.start_date, tp.end_date, tp.is_active, tp.is_locked
            FROM inventory_periods ip
            LEFT JOIN time_periods tp ON ip.time_period_id = tp.id
            WHERE ip.time_period_id > ?
            ORDER BY ip.time_period_id ASC LIMIT 1";
    $stmt = prepareStatement($db, $sql, [$current_time_period_id], "i");
    if (!$stmt) {
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

// Calculate current inventory value for a specific period (ALL USERS)
function calculateCurrentInventoryValue($db, $period_start_date = null, $period_end_date = null) {
    if ($period_start_date && $period_end_date) {
        // Calculate inventory for specific period based on product creation date
        $sql = "SELECT SUM(p.stock_quantity * p.cost_price) as total_value 
                FROM products p 
                WHERE p.created_at >= ? AND p.created_at <= ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("ss", $period_start_date, $period_end_date);
    } else {
        // Calculate all inventory
        $sql = "SELECT SUM(stock_quantity * cost_price) as total_value 
                FROM products";
        $stmt = $db->prepare($sql);
    }
    
    if (!$stmt) {
        securityLog("Failed to prepare statement for calculateCurrentInventoryValue", "ERROR", $_SESSION['user_id']);
        return 0;
    }
    
    if ($period_start_date && $period_end_date) {
        $stmt->execute();
    } else {
        $stmt->execute();
    }
    
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $result['total_value'] ?? 0;
}

// Get or create current inventory period (GENERAL)
function getOrCreateInventoryPeriod($db) {
    // First, check if there's a current active period in the time_periods table
    $sql = "SELECT * FROM time_periods WHERE is_active = 1 ORDER BY year DESC, month DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        securityLog("Failed to prepare statement for getOrCreateInventoryPeriod - time_periods", "ERROR", $_SESSION['user_id']);
        return null;
    }
    $stmt->execute();
    $current_period_data = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$current_period_data) {
        securityLog("No active period found", "WARNING", $_SESSION['user_id']);
        return null;
    }
    
    $current_time_period_id = $current_period_data['id'];
    
    // Check if inventory period already exists for this period
    $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month, tp.start_date, tp.end_date, tp.is_active, tp.is_locked
            FROM inventory_periods ip
            LEFT JOIN time_periods tp ON ip.time_period_id = tp.id
            WHERE ip.time_period_id = ?";
    $stmt = prepareStatement($db, $sql, [$current_time_period_id], "i");
    if (!$stmt) {
        return null;
    }
    
    $stmt->execute();
    $current_period = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($current_period) {
        // Update existing period with current values
        $current_inventory = calculateCurrentInventoryValue($db, $current_period['start_date'], $current_period['end_date']);
        $closing_balance = $current_period['opening_balance'] + $current_inventory;
        
        // Validate financial values
        if (!validateFloat($current_inventory) || !validateFloat($closing_balance)) {
            securityLog("Invalid financial values in inventory period update", "ERROR", $_SESSION['user_id']);
            return $current_period;
        }
        
        // Update if values have changed
        if ($current_period['current_inventory'] != $current_inventory || 
            $current_period['closing_balance'] != $closing_balance) {
            
            $update_sql = "UPDATE inventory_periods 
                          SET current_inventory = ?, closing_balance = ?, updated_at = NOW()
                          WHERE id = ?";
            $stmt = prepareStatement($db, $update_sql, [$current_inventory, $closing_balance, $current_period['id']], "ddi");
            if ($stmt) {
                $stmt->execute();
                $stmt->close();
                
                $current_period['current_inventory'] = $current_inventory;
                $current_period['closing_balance'] = $closing_balance;
            }
        }
        
        return $current_period;
    }
    
    // If inventory period doesn't exist, create it
    // Get previous period for opening balance
    $prev_sql = "SELECT ip.* FROM inventory_periods ip 
                 WHERE ip.time_period_id < ? 
                 ORDER BY ip.time_period_id DESC LIMIT 1";
    $prev_stmt = prepareStatement($db, $prev_sql, [$current_time_period_id], "i");
    $opening_balance = 0;
    if ($prev_stmt) {
        $prev_stmt->execute();
        $previous_period = $prev_stmt->get_result()->fetch_assoc();
        $prev_stmt->close();
        $opening_balance = $previous_period ? ($previous_period['closing_balance'] ?? 0) : 0;
    }
    
    // Calculate current inventory value
    $current_inventory = calculateCurrentInventoryValue($db, $current_period_data['start_date'], $current_period_data['end_date']);
    $closing_balance = $opening_balance + $current_inventory;
    
    // Validate financial values
    if (!validateFloat($current_inventory) || !validateFloat($closing_balance)) {
        securityLog("Invalid financial values in new period creation", "ERROR", $_SESSION['user_id']);
        return null;
    }
    
    $insert_sql = "INSERT INTO inventory_periods 
                  (time_period_id, opening_balance, current_inventory, closing_balance, status) 
                  VALUES (?, ?, ?, ?, 'active')";
    $stmt = prepareStatement($db, $insert_sql, [$current_time_period_id, $opening_balance, $current_inventory, $closing_balance], "iddd");
    
    if ($stmt && $stmt->execute()) {
        $period_id = $stmt->insert_id;
        $stmt->close();
        
        // Get the newly created period with period name
        $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month, tp.start_date, tp.end_date, tp.is_active, tp.is_locked
                FROM inventory_periods ip
                LEFT JOIN time_periods tp ON ip.time_period_id = tp.id
                WHERE ip.id = ?";
        $stmt = prepareStatement($db, $sql, [$period_id], "i");
        if ($stmt) {
            $stmt->execute();
            $period = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            // Carry forward stock if needed
            if ($previous_period) {
                carryForwardStock($db, $previous_period['id'], $period_id);
            }
            
            return $period;
        }
    } else {
        securityLog("Failed to create new inventory period", "ERROR", $_SESSION['user_id']);
    }
    
    return null;
}

// Carry forward stock to new period (ALL PRODUCTS)
function carryForwardStock($db, $previous_period_id, $current_period_id) {
    // Validate period IDs
    if (!validateInt($previous_period_id) || !validateInt($current_period_id)) {
        securityLog("Invalid period IDs in carryForwardStock", "ERROR", $_SESSION['user_id']);
        return false;
    }
    
    // Get all products with remaining stock
    $sql = "SELECT p.id, p.stock_quantity, p.cost_price 
            FROM products p 
            WHERE p.stock_quantity > 0";
    $stmt = $db->prepare($sql);
    if (!$stmt) {
        securityLog("Failed to prepare statement for carryForwardStock", "ERROR", $_SESSION['user_id']);
        return false;
    }
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    foreach ($products as $product) {
        // Validate product data
        if (!validateInt($product['id']) || !validateInt($product['stock_quantity']) || !validateFloat($product['cost_price'])) {
            securityLog("Invalid product data in carryForwardStock", "WARNING", $_SESSION['user_id']);
            continue;
        }
        
        $carried_value = $product['stock_quantity'] * $product['cost_price'];
        
        if (!validateFloat($carried_value)) {
            securityLog("Invalid carried value calculation", "WARNING", $_SESSION['user_id']);
            continue;
        }
        
        $carry_sql = "INSERT INTO period_stock_carry 
                     (period_id, product_id, quantity, cost_price, carried_value) 
                     VALUES (?, ?, ?, ?, ?)";
        $stmt_carry = prepareStatement($db, $carry_sql, [
            $current_period_id, 
            $product['id'], 
            $product['stock_quantity'], 
            $product['cost_price'],
            $carried_value
        ], "iiidd");
        
        if ($stmt_carry) {
            $stmt_carry->execute();
            $stmt_carry->close();
        }
    }
    return true;
}

// Get inventory period by ID with validation and period name
function getInventoryPeriodById($db, $period_id) {
    if (!validateInt($period_id)) {
        securityLog("Invalid period ID in getInventoryPeriodById: $period_id", "WARNING", $_SESSION['user_id']);
        return null;
    }
    
    $sql = "SELECT ip.*, tp.period_name, tp.year, tp.month, tp.start_date, tp.end_date, tp.is_active, tp.is_locked
            FROM inventory_periods ip
            LEFT JOIN time_periods tp ON ip.time_period_id = tp.id
            WHERE ip.id = ?";
    $stmt = prepareStatement($db, $sql, [$period_id], "i");
    if (!$stmt) {
        return null;
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();
    $stmt->close();
    return $data;
}

// Get carried forward products for a period
function getCarriedForwardProducts($db, $period_id) {
    if (!validateInt($period_id)) {
        return [];
    }
    
    $sql = "SELECT psc.*, p.name, p.sku, c.name as category_name,
                   u.name as added_by_name
            FROM period_stock_carry psc
            JOIN products p ON psc.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            LEFT JOIN users u ON p.created_by = u.id
            WHERE psc.period_id = ?
            ORDER BY p.name ASC";
    
    $stmt = prepareStatement($db, $sql, [$period_id], "i");
    if (!$stmt) {
        return [];
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $products = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $products;
}

// Calculate period sales data (ALL USERS) with validation
function calculatePeriodSalesData($db, $start_date, $end_date) {
    $sql = "SELECT 
                COALESCE(SUM(ti.total_price), 0) as total_sales,
                COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit,
                COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
            FROM transaction_items ti
            JOIN products p ON ti.product_id = p.id
            JOIN transactions t ON ti.transaction_id = t.id
            WHERE t.transaction_date >= ? AND t.transaction_date <= ?";
    
    $stmt = prepareStatement($db, $sql, [$start_date, $end_date], "ss");
    if (!$stmt) {
        securityLog("Failed to prepare statement for calculatePeriodSalesData", "ERROR", $_SESSION['user_id']);
        return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
    }
    
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Validate returned values
    if (!validateFloat($result['total_sales']) || !validateFloat($result['total_profit']) || !validateInt($result['total_sold_quantity'])) {
        securityLog("Invalid sales data returned from database", "ERROR", $_SESSION['user_id']);
        return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
    }
    
    return $result;
}

// Helper to get period display name
function getPeriodDisplayName($period_data) {
    if (!$period_data) {
        return "Unknown Period";
    }
    
    // Use period_name if available, otherwise create from year/month
    if (!empty($period_data['period_name'])) {
        return htmlspecialchars($period_data['period_name'], ENT_QUOTES, 'UTF-8');
    } elseif (!empty($period_data['year']) && !empty($period_data['month'])) {
        $month_names = [
            1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
            5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
            9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
        ];
        $month_name = $month_names[$period_data['month']] ?? 'Unknown';
        return $month_name . ' ' . $period_data['year'];
    } else {
        return "Period " . ($period_data['time_period_id'] ?? 'Unknown');
    }
}

// Helper to check if period is current
function isCurrentPeriod($period_data) {
    if (!$period_data || !isset($period_data['is_active'])) {
        return false;
    }
    return $period_data['is_active'] == 1;
}

// Helper to get period date range
function getPeriodDateRange($period_data) {
    if (!$period_data || empty($period_data['start_date']) || empty($period_data['end_date'])) {
        return '';
    }
    
    $start = date('M j, Y', strtotime($period_data['start_date']));
    $end = date('M j, Y', strtotime($period_data['end_date']));
    return $start . ' - ' . $end;
}

/* -------------------------------------------------------
   MAIN LOGIC - PERIOD SELECTION WITH SECURITY
-------------------------------------------------------- */

// Handle period selection with input validation
$selected_period_id = isset($_GET['period_id']) ? clean($_GET['period_id']) : null;

// Validate period_id if provided
if ($selected_period_id && !validateInt($selected_period_id)) {
    securityLog("Invalid period_id parameter: $selected_period_id", "WARNING", $user_id);
    $selected_period_id = null;
}

// Get all periods for the dropdown (GENERAL)
$all_periods = getAllInventoryPeriods($db);

// Get current or selected period
if ($selected_period_id) {
    $selected_period = getInventoryPeriodById($db, $selected_period_id);
} else {
    $selected_period = getOrCreateInventoryPeriod($db);
    $selected_period_id = $selected_period['id'] ?? null;
}

// Get adjacent periods for navigation with validation
if ($selected_period && isset($selected_period['time_period_id'])) {
    $previous_period = getPreviousInventoryPeriod($db, $selected_period['time_period_id']);
    $next_period = getNextInventoryPeriod($db, $selected_period['time_period_id']);
} else {
    $previous_period = null;
    $next_period = null;
}

// Get carried forward products
$carried_products = [];
$total_carried_value = 0;
if ($selected_period_id) {
    $carried_products = getCarriedForwardProducts($db, $selected_period_id);
    foreach ($carried_products as $carried) {
        $value = $carried['carried_value'] ?? 0;
        if (validateFloat($value)) {
            $total_carried_value += $value;
        }
    }
}

// Calculate sales data (ALL USERS) - updated to use period dates
$sales_data = ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
if ($selected_period && !empty($selected_period['start_date']) && !empty($selected_period['end_date'])) {
    $sales_data = calculatePeriodSalesData($db, $selected_period['start_date'], $selected_period['end_date']);
    
    // Validate before update
    if (validateFloat($sales_data['total_sales']) && validateFloat($sales_data['total_profit'])) {
        // Update period with sales data
        $update_sql = "UPDATE inventory_periods 
                       SET total_sales = ?, total_profit = ?, updated_at = NOW()
                       WHERE id = ?";
        $stmt = prepareStatement($db, $update_sql, [$sales_data['total_sales'], $sales_data['total_profit'], $selected_period_id], "ddi");
        if ($stmt) {
            $stmt->execute();
            $stmt->close();
        }
    }
}

// Get current products for ALL USERS with prepared statement
$products_sql = "SELECT p.*, c.name as category_name,
                        u.name as added_by_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                (p.stock_quantity * p.selling_price) as potential_revenue
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                LEFT JOIN users u ON p.created_by = u.id
                ORDER BY p.name ASC";
$stmt_products = $db->prepare($products_sql);
if ($stmt_products) {
    $stmt_products->execute();
    $products = $stmt_products->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt_products->close();
} else {
    $products = [];
    securityLog("Failed to prepare statement for products query", "ERROR", $user_id);
}

// Calculate inventory statistics (ALL USERS) with validation
$total_products = 0;
$low_stock_count = 0;
$out_of_stock_count = 0;
$total_stock_value = 0;
$total_potential_revenue = 0;

foreach ($products as $product) {
    // Validate product data
    $stock_value = $product['stock_value'] ?? 0;
    $potential_revenue = $product['potential_revenue'] ?? 0;
    $stock_quantity = $product['stock_quantity'] ?? 0;
    $min_stock = $product['min_stock'] ?? 0;
    
    if (validateFloat($stock_value)) {
        $total_stock_value += $stock_value;
    }
    
    if (validateFloat($potential_revenue)) {
        $total_potential_revenue += $potential_revenue;
    }
    
    if (validateInt($stock_quantity)) {
        if ($stock_quantity <= 0) {
            $out_of_stock_count++;
        } elseif (validateInt($min_stock) && $stock_quantity <= $min_stock) {
            $low_stock_count++;
        }
    }
    
    $total_products++;
}

// Calculate balance metrics with validation
if ($selected_period) {
    $opening_balance = $selected_period['opening_balance'] ?? 0;
    $closing_balance = $selected_period['closing_balance'] ?? 0;
    
    if (validateFloat($opening_balance) && validateFloat($closing_balance)) {
        $balance_change = $closing_balance - $opening_balance;
        $balance_change_percent = $opening_balance > 0 ? 
                                ($balance_change / $opening_balance) * 100 : 0;
    } else {
        $balance_change = 0;
        $balance_change_percent = 0;
    }
    
    $current_inventory_value = $selected_period['current_inventory'] ?? calculateCurrentInventoryValue($db, $selected_period['start_date'] ?? null, $selected_period['end_date'] ?? null);
    if (!validateFloat($current_inventory_value)) {
        $current_inventory_value = 0;
    }
} else {
    $balance_change = 0;
    $balance_change_percent = 0;
    $current_inventory_value = calculateCurrentInventoryValue($db);
    if (!validateFloat($current_inventory_value)) {
        $current_inventory_value = 0;
    }
}

// Stock status classification helper
function getStockStatus($current, $min) {
    if (!validateInt($current) || !validateInt($min)) {
        return 'unknown';
    }
    
    $current = intval($current);
    $min = intval($min);
    
    if ($current <= 0) return 'out';
    if ($current <= $min) return 'low';
    if ($current <= ($min * 2)) return 'medium';
    return 'high';
}

// Log page access
securityLog("Accessed inventory management page", "INFO", $user_id);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
    <style>
        .period-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .balance-flow {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 1rem;
            margin: 1rem 0;
        }
        .balance-step {
            text-align: center;
            padding: 0.5rem;
        }
        .balance-arrow {
            font-size: 1.5rem;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            height: 100%;
        }
        .kpi-card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            transition: transform 0.2s;
            height: 100%;
        }
        .kpi-card:hover {
            transform: translateY(-5px);
        }
        .kpi-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        .kpi-label {
            font-size: 0.9rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            opacity: 0.8;
        }
        .balance-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .carry-forward-card {
            border-left: 4px solid #17a2b8;
        }
        .balance-change-positive {
            color: #28a745;
            font-weight: bold;
        }
        .balance-change-negative {
            color: #dc3545;
            font-weight: bold;
        }
        .period-navigation {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        .period-selector {
            min-width: 250px;
        }
        .period-nav-btn {
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        .period-nav-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .product-icon {
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .stock-status-high { color: #28a745; }
        .stock-status-medium { color: #ffc107; }
        .stock-status-low { color: #fd7e14; }
        .stock-status-out { color: #dc3545; }
        .stock-status-unknown { color: #6c757d; }
        .progress {
            height: 6px;
        }
        .badge-super-admin {
            background: #ffc107;
            color: #000;
        }
        .added-by-badge {
            background: #6c757d;
            color: white;
            font-size: 0.75rem;
            padding: 2px 6px;
            border-radius: 3px;
        }
        .admin-view-badge {
            background: #17a2b8;
            color: white;
        }
    </style>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Period Information -->
                <div class="period-info-card">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <h2 class="mb-1">General Inventory Period Management</h2>
                            <p class="mb-0">Track opening and closing balances across periods (All Users)</p>
                            <?php if ($user_role === 'super_admin'): ?>
                                <span class="badge badge-super-admin mt-1">Admin View - All Users Data</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="period-navigation">
                                <!-- Previous Period Button -->
                                <?php if ($previous_period): ?>
                                    <a href="?period_id=<?= htmlspecialchars($previous_period['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light period-nav-btn">
                                        <i class="fas fa-chevron-left me-2"></i>
                                        <?= getPeriodDisplayName($previous_period) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-outline-light disabled period-nav-btn">
                                        <i class="fas fa-chevron-left me-2"></i>
                                        No Previous Period
                                    </span>
                                <?php endif; ?>

                                <!-- Period Selector (General Only) -->
                                <div class="period-selector">
                                    <select class="form-select" id="periodSelect" onchange="changePeriod(this.value)">
                                        <option value="">Select Period</option>
                                        <?php foreach($all_periods as $period): 
                                            $period_display_name = getPeriodDisplayName($period);
                                            $is_current = isCurrentPeriod($period);
                                        ?>
                                            <option value="<?= htmlspecialchars($period['id'], ENT_QUOTES, 'UTF-8') ?>" 
                                                <?= $selected_period_id == $period['id'] ? 'selected' : '' ?>>
                                                <?= $period_display_name ?>
                                                <?= $is_current ? ' (Current)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Next Period Button -->
                                <?php if ($next_period): ?>
                                    <a href="?period_id=<?= htmlspecialchars($next_period['id'], ENT_QUOTES, 'UTF-8') ?>" class="btn btn-outline-light period-nav-btn">
                                        <?= getPeriodDisplayName($next_period) ?>
                                        <i class="fas fa-chevron-right ms-2"></i>
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-outline-light disabled period-nav-btn">
                                        No Next Period
                                        <i class="fas fa-chevron-right ms-2"></i>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Selected Period Details -->
                    <?php if ($selected_period): ?>
                    <div class="row mt-3">
                        <div class="col-md-4">
                            <strong>Selected Period:</strong>
                            <h4 class="mb-0 mt-1"><?= getPeriodDisplayName($selected_period) ?></h4>
                            <small class="text-white-50">
                                <?= getPeriodDateRange($selected_period) ?>
                            </small>
                        </div>
                        <div class="col-md-4">
                            <strong>Period Status:</strong>
                            <div class="mt-1">
                                <?php if (isCurrentPeriod($selected_period)): ?>
                                    <span class="badge bg-success">Current Period</span>
                                <?php else: ?>
                                    <span class="badge bg-info">Past Period</span>
                                <?php endif; ?>
                                <?php if (isset($selected_period['status']) && $selected_period['status'] == 'closed'): ?>
                                    <span class="badge bg-danger ms-1">Closed</span>
                                <?php endif; ?>
                                <?php if (isset($selected_period['is_locked']) && $selected_period['is_locked'] == 1): ?>
                                    <span class="badge bg-warning ms-1">Locked</span>
                                <?php endif; ?>
                                <span class="badge admin-view-badge ms-1">All Users</span>
                            </div>
                        </div>
                        <div class="col-md-4 text-end">
                            <div class="btn-group">
                                <button class="btn btn-light" onclick="exportToExcel()">
                                    <i class="fas fa-file-excel me-2"></i>Export Report
                                </button>
                                <button class="btn btn-light" onclick="window.print()">
                                    <i class="fas fa-print me-2"></i>Print
                                </button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Balance Flow Visualization -->
                <?php if ($selected_period): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="balance-flow">
                            <div class="row align-items-center">
                                <div class="col-md-3 balance-step">
                                    <div class="fw-bold">Opening Balance</div>
                                    <div class="fs-4 fw-bold">KSH <?= number_format($selected_period['opening_balance'] ?? 0, 2) ?></div>
                                    <small>
                                        <?php if (($selected_period['opening_balance'] ?? 0) == 0): ?>
                                            First period - starting from zero
                                        <?php else: ?>
                                            From previous period's closing balance
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="col-md-1">
                                    <div class="balance-arrow">
                                        <i class="fas fa-plus"></i>
                                    </div>
                                </div>
                                <div class="col-md-3 balance-step">
                                    <div class="fw-bold">Current Inventory Value</div>
                                    <div class="fs-4 fw-bold">KSH <?= number_format($current_inventory_value, 2) ?></div>
                                    <small>
                                        Inventory added in <?= getPeriodDisplayName($selected_period) ?> (All Users)
                                        <?php if (!empty($selected_period['start_date']) && !empty($selected_period['end_date'])): ?>
                                            <br><?= date('M j', strtotime($selected_period['start_date'])) ?> - <?= date('M j, Y', strtotime($selected_period['end_date'])) ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                                <div class="col-md-1">
                                    <div class="balance-arrow">
                                        <i class="fas fa-equals"></i>
                                    </div>
                                </div>
                                <div class="col-md-3 balance-step">
                                    <div class="fw-bold">Closing Balance</div>
                                    <div class="fs-4 fw-bold">KSH <?= number_format($selected_period['closing_balance'] ?? 0, 2) ?></div>
                                    <small>Will carry to next period</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Detailed Balance Summary -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="balance-section text-center">
                            <h5 class="mb-3"><i class="fas fa-play-circle me-2 text-success"></i>Opening Balance</h5>
                            <div class="d-flex justify-content-center align-items-center mb-2">
                                <span class="fs-4 fw-bold text-success">KSH <?= number_format($selected_period['opening_balance'] ?? 0, 2) ?></span>
                            </div>
                            <small class="text-muted">
                                <?php if (($selected_period['opening_balance'] ?? 0) == 0): ?>
                                    First period - starting fresh
                                <?php else: ?>
                                    Previous period's closing balance
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="balance-section text-center">
                            <h5 class="mb-3"><i class="fas fa-plus-circle me-2 text-info"></i>Current Inventory Value</h5>
                            <div class="d-flex justify-content-center align-items-center mb-2">
                                <span class="fs-4 fw-bold text-info">KSH <?= number_format($current_inventory_value, 2) ?></span>
                            </div>
                            <small class="text-muted">Inventory added this period (All Users)</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="balance-section text-center">
                            <h5 class="mb-3"><i class="fas fa-stop-circle me-2 text-primary"></i>Closing Balance</h5>
                            <div class="d-flex justify-content-center align-items-center mb-2">
                                <span class="fs-4 fw-bold text-primary">KSH <?= number_format($selected_period['closing_balance'] ?? 0, 2) ?></span>
                            </div>
                            <small class="text-muted">Opening + Current Inventory</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="balance-section text-center">
                            <h5 class="mb-3">
                                <i class="fas fa-chart-line me-2 <?= $balance_change >= 0 ? 'text-success' : 'text-danger' ?>"></i>
                                Balance Change
                            </h5>
                            <div class="d-flex justify-content-center align-items-center mb-2">
                                <span class="fs-4 fw-bold <?= $balance_change >= 0 ? 'balance-change-positive' : 'balance-change-negative' ?>">
                                    <?= $balance_change >= 0 ? '+' : '' ?>
                                    KSH <?= number_format($balance_change, 2) ?>
                                </span>
                            </div>
                            <small class="text-muted">
                                <?= $balance_change >= 0 ? 'Increase' : 'Decrease' ?> from opening
                                <?php if (($selected_period['opening_balance'] ?? 0) > 0): ?>
                                    <br>(<?= number_format(abs($balance_change_percent), 2) ?>%)
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Carried Forward Products -->
                <?php if (!empty($carried_products)): ?>
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="card carry-forward-card">
                            <div class="card-header bg-info text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-forward me-2"></i>
                                    Carried Forward Stock from Previous Period
                                    <span class="badge bg-light text-dark ms-2"><?= count($carried_products) ?> products</span>
                                    <span class="badge admin-view-badge ms-1">All Users</span>
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Product</th>
                                                <th>SKU</th>
                                                <th>Category</th>
                                                <th>Added By</th>
                                                <th>Quantity Carried</th>
                                                <th>Cost Price</th>
                                                <th>Carried Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($carried_products as $carried): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($carried['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                                                <td><code><?= htmlspecialchars($carried['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($carried['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></span></td>
                                                <td>
                                                    <?php if (!empty($carried['added_by_name'])): ?>
                                                        <span class="added-by-badge"><?= htmlspecialchars($carried['added_by_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <?php else: ?>
                                                        <span class="added-by-badge">Unknown</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= htmlspecialchars($carried['quantity'] ?? 0, ENT_QUOTES, 'UTF-8') ?></td>
                                                <td>KSH <?= number_format($carried['cost_price'] ?? 0, 2) ?></td>
                                                <td class="fw-bold">KSH <?= number_format($carried['carried_value'] ?? 0, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-active">
                                                <td colspan="6" class="text-end fw-bold">Total Carried Value:</td>
                                                <td class="fw-bold text-primary">KSH <?= number_format($total_carried_value, 2) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Financial KPIs -->
                <div class="row mb-4">
                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-primary border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-primary">
                                            KSH <?= number_format($selected_period ? ($selected_period['closing_balance'] ?? $total_stock_value) : $total_stock_value, 2) ?>
                                        </div>
                                        <div class="kpi-label text-muted">Total Stock Value</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-boxes fa-2x text-primary opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Across <?= $total_products ?> products (All Users)</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-success border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-success">
                                            KSH <?= number_format($sales_data['total_sales'], 2) ?>
                                        </div>
                                        <div class="kpi-label text-muted">Total Sales</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-chart-line fa-2x text-success opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted"><?= $sales_data['total_sold_quantity'] ?> items sold (All Users)</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-warning border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-warning">
                                            KSH <?= number_format($sales_data['total_profit'], 2) ?>
                                        </div>
                                        <div class="kpi-label text-muted">Gross Profit</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-coins fa-2x text-warning opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Sales profit margin (All Users)</small>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4">
                        <div class="card kpi-card border-start border-danger border-4">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="kpi-value text-danger"><?= $low_stock_count + $out_of_stock_count ?></div>
                                        <div class="kpi-label text-muted">Stock Alerts</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-exclamation-triangle fa-2x text-danger opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted"><?= $low_stock_count ?> low, <?= $out_of_stock_count ?> out (All Users)</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Inventory (All Users) -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>
                                    Current Inventory (All Users)
                                    <?php if ($selected_period): ?>
                                        - <?= getPeriodDisplayName($selected_period) ?>
                                    <?php endif; ?>
                                    <small class="text-muted">(<?= $total_products ?> products)</small>
                                    <span class="badge admin-view-badge ms-2">Admin View</span>
                                </h5>
                                <span class="badge bg-primary">Total Value: KSH <?= number_format($total_stock_value, 2) ?></span>
                            </div>
                            <div class="card-body">
                                <?php if (!empty($products)): ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="inventoryTable">
                                            <thead>
                                                <tr>
                                                    <th>Product</th>
                                                    <th>SKU</th>
                                                    <th>Category</th>
                                                    <th>Added By</th>
                                                    <th>Cost Price</th>
                                                    <th>Selling Price</th>
                                                    <th>Stock Level</th>
                                                    <th>Stock Value</th>
                                                    <th>Status</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($products as $product): 
                                                    $stock_quantity = $product['stock_quantity'] ?? 0;
                                                    $min_stock = $product['min_stock'] ?? 0;
                                                    $stock_status = getStockStatus($stock_quantity, $min_stock);
                                                    $progress_width = min(($stock_quantity / max($min_stock * 3, 1)) * 100, 100);
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <div class="d-flex align-items-center">
                                                                <div class="product-icon bg-primary text-white rounded-circle me-3">
                                                                    <i class="fas fa-box"></i>
                                                                </div>
                                                                <div>
                                                                    <strong><?= htmlspecialchars($product['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                                                                    <?php if (!empty($product['supplier'])): ?>
                                                                        <br><small class="text-muted">Supplier: <?= htmlspecialchars($product['supplier'], ENT_QUOTES, 'UTF-8') ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <code><?= htmlspecialchars($product['sku'] ?? '', ENT_QUOTES, 'UTF-8') ?></code>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized', ENT_QUOTES, 'UTF-8') ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if (!empty($product['added_by_name'])): ?>
                                                                <span class="added-by-badge"><?= htmlspecialchars($product['added_by_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                            <?php else: ?>
                                                                <span class="added-by-badge">Unknown</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['cost_price'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <strong class="text-success">KSH <?= number_format($product['selling_price'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <div class="d-flex align-items-center gap-2">
                                                                <span class="stock-status-<?= $stock_status ?> fw-bold">
                                                                    <?= $stock_quantity ?>
                                                                </span>
                                                                <small class="text-muted">/ min: <?= $min_stock ?></small>
                                                            </div>
                                                            <div class="progress mt-1">
                                                                <div class="progress-bar 
                                                                    <?= $stock_status === 'high' ? 'bg-success' : '' ?>
                                                                    <?= $stock_status === 'medium' ? 'bg-warning' : '' ?>
                                                                    <?= $stock_status === 'low' ? 'bg-danger' : '' ?>
                                                                    <?= $stock_status === 'out' ? 'bg-secondary' : '' ?>
                                                                    <?= $stock_status === 'unknown' ? 'bg-dark' : '' ?>"
                                                                    style="width: <?= $progress_width ?>%">
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <strong>KSH <?= number_format($product['stock_value'] ?? 0, 2) ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge 
                                                                <?= $stock_status === 'high' ? 'bg-success' : '' ?>
                                                                <?= $stock_status === 'medium' ? 'bg-warning' : '' ?>
                                                                <?= $stock_status === 'low' ? 'bg-danger' : '' ?>
                                                                <?= $stock_status === 'out' ? 'bg-secondary' : '' ?>
                                                                <?= $stock_status === 'unknown' ? 'bg-dark' : '' ?>">
                                                                <i class="fas fa-<?= [
                                                                    'high' => 'check-circle',
                                                                    'medium' => 'exclamation-circle',
                                                                    'low' => 'exclamation-triangle',
                                                                    'out' => 'times-circle',
                                                                    'unknown' => 'question-circle'
                                                                ][$stock_status] ?? 'question-circle' ?>"></i>
                                                                <?= ucfirst($stock_status) ?> Stock
                                                            </span>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php else: ?>
                                    <div class="text-center py-5">
                                        <i class="fas fa-box-open fa-4x text-muted mb-3"></i>
                                        <h4 class="text-muted">No Products in Inventory</h4>
                                        <p class="text-muted">No products found in the system.</p>
                                        <a href="products.php" class="btn btn-primary">
                                            <i class="fas fa-plus me-2"></i>Add Products
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>
    <script>
        // CSRF token for AJAX requests
        const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        
        function changePeriod(periodId) {
            if (periodId) {
                window.location.href = '?period_id=' + encodeURIComponent(periodId);
            }
        }

        function exportToExcel() {
            const table = document.getElementById('inventoryTable');
            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.table_to_sheet(table);
            XLSX.utils.book_append_sheet(wb, ws, 'Inventory Report');
            
            const periodName = document.querySelector('.period-info-card h4')?.textContent || 'Inventory';
            const safePeriodName = periodName.replace(/[^\w\s]/gi, '').replace(/\s+/g, '_');
            XLSX.writeFile(wb, `inventory_${safePeriodName}_${new Date().toISOString().split('T')[0]}.xlsx`);
        }

        // Auto-refresh for current period with security consideration
        <?php 
        if ($selected_period && isCurrentPeriod($selected_period)): 
        ?>
        setTimeout(function() {
            // Add CSRF token to refresh request if needed
            location.reload();
        }, 30000); // Refresh every 30 seconds for current period
        <?php 
        endif; 
        ?>
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>