<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Check authentication
checkAuth();

// Get current period and user
$current_period = getCurrentTimePeriod($db);
$current_user = getCurrentUser();

// Handle filters
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';
$search = $_GET['search'] ?? '';
$period_filter = $_GET['period'] ?? '';

// Get all available periods for the selector
$periods_query = "SELECT * FROM time_periods ORDER BY year DESC, month DESC";
$periods_result = $db->query($periods_query);
$all_periods = [];
while ($period = $periods_result->fetch_assoc()) {
    $all_periods[] = $period;
}

// Build query for sales transactions (these are your income)
$query = "
    SELECT 
        t.id,
        t.receipt_number,
        t.total_amount,
        t.tax_amount,
        t.discount_amount,
        t.net_amount,
        t.payment_method,
        t.transaction_date,
        c.name as customer_name,
        u.name as user_name,
        tp.period_name,
        COUNT(ti.id) as item_count,
        GROUP_CONCAT(p.name SEPARATOR ', ') as product_names,
        r.id as receipt_id
    FROM transactions t
    LEFT JOIN customers c ON t.customer_id = c.id
    LEFT JOIN users u ON t.user_id = u.id
    LEFT JOIN time_periods tp ON t.time_period_id = tp.id
    LEFT JOIN transaction_items ti ON t.id = ti.transaction_id
    LEFT JOIN products p ON ti.product_id = p.id
    LEFT JOIN receipts r ON t.id = r.transaction_id
    WHERE 1=1
";

$params = [];
$types = '';

// Apply period filter (priority over date range)
if (!empty($period_filter)) {
    $query .= " AND t.time_period_id = ?";
    $params[] = $period_filter;
    $types .= 'i';
} 
// Apply date filters if no period selected
elseif (!empty($start_date) && !empty($end_date)) {
    $query .= " AND DATE(t.transaction_date) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= 'ss';
} elseif ($current_period && empty($period_filter)) {
    // Default to current period if no filters specified
    $query .= " AND t.time_period_id = ?";
    $params[] = $current_period['id'];
    $types .= 'i';
}

// Apply search filter
if (!empty($search)) {
    $query .= " AND (t.receipt_number LIKE ? OR c.name LIKE ? OR u.name LIKE ? OR p.name LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

$query .= " GROUP BY t.id ORDER BY t.transaction_date DESC";

$stmt = $db->prepare($query);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$income_result = $stmt->get_result();

// Calculate totals using the correct column names
$totals_query = "
    SELECT 
        SUM(t.total_amount) as total_amount,
        SUM(t.tax_amount) as total_tax,
        SUM(t.discount_amount) as total_discount,
        SUM(t.net_amount) as total_net_amount,
        COUNT(t.id) as transaction_count
    FROM transactions t
    WHERE 1=1
";

// Apply the same filters to totals
$totals_params = [];
$totals_types = '';

if (!empty($period_filter)) {
    $totals_query .= " AND t.time_period_id = ?";
    $totals_params[] = $period_filter;
    $totals_types .= 'i';
} elseif (!empty($start_date) && !empty($end_date)) {
    $totals_query .= " AND DATE(t.transaction_date) BETWEEN ? AND ?";
    $totals_params[] = $start_date;
    $totals_params[] = $end_date;
    $totals_types .= 'ss';
} elseif ($current_period && empty($period_filter)) {
    $totals_query .= " AND t.time_period_id = ?";
    $totals_params[] = $current_period['id'];
    $totals_types .= 'i';
}

if (!empty($search)) {
    $totals_query .= " AND (t.receipt_number LIKE ? OR EXISTS (
        SELECT 1 FROM transaction_items ti 
        JOIN products p ON ti.product_id = p.id 
        WHERE ti.transaction_id = t.id AND p.name LIKE ?
    ))";
    $search_param = "%$search%";
    $totals_params[] = $search_param;
    $totals_params[] = $search_param;
    $totals_types .= 'ss';
}

