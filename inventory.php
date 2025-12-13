<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Use your existing config structure
require_once 'config.php'; // This contains Database class and session start
session_start();

// Check authentication - CORRECTED VERSION
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'] ?? null; // Use null coalescing operator
$user_role = $_SESSION['role'] ?? 'admin';

// Check if user_id is set
if (!$user_id) {
    header("Location: login.php");
    exit();
}

$message = '';
$error = '';

/* -------------------------------------------------------
   INVENTORY PERIOD MANAGEMENT FUNCTIONS
-------------------------------------------------------- */

// Function to create inventory tables if not exists
function createInventoryTables($db) {
    // Check if tables already exist
    $check_periods = $db->query("SHOW TABLES LIKE 'inventory_periods'");
    if ($check_periods->num_rows == 0) {
        $create_periods_table = "
        CREATE TABLE inventory_periods (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL,
            period_month VARCHAR(7) NOT NULL,
            opening_balance DECIMAL(15,2) DEFAULT 0.00,
            current_inventory DECIMAL(15,2) DEFAULT 0.00,
            closing_balance DECIMAL(15,2) DEFAULT 0.00,
            total_sales DECIMAL(15,2) DEFAULT 0.00,
            total_profit DECIMAL(15,2) DEFAULT 0.00,
            status ENUM('active', 'closed', 'future') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE KEY unique_user_period (user_id, period_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $db->query($create_periods_table);
    }
    
    $check_carry = $db->query("SHOW TABLES LIKE 'period_stock_carry'");
    if ($check_carry->num_rows == 0) {
        $create_carry_table = "
        CREATE TABLE period_stock_carry (
            id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            period_id INT(11) NOT NULL,
            product_id INT(11) NOT NULL,
            quantity INT(11) NOT NULL,
            cost_price DECIMAL(10,2) NOT NULL,
            carried_value DECIMAL(15,2) NOT NULL,
            carried_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (period_id) REFERENCES inventory_periods(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
            KEY idx_period_product (period_id, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $db->query($create_carry_table);
    }
}

// Initialize tables
createInventoryTables($db);

// Get all inventory periods for current user
function getAllInventoryPeriods($db, $user_id, $user_role = 'admin') {
    if ($user_role === 'super_admin') {
        $sql = "SELECT ip.*, u.name as user_name 
                FROM inventory_periods ip
                LEFT JOIN users u ON ip.user_id = u.id
                ORDER BY ip.period_month DESC, ip.user_id";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->execute();
        } else {
            return [];
        }
    } else {
        $sql = "SELECT * FROM inventory_periods 
                WHERE user_id = ? 
                ORDER BY period_month DESC";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
        } else {
            return [];
        }
    }
    
    if ($stmt) {
        $result = $stmt->get_result();
        $periods = [];
        while ($row = $result->fetch_assoc()) {
            $periods[] = $row;
        }
        return $periods;
    }
    return [];
}

// Get previous inventory period
function getPreviousInventoryPeriod($db, $user_id, $current_period_month) {
    $sql = "SELECT * FROM inventory_periods 
            WHERE user_id = ? AND period_month < ?
            ORDER BY period_month DESC LIMIT 1";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $current_period_month);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    return null;
}

// Get next inventory period
function getNextInventoryPeriod($db, $user_id, $current_period_month) {
    $sql = "SELECT * FROM inventory_periods 
            WHERE user_id = ? AND period_month > ?
            ORDER BY period_month ASC LIMIT 1";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $current_period_month);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    return null;
}

// Calculate current inventory value for a specific period
function calculateCurrentInventoryValue($db, $user_id, $period_month = null) {
    if ($period_month) {
        list($year, $month) = explode('-', $period_month);
        $sql = "SELECT SUM(p.stock_quantity * p.cost_price) as total_value 
                FROM products p 
                WHERE p.created_by = ? 
                AND YEAR(p.created_at) = ? 
                AND MONTH(p.created_at) = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("iss", $user_id, $year, $month);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            return $result['total_value'] ?? 0;
        }
    } else {
        $sql = "SELECT SUM(stock_quantity * cost_price) as total_value 
                FROM products WHERE created_by = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result()->fetch_assoc();
            return $result['total_value'] ?? 0;
        }
    }
    return 0;
}

// Get or create current inventory period
function getOrCreateInventoryPeriod($db, $user_id) {
    $current_month = date('Y-m');
    
    // Check if current period exists
    $sql = "SELECT * FROM inventory_periods 
            WHERE user_id = ? AND period_month = ?";
    $stmt = $db->prepare($sql);
    if (!$stmt) return null;
    
    $stmt->bind_param("is", $user_id, $current_month);
    $stmt->execute();
    $current_period = $stmt->get_result()->fetch_assoc();
    
    if (!$current_period) {
        // Get previous period for opening balance
        $previous_period = getPreviousInventoryPeriod($db, $user_id, $current_month);
        $opening_balance = $previous_period ? ($previous_period['closing_balance'] ?? 0) : 0;
        
        // Calculate current inventory value
        $current_inventory = calculateCurrentInventoryValue($db, $user_id, $current_month);
        $closing_balance = $opening_balance + $current_inventory;
        
        // Insert new period
        $insert_sql = "INSERT INTO inventory_periods 
                      (user_id, period_month, opening_balance, current_inventory, closing_balance, status) 
                      VALUES (?, ?, ?, ?, ?, 'active')";
        $stmt = $db->prepare($insert_sql);
        if (!$stmt) return null;
        
        $stmt->bind_param("isddd", $user_id, $current_month, $opening_balance, $current_inventory, $closing_balance);
        
        if ($stmt->execute()) {
            $period_id = $stmt->insert_id;
            
            // Carry forward stock from previous period if exists
            if ($previous_period) {
                carryForwardStock($db, $user_id, $previous_period['id'], $period_id);
            }
            
            // Get the newly created period
            return [
                'id' => $period_id,
                'user_id' => $user_id,
                'period_month' => $current_month,
                'opening_balance' => $opening_balance,
                'current_inventory' => $current_inventory,
                'closing_balance' => $closing_balance,
                'status' => 'active'
            ];
        }
    } else {
        // Update existing period with current values
        $current_inventory = calculateCurrentInventoryValue($db, $user_id, $current_month);
        $closing_balance = $current_period['opening_balance'] + $current_inventory;
        
        // Update if values have changed
        if ($current_period['current_inventory'] != $current_inventory || 
            $current_period['closing_balance'] != $closing_balance) {
            
            $update_sql = "UPDATE inventory_periods 
                          SET current_inventory = ?, closing_balance = ?, updated_at = NOW()
                          WHERE id = ?";
            $stmt = $db->prepare($update_sql);
            if ($stmt) {
                $stmt->bind_param("ddi", $current_inventory, $closing_balance, $current_period['id']);
                $stmt->execute();
            }
            
            $current_period['current_inventory'] = $current_inventory;
            $current_period['closing_balance'] = $closing_balance;
        }
        
        return $current_period;
    }
    
    return null;
}

// Carry forward stock to new period
function carryForwardStock($db, $user_id, $previous_period_id, $current_period_id) {
    // Get products with remaining stock from previous period
    $sql = "SELECT p.id, p.stock_quantity, p.cost_price 
            FROM products p 
            WHERE p.created_by = ? AND p.stock_quantity > 0";
    $stmt = $db->prepare($sql);
    if (!$stmt) return;
    
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    
    foreach ($products as $product) {
        $carried_value = $product['stock_quantity'] * $product['cost_price'];
        
        $carry_sql = "INSERT INTO period_stock_carry 
                     (period_id, product_id, quantity, cost_price, carried_value) 
                     VALUES (?, ?, ?, ?, ?)";
        $stmt_carry = $db->prepare($carry_sql);
        if (!$stmt_carry) continue;
        
        $stmt_carry->bind_param("iiidd", 
            $current_period_id, 
            $product['id'], 
            $product['stock_quantity'], 
            $product['cost_price'],
            $carried_value
        );
        $stmt_carry->execute();
    }
}

// Get inventory period by ID
function getInventoryPeriodById($db, $period_id, $user_id, $user_role = 'admin') {
    if ($user_role === 'super_admin') {
        $sql = "SELECT ip.*, u.name as user_name 
                FROM inventory_periods ip
                LEFT JOIN users u ON ip.user_id = u.id
                WHERE ip.id = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("i", $period_id);
        }
    } else {
        $sql = "SELECT * FROM inventory_periods WHERE id = ? AND user_id = ?";
        $stmt = $db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param("ii", $period_id, $user_id);
        }
    }
    
    if ($stmt) {
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    return null;
}

