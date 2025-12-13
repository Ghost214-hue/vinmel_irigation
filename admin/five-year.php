<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'config.php';

// Initialize database connection
$database = new Database();
$db = $database->getConnection();

// Get current year
$current_year = date('Y');

// Get range of years (last 5 years)
$years = [];
for ($i = 4; $i >= 0; $i--) {
    $years[] = $current_year - $i;
}

// Default selected year
$selected_year = isset($_GET['year']) ? intval($_GET['year']) : $current_year;

// Validate selected year
if (!in_array($selected_year, $years)) {
    $selected_year = $current_year;
}

// Get data for all years
$yearly_data = [];
$total_income_5y = 0;
$total_expenses_5y = 0;
$total_profit_5y = 0;

foreach ($years as $year) {
    // Get periods for this year
    $periods_query = "SELECT id FROM time_periods WHERE year = ?";
    $stmt = $db->prepare($periods_query);
    $stmt->bind_param("i", $year);
    $stmt->execute();
    $result = $stmt->get_result();
    $period_ids = [];
    while ($row = $result->fetch_assoc()) {
        $period_ids[] = $row['id'];
    }
    
    if (!empty($period_ids)) {
        $placeholders = str_repeat('?,', count($period_ids) - 1) . '?';
        
        // Get total income for year
        $income_query = "SELECT SUM(net_amount) as total FROM transactions WHERE time_period_id IN ($placeholders)";
        $income_stmt = $db->prepare($income_query);
        $income_stmt->bind_param(str_repeat('i', count($period_ids)), ...$period_ids);
        $income_stmt->execute();
        $income_result = $income_stmt->get_result();
        $income_data = $income_result->fetch_assoc();
        $income = $income_data['total'] ?? 0;
        
        // Get total expenses for year
        $expense_query = "SELECT SUM(net_amount) as total FROM expenses WHERE time_period_id IN ($placeholders)";
        $expense_stmt = $db->prepare($expense_query);
        $expense_stmt->bind_param(str_repeat('i', count($period_ids)), ...$period_ids);
        $expense_stmt->execute();
        $expense_result = $expense_stmt->get_result();
        $expense_data = $expense_result->fetch_assoc();
        $expenses = $expense_data['total'] ?? 0;
        
        $profit = $income - $expenses;
        
        // Get monthly data
        $monthly_data = [];
        for ($month = 1; $month <= 12; $month++) {
            $month_period_query = "SELECT id FROM time_periods WHERE year = ? AND month = ?";
            $month_stmt = $db->prepare($month_period_query);
            $month_stmt->bind_param("ii", $year, $month);
            $month_stmt->execute();
            $month_result = $month_stmt->get_result();
            $month_period = $month_result->fetch_assoc();
            
            $month_income = 0;
            $month_expenses = 0;
            
            if ($month_period) {
                // Get month income
                $month_income_query = "SELECT SUM(net_amount) as total FROM transactions WHERE time_period_id = ?";
                $month_income_stmt = $db->prepare($month_income_query);
                $month_income_stmt->bind_param("i", $month_period['id']);
                $month_income_stmt->execute();
                $month_income_result = $month_income_stmt->get_result();
                $month_income_data = $month_income_result->fetch_assoc();
                $month_income = $month_income_data['total'] ?? 0;
                
                // Get month expenses
                $month_expense_query = "SELECT SUM(net_amount) as total FROM expenses WHERE time_period_id = ?";
                $month_expense_stmt = $db->prepare($month_expense_query);
                $month_expense_stmt->bind_param("i", $month_period['id']);
                $month_expense_stmt->execute();
                $month_expense_result = $month_expense_stmt->get_result();
                $month_expense_data = $month_expense_result->fetch_assoc();
                $month_expenses = $month_expense_data['total'] ?? 0;
            }
            
            $month_profit = $month_income - $month_expenses;
            
            $monthly_data[$month] = [
                'income' => $month_income,
                'expenses' => $month_expenses,
                'profit' => $month_profit
            ];
        }
        
        // Get top products for the year
        $top_products_query = "
            SELECT 
                p.name as product_name,
                SUM(ti.quantity) as total_quantity,
                SUM(ti.total_price) as total_revenue,
                SUM((ti.unit_price - p.cost_price) * ti.quantity) as total_profit
            FROM transaction_items ti
            JOIN products p ON ti.product_id = p.id
            JOIN transactions t ON ti.transaction_id = t.id
            JOIN time_periods tp ON t.time_period_id = tp.id
            WHERE tp.year = ?
            GROUP BY p.id
            ORDER BY total_revenue DESC
            LIMIT 5
        ";
        $top_products_stmt = $db->prepare($top_products_query);
        $top_products_stmt->bind_param("i", $year);
        $top_products_stmt->execute();
        $top_products_result = $top_products_stmt->get_result();
        $top_products = [];
        while ($product = $top_products_result->fetch_assoc()) {
            $top_products[] = $product;
        }
        
        // Get top categories for the year
        $top_categories_query = "
            SELECT 
                c.name as category_name,
                COUNT(DISTINCT ti.product_id) as product_count,
                SUM(ti.quantity) as total_quantity,
                SUM(ti.total_price) as total_revenue,
                SUM((ti.unit_price - p.cost_price) * ti.quantity) as total_profit
            FROM transaction_items ti
            JOIN products p ON ti.product_id = p.id
            JOIN categories c ON p.category_id = c.id
            JOIN transactions t ON ti.transaction_id = t.id
            JOIN time_periods tp ON t.time_period_id = tp.id
            WHERE tp.year = ?
            GROUP BY c.id
            ORDER BY total_revenue DESC
            LIMIT 5
        ";
        $top_categories_stmt = $db->prepare($top_categories_query);
        $top_categories_stmt->bind_param("i", $year);
        $top_categories_stmt->execute();
        $top_categories_result = $top_categories_stmt->get_result();
        $top_categories = [];
        while ($category = $top_categories_result->fetch_assoc()) {
            $top_categories[] = $category;
        }
        
        $yearly_data[$year] = [
            'income' => $income,
            'expenses' => $expenses,
            'profit' => $profit,
            'monthly_data' => $monthly_data,
            'top_products' => $top_products,
            'top_categories' => $top_categories
        ];
        
        // Add to 5-year totals
        $total_income_5y += $income;
        $total_expenses_5y += $expenses;
        $total_profit_5y += $profit;
    } else {
        // No data for this year
        $monthly_data = [];
        for ($month = 1; $month <= 12; $month++) {
            $monthly_data[$month] = [
                'income' => 0,
                'expenses' => 0,
                'profit' => 0
            ];
        }
        
        $yearly_data[$year] = [
            'income' => 0,
            'expenses' => 0,
            'profit' => 0,
            'monthly_data' => $monthly_data,
            'top_products' => [],
            'top_categories' => []
        ];
    }
}

