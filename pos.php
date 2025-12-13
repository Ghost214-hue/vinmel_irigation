<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';
require_once 'functions.php';
require_once 'period_security.php';
session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isSeller() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

if (!isLoggedIn() || !isSeller()) {
    header("Location: login.php");
    exit();
}

$database = new Database();
$db = $database->getConnection();
$user_id = $_SESSION['user_id'];

$message = '';
$error = '';
$receipt_number = '';
$last_sale_amount = 0;
$receipt_id = 0;

// Company details
$company_details = [
    'name' => 'Vinmel Irrigation',
    'address' => 'Nairobi, Kenya',
    'phone' => '+254 700 000000',
    'email' => 'info@vinmel.com'
];

// Get current period
$current_period = getCurrentTimePeriod($user_id, $db);
$period_check = canModifyData($user_id, $db);

// Check if this is a print request
$is_print_request = isset($_GET['print_receipt']) && isset($_GET['receipt_number']);

/* -------------------------------------------------------
   RECEIPT FUNCTIONS
-------------------------------------------------------- */

/**
 * Generate receipt HTML for preview and printing
 */
function generateReceiptHTML($receipt_number, $transaction, $items, $company_details) {
    ob_start();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <style>
            body { 
                font-family: 'Courier New', monospace; 
                font-size: 12px; 
                margin: 0; 
                padding: 15px;
                background: white;
            }
            .receipt { 
                width: 100%; 
                max-width: 300px; 
                margin: 0 auto; 
            }
            .header { 
                text-align: center; 
                border-bottom: 2px dashed #000; 
                padding-bottom: 10px; 
                margin-bottom: 10px; 
            }
            .header h2 { 
                margin: 5px 0; 
                font-size: 16px;
                font-weight: bold;
            }
            .header p { 
                margin: 3px 0; 
                font-size: 11px;
            }
            .info { 
                margin: 10px 0; 
            }
            .info-row { 
                display: flex; 
                justify-content: space-between; 
                margin: 3px 0; 
                font-size: 11px;
            }
            .items-table { 
                width: 100%; 
                border-collapse: collapse; 
                margin: 10px 0; 
                font-size: 11px;
            }
            .items-table th, 
            .items-table td { 
                border-bottom: 1px solid #ddd; 
                padding: 5px 3px; 
                text-align: left; 
            }
            .items-table th { 
                border-bottom: 2px solid #000; 
                font-weight: bold;
            }
            .total-section { 
                margin-top: 10px; 
            }
            .total-row { 
                display: flex; 
                justify-content: space-between; 
                padding: 3px 0; 
                font-size: 11px;
            }
            .grand-total { 
                font-weight: bold; 
                font-size: 13px; 
                border-top: 2px solid #000; 
                padding-top: 8px; 
                margin-top: 8px; 
            }
            .footer { 
                text-align: center; 
                margin-top: 15px; 
                padding-top: 10px; 
                border-top: 2px dashed #000; 
                font-size: 10px; 
            }
            .divider { 
                border-bottom: 1px dashed #000; 
                margin: 8px 0; 
            }
        </style>
    </head>
    <body>
        <div class="receipt">
            <div class="header">
                <h2><?= htmlspecialchars($company_details['name']) ?></h2>
                <p><?= htmlspecialchars($company_details['address']) ?></p>
                <p>Tel: <?= htmlspecialchars($company_details['phone']) ?></p>
                <p>Email: <?= htmlspecialchars($company_details['email']) ?></p>
            </div>
            
            <div class="divider"></div>
            
            <div class="info">
                <div class="info-row">
                    <span><strong>Receipt #:</strong></span>
                    <span><?= htmlspecialchars($receipt_number) ?></span>
                </div>
                <div class="info-row">
                    <span><strong>Date:</strong></span>
                    <span><?= date('Y-m-d H:i:s', strtotime($transaction['transaction_date'])) ?></span>
                </div>
                <?php if (!empty($transaction['customer_name'])): ?>
                <div class="info-row">
                    <span><strong>Customer:</strong></span>
                    <span><?= htmlspecialchars($transaction['customer_name']) ?></span>
                </div>
                <?php endif; ?>
                <div class="info-row">
                    <span><strong>Seller:</strong></span>
                    <span><?= htmlspecialchars($transaction['seller_name']) ?></span>
                </div>
                <div class="info-row">
                    <span><strong>Payment:</strong></span>
                    <span><?= strtoupper(htmlspecialchars($transaction['payment_method'])) ?></span>
                </div>
            </div>
            
            <div class="divider"></div>
            
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Price</th>
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td style="text-align: center;"><?= $item['quantity'] ?></td>
                        <td style="text-align: right;"><?= number_format($item['unit_price'], 2) ?></td>
                        <td style="text-align: right;"><?= number_format($item['total_price'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="divider"></div>
            
            <div class="total-section">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>KSh <?= number_format($transaction['total_amount'], 2) ?></span>
                </div>
                <?php if ($transaction['discount_amount'] > 0): ?>
                <div class="total-row">
                    <span>Discount:</span>
                    <span>- KSh <?= number_format($transaction['discount_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row grand-total">
                    <span>TOTAL:</span>
                    <span>KSh <?= number_format($transaction['net_amount'], 2) ?></span>
                </div>
            </div>
            
            <div class="divider"></div>
            
            <div class="footer">
                <p><strong>Thank you for your business!</strong></p>
                <p><?= htmlspecialchars($company_details['name']) ?></p>
                <p>Date Printed: <?= date('Y-m-d H:i:s') ?></p>
            </div>
        </div>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * Generate receipt preview HTML
 */
function generateReceiptPreview($receipt_number, $transaction_data, $items, $company_details, $subtotal, $discount, $total) {
    ob_start();
    ?>
    <div class="receipt-preview-content">
        <!-- Company Header -->
        <div class="receipt-company">
            <h4 class="mb-2"><?= htmlspecialchars($company_details['name']) ?></h4>
            <p class="text-muted mb-1"><?= htmlspecialchars($company_details['address']) ?></p>
            <p class="text-muted mb-1">Tel: <?= htmlspecialchars($company_details['phone']) ?></p>
            <p class="text-muted mb-0">Email: <?= htmlspecialchars($company_details['email']) ?></p>
        </div>
        
        <!-- Receipt Info -->
        <div class="receipt-info">
            <div class="receipt-info-row">
                <span><strong>Receipt #:</strong></span>
                <span><?= htmlspecialchars($receipt_number) ?></span>
            </div>
            <div class="receipt-info-row">
                <span><strong>Date:</strong></span>
                <span><?= date('Y-m-d H:i:s') ?></span>
            </div>
            <?php if (!empty($transaction_data['customer_name'])): ?>
            <div class="receipt-info-row">
                <span><strong>Customer:</strong></span>
                <span><?= htmlspecialchars($transaction_data['customer_name']) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-info-row">
                <span><strong>Payment:</strong></span>
                <span class="text-uppercase"><?= htmlspecialchars($transaction_data['payment_method']) ?></span>
            </div>
        </div>
        
        <!-- Items Table -->
        <table class="receipt-items-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Price</th>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td>
                        <div class="fw-semibold"><?= htmlspecialchars($item['name']) ?></div>
                        <small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small>
                    </td>
                    <td style="text-align: center;"><?= $item['quantity'] ?></td>
                    <td style="text-align: right;">KSh <?= number_format($item['selling_price'], 2) ?></td>
                    <td style="text-align: right;">KSh <?= number_format($item['selling_price'] * $item['quantity'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Totals -->
        <div class="receipt-totals-section">
            <div class="receipt-totals-row">
                <span>Subtotal:</span>
                <span>KSh <?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if ($discount > 0): ?>
            <div class="receipt-totals-row">
                <span>Discount:</span>
                <span>- KSh <?= number_format($discount, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="receipt-grand-total">
                <span>TOTAL:</span>
                <span>KSh <?= number_format($total, 2) ?></span>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="receipt-footer">
            <p class="mb-1">Thank you for your business!</p>
            <p class="mb-0"><?= htmlspecialchars($company_details['name']) ?></p>
            <p class="mb-0">Date Previewed: <?= date('Y-m-d H:i:s') ?></p>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Save receipt details
 */
function saveReceipt($transaction_id, $receipt_number, $user_id, $db, $period_id = null) {
    try {
        $transaction_sql = "SELECT t.*, u.name as seller_name, c.name as customer_name, 
                                   c.phone as customer_phone, c.email as customer_email
                          FROM transactions t
                          LEFT JOIN users u ON t.user_id = u.id
                          LEFT JOIN customers c ON t.customer_id = c.id
                          WHERE t.id = ?";
        $stmt = $db->prepare($transaction_sql);
        $stmt->bind_param("i", $transaction_id);
        $stmt->execute();
        $transaction = $stmt->get_result()->fetch_assoc();
        
        if (!$transaction) throw new Exception("Transaction not found");
        
        $items_sql = "SELECT ti.*, p.name as product_name, p.sku 
                     FROM transaction_items ti
                     JOIN products p ON ti.product_id = p.id
                     WHERE ti.transaction_id = ?
                     ORDER BY ti.id";
        $items_stmt = $db->prepare($items_sql);
        $items_stmt->bind_param("i", $transaction_id);
        $items_stmt->execute();
        $items_result = $items_stmt->get_result();
        $items = [];
        
        while ($item = $items_result->fetch_assoc()) {
            $items[] = [
                'product_name' => $item['product_name'],
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'unit_price' => $item['unit_price'],
                'total_price' => $item['total_price']
            ];
        }
        
        $company_details = [
            'name' => 'Vinmel Irrigation',
            'address' => 'Nairobi, Kenya',
            'phone' => '+254 700 000000',
            'email' => 'info@vinmel.com'
        ];
        
        $receipt_html = generateReceiptHTML($receipt_number, $transaction, $items, $company_details);
        
        $table_check = "SHOW TABLES LIKE 'receipts'";
        $table_exists = $db->query($table_check)->num_rows > 0;
        
        if (!$table_exists) {
            $create_table = "CREATE TABLE IF NOT EXISTS `receipts` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `transaction_id` int(11) NOT NULL,
                `receipt_number` varchar(50) NOT NULL,
                `customer_name` varchar(255) DEFAULT NULL,
                `customer_phone` varchar(20) DEFAULT NULL,
                `customer_email` varchar(100) DEFAULT NULL,
                `seller_id` int(11) NOT NULL,
                `seller_name` varchar(100) NOT NULL,
                `total_amount` decimal(10,2) NOT NULL,
                `discount_amount` decimal(10,2) DEFAULT 0.00,
                `net_amount` decimal(10,2) NOT NULL,
                `payment_method` enum('cash','mpesa') DEFAULT 'cash',
                `transaction_date` datetime NOT NULL,
                `items_json` text NOT NULL,
                `receipt_html` text NOT NULL,
                `period_id` int(11) DEFAULT NULL,
                `company_details` text DEFAULT NULL,
                `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
                PRIMARY KEY (`id`),
                UNIQUE KEY `receipt_number` (`receipt_number`),
                KEY `transaction_id` (`transaction_id`),
                KEY `seller_id` (`seller_id`),
                KEY `period_id` (`period_id`),
                KEY `transaction_date` (`transaction_date`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            
            if (!$db->query($create_table)) {
                throw new Exception("Failed to create receipts table: " . $db->error);
            }
        }
        
        $insert_sql = "INSERT INTO receipts (
            transaction_id, receipt_number, customer_name, customer_phone, customer_email,
            seller_id, seller_name, total_amount, discount_amount, net_amount,
            payment_method, transaction_date, items_json, receipt_html, period_id, company_details
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $insert_stmt = $db->prepare($insert_sql);
        $items_json = json_encode($items);
        $company_json = json_encode($company_details);
        $insert_stmt->bind_param(
            "issssiddddssssis",
            $transaction_id,
            $receipt_number,
            $transaction['customer_name'],
            $transaction['customer_phone'],
            $transaction['customer_email'],
            $user_id,
            $transaction['seller_name'],
            $transaction['total_amount'],
            $transaction['discount_amount'],
            $transaction['net_amount'],
            $transaction['payment_method'],
            $transaction['transaction_date'],
            $items_json,
            $receipt_html,
            $period_id,
            $company_json
        );
        
        return $insert_stmt->execute();
        
    } catch (Exception $e) {
        error_log("Error saving receipt: " . $e->getMessage());
        return false;
    }
}

/* -------------------------------------------------------
   HANDLE PRINT RECEIPT REQUEST
-------------------------------------------------------- */

if ($is_print_request) {
    $receipt_number = $_GET['receipt_number'];
    
    $sql = "SELECT receipt_html FROM receipts WHERE receipt_number = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("s", $receipt_number);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $receipt = $result->fetch_assoc();
        echo $receipt['receipt_html'];
        exit();
    } else {
        // Fallback: generate receipt from session
        if (isset($_SESSION['last_receipt_data'])) {
            $data = $_SESSION['last_receipt_data'];
            
            // Create transaction data structure
            $transaction_data = [
                'transaction_date' => date('Y-m-d H:i:s'),
                'customer_name' => $data['customer_name'] ?? null,
                'seller_name' => $_SESSION['name'] ?? 'Seller',
                'payment_method' => $data['payment_method'] ?? 'cash',
                'total_amount' => $data['subtotal'] ?? 0,
                'discount_amount' => $data['discount'] ?? 0,
                'net_amount' => $data['total'] ?? 0
            ];
            
            // Create items array
            $items = [];
            foreach ($data['items'] as $item) {
                $items[] = [
                    'product_name' => $item['name'],
                    'sku' => $item['sku'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['selling_price'],
                    'total_price' => $item['selling_price'] * $item['quantity']
                ];
            }
            
            echo generateReceiptHTML($receipt_number, $transaction_data, $items, $company_details);
            exit();
        }
    }
    
    // If nothing found, show error
    echo "<html><body><h1>Receipt not found</h1></body></html>";
    exit();
}

/* -------------------------------------------------------
   CART MANAGEMENT
-------------------------------------------------------- */

// Initialize cart if not exists
if (!isset($_SESSION['pos_cart'])) {
    $_SESSION['pos_cart'] = [];
    $_SESSION['pos_customer'] = null;
    $_SESSION['pos_discount'] = 0;
    $_SESSION['pos_payment_method'] = 'cash';
}

// Handle add to cart with quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']) ?? 1;
    
    if ($quantity <= 0) {
        $error = "Quantity must be greater than 0!";
    } else {
        $sql = "SELECT p.*, c.name as category_name 
                FROM products p 
                LEFT JOIN categories c ON p.category_id = c.id 
                WHERE p.id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $product_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $product = $result->fetch_assoc();
            
            if ($product['stock_quantity'] < $quantity) {
                $error = "Insufficient stock! Only {$product['stock_quantity']} units available.";
            } else {
                if (isset($_SESSION['pos_cart'][$product_id])) {
                    $_SESSION['pos_cart'][$product_id]['quantity'] += $quantity;
                } else {
                    $_SESSION['pos_cart'][$product_id] = [
                        'name' => $product['name'],
                        'sku' => $product['sku'],
                        'selling_price' => $product['selling_price'],
                        'quantity' => $quantity,
                        'category' => $product['category_name'],
                        'description' => $product['description'] ?? ''
                    ];
                }
                $message = "{$product['name']} (x{$quantity}) added to cart!";
            }
        } else {
            $error = "Product not found!";
        }
    }
}

// Handle update cart quantity
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_cart'])) {
    $product_id = intval($_POST['product_id']);
    $quantity = intval($_POST['quantity']);
    
    if ($quantity <= 0) {
        unset($_SESSION['pos_cart'][$product_id]);
        $message = "Item removed from cart!";
    } else {
        if (isset($_SESSION['pos_cart'][$product_id])) {
            // Check stock
            $sql = "SELECT stock_quantity FROM products WHERE id = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("i", $product_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $product = $result->fetch_assoc();
            
            if ($product['stock_quantity'] >= $quantity) {
                $_SESSION['pos_cart'][$product_id]['quantity'] = $quantity;
                $message = "Cart updated!";
            } else {
                $error = "Insufficient stock! Only {$product['stock_quantity']} units available.";
            }
        }
    }
}

// Handle remove from cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_from_cart'])) {
    $product_id = intval($_POST['product_id']);
    
    if (isset($_SESSION['pos_cart'][$product_id])) {
        unset($_SESSION['pos_cart'][$product_id]);
        $message = "Item removed from cart!";
    }
}

// Handle customer update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_customer'])) {
    $customer_name = trim($_POST['customer_name']);
    $customer_phone = trim($_POST['customer_phone']);
    $customer_email = trim($_POST['customer_email']);
    
    $_SESSION['pos_customer'] = [
        'name' => $customer_name,
        'phone' => $customer_phone,
        'email' => $customer_email
    ];
    
    $message = "Customer information updated!";
}

// Handle discount update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_discount'])) {
    $discount = floatval($_POST['discount']);
    
    if ($discount >= 0) {
        $_SESSION['pos_discount'] = $discount;
        $message = "Discount updated!";
    } else {
        $error = "Discount cannot be negative!";
    }
}

// Handle payment method update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_payment_method'])) {
    $_SESSION['pos_payment_method'] = $_POST['payment_method'];
    $message = "Payment method updated!";
}

// Handle complete sale with receipt preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_sale'])) {
    if (empty($_SESSION['pos_cart'])) {
        $error = "Cart is empty! Add products to complete sale.";
    } else {
        // Calculate totals
        $subtotal = 0;
        $cart_items_detail = [];
        foreach ($_SESSION['pos_cart'] as $product_id => $item) {
            $item_total = $item['selling_price'] * $item['quantity'];
            $subtotal += $item_total;
            $cart_items_detail[] = [
                'name' => $item['name'],
                'sku' => $item['sku'],
                'selling_price' => $item['selling_price'],
                'quantity' => $item['quantity']
            ];
        }
        
        $discount_amount = $_SESSION['pos_discount'];
        $net_amount = $subtotal - $discount_amount;
        
        // Generate receipt number
        $receipt_number = 'RCP' . date('Ymd') . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        
        // Store for receipt preview
        $_SESSION['last_receipt_preview'] = [
            'receipt_number' => $receipt_number,
            'subtotal' => $subtotal,
            'discount' => $discount_amount,
            'total' => $net_amount,
            'customer_name' => $_SESSION['pos_customer']['name'] ?? null,
            'payment_method' => $_SESSION['pos_payment_method'],
            'items' => $cart_items_detail
        ];
        
        // Show receipt preview popup
        $show_receipt_preview = true;
    }
}

// Handle confirm sale after preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_sale'])) {
    if (isset($_SESSION['last_receipt_preview'])) {
        $receipt_data = $_SESSION['last_receipt_preview'];
        $receipt_number = $receipt_data['receipt_number'];
        
        // Start transaction
        $db->begin_transaction();
        
        try {
            // Get period ID if exists
            $period_id = $current_period ? $current_period['id'] : null;
            
            // Insert transaction
            $transaction_sql = "INSERT INTO transactions (
                receipt_number, user_id, total_amount, discount_amount, net_amount, 
                payment_method, transaction_date, time_period_id
            ) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)";
            
            $transaction_stmt = $db->prepare($transaction_sql);
            $transaction_stmt->bind_param(
                "sidddsi",
                $receipt_number,
                $user_id,
                $receipt_data['subtotal'],
                $receipt_data['discount'],
                $receipt_data['total'],
                $receipt_data['payment_method'],
                $period_id
            );
            
            if (!$transaction_stmt->execute()) {
                throw new Exception("Failed to create transaction: " . $transaction_stmt->error);
            }
            
            $transaction_id = $db->insert_id;
            
            // Insert transaction items and update stock
            foreach ($_SESSION['pos_cart'] as $product_id => $item) {
                // Insert transaction item
                $item_sql = "INSERT INTO transaction_items (
                    transaction_id, product_id, quantity, unit_price, total_price
                ) VALUES (?, ?, ?, ?, ?)";
                
                $item_stmt = $db->prepare($item_sql);
                $total_price = $item['selling_price'] * $item['quantity'];
                
                $item_stmt->bind_param(
                    "iiidd", 
                    $transaction_id, $product_id, $item['quantity'],
                    $item['selling_price'], $total_price
                );
                
                if (!$item_stmt->execute()) {
                    throw new Exception("Failed to add transaction item: " . $item_stmt->error);
                }
                
                // Update product stock
                $update_stock_sql = "UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?";
                $update_stmt = $db->prepare($update_stock_sql);
                $update_stmt->bind_param("ii", $item['quantity'], $product_id);
                
                if (!$update_stmt->execute()) {
                    throw new Exception("Failed to update product stock: " . $update_stmt->error);
                }
            }
            
            // Save receipt
            $receipt_saved = saveReceipt($transaction_id, $receipt_number, $user_id, $db, $period_id);
            
            if ($receipt_saved) {
                $receipt_sql = "SELECT id FROM receipts WHERE receipt_number = ?";
                $receipt_stmt = $db->prepare($receipt_sql);
                $receipt_stmt->bind_param("s", $receipt_number);
                $receipt_stmt->execute();
                $receipt_result = $receipt_stmt->get_result();
                $receipt_data = $receipt_result->fetch_assoc();
                
                $receipt_id = $receipt_data['id'] ?? 0;
                
                $message = "Sale completed successfully! Receipt: $receipt_number";
                
                // Store for printing
                $_SESSION['last_receipt_data'] = array_merge($_SESSION['last_receipt_preview'], [
                    'transaction_id' => $transaction_id
                ]);
                
                // Clear receipt preview
                unset($_SESSION['last_receipt_preview']);
            } else {
                $message = "Sale completed but receipt saving failed! Transaction ID: $transaction_id";
            }
            
            // Commit transaction
            $db->commit();
            
            // Clear cart
            $_SESSION['pos_cart'] = [];
            $_SESSION['pos_discount'] = 0;
            $_SESSION['pos_customer'] = null;
            
        } catch (Exception $e) {
            $db->rollback();
            $error = "Transaction failed: " . $e->getMessage();
            unset($_SESSION['last_receipt_preview']);
        }
    }
}

// Handle cancel sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_sale'])) {
    unset($_SESSION['last_receipt_preview']);
    $message = "Sale cancelled. Cart preserved.";
}

// Handle clear cart
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear_cart'])) {
    $_SESSION['pos_cart'] = [];
    $_SESSION['pos_discount'] = 0;
    $_SESSION['pos_customer'] = null;
    $message = "Cart cleared!";
}

/* -------------------------------------------------------
   FETCH PRODUCTS
-------------------------------------------------------- */

$sql = "SELECT p.*, c.name as category_name, u.name as creator_name
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        LEFT JOIN users u ON p.created_by = u.id 
        WHERE p.stock_quantity > 0 
        ORDER BY p.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute();
$products = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

// Calculate cart totals
$cart_total = 0;
$cart_items = 0;
foreach ($_SESSION['pos_cart'] as $item) {
    $cart_total += $item['selling_price'] * $item['quantity'];
    $cart_items += $item['quantity'];
}

$display_discount = $_SESSION['pos_discount'];
$display_net = $cart_total - $display_discount;
$display_cart_total = $cart_total;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS System - Vinmel Irrigation</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="style.css" rel="stylesheet">
 
</head>
<body>
    <?php include 'nav_bar.php'; ?>
    
    <div class="main-content">
        <?php include 'header.php'; ?>

        <div class="content-area">
            <div class="container-fluid">
                <!-- Page Header -->
                <div class="dashboard-header mb-4">
                    <div>
                        <h1 class="h2">
                            <i class="fas fa-cash-register me-2"></i>
                            POS System
                        </h1>
                        <p class="lead mb-0">Professional Point of Sale</p>
                    </div>
                    <div class="text-end">
                        <?php if ($current_period): ?>
                            <div class="badge bg-primary fs-6 p-2">
                                <i class="fas fa-calendar me-1"></i>
                                <?= $current_period['period_name'] ?>
                            </div>
                        <?php endif; ?>
                        <div class="badge bg-success fs-6 p-2 ms-2">
                            <i class="fas fa-shopping-cart me-1"></i>
                            <?= $cart_items ?> item<?= $cart_items != 1 ? 's' : '' ?>
                        </div>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?= $message ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i><?= $error ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Receipt Preview Modal -->
                <?php if (isset($show_receipt_preview) && $show_receipt_preview && isset($_SESSION['last_receipt_preview'])): 
                    $receipt_data = $_SESSION['last_receipt_preview'];
                ?>
                    <div class="modal fade show" id="receiptModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
                        <div class="modal-dialog modal-lg">
                            <div class="modal-content">
                                <div class="modal-header">
                                    <h5 class="modal-title">
                                        <i class="fas fa-receipt me-2"></i>
                                        Receipt Preview
                                    </h5>
                                    <button type="button" class="btn-close" onclick="closeReceiptModal()"></button>
                                </div>
                                <div class="modal-body">
                                    <div class="alert alert-info mb-3">
                                        <i class="fas fa-info-circle me-2"></i>
                                        Review the receipt below before completing the sale.
                                    </div>
                                    
                                    <div class="receipt-preview">
                                        <?= generateReceiptPreview(
                                            $receipt_data['receipt_number'],
                                            [
                                                'customer_name' => $receipt_data['customer_name'],
                                                'payment_method' => $receipt_data['payment_method']
                                            ],
                                            $receipt_data['items'],
                                            $company_details,
                                            $receipt_data['subtotal'],
                                            $receipt_data['discount'],
                                            $receipt_data['total']
                                        ) ?>
                                    </div>
                                    
                                    <div class="receipt-actions">
                                        <form method="POST" class="d-inline">
                                            <button type="submit" name="cancel_sale" class="btn btn-outline-secondary">
                                                <i class="fas fa-times me-2"></i>Cancel
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-success" onclick="printReceiptPreview('<?= $receipt_data['receipt_number'] ?>')">
                                            <i class="fas fa-print me-2"></i>Print
                                        </button>
                                        <form method="POST" class="d-inline">
                                            <button type="submit" name="confirm_sale" class="btn btn-primary">
                                                <i class="fas fa-check me-2"></i>Confirm & Save
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Period Security Warning -->
                <?php displayPeriodSecurityWarning($user_id, $db); ?>

                <!-- POS Interface -->
                <div class="pos-container">
                    <!-- Products Section -->
                    <div class="products-section">
                        <!-- Search and Filter -->
                        <div class="pos-search-container">
                            <div class="d-flex gap-2 mb-3">
                                <input type="text" id="productSearch" class="form-control pos-search" 
                                       placeholder="Search products by name or SKU...">
                                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal">
                                    <i class="fas fa-user me-2"></i>Customer
                                </button>
                            </div>

                            <!-- Category Filter -->
                            <div class="category-filter">
                                <button class="category-btn active" data-category="all">All Products</button>
                                <?php
                                $categories = [];
                                foreach ($products as $product) {
                                    $category = $product['category_name'] ?? 'Uncategorized';
                                    if ($category && !in_array($category, $categories)) {
                                        $categories[] = $category;
                                    }
                                }
                                sort($categories);
                                foreach ($categories as $category): ?>
                                    <button class="category-btn" data-category="<?= htmlspecialchars($category) ?>">
                                        <?= htmlspecialchars($category) ?>
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Products Grid -->
                        <div class="products-grid" id="productsGrid">
                            <?php if (empty($products)): ?>
                                <div class="empty-products">
                                    <i class="fas fa-box-open"></i>
                                    <p>No products available</p>
                                    <p class="small">Add products to your inventory first</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($products as $product): 
                                    $stock_status = $product['stock_quantity'] <= 0 ? 'out' : 
                                                   ($product['stock_quantity'] <= $product['min_stock'] ? 'low' : 'ok');
                                    $stock_color = $stock_status == 'ok' ? 'high' : 
                                                  ($stock_status == 'low' ? 'low' : 'medium');
                                ?>
                                    <div class="product-card" 
                                         data-product-id="<?= $product['id'] ?>" 
                                         data-category="<?= htmlspecialchars($product['category_name'] ?? 'Uncategorized') ?>"
                                         data-name="<?= htmlspecialchars(strtolower($product['name'])) ?>"
                                         data-sku="<?= htmlspecialchars(strtolower($product['sku'])) ?>">
                                        
                                        <div class="product-card-header">
                                            <div class="product-icon">
                                                <i class="fas fa-box"></i>
                                            </div>
                                            <div>
                                                <div class="product-name"><?= htmlspecialchars($product['name']) ?></div>
                                                <div class="product-sku">SKU: <?= htmlspecialchars($product['sku']) ?></div>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($product['description'])): ?>
                                            <div class="product-description">
                                                <?= htmlspecialchars(substr($product['description'], 0, 100)) ?>
                                                <?= (strlen($product['description']) > 100) ? '...' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="product-price">KSH <?= number_format($product['selling_price'], 2) ?></div>
                                        
                                        <div class="stock-info">
                                            <span class="stock-indicator-dot <?= $stock_color ?>"></span>
                                            <span>Stock: <?= $product['stock_quantity'] ?></span>
                                        </div>
                                        
                                        <div class="quantity-section">
                                            <form method="POST" class="add-to-cart-form" onsubmit="return validateQuantity(this, <?= $product['stock_quantity'] ?>)">
                                                <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                                
                                                <div class="quantity-control-group">
                                                    <div class="quantity-input-wrapper">
                                                        <button type="button" class="quantity-btn quantity-decrease" onclick="decreaseQuantity(this)">
                                                            <i class="fas fa-minus"></i>
                                                        </button>
                                                        <input type="number" name="quantity" value="1" min="1" 
                                                               max="<?= $product['stock_quantity'] ?>" 
                                                               class="form-control quantity-input"
                                                               onchange="updateTotalPrice(this, <?= $product['selling_price'] ?>, '<?= $product['id'] ?>')">
                                                        <button type="button" class="quantity-btn quantity-increase" onclick="increaseQuantity(this, <?= $product['stock_quantity'] ?>)">
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </div>
                                                </div>
                                                
                                                <div class="quantity-quick-buttons">
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 1)">1</button>
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 2)">2</button>
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 5)">5</button>
                                                    <button type="button" class="quantity-quick-btn" onclick="setQuickQuantity(this, 10)">10</button>
                                                </div>
                                                
                                                <div class="product-total-price" id="total-price-<?= $product['id'] ?>">
                                                    Total: KSH <?= number_format($product['selling_price'], 2) ?>
                                                </div>
                                                
                                                <button type="submit" name="add_to_cart" class="btn btn-success btn-sm w-100">
                                                    <i class="fas fa-cart-plus me-2"></i>Add to Cart
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Cart Sidebar -->
                    <div class="cart-sidebar">
                        <div class="cart-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h4 class="mb-1">
                                        <i class="fas fa-shopping-cart me-2"></i>
                                        Shopping Cart
                                    </h4>
                                    <small><?= $cart_items ?> item<?= $cart_items != 1 ? 's' : '' ?> in cart</small>
                                </div>
                                <?php if ($_SESSION['pos_customer'] && !empty($_SESSION['pos_customer']['name'])): ?>
                                    <div class="text-end">
                                        <small class="d-block">Customer:</small>
                                        <strong><?= htmlspecialchars($_SESSION['pos_customer']['name']) ?></strong>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Cart Items -->
                        <div class="cart-items-container">
                            <?php if (empty($_SESSION['pos_cart'])): ?>
                                <div class="empty-cart">
                                    <i class="fas fa-shopping-cart"></i>
                                    <p>Your cart is empty</p>
                                    <p class="small">Add products from the first panel</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($_SESSION['pos_cart'] as $product_id => $item): 
                                    $item_total = $item['selling_price'] * $item['quantity'];
                                ?>
                                    <div class="cart-item">
                                        <div class="cart-item-details">
                                            <div class="cart-item-name"><?= htmlspecialchars($item['name']) ?></div>
                                            <div class="cart-item-meta">
                                                <span class="cart-item-price">
                                                    KSH <?= number_format($item['selling_price'], 2) ?>
                                                </span>
                                                <?php if (!empty($item['sku'])): ?>
                                                    <span class="cart-item-sku">SKU: <?= htmlspecialchars($item['sku']) ?></span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="cart-item-quantity">
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                <input type="hidden" name="quantity" value="<?= $item['quantity'] - 1 ?>">
                                                <button type="submit" name="update_cart" class="quantity-btn" 
                                                        <?= $item['quantity'] <= 1 ? 'disabled' : '' ?>>
                                                    <i class="fas fa-minus"></i>
                                                </button>
                                            </form>
                                            <span class="quantity-display"><?= $item['quantity'] ?></span>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                                <input type="hidden" name="quantity" value="<?= $item['quantity'] + 1 ?>">
                                                <button type="submit" name="update_cart" class="quantity-btn">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </form>
                                        </div>
                                        
                                        <div class="cart-item-total">
                                            <div class="cart-total">KSH <?= number_format($item_total, 2) ?></div>
                                        </div>
                                        
                                        <form method="POST" class="d-inline">
                                            <input type="hidden" name="product_id" value="<?= $product_id ?>">
                                            <button type="submit" name="remove_from_cart" class="btn btn-link p-0 text-danger">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Payment Method -->
                        <div class="payment-dropdown mb-3">
                            <h6 class="mb-2">
                                <i class="fas fa-money-bill-wave me-2"></i>Payment Method
                            </h6>
                            <form method="POST" id="paymentForm">
                                <select name="payment_method" class="form-select" onchange="this.form.submit()">
                                    <option value="cash" <?= $_SESSION['pos_payment_method'] === 'cash' ? 'selected' : '' ?>>
                                        💵 Walk-in (Cash)
                                    </option>
                                    <option value="mpesa" <?= $_SESSION['pos_payment_method'] === 'mpesa' ? 'selected' : '' ?>>
                                        📱 M-Pesa
                                    </option>
                                </select>
                                <input type="hidden" name="update_payment_method" value="1">
                            </form>
                        </div>

                        <!-- Discount -->
                        <div class="discount-section">
                            <form method="POST" class="d-flex w-100 gap-2">
                                <input type="number" name="discount" value="<?= $display_discount ?>" 
                                       step="0.01" min="0" class="form-control discount-input" 
                                       placeholder="Discount amount">
                                <button type="submit" name="update_discount" class="btn btn-outline-primary">
                                    <i class="fas fa-tag"></i> Apply
                                </button>
                            </form>
                        </div>

                        <!-- Cart Summary -->
                        <div class="cart-summary">
                            <div class="summary-row">
                                <span>Subtotal:</span>
                                <span>KSH <?= number_format($cart_total, 2) ?></span>
                            </div>
                            
                            <?php if ($display_discount > 0): ?>
                            <div class="summary-row text-danger">
                                <span>Discount:</span>
                                <span>- KSH <?= number_format($display_discount, 2) ?></span>
                            </div>
                            <?php endif; ?>
                            
                            <div class="summary-row summary-total">
                                <span>TOTAL:</span>
                                <span>KSH <?= number_format($display_net, 2) ?></span>
                            </div>
                        </div>

                        <!-- Action Buttons -->
                        <div class="action-buttons">
                            <form method="POST">
                                <button type="submit" name="clear_cart" class="btn btn-clear w-100" 
                                        <?= empty($_SESSION['pos_cart']) ? 'disabled' : '' ?>>
                                    <i class="fas fa-trash me-2"></i>Clear Cart
                                </button>
                            </form>
                            <form method="POST">
                                <button type="submit" name="complete_sale" class="btn btn-complete w-100" 
                                        <?= empty($_SESSION['pos_cart']) || !$period_check['allowed'] ? 'disabled' : '' ?>>
                                    <i class="fas fa-check me-2"></i>Complete Sale
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Customer Modal -->
    <div class="modal fade" id="customerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Customer Information
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Customer Name</label>
                            <input type="text" class="form-control" name="customer_name" 
                                   value="<?= $_SESSION['pos_customer']['name'] ?? '' ?>" 
                                   placeholder="Walk-in Customer">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="customer_phone" 
                                   value="<?= $_SESSION['pos_customer']['phone'] ?? '' ?>" 
                                   placeholder="+254...">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email Address</label>
                            <input type="email" class="form-control" name="customer_email" 
                                   value="<?= $_SESSION['pos_customer']['email'] ?? '' ?>" 
                                   placeholder="customer@example.com">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" name="update_customer" class="btn btn-primary">Save</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script><script>
    // Quantity control functions
    function decreaseQuantity(button) {
        const input = button.closest('.quantity-input-wrapper').querySelector('.quantity-input');
        const currentValue = parseInt(input.value) || 1;
        if (currentValue > 1) {
            input.value = currentValue - 1;
            input.dispatchEvent(new Event('change'));
        }
    }

    function increaseQuantity(button, maxStock) {
        const input = button.closest('.quantity-input-wrapper').querySelector('.quantity-input');
        const currentValue = parseInt(input.value) || 1;
        if (currentValue < maxStock) {
            input.value = currentValue + 1;
            input.dispatchEvent(new Event('change'));
        } else {
            alert(`Maximum stock available: ${maxStock}`);
        }
    }

    function setQuickQuantity(button, quantity) {
        const form = button.closest('.add-to-cart-form');
        const input = form.querySelector('.quantity-input');
        const maxStock = parseInt(input.getAttribute('max')) || 999;
        
        if (quantity <= maxStock) {
            input.value = quantity;
            input.dispatchEvent(new Event('change'));
        } else {
            input.value = maxStock;
            input.dispatchEvent(new Event('change'));
            alert(`Maximum stock available: ${maxStock}`);
        }
    }

    function updateTotalPrice(input, unitPrice, productId) {
        const quantity = parseInt(input.value) || 1;
        const total = quantity * unitPrice;
        const totalElement = document.getElementById(`total-price-${productId}`);
        if (totalElement) {
            totalElement.textContent = `Total: KSH ${total.toFixed(2)}`;
        }
    }

    function validateQuantity(form, maxStock) {
        const input = form.querySelector('.quantity-input');
        const quantity = parseInt(input.value) || 0;
        
        if (quantity <= 0) {
            alert('Quantity must be greater than 0');
            input.focus();
            return false;
        }
        
        if (quantity > maxStock) {
            alert(`Only ${maxStock} units available in stock`);
            input.value = maxStock;
            const productId = form.querySelector('input[name="product_id"]').value;
            const card = form.closest('.product-card');
            const priceElement = card ? card.querySelector('.product-price') : null;
            const priceText = priceElement ? priceElement.textContent : '';
            const unitPrice = parseFloat(priceText.replace('KSH', '').replace(',', '').trim());
            updateTotalPrice(input, unitPrice, productId);
            return false;
        }
        
        return true;
    }

    // Initialize total prices on page load
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.add-to-cart-form').forEach(form => {
            const input = form.querySelector('.quantity-input');
            
            // Find the parent product card
            const card = form.closest('.product-card');
            
            // Look for price in the card
            const priceElement = card ? card.querySelector('.product-price') : null;
            const priceText = priceElement ? priceElement.textContent : '';
            const unitPrice = parseFloat(priceText.replace('KSH', '').replace(',', '').trim());
            const productId = form.querySelector('input[name="product_id"]').value;
            
            if (input && !isNaN(unitPrice)) {
                updateTotalPrice(input, unitPrice, productId);
            }
        });
        
        // Ensure all buttons are visible on load
        ensureButtonsVisible();
    });

    // Function to ensure all add-to-cart buttons are visible
    function ensureButtonsVisible() {
        const buttons = document.querySelectorAll('.add-to-cart-form button[type="submit"]');
        buttons.forEach(button => {
            button.style.display = 'flex';
            button.style.visibility = 'visible';
            button.style.opacity = '1';
        });
    }

    // Product search with button visibility check
    document.getElementById('productSearch').addEventListener('input', function(e) {
        const searchTerm = e.target.value.toLowerCase();
        const productCards = document.querySelectorAll('.product-card');
        
        productCards.forEach(card => {
            const name = card.getAttribute('data-name');
            const sku = card.getAttribute('data-sku');
            
            if (name.includes(searchTerm) || sku.includes(searchTerm)) {
                card.style.display = 'block';
            } else {
                card.style.display = 'none';
            }
        });
        
        // Ensure buttons remain visible after search
        setTimeout(ensureButtonsVisible, 100);
    });

    // Category filter with button visibility check
    document.querySelectorAll('.category-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            document.querySelectorAll('.category-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            const selectedCategory = this.getAttribute('data-category');
            const productCards = document.querySelectorAll('.product-card');
            
            productCards.forEach(card => {
                const category = card.getAttribute('data-category');
                
                if (selectedCategory === 'all' || category === selectedCategory) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
            
            // Ensure buttons remain visible after filtering
            setTimeout(ensureButtonsVisible, 100);
        });
    });

    // Receipt modal functions
    function closeReceiptModal() {
        const modal = document.getElementById('receiptModal');
        if (modal) {
            modal.style.display = 'none';
        }
        
        // Submit cancel form
        const cancelBtn = document.querySelector('[name="cancel_sale"]');
        if (cancelBtn) {
            cancelBtn.closest('form').submit();
        }
    }

    function printReceiptPreview(receiptNumber) {
        const printWindow = window.open('pos.php?print_receipt=1&receipt_number=' + encodeURIComponent(receiptNumber), '_blank');
        if (printWindow) {
            printWindow.focus();
        }
    }

    // Print receipt after successful sale
    <?php if ($receipt_id > 0 && isset($_SESSION['last_receipt_data'])): ?>
    setTimeout(() => {
        printReceiptPreview('<?= $_SESSION['last_receipt_data']['receipt_number'] ?>');
    }, 500);
    <?php endif; ?>

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl + P to print receipt when modal is open
        if (e.ctrlKey && e.key === 'p' && document.getElementById('receiptModal')) {
            e.preventDefault();
            const modal = document.getElementById('receiptModal');
            if (modal && modal.style.display === 'block') {
                const receiptNumber = modal.getAttribute('data-receipt-number');
                if (receiptNumber) {
                    printReceiptPreview(receiptNumber);
                }
            }
        }
        
        // Ctrl + S to save/confirm
        if (e.ctrlKey && e.key === 's' && document.getElementById('receiptModal')) {
            e.preventDefault();
            const confirmBtn = document.querySelector('[name="confirm_sale"]');
            if (confirmBtn) {
                confirmBtn.click();
            }
        }
        
        // Ctrl + C to cancel (when modal is open)
        if (e.ctrlKey && e.key === 'c' && document.getElementById('receiptModal')) {
            e.preventDefault();
            const cancelBtn = document.querySelector('[name="cancel_sale"]');
            if (cancelBtn) {
                cancelBtn.click();
            }
        }
        
        // Ctrl + F to focus search
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            const searchInput = document.getElementById('productSearch');
            if (searchInput) {
                searchInput.focus();
            }
        }
        
        // Ctrl + D to focus discount
        if (e.ctrlKey && e.key === 'd') {
            e.preventDefault();
            const discountInput = document.querySelector('.discount-input');
            if (discountInput) {
                discountInput.focus();
            }
        }
    });

    // Auto-hide success messages
    <?php if ($message && !isset($show_receipt_preview)): ?>
    setTimeout(() => {
        const alert = document.querySelector('.alert-success');
        if (alert) {
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 300);
        }
    }, 5000);
    <?php endif; ?>

    // Auto-focus quantity input when clicking product card
    document.querySelectorAll('.product-card').forEach(card => {
        card.addEventListener('click', function(e) {
            // Don't trigger if clicking on interactive elements
            if (!e.target.closest('.add-to-cart-form') && 
                !e.target.closest('.quantity-btn') && 
                !e.target.closest('.quantity-quick-btn') &&
                !e.target.closest('button')) {
                const input = this.querySelector('.quantity-input');
                if (input) {
                    input.focus();
                    input.select();
                }
            }
        });
    });

    // Periodically check button visibility (failsafe)
    setInterval(ensureButtonsVisible, 2000);
</script>
</body>
</html>