// Get carried forward products for a period
function getCarriedForwardProducts($db, $period_id) {
    $sql = "SELECT psc.*, p.name, p.sku, c.name as category_name
            FROM period_stock_carry psc
            JOIN products p ON psc.product_id = p.id
            LEFT JOIN categories c ON p.category_id = c.id
            WHERE psc.period_id = ?
            ORDER BY p.name ASC";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $period_id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }
    return [];
}

// Calculate period sales data
function calculatePeriodSalesData($db, $user_id, $period_month) {
    $sql = "SELECT 
                COALESCE(SUM(ti.total_price), 0) as total_sales,
                COALESCE(SUM(ti.total_price - (ti.quantity * p.cost_price)), 0) as total_profit,
                COALESCE(SUM(ti.quantity), 0) as total_sold_quantity
            FROM transaction_items ti
            JOIN products p ON ti.product_id = p.id
            JOIN transactions t ON ti.transaction_id = t.id
            WHERE p.created_by = ? AND DATE_FORMAT(t.transaction_date, '%Y-%m') = ?";
    
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("is", $user_id, $period_month);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }
    return ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
}

/* -------------------------------------------------------
   MAIN LOGIC - PERIOD SELECTION
-------------------------------------------------------- */

// Handle period selection
$selected_period_id = $_GET['period_id'] ?? null;