// Calculate averages
$avg_monthly_income = $total_income_5y / (5 * 12);
$avg_monthly_expenses = $total_expenses_5y / (5 * 12);
$avg_monthly_profit = $total_profit_5y / (5 * 12);

// Calculate growth rates
$growth_data = [];
$previous_year_income = null;
foreach ($years as $year) {
    $current_income = $yearly_data[$year]['income'];
    if ($previous_year_income !== null && $previous_year_income > 0) {
        $growth_rate = (($current_income - $previous_year_income) / $previous_year_income) * 100;
    } else {
        $growth_rate = 0;
    }
    $growth_data[$year] = $growth_rate;
    $previous_year_income = $current_income;
}

// Get best performing year
$best_year = null;
$best_profit = -999999999;
foreach ($yearly_data as $year => $data) {
    if ($data['profit'] > $best_profit) {
        $best_profit = $data['profit'];
        $best_year = $year;
    }
}

// Get worst performing year
$worst_year = null;
$worst_profit = 999999999;
foreach ($yearly_data as $year => $data) {
    if ($data['profit'] < $worst_profit) {
        $worst_profit = $data['profit'];
        $worst_year = $year;
    }
}

// Prepare data for charts
$chart_years = $years;
$chart_income_data = array_map(function($year) use ($yearly_data) {
    return $yearly_data[$year]['income'];
}, $years);

