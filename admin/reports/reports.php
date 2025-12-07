<?php
include("../../authentication/session_check.php");
include("../../db_connection.php");
$conn = db_connection(); // Establish database connection

$user_data = get_user_existence_and_id(conn: $conn);
if ($user_data[0]) {
    $user_id = $user_data[1];
} else {
    header(header: "Location: ../authentication/login.php");
    exit();
}

// Check if the user is an admin
if (user_type(conn: $conn, user_id: $user_id) == "admin") {
    // User is an admin, proceed with the admin panel
} else {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Store filters in session when form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['clear_filters'])) {
        unset($_SESSION['report_filters']);
        header("Location: reports.php");
        exit();
    } else {
        $_SESSION['report_filters'] = $_POST;
    }
}

// Retrieve filters from session if available
$filter_source = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : ($_SESSION['report_filters'] ?? []);



// Handle accept/reject actions
if (isset($_GET['action']) && isset($_GET['id'])) {
    $report_id = mysqli_real_escape_string($conn, $_GET['id']);
    $action = mysqli_real_escape_string($conn, $_GET['action']);
    
    if ($action == 'accepte') {
        $query = "UPDATE reports SET status = 'A' WHERE report_id = '$report_id'";
    } elseif ($action == 'reject') {
        $query = "UPDATE reports SET status = 'R' WHERE report_id = '$report_id'";
    } else {
        header("Location: reports.php");
        exit();
    }
    
    $result = mysqli_query($conn, $query);
    
    if ($result) {
        // Build redirect URL with current filters
        $redirect_url = "reports.php?success=Report+#$report_id+" . 
                       urlencode($action == 'accepte' ? 'accepted' : 'rejected') . 
                       "+successfully";
        
        // Add filters if they exist in session
        if (!empty($_SESSION['report_filters'])) {
            $redirect_url .= '&' . http_build_query($_SESSION['report_filters']);
        }
        
        header("Location: $redirect_url");
    } else {
        $error = "Error processing report: " . mysqli_error($conn);
        header("Location: reports.php?error=" . urlencode($error));
    }
    exit();
}

// Pagination configuration
$records_per_page = 10;

// Get current page from URL, default to 1
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}

// Calculate offset for SQL query
$offset = ($current_page - 1) * $records_per_page;

// Build base query
$query = "SELECT * FROM reports";

// Apply filters if they exist
$filter_conditions = [];
if (isset($filter_source['user_id']) && !empty($filter_source['user_id'])) {
    $user_id_filter = mysqli_real_escape_string($conn, $filter_source['user_id']);
    $filter_conditions[] = "user_id = '$user_id_filter'";
}

if (isset($filter_source['report_id']) && !empty($filter_source['report_id'])) {
    $report_id_filter = mysqli_real_escape_string($conn, $filter_source['report_id']);
    $filter_conditions[] = "report_id = '$report_id_filter'";
}

if (isset($filter_source['subject']) && !empty($filter_source['subject'])) {
    $subject_filter = mysqli_real_escape_string($conn, $filter_source['subject']);
    $filter_conditions[] = "subject LIKE '%$subject_filter%'";
}

if (isset($filter_source['start_date']) && !empty($filter_source['start_date'])) {
    $start_date_filter = mysqli_real_escape_string($conn, $filter_source['start_date']);
    $filter_conditions[] = "submission_date >= '$start_date_filter'";
}

if (isset($filter_source['end_date']) && !empty($filter_source['end_date'])) {
    $end_date_filter = mysqli_real_escape_string($conn, $filter_source['end_date']);
    $filter_conditions[] = "submission_date <= '$end_date_filter'";
}

if (isset($filter_source['status']) && $filter_source['status'] != 'ALL') {
    $status_filter = mysqli_real_escape_string($conn, $filter_source['status']);
    $filter_conditions[] = "status = '$status_filter'";
}

if (!empty($filter_conditions)) {
    $query .= " WHERE " . implode(" AND ", $filter_conditions);
}

// Get total number of records (for pagination)
$count_query = preg_replace('/SELECT \* FROM/', 'SELECT COUNT(*) as total FROM', $query);
$count_result = mysqli_query($conn, $count_query);
$total_records = mysqli_fetch_assoc($count_result)['total'];

// Calculate total pages
$total_pages = ceil($total_records / $records_per_page);

// Ensure current page doesn't exceed total pages
if ($current_page > $total_pages && $total_pages > 0) {
    $current_page = $total_pages;
}

