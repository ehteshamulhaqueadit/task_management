<?php
include("../authentication/session_check.php");
include("../db_connection.php");
$conn = db_connection(); // Establish database connection

$user_data = get_user_existence_and_id(conn: $conn);
if ($user_data[0]) {
    $user_id = $user_data[1];
} else {
    header(header: "Location: ../authentication/login.php");
    exit();
}
?>

<?php   

if (user_type(conn: $conn, user_id: $user_id) == "admin") { 
    header(header: "Location: ../admin/reports/reports.php");
    exit();
}                
?>


<?php
// check if it is a post request
if (isset($_POST["submit"])) {
    $report_id = $_POST["report_id"];
    $action = $_POST["action"];

    if ($action == "delete") {
        // Perform delete action
        $query = "DELETE FROM reports WHERE report_id = '$report_id' AND user_id = '$user_id'";
        $result = mysqli_query($conn, $query);
        if ($result) {
            // Redirect to the same page to refresh the list
            header(header: "Location: reports.php");
            exit();
        } else {
            echo "<scirpt> alert('Error deleting report: " . mysqli_error($conn) . "'); </script>";
        }
    } elseif ($action == "edit") {
        // Redirect to edit page
        header(header: "Location: edit_report.php?report_id=$report_id");
        exit();
    }
}
?>

<?php
// Fetch reports from the database
$query = "SELECT * FROM reports WHERE user_id = $user_id";
$reports = $conn->query(query: $query);