// Get all periods for the dropdown
$all_periods = getAllInventoryPeriods($db, $user_id, $user_role);

// Get current or selected period
if ($selected_period_id) {
    $selected_period = getInventoryPeriodById($db, $selected_period_id, $user_id, $user_role);
} else {
    $selected_period = getOrCreateInventoryPeriod($db, $user_id);
    $selected_period_id = $selected_period['id'] ?? null;
}

// Get adjacent periods for navigation
$previous_period = $selected_period ? getPreviousInventoryPeriod($db, $user_id, $selected_period['period_month']) : null;
$next_period = $selected_period ? getNextInventoryPeriod($db, $user_id, $selected_period['period_month']) : null;

// Get carried forward products
$carried_products = [];
$total_carried_value = 0;
if ($selected_period_id) {
    $carried_products = getCarriedForwardProducts($db, $selected_period_id);
    foreach ($carried_products as $carried) {
        $total_carried_value += $carried['carried_value'] ?? 0;
    }
}

// Calculate sales data for the period
$sales_data = ['total_sales' => 0, 'total_profit' => 0, 'total_sold_quantity' => 0];
if ($selected_period) {
    $sales_data = calculatePeriodSalesData($db, $user_id, $selected_period['period_month']);
    
    // Update period with sales data
    $update_sql = "UPDATE inventory_periods 
                   SET total_sales = ?, total_profit = ?, updated_at = NOW()
                   WHERE id = ?";
    $stmt = $db->prepare($update_sql);
    if ($stmt) {
        $stmt->bind_param("ddi", $sales_data['total_sales'], $sales_data['total_profit'], $selected_period_id);
        $stmt->execute();
    }
}

// Get current products for the user
$products_sql = "SELECT p.*, c.name as category_name,
                (p.stock_quantity * p.cost_price) as stock_value,
                (p.stock_quantity * p.selling_price) as potential_revenue
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.created_by = ?
                ORDER BY p.name ASC";
$stmt_products = $db->prepare($products_sql);
if ($stmt_products) {
    $stmt_products->bind_param("i", $user_id);
    $stmt_products->execute();
    $products = $stmt_products->get_result()->fetch_all(MYSQLI_ASSOC);
} else {
    $products = [];
}

// Calculate inventory statistics
$total_products = count($products);
$low_stock_count = 0;
$out_of_stock_count = 0;
$total_stock_value = 0;
$total_potential_revenue = 0;