// Add pagination to main query
$query .= " LIMIT $records_per_page OFFSET $offset";

// Execute the main query
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - Reports Management</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 20px;
        }

        h1 {
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: auto;
            background: #fff;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
        }

        .back-button {
            background-color: #007BFF;
            padding: 10px 20px;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            border-color: #007BFF;
            flex: 1;
        }

        .button:hover {
            background-color: #0056b3;
        }
        
        .head_content {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .filter-section {
            margin-top: 20px;
            padding: 20px;
            background-color: #f9f9f9;
            border-radius: 5px;
            box-shadow: 0 0 5px rgba(0, 0, 0, 0.1);
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            max-width: 90%;
        }

        .filter-section label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        
        .filter-section input[type="text"],
        .filter-section input[type="date"],
        .filter-section select {
            width: 100%;
            padding: 10px;
            margin-bottom: 15px;
            border: 1px solid #ccc;
            border-radius: 5px;
            box-sizing: border-box;
        }
        
        .filter-section input[type="submit"], 
        .cancel-button {
            background-color: rgb(32, 118, 30);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            margin: 5px;
            font-size: 1rem;
        }
        
        .modal {
            display: none;
            justify-content: center;
            align-items: center;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.5);
            z-index: 9999;
        }
        
        .button-container {
            display: flex;
            justify-content: center;
            width: 100%;
            margin-top: 15px;
        }
        
        /* Table Styles */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
            border-radius: 5px;
            box-shadow: 0 0 20px rgba(0, 0, 0, 0.1);
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9em;
            min-width: 600px;
        }
        
        table th {
            background-color: #009879;
            color: #ffffff;
            text-align: left;
            padding: 12px 15px;
            position: sticky;
            top: 0;
        }
        
        table td {
            padding: 12px 15px;
            border-bottom: 1px solid #dddddd;
        }
        
        table tr {
            border-bottom: 1px solid #dddddd;
        }
        
        table tr:nth-of-type(even) {
            background-color: #f3f3f3;
        }
        
        table tr:last-of-type {
            border-bottom: 2px solid #009879;
        }
        
        table tr:hover {
            background-color: #e9e9e9;
        }
        
        /* Text overflow handling */
        .table-cell {
            max-width: 250px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            cursor: pointer;
        }
        
        .table-cell.expanded {
            white-space: normal;
            overflow: visible;
            text-overflow: clip;
            word-break: break-word;
        }
        
        /* Status colors */
        .status-pending {
            color: #ff9800;
            font-weight: bold;
        }
        
        .status-accepted {
            color: #4caf50;
            font-weight: bold;
        }
        
        .status-rejected {
            color: #f44336;
            font-weight: bold;
        }
        
        /* Action buttons */
        .action-btn {
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            transition: background-color 0.3s;
            color: white;
            font-size: 0.8em;
            margin-right: 5px;
        }
        
        .view-btn {
            background-color: #2196F3;
        }
        
        .view-btn:hover {
            background-color: #0b7dda;
        }
        
        .accepte-btn {
            background-color: #4CAF50;
        }
        
        .accepte-btn:hover {
            background-color: #3e8e41;
        }
        
        .reject-btn {
            background-color: #f44336;
        }
        
        .reject-btn:hover {
            background-color: #da190b;
        }
        
        .cancel-button {
            background-color: rgb(178, 70, 70);
        }
        
        .cancel-button:hover {
            background-color: rgb(150, 50, 50);
        }
        
        .row-number {
            width: 50px;
            text-align: center;
        }
        
        /* Pagination styles */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            padding: 10px;
        }
        
        .pagination a, .pagination span {
            color: #333;
            padding: 8px 16px;
            text-decoration: none;
            border: 1px solid #ddd;
            margin: 0 4px;
            border-radius: 4px;
        }
        
        .pagination a.active {
            background-color: #009879;
            color: white;
            border: 1px solid #009879;
        }
        
        .pagination a:hover:not(.active) {
            background-color: #ddd;
        }
        
        .pagination a.disabled {
            pointer-events: none;
            color: #aaa;
            border-color: #ddd;
        }

        /* Alert messages */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-danger {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>

<body>
    <div class="container">
        <!-- Display success/error messages -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($_GET['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($_GET['error']); ?>
            </div>
        <?php endif; ?>

        <div class="head_content">
            <button onclick="window.location.href='../admin.php'" 
                    style="padding: 10px 20px; font-size: 1rem; background-color: #007bff; color: white; 
                            border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s;">
                Go Back
            </button>
            <h1 style="margin: 0 auto; text-align: center; flex: 11;">Reports Management</h1>

            <div>
                <button onclick="openModal()"
                        style="padding: 10px 20px; font-size: 1rem; background-color:rgb(47, 156, 63); color: white; 
                                border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s;">
                    Filter
                </button>
                <?php if (!empty($filter_source)): ?>
                <form method="POST" action="reports.php" style="display: inline;">
                    <input type="hidden" name="clear_filters" value="1">
                    <button type="submit" class="action-btn" style="background-color: #666; margin-left: 10px;">Clear Filters</button>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th class="row-number">#</th>
                        <th>User ID</th>
                        <th>Subject</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (mysqli_num_rows($result) > 0) {
                        $row_number = ($current_page - 1) * $records_per_page + 1;
                        while ($row = mysqli_fetch_assoc($result)) {
                            $report_id = $row['report_id'];
                            $user_id = $row['user_id'];
                            $subject = $row['subject'];
                            $status = $row['status'];
                            $submission_date = date('Y-m-d H:i', strtotime($row['submission_date']));
                            
                            // Determine status text and class
                            $status_text = '';
                            $status_class = '';
                            if ($status == 'A') {
                                $status_text = 'Accepted';
                                $status_class = 'status-accepted';
                            } elseif ($status == 'R') {
                                $status_text = 'Rejected';
                                $status_class = 'status-rejected';
                            } else {
                                $status_text = 'Pending';
                                $status_class = 'status-pending';
                            }
                            ?>
                            <tr>
                                <td class="row-number"><?php echo $row_number; ?></td>
                                <td class="table-cell" title="<?php echo htmlspecialchars($user_id); ?>">
                                    <?php echo htmlspecialchars($user_id); ?>
                                </td>
                                <td class="table-cell subject-cell" title="<?php echo htmlspecialchars($subject); ?>" onclick="toggleSubject(this)">
                                    <?php echo htmlspecialchars($subject); ?>
                                </td>
                                <td class="<?php echo $status_class; ?>">
                                    <?php echo $status_text; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($submission_date); ?>
                                </td>
                                <td>
                                    <button class="action-btn view-btn" onclick="viewReport('<?php echo $report_id; ?>')">View</button>
                                    <button class="action-btn accepte-btn" onclick="accepteReport('<?php echo $report_id; ?>')">Accept</button>
                                    <button class="action-btn reject-btn" onclick="rejectReport('<?php echo $report_id; ?>')">Reject</button>
                                </td>
                            </tr>
                            <?php
                            $row_number++;
                        }
                    } else {
                        echo '<tr><td colspan="6" style="text-align: center;">No reports found.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div class="pagination">
            <?php 
            // Build URL parameters for pagination links
            $url_params = [];
            if (!empty($_SESSION['report_filters'])) {
                $url_params = $_SESSION['report_filters'];
            }
            
            // Remove page parameter if it exists
            if (isset($url_params['page'])) {
                unset($url_params['page']);
            }
            
            $url_suffix = !empty($url_params) ? '&' . http_build_query($url_params) : '';
            ?>

            <?php if ($current_page > 1): ?>
                <a href="?page=1<?php echo $url_suffix; ?>">&laquo; First</a>
                <a href="?page=<?php echo $current_page - 1 . $url_suffix; ?>">&lt; Previous</a>
            <?php else: ?>
                <span class="disabled">&laquo; First</span>
                <span class="disabled">&lt; Previous</span>
            <?php endif; ?>

            <?php
            // Show page numbers
            $visible_pages = 5; // Number of pages to show around current page
            $start_page = max(1, $current_page - floor($visible_pages / 2));
            $end_page = min($total_pages, $start_page + $visible_pages - 1);
            
            // Adjust if we're at the start or end
            if ($end_page - $start_page + 1 < $visible_pages) {
                if ($current_page < $visible_pages / 2) {
                    $end_page = min($visible_pages, $total_pages);
                } else {
                    $start_page = max(1, $total_pages - $visible_pages + 1);
                }
            }
            
            for ($i = $start_page; $i <= $end_page; $i++): ?>
                <?php if ($i == $current_page): ?>
                    <a class="active" href="?page=<?php echo $i . $url_suffix; ?>"><?php echo $i; ?></a>
                <?php else: ?>
                    <a href="?page=<?php echo $i . $url_suffix; ?>"><?php echo $i; ?></a>
                <?php endif; ?>
            <?php endfor; ?>

            <?php if ($current_page < $total_pages): ?>
                <a href="?page=<?php echo $current_page + 1 . $url_suffix; ?>">Next &gt;</a>
                <a href="?page=<?php echo $total_pages . $url_suffix; ?>">Last &raquo;</a>
            <?php else: ?>
                <span class="disabled">Next &gt;</span>
                <span class="disabled">Last &raquo;</span>
            <?php endif; ?>
        </div>

        <div class="modal" id="modal-open">
            <div class="filter-section">
                <form method="POST" action="reports.php">
                    <label for="user_id">User ID:</label>
                    <input type="text" name="user_id" id="user_id" value="<?php echo isset($filter_source['user_id']) ? htmlspecialchars($filter_source['user_id']) : ''; ?>">

                    <label for="report_id">Report ID:</label>
                    <input type="text" name="report_id" id="report_id" value="<?php echo isset($filter_source['report_id']) ? htmlspecialchars($filter_source['report_id']) : ''; ?>">

                    <label for="subject">Subject:</label>
                    <input type="text" name="subject" id="subject" value="<?php echo isset($filter_source['subject']) ? htmlspecialchars($filter_source['subject']) : ''; ?>">

                    <label for="start_date">Start Date:</label>
                    <input type="date" name="start_date" id="start_date" value="<?php echo isset($filter_source['start_date']) ? htmlspecialchars($filter_source['start_date']) : ''; ?>">
                    
                    <label for="end_date">End Date:</label>
                    <input type="date" name="end_date" id="end_date" value="<?php echo isset($filter_source['end_date']) ? htmlspecialchars($filter_source['end_date']) : ''; ?>">

                    <label for="status">Status:</label>
                    <select name="status" id="status">
                        <option value="P" <?php echo (isset($filter_source['status']) && $filter_source['status'] == 'P') ? 'selected' : ''; ?>>Pending</option>
                        <option value="R" <?php echo (isset($filter_source['status']) && $filter_source['status'] == 'R') ? 'selected' : ''; ?>>Rejected</option>
                        <option value="A" <?php echo (isset($filter_source['status']) && $filter_source['status'] == 'A') ? 'selected' : ''; ?>>Accepted</option>
                        <option value="ALL" <?php echo (!isset($filter_source['status']) || (isset($filter_source['status']) && $filter_source['status'] == 'ALL')) ? 'selected' : ''; ?>>All</option>
                    </select>

                    <div class="button-container">
                        <input type="submit" value="Filter">
                        <button type="button" class="cancel-button" onclick="closeModal(event)">Cancel</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Function to open the modal
        function openModal() {
            document.getElementById("modal-open").style.display = "flex";
        }

        // Function to close the modal
        function closeModal(event) {
            event.preventDefault();
            document.getElementById("modal-open").style.display = "none";
        }

        // Function to view report
        function viewReport(reportId) {
            window.location.href = 'view_report.php?id=' + reportId;
        }

        // Function to accept report
        function accepteReport(reportId) {
            if (confirm('Are you sure you want to accept this report?')) {
                // Get current filter parameters
                let filterParams = '';
                <?php if (!empty($_SESSION['report_filters'])): ?>
                    filterParams = '&<?php echo http_build_query($_SESSION['report_filters']); ?>';
                <?php endif; ?>
                
                window.location.href = 'reports.php?action=accepte&id=' + reportId + filterParams;
            }
        }

        // Function to reject report
        function rejectReport(reportId) {
            if (confirm('Are you sure you want to reject this report?')) {
                // Get current filter parameters
                let filterParams = '';
                <?php if (!empty($_SESSION['report_filters'])): ?>
                    filterParams = '&<?php echo http_build_query($_SESSION['report_filters']); ?>';
                <?php endif; ?>
                
                window.location.href = 'reports.php?action=reject&id=' + reportId + filterParams;
            }
        }

        // Function to toggle subject text expansion
        function toggleSubject(element) {
            element.classList.toggle('expanded');
            
            // Collapse other expanded subjects when clicking a new one
            const allSubjects = document.querySelectorAll('.subject-cell');
            allSubjects.forEach(subject => {
                if (subject !== element && subject.classList.contains('expanded')) {
                    subject.classList.remove('expanded');
                }
            });
        }
    </script>
</body>
</html>
<?php
// Close the database connection
mysqli_close($conn);
?>