$chart_expenses_data = array_map(function($year) use ($yearly_data) {
    return $yearly_data[$year]['expenses'];
}, $years);

$chart_profit_data = array_map(function($year) use ($yearly_data) {
    return $yearly_data[$year]['profit'];
}, $years);

// Monthly data for selected year
$selected_monthly_data = $yearly_data[$selected_year]['monthly_data'];
$month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>5-Year Dashboard - Vinmel Irrigation</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="five-year-dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="main-content">
        <?php include 'nav_bar.php'; ?>
        
        <div class="content-area five-year-container">
            <!-- Dashboard Header -->
            <div class="dashboard-header-container">
                <div class="dashboard-title-section">
                    <h1><i class="fas fa-chart-area"></i> 5-Year Financial Overview</h1>
                    <p class="dashboard-subtitle">
                        Comprehensive analysis from <?php echo $years[0]; ?> to <?php echo $years[count($years)-1]; ?>
                    </p>
                </div>
                <div>
                    <button class="btn btn-primary" onclick="exportDashboard()">
                        <i class="fas fa-download"></i> Export Report
                    </button>
                </div>
            </div>

            <!-- Year Selector -->
            <div class="year-selector-container">
                <div class="year-tabs">
                    <?php foreach ($years as $year): ?>
                        <div class="year-tab <?php echo $year == $selected_year ? 'active' : ''; ?>" 
                             onclick="selectYear(<?php echo $year; ?>)">
                            <?php echo $year; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="year-range-display">
                    Showing: <?php echo $years[0]; ?> - <?php echo $years[count($years)-1]; ?>
                    (<?php echo count($years); ?> years)
                </div>
            </div>

            <!-- 5-Year Summary Stats -->
            <div class="summary-stats-grid">
                <div class="summary-stat-card total-revenue">
                    <div class="summary-stat-label">Total Revenue (5 Years)</div>
                    <div class="summary-stat-value">KSh <?php echo number_format($total_income_5y, 2); ?></div>
                    <div class="summary-stat-period"><?php echo $years[0]; ?> - <?php echo $years[count($years)-1]; ?></div>
                </div>
                
                <div class="summary-stat-card total-expenses">
                    <div class="summary-stat-label">Total Expenses (5 Years)</div>
                    <div class="summary-stat-value">KSh <?php echo number_format($total_expenses_5y, 2); ?></div>
                    <div class="summary-stat-period"><?php echo $years[0]; ?> - <?php echo $years[count($years)-1]; ?></div>
                </div>
                
                <div class="summary-stat-card total-profit">
                    <div class="summary-stat-label">Total Net Profit (5 Years)</div>
                    <div class="summary-stat-value">KSh <?php echo number_format($total_profit_5y, 2); ?></div>
                    <div class="summary-stat-period"><?php echo $years[0]; ?> - <?php echo $years[count($years)-1]; ?></div>
                </div>
                
                <div class="summary-stat-card average-monthly">
                    <div class="summary-stat-label">Avg. Monthly Profit</div>
                    <div class="summary-stat-value">KSh <?php echo number_format($avg_monthly_profit, 2); ?></div>
                    <div class="summary-stat-period">Based on 60 months</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <div class="charts-grid">
                    <!-- Yearly Revenue Trend -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="fas fa-chart-line"></i> Yearly Revenue Trend</h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="yearlyRevenueChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #29AB87;"></span>
                                <span>Revenue</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #e74c3c;"></span>
                                <span>Expenses</span>
                            </div>
                            <div class="legend-item">
                                <span class="legend-color" style="background-color: #3498db;"></span>
                                <span>Profit</span>
                            </div>
                        </div>
                    </div>

                    <!-- Profit Comparison -->
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="fas fa-balance-scale"></i> Profit Comparison</h3>
                        </div>
                        <div class="chart-container">
                            <canvas id="profitComparisonChart"></canvas>
                        </div>
                        <div class="chart-legend">
                            <?php foreach ($years as $year): ?>
                                <div class="legend-item">
                                    <span class="legend-color" 
                                          style="background-color: <?php echo $year == $selected_year ? '#FFD700' : '#7f8c8d'; ?>;"></span>
                                    <span><?php echo $year; ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Year Comparison -->
            <div class="year-comparison-section">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-exchange-alt"></i> Year Comparison</h3>
                    </div>
                    <div class="card-body">
                        <div class="year-comparison-grid">
                            <?php foreach ($years as $year): 
                                $data = $yearly_data[$year];
                                $growth = isset($growth_data[$year]) ? $growth_data[$year] : 0;
                            ?>
                                <div class="year-comparison-card">
                                    <div class="year-comparison-header">
                                        <h4><?php echo $year; ?></h4>
                                        <span class="trend-indicator <?php echo $growth > 0 ? 'trend-up' : ($growth < 0 ? 'trend-down' : 'trend-neutral'); ?>">
                                            <i class="fas fa-<?php echo $growth > 0 ? 'arrow-up' : ($growth < 0 ? 'arrow-down' : 'minus'); ?>"></i>
                                            <?php echo number_format(abs($growth), 1); ?>%
                                        </span>
                                    </div>
                                    <div class="year-stats">
                                        <div class="year-stat-item">
                                            <div class="year-stat-value">KSh <?php echo number_format($data['income'], 2); ?></div>
                                            <div class="year-stat-label">Revenue</div>
                                        </div>
                                        <div class="year-stat-item">
                                            <div class="year-stat-value">KSh <?php echo number_format($data['expenses'], 2); ?></div>
                                            <div class="year-stat-label">Expenses</div>
                                        </div>
                                        <div class="year-stat-item">
                                            <div class="year-stat-value <?php echo $data['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                                KSh <?php echo number_format($data['profit'], 2); ?>
                                            </div>
                                            <div class="year-stat-label">Net Profit</div>
                                        </div>
                                        <div class="year-stat-item">
                                            <div class="year-stat-value">
                                                <?php echo $data['income'] > 0 ? number_format(($data['profit'] / $data['income']) * 100, 1) : 0; ?>%
                                            </div>
                                            <div class="year-stat-label">Profit Margin</div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Selected Year Monthly Breakdown -->
            <div class="monthly-breakdown-section">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">
                            <i class="fas fa-calendar-alt"></i> Monthly Breakdown - <?php echo $selected_year; ?>
                        </h3>
                        <div class="text-muted">
                            Hover over any month for details
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="monthly-breakdown-grid">
                            <?php foreach ($month_names as $index => $month_name): 
                                $month_num = $index + 1;
                                $month_data = $selected_monthly_data[$month_num];
                            ?>
                                <div class="month-block" title="<?php echo $month_name . ' ' . $selected_year; ?>">
                                    <div class="month-name"><?php echo $month_name; ?></div>
                                    <div class="month-income">
                                        <?php echo $month_data['income'] > 0 ? 'KSh ' . number_format($month_data['income'], 0) : '-'; ?>
                                    </div>
                                    <div class="month-profit <?php echo $month_data['profit'] > 0 ? 'profit-positive' : ($month_data['profit'] < 0 ? 'profit-negative' : 'profit-neutral'); ?>">
                                        <?php echo $month_data['profit'] > 0 ? '+' : ''; ?>
                                        KSh <?php echo number_format($month_data['profit'], 0); ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Performers Section -->
            <div class="top-performers-section">
                <div class="top-performers-grid">
                    <!-- Top Products -->
                    <div class="top-products-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-star"></i> Top Products - <?php echo $selected_year; ?></h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="performance-table">
                                    <thead>
                                        <tr>
                                            <th class="rank">Rank</th>
                                            <th>Product</th>
                                            <th>Revenue</th>
                                            <th>Profit</th>
                                            <th>Quantity</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rank = 1;
                                        $top_products = $yearly_data[$selected_year]['top_products'];
                                        foreach ($top_products as $product): 
                                        ?>
                                            <tr class="rank-<?php echo $rank; ?>">
                                                <td class="rank">
                                                    <span class="rank-badge"><?php echo $rank; ?></span>
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($product['product_name']); ?></strong></td>
                                                <td>KSh <?php echo number_format($product['total_revenue'], 2); ?></td>
                                                <td class="text-success">KSh <?php echo number_format($product['total_profit'], 2); ?></td>
                                                <td><?php echo number_format($product['total_quantity']); ?></td>
                                            </tr>
                                        <?php 
                                        $rank++;
                                        endforeach; 
                                        ?>
                                        <?php if (empty($top_products)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    No product data available for <?php echo $selected_year; ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <!-- Top Categories -->
                    <div class="top-categories-card">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-tags"></i> Top Categories - <?php echo $selected_year; ?></h3>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="performance-table">
                                    <thead>
                                        <tr>
                                            <th class="rank">Rank</th>
                                            <th>Category</th>
                                            <th>Revenue</th>
                                            <th>Profit</th>
                                            <th>Products</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $rank = 1;
                                        $top_categories = $yearly_data[$selected_year]['top_categories'];
                                        foreach ($top_categories as $category): 
                                        ?>
                                            <tr class="rank-<?php echo $rank; ?>">
                                                <td class="rank">
                                                    <span class="rank-badge"><?php echo $rank; ?></span>
                                                </td>
                                                <td><strong><?php echo htmlspecialchars($category['category_name']); ?></strong></td>
                                                <td>KSh <?php echo number_format($category['total_revenue'], 2); ?></td>
                                                <td class="text-success">KSh <?php echo number_format($category['total_profit'], 2); ?></td>
                                                <td><?php echo number_format($category['product_count']); ?></td>
                                            </tr>
                                        <?php 
                                        $rank++;
                                        endforeach; 
                                        ?>
                                        <?php if (empty($top_categories)): ?>
                                            <tr>
                                                <td colspan="5" class="text-center py-4 text-muted">
                                                    No category data available for <?php echo $selected_year; ?>
                                                </td>
                                            </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Key Insights -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title"><i class="fas fa-lightbulb"></i> Key Insights</h3>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="alert alert-info">
                                <h5><i class="fas fa-trophy"></i> Best Performing Year</h5>
                                <p>
                                    <strong><?php echo $best_year; ?></strong> was the best year with a profit of 
                                    <strong>KSh <?php echo number_format($yearly_data[$best_year]['profit'], 2); ?></strong>.
                                </p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="alert alert-warning">
                                <h5><i class="fas fa-chart-line"></i> Growth Trend</h5>
                                <p>
                                    Overall <?php echo ($growth_data[$current_year] > 0 ? 'growth' : 'decline'); ?> of 
                                    <strong><?php echo number_format($growth_data[$current_year], 1); ?>%</strong> 
                                    from previous year.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="alert alert-success">
                                <h5><i class="fas fa-chart-pie"></i> 5-Year Performance Summary</h5>
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <strong>Total Revenue:</strong> KSh <?php echo number_format($total_income_5y, 2); ?>
                                    </div>
                                    <div>
                                        <strong>Avg. Yearly Growth:</strong> 
                                        <?php 
                                        $avg_growth = array_sum(array_slice($growth_data, 1)) / max(1, count(array_slice($growth_data, 1)));
                                        echo number_format($avg_growth, 1); ?>%
                                    </div>
                                    <div>
                                        <strong>Best Month Avg. Profit:</strong> KSh <?php echo number_format($avg_monthly_profit, 2); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Prepare chart data
        const chartYears = <?php echo json_encode($chart_years); ?>;
        const chartIncomeData = <?php echo json_encode($chart_income_data); ?>;
        const chartExpensesData = <?php echo json_encode($chart_expenses_data); ?>;
        const chartProfitData = <?php echo json_encode($chart_profit_data); ?>;

        // Yearly Revenue Chart
        const revenueCtx = document.getElementById('yearlyRevenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'line',
            data: {
                labels: chartYears,
                datasets: [
                    {
                        label: 'Revenue',
                        data: chartIncomeData,
                        borderColor: '#29AB87',
                        backgroundColor: 'rgba(41, 171, 135, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Expenses',
                        data: chartExpensesData,
                        borderColor: '#e74c3c',
                        backgroundColor: 'rgba(231, 76, 60, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    },
                    {
                        label: 'Profit',
                        data: chartProfitData,
                        borderColor: '#3498db',
                        backgroundColor: 'rgba(52, 152, 219, 0.1)',
                        borderWidth: 3,
                        tension: 0.4,
                        fill: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSh ' + (value / 1000).toFixed(0) + 'K';
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += 'KSh ' + context.parsed.y.toLocaleString('en-US', {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2
                                });
                                return label;
                            }
                        }
                    }
                }
            }
        });

        // Profit Comparison Chart
        const profitCtx = document.getElementById('profitComparisonChart').getContext('2d');
        
        // Generate colors for each year
        const yearColors = chartYears.map(year => {
            return year === <?php echo $selected_year; ?> ? '#FFD700' : '#7f8c8d';
        });

        new Chart(profitCtx, {
            type: 'bar',
            data: {
                labels: chartYears,
                datasets: [{
                    label: 'Profit',
                    data: chartProfitData,
                    backgroundColor: yearColors,
                    borderColor: chartYears.map(year => {
                        return year === <?php echo $selected_year; ?> ? '#FFC107' : '#95a5a6';
                    }),
                    borderWidth: 2,
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return 'KSh ' + (value / 1000).toFixed(0) + 'K';
                            }
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)'
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const year = chartYears[context.dataIndex];
                                const growth = <?php echo json_encode($growth_data); ?>[year] || 0;
                                
                                return [
                                    'Profit: KSh ' + context.parsed.y.toLocaleString('en-US', {
                                        minimumFractionDigits: 2,
                                        maximumFractionDigits: 2
                                    }),
                                    'Growth: ' + growth.toFixed(1) + '%'
                                ];
                            }
                        }
                    }
                }
            }
        });

        // Year selection function
        function selectYear(year) {
            window.location.href = 'five-year.php?year=' + year;
        }

        // Export dashboard function
        function exportDashboard() {
            // You can implement export functionality here
            // For now, let's show an alert
            alert('Export functionality would generate a PDF/Excel report of the 5-year dashboard.');
            
            // Example implementation:
            /*
            window.open('export-five-year.php?year=' + <?php echo $selected_year; ?>, '_blank');
            */
        }

        // Add event listeners for month blocks
        document.querySelectorAll('.month-block').forEach(block => {
            block.addEventListener('click', function() {
                const monthName = this.querySelector('.month-name').textContent;
                const monthIncome = this.querySelector('.month-income').textContent;
                const monthProfit = this.querySelector('.month-profit').textContent;
                
                // Show detailed view or tooltip
                alert(`${monthName} <?php echo $selected_year; ?>:\n${monthIncome}\n${monthProfit}`);
            });
        });

        // Mobile menu toggle
        document.getElementById('mobileMenuToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('mobile-open');
        });
    </script>
</body>
</html>