$totals_stmt = $db->prepare($totals_query);
if (!empty($totals_params)) {
    $totals_stmt->bind_param($totals_types, ...$totals_params);
}
$totals_stmt->execute();
$totals = $totals_stmt->get_result()->fetch_assoc();

// Get period info for display
$selected_period_info = null;
if (!empty($period_filter)) {
    foreach ($all_periods as $period) {
        if ($period['id'] == $period_filter) {
            $selected_period_info = $period;
            break;
        }
    }
} elseif ($current_period) {
    $selected_period_info = $current_period;
}

// Display success/error messages from session
$success_message = $_SESSION['success_message'] ?? '';
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['success_message']);
unset($_SESSION['error_message']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Income Management - Vinmel Irrigation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --spacing-sm: 0.5rem;
            --spacing-md: 1rem;
            --spacing-lg: 1.5rem;
            --spacing-xl: 2rem;
            --border-radius-sm: 0.25rem;
            --border-radius-md: 0.375rem;
            --border-radius-lg: 0.5rem;
            --success-color: #198754;
            --info-color: #0dcaf0;
            --info-light: #d1ecf1;
            --muted-text: #6c757d;
            --white: #ffffff;
            --shadow-sm: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --font-weight-medium: 500;
            --font-weight-bold: 600;
        }
        
        .income-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: var(--spacing-lg);
            margin-bottom: var(--spacing-xl);
        }
        
        .income-stat-card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: var(--spacing-lg);
            text-align: center;
            box-shadow: var(--shadow-sm);
            border-top: 4px solid var(--success-color);
        }
        
        .income-stat-value {
            font-size: 1.5rem;
            font-weight: var(--font-weight-bold);
            color: var(--success-color);
            margin: var(--spacing-sm) 0;
        }
        
        .income-stat-label {
            color: var(--muted-text);
            font-size: 0.9rem;
            font-weight: var(--font-weight-medium);
        }
        
        .transaction-products {
            max-width: 200px;
            font-size: 0.85rem;
            color: var(--muted-text);
        }
        
        .amount-positive {
            color: var(--success-color);
            font-weight: var(--font-weight-bold);
        }
        
        .period-badge {
            background: var(--info-light);
            color: var(--info-color);
            padding: 4px 8px;
            border-radius: var(--border-radius-sm);
            font-size: 0.8rem;
            font-weight: var(--font-weight-medium);
        }
        
        .payment-badge {
            padding: 4px 8px;
            border-radius: var(--border-radius-sm);
            font-size: 0.8rem;
            font-weight: var(--font-weight-medium);
        }
        
        .badge-cash { background: #d4edda; color: #155724; }
        .badge-card { background: #cce7ff; color: #004085; }
        .badge-mobile { background: #e2e3ff; color: #383d41; }
        
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: var(--spacing-xl);
            padding: var(--spacing-lg);
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
        }
        
        .alert {
            padding: var(--spacing-md);
            border-radius: var(--border-radius-md);
            margin-bottom: var(--spacing-lg);
        }
        
        .alert-success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-info {
            background: var(--info-light);
            color: var(--info-color);
            border: 1px solid #bee5eb;
        }
        
        .card {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            box-shadow: var(--shadow-sm);
            margin-bottom: var(--spacing-lg);
        }
        
        .card-header {
            padding: var(--spacing-lg);
            background: #f8f9fa;
            border-bottom: 1px solid #dee2e6;
            border-radius: var(--border-radius-lg) var(--border-radius-lg) 0 0 !important;
        }
        
        .card-body {
            padding: var(--spacing-lg);
        }
        
        .table-responsive {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            margin-bottom: 0;
        }
        
        .table th {
            background: #f8f9fa;
            font-weight: var(--font-weight-bold);
            padding: var(--spacing-md);
        }
        
        .table td {
            padding: var(--spacing-md);
            vertical-align: middle;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: var(--border-radius-sm);
            font-size: 0.75rem;
            font-weight: var(--font-weight-medium);
        }
        
        .badge-success { background: #d1e7dd; color: #0f5132; }
        .badge-warning { background: #fff3cd; color: #856404; }
        .badge-secondary { background: #e2e3e5; color: #383d41; }
        .badge-primary { background: #cfe2ff; color: #084298; }
        .badge-outline { background: transparent; border: 1px solid currentColor; }
        
        .btn {
            padding: 0.5rem 1rem;
            border-radius: var(--border-radius-md);
            font-weight: var(--font-weight-medium);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border: none;
            cursor: pointer;
        }
        
        .btn-success { background: var(--success-color); color: white; }
        .btn-primary { background: #0d6efd; color: white; }
        .btn-outline { background: transparent; border: 1px solid #6c757d; color: #6c757d; }
        .btn-outline-danger { background: transparent; border: 1px solid #dc3545; color: #dc3545; }
        .btn-sm { padding: 0.25rem 0.5rem; font-size: 0.875rem; }
        
        .form-control, .form-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid #ced4da;
            border-radius: var(--border-radius-md);
            width: 100%;
        }
        
        .form-label {
            font-weight: var(--font-weight-medium);
            margin-bottom: 0.5rem;
            display: block;
        }
        
        .text-muted { color: var(--muted-text); }
        .text-primary { color: #0d6efd; }
        .text-warning { color: #ffc107; }
        .text-info { color: var(--info-color); }
        .text-success { color: var(--success-color); }
        
        .d-flex { display: flex; }
        .justify-content-between { justify-content: space-between; }
        .justify-content-end { justify-content: flex-end; }
        .align-items-center { align-items: center; }
        .align-items-end { align-items: flex-end; }
        .flex-fill { flex: 1; }
        
        .row {
            display: flex;
            flex-wrap: wrap;
            margin: 0 -0.5rem;
        }
        
        .col-md-2 { flex: 0 0 16.666667%; max-width: 16.666667%; padding: 0 0.5rem; }
        .col-md-3 { flex: 0 0 25%; max-width: 25%; padding: 0 0.5rem; }
        .col-md-4 { flex: 0 0 33.333333%; max-width: 33.333333%; padding: 0 0.5rem; }
        .col-md-8 { flex: 0 0 66.666667%; max-width: 66.666667%; padding: 0 0.5rem; }
        .col-md-12 { flex: 0 0 100%; max-width: 100%; padding: 0 0.5rem; }
        
        .mb-0 { margin-bottom: 0; }
        .mb-3 { margin-bottom: var(--spacing-lg); }
        .mb-4 { margin-bottom: var(--spacing-xl); }
        .mt-3 { margin-top: var(--spacing-lg); }
        
        .p-0 { padding: 0; }
        
        .gap-2 { gap: 0.5rem; }
        
        .input-group { display: flex; }
        .input-group-text { 
            padding: 0.5rem 0.75rem; 
            background: #e9ecef; 
            border: 1px solid #ced4da; 
            border-right: none;
            border-radius: var(--border-radius-md) 0 0 var(--border-radius-md);
        }
        
        .input-group .form-control {
            border-radius: 0 var(--border-radius-md) var(--border-radius-md) 0;
        }
        
        .text-center { text-align: center; }
        .text-end { text-align: right; }
        
        .table-light { background: #f8f9fa; }
        
        .btn-group { display: flex; gap: 0.25rem; }
        
        .w-100 { width: 100%; }
        
        .py-5 { padding-top: 3rem; padding-bottom: 3rem; }
        
        .fa-3x { font-size: 3rem; }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="main-content">
        <?php include 'nav_bar.php'; ?>
        
        <div class="content-area">
            <!-- Page Header -->
            <div class="dashboard-header">
                <div>
                    <h1><i class="fas fa-money-bill-wave"></i> Sales & Income</h1>
                    <p class="text-muted">Track and manage all sales transactions and income</p>
                </div>
                
            </div>

            <!-- Success/Error Messages -->
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <!-- Current Period Info -->
            <?php if ($current_period): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i>
                    <strong>Current Active Period:</strong> <?php echo htmlspecialchars($current_period['period_name']); ?>
                    (<?php echo date('M j, Y', strtotime($current_period['start_date'])); ?> - <?php echo date('M j, Y', strtotime($current_period['end_date'])); ?>)
                    <?php if ($current_period['is_locked']): ?>
                        <span class="badge badge-warning">Locked</span>
                    <?php else: ?>
                        <span class="badge badge-success">Active</span>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>No Active Period:</strong> Please create or activate a period to track sales.
                </div>
            <?php endif; ?>

            <!-- Income Statistics -->
            <div class="income-stats">
                <div class="income-stat-card">
                    <div class="income-stat-label">Total Sales</div>
                    <div class="income-stat-value">KSh <?php echo number_format($totals['total_amount'] ?? 0, 2); ?></div>
                    <small class="text-muted">Gross Sales Amount</small>
                </div>
                
                <div class="income-stat-card">
                    <div class="income-stat-label">Total Tax</div>
                    <div class="income-stat-value">KSh <?php echo number_format($totals['total_tax'] ?? 0, 2); ?></div>
                    <small class="text-muted">Tax Collected</small>
                </div>
                
                <div class="income-stat-card">
                    <div class="income-stat-label">Total Discount</div>
                    <div class="income-stat-value">KSh <?php echo number_format($totals['total_discount'] ?? 0, 2); ?></div>
                    <small class="text-muted">Discounts Given</small>
                </div>
                
                <div class="income-stat-card">
                    <div class="income-stat-label">Net Income</div>
                    <div class="income-stat-value">KSh <?php echo number_format($totals['total_net_amount'] ?? 0, 2); ?></div>
                    <small class="text-muted">After Tax & Discount</small>
                </div>
            </div>

            <!-- Period Selector -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-calendar-alt"></i> Select Time Period</h5>
                </div>
                <div class="card-body">
                    <form method="GET" action="" class="row align-items-end">
                        <div class="col-md-8">
                            <label class="form-label">View Sales For Period:</label>
                            <select name="period" class="form-control form-select" onchange="this.form.submit()">
                                <option value="">-- Select Period --</option>
                                <?php foreach ($all_periods as $period): ?>
                                    <option value="<?php echo $period['id']; ?>" 
                                            <?php echo $period_filter == $period['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($period['period_name']); ?>
                                        (<?php echo date('M j, Y', strtotime($period['start_date'])); ?> - <?php echo date('M j, Y', strtotime($period['end_date'])); ?>)
                                        <?php if ($period['is_active'] == 1): ?> ⚡ Active<?php endif; ?>
                                        <?php if ($period['is_locked']): ?> 🔒 Locked<?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <?php if ($selected_period_info): ?>
                                <div class="period-info alert alert-info mb-0">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($selected_period_info['period_name']); ?></strong>
                                            <span class="text-muted">|</span>
                                            <?php echo date('M j, Y', strtotime($selected_period_info['start_date'])); ?> - 
                                            <?php echo date('M j, Y', strtotime($selected_period_info['end_date'])); ?>
                                        </div>
                                        <div class="period-status">
                                            <?php if ($selected_period_info['is_active'] == 1): ?>
                                                <span class="badge badge-success">Active</span>
                                            <?php elseif ($selected_period_info['is_locked']): ?>
                                                <span class="badge badge-warning">Locked</span>
                                            <?php else: ?>
                                                <span class="badge badge-secondary">Inactive</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Filters -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" action="">
                        <?php if (!empty($period_filter)): ?>
                            <input type="hidden" name="period" value="<?php echo $period_filter; ?>">
                        <?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" 
                                       value="<?php echo htmlspecialchars($start_date); ?>"
                                       <?php echo !empty($period_filter) ? 'disabled' : ''; ?>>
                            </div>
                            
                            <div class="col-md-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" 
                                       value="<?php echo htmlspecialchars($end_date); ?>"
                                       <?php echo !empty($period_filter) ? 'disabled' : ''; ?>>
                            </div>
                            
                            <div class="col-md-4">
                                <label class="form-label">Search</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" name="search" class="form-control" 
                                           placeholder="Search receipt, customer, user, or products..." 
                                           value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-2 d-flex align-items-end">
                                <div class="d-flex gap-2 w-100">
                                    <button type="submit" class="btn btn-primary flex-fill">
                                        <i class="fas fa-filter"></i> Filter
                                    </button>
                                    <a href="income.php" class="btn btn-outline">
                                        <i class="fas fa-times"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="col-md-12 mt-3">
                                <div class="d-flex gap-2 justify-content-end">
                                    <button type="button" onclick="exportToCSV()" class="btn btn-success">
                                        <i class="fas fa-download"></i> Export CSV
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Sales Transactions Table -->
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-receipt"></i> Sales Transactions</h5>
                    <div class="text-muted">
                        Showing: <strong><?php echo $income_result->num_rows; ?></strong> transactions
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table" id="income-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Receipt #</th>
                                    <th>Customer</th>
                                    <th>User</th>
                                    <th>Products</th>
                                    <th>Items</th>
                                    <th>Total Amount</th>
                                    <th>Tax</th>
                                    <th>Discount</th>
                                    <th>Net Amount</th>
                                    <th>Payment</th>
                                    <th>Period</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($income_result->num_rows > 0): ?>
                                    <?php while ($transaction = $income_result->fetch_assoc()): ?>
                                        <tr>
                                            <td>
                                                <?php echo date('j M Y H:i', strtotime($transaction['transaction_date'])); ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-outline badge-primary">
                                                    <?php echo htmlspecialchars($transaction['receipt_number'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo $transaction['customer_name'] ? htmlspecialchars($transaction['customer_name']) : '<span class="text-muted">Walk-in</span>'; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($transaction['user_name']); ?>
                                            </td>
                                            <td class="transaction-products" title="<?php echo htmlspecialchars($transaction['product_names'] ?? ''); ?>">
                                                <?php 
                                                $products = $transaction['product_names'] ? explode(', ', $transaction['product_names']) : [];
                                                if (count($products) > 2) {
                                                    echo htmlspecialchars(implode(', ', array_slice($products, 0, 2))) . '...';
                                                } else {
                                                    echo htmlspecialchars($transaction['product_names'] ?? 'N/A');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge badge-secondary"><?php echo $transaction['item_count']; ?></span>
                                            </td>
                                            <td class="amount-positive">
                                                KSh <?php echo number_format($transaction['total_amount'], 2); ?>
                                            </td>
                                            <td>
                                                KSh <?php echo number_format($transaction['tax_amount'], 2); ?>
                                            </td>
                                            <td>
                                                KSh <?php echo number_format($transaction['discount_amount'], 2); ?>
                                            </td>
                                            <td class="amount-positive">
                                                <strong>KSh <?php echo number_format($transaction['net_amount'], 2); ?></strong>
                                            </td>
                                            <td>
                                                <span class="payment-badge badge-<?php echo $transaction['payment_method']; ?>">
                                                    <?php echo ucfirst($transaction['payment_method']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="period-badge">
                                                    <?php echo htmlspecialchars($transaction['period_name'] ?? 'N/A'); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <!-- View Receipt Button -->
                                                    <?php if ($transaction['receipt_id']): ?>
                                                        <a href="view_receipt.php?id=<?php echo $transaction['receipt_id']; ?>" 
                                                           class="btn btn-sm btn-primary" 
                                                           title="View Receipt" target="_blank">
                                                            <i class="fas fa-eye"></i>
                                                        </a>
                                                        <!-- Print Receipt Button -->
                                                        <a href="view_receipt.php?id=<?php echo $transaction['receipt_id']; ?>&mode=print" 
                                                           class="btn btn-sm btn-outline-secondary" 
                                                           title="Print Receipt" target="_blank">
                                                            <i class="fas fa-print"></i>
                                                        </a>
                                                    <?php else: ?>
                                                        <button class="btn btn-sm btn-secondary" disabled title="No receipt saved">
                                                            <i class="fas fa-eye"></i>
                                                        </button>
                                                        <button class="btn btn-sm btn-outline-secondary" disabled title="No receipt saved">
                                                            <i class="fas fa-print"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    <!-- Delete Button -->
                                                    <button class="btn btn-sm btn-outline-danger" 
                                                            onclick="confirmDelete(<?php echo $transaction['id']; ?>, '<?php echo htmlspecialchars($transaction['receipt_number']); ?>')"
                                                            title="Delete Transaction">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="13" class="text-center py-5">
                                            <i class="fas fa-receipt fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">No sales transactions found.</p>
                                            <?php if (!empty($start_date) || !empty($search) || !empty($period_filter)): ?>
                                                <p class="text-muted">Try adjusting your filters or</p>
                                                <a href="income.php" class="btn btn-outline">Clear Filters</a>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-success" onclick="window.location.href='pos.php'">
                                                    <i class="fas fa-cash-register"></i> Create Your First Sale
                                                </button>
                                            <?php endif; ?>
                                            </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                            <?php if ($income_result->num_rows > 0): ?>
                            <tfoot class="table-light">
                                <tr>
                                    <th colspan="6" class="text-end">TOTALS:</th>
                                    <th class="text-primary">KSh <?php echo number_format($totals['total_amount'] ?? 0, 2); ?></th>
                                    <th class="text-warning">KSh <?php echo number_format($totals['total_tax'] ?? 0, 2); ?></th>
                                    <th class="text-info">KSh <?php echo number_format($totals['total_discount'] ?? 0, 2); ?></th>
                                    <th class="text-success">KSh <?php echo number_format($totals['total_net_amount'] ?? 0, 2); ?></th>
                                    <th colspan="3"></th>
                                </tr>
                            </tfoot>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Export to CSV
        function exportToCSV() {
            const table = document.getElementById('income-table');
            let csv = [];
            
            // Headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                // Skip actions column
                if (th.textContent.trim() !== 'Actions') {
                    headers.push(th.textContent.trim());
                }
            });
            csv.push(headers.join(','));
            
            // Rows
            table.querySelectorAll('tbody tr').forEach(row => {
                const rowData = [];
                row.querySelectorAll('td').forEach((td, index) => {
                    // Skip actions column (last one)
                    if (index < headers.length) {
                        let text = td.textContent.trim().replace(/,/g, '');
                        // Remove KSh prefix from amounts
                        text = text.replace('KSh ', '');
                        rowData.push(text);
                    }
                });
                if (rowData.length > 0) {
                    csv.push(rowData.join(','));
                }
            });
            
            // Download
            const csvContent = csv.join('\n');
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'sales-income_' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
        }

        // Confirm delete
        function confirmDelete(transactionId, receiptNumber) {
            if (confirm(`Are you sure you want to delete sales transaction: "${receiptNumber}"?\n\nThis will also delete all associated transaction items and receipt. This action cannot be undone.`)) {
                window.location.href = `delete_transaction.php?id=${transactionId}`;
            }
        }

        // Auto-submit period selector when changed
        document.querySelector('select[name="period"]')?.addEventListener('change', function() {
            this.form.submit();
        });

        // Auto-submit form when dates change (if period not selected)
        document.querySelector('input[name="start_date"]')?.addEventListener('change', function() {
            if (!document.querySelector('select[name="period"]').value) {
                this.form.submit();
            }
        });
        
        document.querySelector('input[name="end_date"]')?.addEventListener('change', function() {
            if (!document.querySelector('select[name="period"]').value) {
                this.form.submit();
            }
        });
    </script>
</body>
</html>