function getStatusFullName(string $statusChar): string {
    switch (strtoupper(string: $statusChar)) {
        case 'P': return 'Pending';
        case 'R': return 'Rejected';
        case 'A': return 'Accepted';
        default: return 'Unknown';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports Management</title>
    <style>
        /* Core Styles */
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .container {
            width: 80%;
            background-color: #fff;
            border-radius: 15px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            padding: 20px;
        }

        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 2.5rem;
            color: rgb(255, 0, 0);
            text-align: center;
            flex-grow: 1;
            margin: 0;
        }

        p {
            font-size: 1.2rem;
            color: #555;
            text-align: center;
        }

        /* Button Styles */
        .btn {
            padding: 8px 16px;
            font-size: 1rem;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-back {
            background-color: #007bff;
        }

        .btn-back:hover {
            background-color: #0056b3;
        }

        .btn-refresh {
            background-color: rgb(59, 204, 84);
        }

        .btn-refresh:hover {
            background-color: rgb(38, 125, 56);
        }

        /* Table Styles */
        table {
            width: 100%;
            margin: 20px 0;
            border-collapse: collapse;
            background-color: #ffffff;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 15px rgba(0, 0, 0, 0.1);
        }

        table th, table td {
            padding: 15px;
            text-align: center;
            border: 1px solid #ddd;
        }

        table th {
            background-color: rgb(72, 72, 72);
            color: white;
            font-size: 1rem;
        }

        table td {
            font-size: 0.95rem;
            color: #333;
        }

        table tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        /* Action Buttons */
        .btn-action {
            padding: 10px 15px;
            margin: 5px;
            border: none;
            border-radius: 20px;
            color: white;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-action:hover {
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
            transform: translateY(-2px);
        }

        .btn-view {
            background-color: rgb(0, 140, 255);
        }
        .btn-view:hover {
            background-color: rgb(0, 76, 138);
        }

        .btn-edit {
            background-color: rgb(0, 140, 255);
        }
        .btn-edit:hover {
            background-color: rgb(0, 76, 138);
        }

        .btn-delete {
            background-color: rgb(255, 0, 0);
        }
        .btn-delete:hover {
            background-color: rgb(163, 0, 0);
        }

        /* Status Indicators */
        .status {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 1rem;
            font-size: 0.85rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .status-pending {
            background-color: rgb(229, 227, 255);
            color: rgb(112, 107, 255);
            border: 1px solid rgb(201, 201, 255);
        }

        .status-approved {
            background-color: #ebfbee;
            color: #51cf66;
            border: 1px solid #d3f9d8;
        }

        .status-rejected {
            background-color: #fff0f0;
            color: #ff6b6b;
            border: 1px solid #ffc9c9;
        }

        /* Modal Styles */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .modal-overlay.show {
            display: flex;
            opacity: 1;
        }

        .modal-content {
            background-color: #fff;
            border-radius: 10px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.3);
            transform: translateY(-50px);
            opacity: 0;
            transition: all 0.3s ease;
            max-width: 90%;
            width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-overlay.show .modal-content {
            transform: translateY(0);
            opacity: 1;
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        .modal-header h2 {
            margin: 0;
            color: #333;
        }

        .close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: color 0.2s;
        }

        .close:hover {
            color: #333;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 15px 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* View Modal Specific Styles */
        .modal-row {
            display: flex;
            margin-bottom: 15px;
        }

        .modal-label {
            font-weight: bold;
            width: 150px;
            color: #555;
        }

        .modal-value {
            flex: 1;
            color: #333;
            word-break: break-word;
        }

        .attachment-link {
            color: #007bff;
            text-decoration: none;
            transition: color 0.2s;
        }

        .attachment-link:hover {
            color: #0056b3;
            text-decoration: underline;
        }

        .attachment-image {
            max-width: 100%;
            max-height: 300px;
            border: 1px solid #ddd;
            border-radius: 4px;
            margin-top: 10px;
            transition: transform 0.3s;
        }

        .attachment-image:hover {
            transform: scale(1.02);
        }

        .attachment-container {
            margin-top: 10px;
        }

        /* Confirmation Modal Styles */
        .confirmation-modal .modal-body {
            text-align: center;
            padding: 30px 20px;
        }

        .confirmation-modal .modal-message {
            font-size: 1.2rem;
            margin-bottom: 20px;
        }

        .confirmation-modal .btn-confirm {
            background-color: #dc3545;
            padding: 10px 20px;
        }

        .confirmation-modal .btn-confirm:hover {
            background-color: #c82333;
        }

        .confirmation-modal .btn-cancel {
            background-color: #6c757d;
            padding: 10px 20px;
        }

        .confirmation-modal .btn-cancel:hover {
            background-color: #5a6268;
        }

        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .container {
                width: 95%;
                padding: 15px;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .header-content {
                flex-direction: column;
                gap: 10px;
            }
            
            .btn {
                width: 100%;
            }
            
            .modal-content {
                width: 95%;
            }
            
            .modal-row {
                flex-direction: column;
            }
            
            .modal-label {
                width: 100%;
                margin-bottom: 5px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header-content">
            <button class="btn btn-back" onclick="window.location.href='../user_management/security.php'">
                Go Back
            </button>
            
            <h1>Reports Management</h1>
            
            <button class="btn btn-refresh" onclick="location.reload()">
                Refresh
            </button>
        </div>

        <p>Welcome to the Reports Management page. Here you can view and manage all reports that you have submitted to the admin.</p>

        <h2>Submitted Reports</h2>

        <table>
            <tr>
                <th>Report No</th>
                <th>Subject</th>
                <th>Submission Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
            <?php $number = 1; ?>
            <?php while ($row = $reports->fetch_assoc()): ?>
                <?php $status = getStatusFullName(statusChar: $row["status"]); ?>
                <tr>
                    <td><?php echo $number; ?></td>
                    <td><?php echo htmlspecialchars($row["subject"]); ?></td>
                    <td><?php echo htmlspecialchars($row["submission_date"]); ?></td>
                    <td><span class="status status-<?php echo strtolower($status); ?>"><?php echo $status; ?></span></td>
                    <td>
                        <button class="btn-action btn-view" onclick="openViewModal(<?php echo htmlspecialchars(string: json_encode(value: $row) ); ?>, '<?php echo $number; ?>')">View</button>
                        <button class="btn-action btn-edit" onclick="openEdit('<?php echo $row['report_id']; ?>')">Edit</button>
                        <button class="btn-action btn-delete" onclick="showDeleteConfirmation('<?php echo $row['report_id']; ?>')">Delete</button>
                    </td>
                </tr>
                <?php $number++; ?>
            <?php endwhile; ?>
        </table>
    </div>

    <!-- View Report Modal -->
    <div class="modal-overlay" id="view-modal">
        <div class="modal-content">
            <div class="modal-header">
                <button class="btn btn-back" onclick="closeModal('view-modal')">Close</button>
                <h2 id="report-number"></h2>
            </div>
            <div class="modal-body">
                <div class="modal-row">
                    <div class="modal-label">Report ID:</div>
                    <div class="modal-value" id="modal-report-id"></div>
                </div>
                <div class="modal-row">
                    <div class="modal-label">Subject:</div>
                    <div class="modal-value" id="modal-subject"></div>
                </div>
                <div class="modal-row">
                    <div class="modal-label">Details:</div>
                    <div class="modal-value" id="modal-details"></div>
                </div>
                <div class="modal-row">
                    <div class="modal-label">Submission Date:</div>
                    <div class="modal-value" id="modal-submission-date"></div>
                </div>
                <div class="modal-row">
                    <div class="modal-label">Status:</div>
                    <div class="modal-value" id="modal-status"></div>
                </div>
                <div class="modal-row">
                    <div class="modal-label">Attachment:</div>
                    <div class="modal-value" id="modal-attachment"></div>
                </div>
            </div>
            <div class="modal-footer">
                
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-overlay confirmation-modal" id="delete-confirmation-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Confirm Deletion</h2>
                <span class="close" onclick="closeModal('delete-confirmation-modal')">&times;</span>
            </div>
            <div class="modal-body">
                <p class="modal-message">Are you sure you want to delete this report? This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-cancel" onclick="closeModal('delete-confirmation-modal')">Cancel</button>
                <button class="btn btn-confirm" id="confirm-delete-btn">Delete</button>
            </div>
        </div>
    </div>

    <script>
        // Current report ID to be deleted
        let currentReportToDelete = null;
        
        // Open view modal
        function openViewModal(row_data, report_number) {
            const modal = document.getElementById('view-modal');
            
            // Populate modal with data
            document.getElementById('report-number').textContent = "Report " + report_number;
            document.getElementById('modal-report-id').textContent = row_data.report_id;
            document.getElementById('modal-subject').textContent = row_data.subject;
            document.getElementById('modal-details').textContent = row_data.details;
            document.getElementById('modal-submission-date').textContent = row_data.submission_date;
            
            // Format status
            let statusText = '';
            switch(row_data.status.toUpperCase()) {
                case 'P': statusText = 'Pending'; break;
                case 'A': statusText = 'Accepted'; break;
                case 'R': statusText = 'Rejected'; break;
                default: statusText = 'Unknown';
            }
            document.getElementById('modal-status').textContent = statusText;
            
            // Handle attachment
            const attachmentElement = document.getElementById('modal-attachment');
            if (row_data.file_extension) {
                // Clean up the extension (remove leading dot if present)
                const extension = row_data.file_extension.startsWith('.') 
                    ? row_data.file_extension.substring(1) 
                    : row_data.file_extension;
                
                const imageUrl = `../user_management/serve.php?file_path=images/reports/${row_data.report_id}.${extension}`;
                
                attachmentElement.innerHTML = `
                    <div class="attachment-container">
                        <img src="${imageUrl}" alt="Report Attachment" class="attachment-image">
                        <div style="margin-top: 5px;">
                            <a href="${imageUrl}" target="_blank" class="attachment-link">View Full Size</a>
                        </div>
                    </div>
                `;
            } else {
                attachmentElement.textContent = 'No attachment';
            }

            // Show modal with animation
            modal.classList.add('show');
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
        }
        
        // Show delete confirmation modal
        function showDeleteConfirmation(report_id) {
            currentReportToDelete = report_id;
            const modal = document.getElementById('delete-confirmation-modal');
            modal.classList.add('show');
            setTimeout(() => {
                modal.classList.add('show');
            }, 10);
        }
        
        // Close modal
        function closeModal(modalId) {
            const modal = document.getElementById(modalId);
            modal.classList.remove('show');
            setTimeout(() => {
                modal.classList.remove('show');
            }, 300);
        }
        
        // Initialize delete confirmation button
        document.getElementById('confirm-delete-btn').addEventListener('click', function() {
        if (currentReportToDelete) {
            // Create a form element
            const form = document.createElement('form');
            form.method = 'post';
            form.action = '';
            
            // Add hidden inputs
            const addHiddenInput = (name, value) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            };
            
            addHiddenInput('report_id', currentReportToDelete);
            addHiddenInput('action', 'delete');
            addHiddenInput('submit', '1');
            
            // Add to DOM and submit
            document.body.appendChild(form);
            HTMLFormElement.prototype.submit.call(form); // Native submission
            document.body.removeChild(form); // Clean up
        }
    });
        
        // Close modal when clicking outside of it
        window.addEventListener('click', function(event) {
            if (event.target.classList.contains('modal-overlay')) {
                const modals = document.querySelectorAll('.modal-overlay');
                modals.forEach(modal => {
                    if (modal.classList.contains('show')) {
                        closeModal(modal.id);
                    }
                });
            }
        });
        
        // Open edit page
        function openEdit(report_id) {
            window.location.href = `edit_report.php?report_id=${report_id}`;
        }
    </script>
</body>
</html>