foreach ($products as $product) {
    $total_stock_value += $product['stock_value'] ?? 0;
    $total_potential_revenue += $product['potential_revenue'] ?? 0;
    if (($product['stock_quantity'] ?? 0) <= 0) {
        $out_of_stock_count++;
    } elseif (($product['stock_quantity'] ?? 0) <= ($product['min_stock'] ?? 0)) {
        $low_stock_count++;
    }
}

// Calculate balance metrics
if ($selected_period) {
    $balance_change = ($selected_period['closing_balance'] ?? 0) - ($selected_period['opening_balance'] ?? 0);
    $balance_change_percent = ($selected_period['opening_balance'] ?? 0) > 0 ? 
                            ($balance_change / $selected_period['opening_balance']) * 100 : 0;
    $current_inventory_value = $selected_period['current_inventory'] ?? calculateCurrentInventoryValue($db, $user_id, $selected_period['period_month'] ?? null);
} else {
    $balance_change = 0;
    $balance_change_percent = 0;
    $current_inventory_value = calculateCurrentInventoryValue($db, $user_id);
}

// Stock status classification helper
function getStockStatus($current, $min) {
    if ($current <= 0) return 'out';
    if ($current <= $min) return 'low';
    if ($current <= ($min * 2)) return 'medium';
    return 'high';
}
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
        .progress {
            height: 6px;
        }
        .badge-user {
            background: #17a2b8;
            color: white;
        }
        .badge-super-admin {
            background: #ffc107;
            color: #000;
        }
    </style>
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
                            <h2 class="mb-1">Inventory Period Management</h2>
                            <p class="mb-0">Track opening and closing balances across monthly periods</p>
                            <?php if ($user_role === 'super_admin'): ?>
                                <span class="badge badge-super-admin mt-1">Super Admin View</span>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="period-navigation">
                                <!-- Previous Period Button -->
                                <?php if ($previous_period): ?>
                                    <a href="?period_id=<?= $previous_period['id'] ?>" class="btn btn-outline-light period-nav-btn">
                                        <i class="fas fa-chevron-left me-2"></i>
                                        <?= date('F Y', strtotime($previous_period['period_month'] . '-01')) ?>
                                    </a>
                                <?php else: ?>
                                    <span class="btn btn-outline-light disabled period-nav-btn">
                                        <i class="fas fa-chevron-left me-2"></i>
                                        No Previous Period
                                    </span>
                                <?php endif; ?>

                                <!-- Period Selector -->
                                <div class="period-selector">
                                    <select class="form-select" id="periodSelect" onchange="changePeriod(this.value)">
                                        <option value="">Select Period</option>
                                        <?php foreach($all_periods as $period): 
                                            $is_current = $period['period_month'] == date('Y-m');
                                            $user_label = ($user_role === 'super_admin' && isset($period['user_name'])) ? ' - ' . $period['user_name'] : '';
                                        ?>
                                            <option value="<?= $period['id'] ?>" 
                                                <?= $selected_period_id == $period['id'] ? 'selected' : '' ?>>
                                                <?= date('F Y', strtotime($period['period_month'] . '-01')) ?>
                                                <?= $user_label ?>
                                                <?= $is_current ? ' (Current)' : '' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Next Period Button -->
                                <?php if ($next_period): ?>
                                    <a href="?period_id=<?= $next_period['id'] ?>" class="btn btn-outline-light period-nav-btn">
                                        <?= date('F Y', strtotime($next_period['period_month'] . '-01')) ?>
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
                            <h4 class="mb-0 mt-1"><?= date('F Y', strtotime($selected_period['period_month'] . '-01')) ?></h4>
                            <?php if ($user_role === 'super_admin' && isset($selected_period['user_name'])): ?>
                                <small class="text-white-50">User: <?= $selected_period['user_name'] ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Period Status:</strong>
                            <div class="mt-1">
                                <?php if ($selected_period['period_month'] == date('Y-m')): ?>
                                    <span class="badge bg-success">Current Period</span>
                                <?php elseif ($selected_period['period_month'] < date('Y-m')): ?>
                                    <span class="badge bg-info">Past Period</span>
                                <?php else: ?>
                                    <span class="badge bg-warning">Future Period</span>
                                <?php endif; ?>
                                <?php if (($selected_period['status'] ?? '') == 'closed'): ?>
                                    <span class="badge bg-danger ms-1">Closed</span>
                                <?php endif; ?>
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
                                    <small>Inventory added this period</small>
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
                            <small class="text-muted">Inventory added this period</small>
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
                                                <th>Quantity Carried</th>
                                                <th>Cost Price</th>
                                                <th>Carried Value</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($carried_products as $carried): ?>
                                            <tr>
                                                <td><?= htmlspecialchars($carried['name'] ?? '') ?></td>
                                                <td><code><?= htmlspecialchars($carried['sku'] ?? '') ?></code></td>
                                                <td><span class="badge bg-secondary"><?= htmlspecialchars($carried['category_name'] ?? 'Uncategorized') ?></span></td>
                                                <td><?= $carried['quantity'] ?? 0 ?></td>
                                                <td>KSH <?= number_format($carried['cost_price'] ?? 0, 2) ?></td>
                                                <td class="fw-bold">KSH <?= number_format($carried['carried_value'] ?? 0, 2) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr class="table-active">
                                                <td colspan="5" class="text-end fw-bold">Total Carried Value:</td>
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
                                            KSH <?= number_format($selected_period ? ($selected_period['closing_balance'] ?? 0) : $total_stock_value, 2) ?>
                                        </div>
                                        <div class="kpi-label text-muted">Total Stock Value</div>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="fas fa-boxes fa-2x text-primary opacity-50"></i>
                                    </div>
                                </div>
                                <small class="text-muted">Across <?= $total_products ?> products</small>
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
                                <small class="text-muted"><?= $sales_data['total_sold_quantity'] ?> items sold</small>
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
                                <small class="text-muted">Sales profit margin</small>
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
                                <small class="text-muted"><?= $low_stock_count ?> low, <?= $out_of_stock_count ?> out</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Current Inventory -->
                <div class="row">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="fas fa-boxes me-2"></i>
                                    Current Inventory
                                    <?php if ($selected_period): ?>
                                        - <?= date('F Y', strtotime($selected_period['period_month'] . '-01')) ?>
                                    <?php endif; ?>
                                    <small class="text-muted">(<?= $total_products ?> products)</small>
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
                                                                    <strong><?= htmlspecialchars($product['name'] ?? '') ?></strong>
                                                                    <?php if (!empty($product['supplier'])): ?>
                                                                        <br><small class="text-muted">Supplier: <?= htmlspecialchars($product['supplier']) ?></small>
                                                                    <?php endif; ?>
                                                                </div>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <code><?= htmlspecialchars($product['sku'] ?? '') ?></code>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?></span>
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
                                                                    <?= $stock_status === 'out' ? 'bg-secondary' : '' ?>"
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
                                                                <?= $stock_status === 'out' ? 'bg-secondary' : '' ?>">
                                                                <i class="fas fa-<?= [
                                                                    'high' => 'check-circle',
                                                                    'medium' => 'exclamation-circle',
                                                                    'low' => 'exclamation-triangle',
                                                                    'out' => 'times-circle'
                                                                ][$stock_status] ?>"></i>
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
                                        <p class="text-muted">Add products to your inventory to see them here.</p>
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
        function changePeriod(periodId) {
            if (periodId) {
                window.location.href = '?period_id=' + periodId;
            }
        }

        function exportToExcel() {
            const table = document.getElementById('inventoryTable');
            if (table) {
                const wb = XLSX.utils.book_new();
                const ws = XLSX.utils.table_to_sheet(table);
                XLSX.utils.book_append_sheet(wb, ws, 'Inventory Report');
                
                const periodElement = document.querySelector('.period-info-card h4');
                const periodName = periodElement ? periodElement.textContent : 'Inventory';
                XLSX.writeFile(wb, `inventory_${periodName.replace(/\s+/g, '_')}_${new Date().toISOString().split('T')[0]}.xlsx`);
            }
        }

        // Auto-refresh for current period
        <?php if ($selected_period && ($selected_period['period_month'] ?? '') == date('Y-m')): ?>
        setTimeout(function() {
            location.reload();
        }, 30000); // Refresh every 30 seconds for current period
        <?php endif; ?>
    </script>
</body>
</html>