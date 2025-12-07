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
if (user_type(conn: $conn, user_id: $user_id) != "admin") {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}

// Handle status update if form is submitted
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report_id'], $_POST['status'])) {
    $report_id = $_POST['report_id'];
    $status = $_POST['status'];

    if (in_array($status, ['A', 'R'])) {
        $query = $conn->prepare("UPDATE reports SET status = ? WHERE report_id = ?");
        $query->bind_param("ss", $status, $report_id);
        $query->execute();
    }

    // Redirect to avoid form resubmission
    header("Location: view_report.php?id=" . urlencode($report_id));
    exit();
}

// Fetch report details using ?id=
if (!isset($_GET['id'])) {
    echo "<p>Report ID not specified.</p>";
    exit();
}

$report_id = $_GET['id'];
$query = $conn->prepare("SELECT * FROM reports WHERE report_id = ?");
$query->bind_param("s", $report_id);
$query->execute();
$result = $query->get_result();

if ($result->num_rows === 0) {
    echo "<p>No report found with the provided ID.</p>";
    exit();
}

$report = $result->fetch_assoc();

$status_map = [
    "P" => "Pending",
    "A" => "Approved",
    "R" => "Rejected"
];
$status_text = $status_map[$report['status']] ?? "Unknown";


$image_src = null;
if (!empty($report['file_extension'])) {
    $image_src = "../../user_management/serve.php?file_path=images/reports/{$report['report_id']}{$report['file_extension']}";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Details</title>
    <style>
        body {
            font-family: 'Segoe UI', sans-serif;
            background-color: #f4f6f9;
            padding: 30px;
        }
        .report-card {
            max-width: 800px;
            background: #ffffff;
            margin: auto;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.1);
        }
        .report-card h2 {
            margin-top: 0;
            color: #333;
        }
        .report-img {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            margin-bottom: 20px;
            border: 1px solid #ccc;
        }
        .label {
            font-weight: bold;
            color: #555;
        }
        .value {
            margin-bottom: 15px;
        }
        .status {
            font-weight: bold;
            padding: 5px 12px;
            border-radius: 10px;
            display: inline-block;
        }
        .status.P { background-color: #ffc107; color: #212529; }
        .status.A { background-color: #28a745; color: white; }
        .status.R { background-color: #dc3545; color: white; }
        .actions button {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            margin-right: 10px;
            cursor: pointer;
            font-weight: bold;
            font-size: 14px;
        }
        .approve { background-color: #28a745; color: white; }
        .reject { background-color: #dc3545; color: white; }
        .actions {
            margin-top: 20px;
        }
        .value {
            margin-bottom: 15px;
            overflow-wrap: break-word;      /* Wraps long words */
            word-break: break-word;         /* Breaks words if needed */
            white-space: pre-wrap;          /* Keeps line breaks, wraps long lines */
        }
        .head_content {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
        }

    </style>
</head>
<body>
    <div class="report-card">
    <div class="head_content">
            <button onclick="window.location.href='reports.php'" 
                    style="padding: 10px 20px; font-size: 1rem; background-color: #007bff; color: white; 
                            border: none; border-radius: 4px; cursor: pointer; transition: background-color 0.3s;">
                Go Back
            </button>
            <h1 style="margin: 0 auto; text-align: center; flex: 11;">Reports Details</h1>

        </div>
        <?php if ($image_src): ?>
            <img class="report-img" src="<?= htmlspecialchars($image_src) ?>" alt="Protected Image">
        <?php endif; ?>

        <div class="value"><span class="label">User ID:</span> <?= htmlspecialchars($report['user_id']) ?></div>
        <div class="value"><span class="label">Report ID:</span> <?= htmlspecialchars($report['report_id']) ?></div>
        <div class="value"><span class="label">Subject:</span> <?= htmlspecialchars($report['subject']) ?></div>
        <div class="value"><span class="label">Details:</span> <?= htmlspecialchars($report['details']) ?></div>
        <div class="value"><span class="label">Submitted On:</span> <?= htmlspecialchars($report['submission_date']) ?></div>

        <div class="value">
            <span class="label">Status:</span> 
            <span class="status <?= $report['status'] ?>"><?= $status_text ?></span>
        </div>

        <div class="actions">
            <form method="post" style="display:inline;">
                <input type="hidden" name="report_id" value="<?= $report['report_id'] ?>">
                <input type="hidden" name="status" value="A">
                <button class="approve" type="submit">Approve</button>
            </form>
            <form method="post" style="display:inline;">
                <input type="hidden" name="report_id" value="<?= $report['report_id'] ?>">
                <input type="hidden" name="status" value="R">
                <button class="reject" type="submit">Reject</button>
            </form>
        </div>
    </div>
</body>
</html>
