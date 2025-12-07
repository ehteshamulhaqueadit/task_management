<?php
require_once("../authentication/session_check.php");
require_once("../db_connection.php");

// Function to fetch groups (joined or created) with unified search filters
function fetch_groups(mysqli $conn, int $user_id, string $type, ?string $search_term = null) {
    // Base query to select groups based on user ID and membership type (leader/general)
    $query = "
        SELECT g.group_id, g.name, g.description, j.joining_date
        FROM joined_group j
        JOIN groups g ON j.group_id = g.group_id
        JOIN member m ON j.membership_id = m.membership_id
        WHERE j.user_id = ? AND m.type = ?
    ";

    // Initialize parameters for the prepared statement
    $params = [$user_id, $type];
    $param_types = "is"; // i for user_id, s for type

    // Add search conditions if a search term is provided
    if ($search_term !== null && $search_term !== '') {
        // Check if the search term is purely numeric (potential group ID)
        if (is_numeric($search_term)) {
            $query .= " AND g.group_id = ?"; // Search by exact group ID
            $params[] = (int)$search_term;   // Add group ID as integer parameter
            $param_types .= "i";             // Add integer type
        } else {
            $query .= " AND g.name LIKE ?"; // Search by group name (case-insensitive)
            $params[] = "%$search_term%";   // Add wildcard search term
            $param_types .= "s";            // Add string type
        }
    }

    // Order the results by joining date, newest first
    $query .= " ORDER BY j.joining_date DESC";

    // Prepare the SQL statement
    $stmt = $conn->prepare($query);
    if ($stmt === false) {
        // Log error if statement preparation fails
        error_log("Error preparing statement (Type: $type): " . $conn->error);
        return null; // Return null on error
    }

    // Bind parameters dynamically based on search criteria
    $stmt->bind_param($param_types, ...$params);
    // Execute the statement
    $stmt->execute();
    // Get the result set
    $result = $stmt->get_result();
    if ($result === false) {
        // Log error if execution fails
        error_log("Error executing statement (Type: $type): " . $stmt->error);
        $stmt->close(); // Close statement before returning
        return null; // Return null on error
    }

    // Close the statement
    $stmt->close();
    // Return the result set
    return $result;
}

// Establish database connection
$conn = db_connection();
if ($conn === false) {
    // Handle database connection failure
    die("Failed to connect to database.");
}

// Check user login status and get user ID
$user_data = get_user_existence_and_id(conn: $conn);
if (!$user_data[0]) {
    // Redirect to login if user is not logged in
    header("Location: ../authentication/login.php");
    exit();
}
$user_id = $user_data[1];





// Check if the user is an admin
if (user_type(conn: $conn, user_id: $user_id) == "admin") {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}



// Get the unified search term from query parameters, trim whitespace
$search_term = isset($_GET['search']) ? trim($_GET['search']) : null;

// Initialize variables for leave status messages
$leave_error = null;
$leave_success = null;

// Handle leave group request via POST method
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'leave' && isset($_POST['group_id'])) {
        // Sanitize the group ID input
        $group_id = filter_input(INPUT_POST, 'group_id', FILTER_SANITIZE_NUMBER_INT);

        // Start database transaction for atomicity
        $conn->begin_transaction();

        try {
            // Step 1: Get the membership_id and type for the user and group
            $get_membership = $conn->prepare("
                SELECT j.membership_id, m.type
                FROM joined_group j
                JOIN member m ON j.membership_id = m.membership_id
                WHERE j.user_id = ? AND j.group_id = ?
            ");
            if (!$get_membership) throw new Exception("Error preparing membership select statement: " . $conn->error);

            $get_membership->bind_param("ii", $user_id, $group_id);
            $get_membership->execute();
            $membership_result = $get_membership->get_result();

            if ($membership_result->num_rows === 0) {
                // User is not a member or group doesn't exist for this user
                $leave_error = "You are not a member of this group or cannot perform this action.";
                $get_membership->close();
                $conn->rollback();
            } else {
                $membership = $membership_result->fetch_assoc();
                $membership_id = $membership['membership_id'];
                $membership_type = $membership['type'];
                $get_membership->close();

                // Prevent leaders from leaving (they must transfer leadership or delete group)
                if ($membership_type === 'leader') {
                    $leave_error = "As the group leader, you cannot leave. Transfer leadership or delete the group.";
                    $conn->rollback();
                } else {
                    // Step 2: Delete from joined_group table
                    $delete_joined = $conn->prepare("
                        DELETE FROM joined_group
                        WHERE user_id = ? AND group_id = ? AND membership_id = ?
                    ");
                    if (!$delete_joined) throw new Exception("Error preparing joined_group delete statement: " . $conn->error);
                    $delete_joined->bind_param("iii", $user_id, $group_id, $membership_id);
                    $delete_joined->execute();
                    $affected_joined = $delete_joined->affected_rows;
                    $delete_joined->close();

                    // Step 3: Delete from member table
                    $delete_member = $conn->prepare("
                        DELETE FROM member
                        WHERE membership_id = ?
                    ");
                    if (!$delete_member) throw new Exception("Error preparing member delete statement: " . $conn->error);
                    $delete_member->bind_param("i", $membership_id);
                    $delete_member->execute();
                    $affected_member = $delete_member->affected_rows;
                    $delete_member->close();

                    // Check if records were deleted
                    if ($affected_joined > 0 && $affected_member > 0) {
                        $conn->commit();
                        $leave_success = "You have successfully left the group.";
                    } else {
                        throw new Exception("Failed to delete membership record.");
                    }
                }
            }
        } catch (Exception $e) {
            // Rollback transaction on any error
            $conn->rollback();
            error_log("Error leaving group (User ID: $user_id, Group ID: $group_id): " . $e->getMessage());
            $leave_error = "Error leaving group. Please try again.";
        }
    }
}

// Fetch groups the user created (leader) using the unified search term
$leader_result = fetch_groups($conn, $user_id, 'leader', $search_term);
// Fetch groups the user joined (general member) using the unified search term
$general_result = fetch_groups($conn, $user_id, 'general', $search_term);

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Groups</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --leader-color: #e74c3c; /* Red */
            --member-color: #3498db; /* Blue */
            --text-color: #2c3e50; /* Dark Blue/Gray */
            --light-bg: #f8f9fa; /* Very Light Gray */
            --card-bg: #ffffff; /* White */
            --error-color: #e74c3c; /* Red */
            --success-color: #2ecc71; /* Green */
            --border-color: #e0e0e0; /* Light Gray Border */
            --input-border-color: #ddd;
            --input-focus-border: var(--member-color);
            --input-focus-shadow: rgba(52, 152, 219, 0.2);
            --button-secondary-bg: #6c757d;
            --button-secondary-hover: #5a6268;
            --button-primary-bg: var(--leader-color);
            --button-primary-hover: #c0392b;
            --button-search-bg: var(--member-color);
            --button-search-hover: #2980b9;
            --button-clear-bg: #95a5a6;
            --button-clear-hover: #7f8c8d;
            --button-leave-bg: #e74c3c;
            --button-leave-hover: #c0392b;
            --link-color: var(--member-color);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', system-ui, sans-serif;
        }

        body {
            background-color: var(--light-bg);
            color: var(--text-color);
            padding: 20px;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
            margin: 20px auto;
            padding: 25px 30px;
            background: var(--card-bg);
            border-radius: 10px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            flex-wrap: wrap; /* Allow wrapping on smaller screens */
            gap: 15px; /* Space between items when wrapped */
        }

        .header-title {
            font-size: 1.8em;
            font-weight: 600;
            flex: 1; /* Allow title to take available space */
            min-width: 200px; /* Prevent title from becoming too small */
            text-align: center; /* Center title */
        }

        .header-actions {
            display: flex;
            gap: 10px; /* Space between buttons */
            flex-wrap: wrap; /* Allow buttons to wrap */
            justify-content: center; /* Center buttons if they wrap */
        }

        .btn {
            padding: 10px 18px;
            font-size: 0.95em;
            font-weight: 500;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex; /* Use inline-flex for alignment with icons */
            align-items: center;
            justify-content: center;
            border: none;
            white-space: nowrap; /* Prevent button text wrapping */
        }
        .btn i { margin-right: 6px; } /* Space between icon and text */

        .btn-secondary { background: var(--button-secondary-bg); color: white; }
        .btn-secondary:hover { background: var(--button-secondary-hover); box-shadow: 0 2px 5px rgba(0,0,0,0.1); }

        .btn-primary { background: var(--button-primary-bg); color: white; }
        .btn-primary:hover { background: var(--button-primary-hover); }

        .btn-search-groups { background-color: var(--success-color); color: white; }
        .btn-search-groups:hover { background-color: #27ae60; }

        /* Message Styles */
        .message {
            padding: 12px 15px;
            margin-bottom: 15px;
            border-radius: 6px;
            font-weight: 500;
            border: 1px solid transparent;
        }
        .success-message { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
        .error-message { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }


        .groups-container { margin-top: 20px; }

        .group-toggle {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .toggle-option {
            padding: 10px 20px;
            cursor: pointer;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            color: #666; /* Default color for inactive tabs */
        }
        .toggle-option.active { border-bottom-color: var(--member-color); color: var(--member-color); }
        .toggle-option.leader.active { border-bottom-color: var(--leader-color); color: var(--leader-color); }
        .toggle-option:hover { color: var(--text-color); } /* Hover effect */


        .group-content { display: none; }
        .group-content.active { display: block; }

        /* Unified Search Box */
        .search-box {
            background-color: #fdfdfd;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
        }

        .search-form {
             display: flex;
             flex-wrap: wrap; /* Allow wrapping on small screens */
             gap: 10px; /* Space between input and buttons */
             align-items: center;
        }

        .search-input-wrapper {
            flex-grow: 1; /* Input takes available space */
            position: relative;
            min-width: 200px; /* Minimum width for the input */
        }

        .search-icon {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #aaa;
            pointer-events: none; /* Make icon non-interactive */
        }

        .search-input {
            width: 100%; /* Fill the wrapper */
            padding: 8px 12px 8px 35px; /* Adjust padding for icon */
            font-size: 0.95em;
            border: 1px solid var(--input-border-color);
            border-radius: 6px;
            transition: border-color 0.3s ease, box-shadow 0.3s ease;
        }

        .search-input:focus {
            outline: none;
            border-color: var(--input-focus-border);
            box-shadow: 0 0 0 3px var(--input-focus-shadow);
        }

        .search-button, .clear-search {
            padding: 9px 15px; /* Slightly adjust padding for alignment */
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background-color 0.3s ease;
            font-size: 0.95em;
            color: white;
            text-decoration: none; /* For clear link */
            display: inline-flex;
            align-items: center;
            white-space: nowrap;
        }
        .search-button i, .clear-search i { margin-right: 5px; }

        .search-button { background-color: var(--button-search-bg); }
        .search-button:hover { background-color: var(--button-search-hover); }

        .clear-search { background-color: var(--button-clear-bg); }
        .clear-search:hover { background-color: var(--button-clear-hover); }


        .group-list { list-style: none; padding: 0; }

        .group-item {
            background: var(--card-bg);
            border-left: 5px solid; /* Thicker border */
            border-radius: 8px;
            padding: 15px 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 5px rgba(0,0,0,0.07);
            position: relative; /* For potential future absolute elements */
            overflow: hidden; /* Contain elements */
        }
        .leader-group { border-left-color: var(--leader-color); }
        .member-group { border-left-color: var(--member-color); }
        .group-item:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.1); }

        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start; /* Align items top */
            margin-bottom: 8px;
            flex-wrap: wrap; /* Wrap if needed */
            gap: 10px;
        }

        .group-name {
            font-size: 1.25em;
            font-weight: 600;
            color: var(--text-color);
            margin-right: 10px; /* Space before role */
            word-break: break-word; /* Prevent long names from overflowing */
        }

        .group-id {
            display: inline-block;
            padding: 3px 8px;
            background-color: #f0f0f0;
            border-radius: 4px;
            font-size: 0.8em;
            color: #555;
            margin-left: 8px;
            font-weight: normal;
            white-space: nowrap;
        }

        .group-role {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 600;
            text-transform: uppercase;
            white-space: nowrap;
            margin-left: auto; /* Push role to the right */
        }
        .role-leader { background-color: rgba(231, 76, 60, 0.1); color: var(--leader-color); }
        .role-member { background-color: rgba(52, 152, 219, 0.1); color: var(--member-color); }

        .group-description {
            color: #555;
            margin-bottom: 12px;
            font-size: 0.95em;
            word-wrap: break-word;
            clear: both; /* Ensure description is below header elements */
        }

        .group-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85em;
            color: #777;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #f0f0f0; /* Subtle separator */
            flex-wrap: wrap;
            gap: 10px;
        }

        .group-actions {
            display: flex;
            gap: 10px;
            align-items: center; /* Align buttons vertically */
        }

        .action-btn {
            padding: 6px 12px;
            font-size: 0.9em;
            border-radius: 5px;
            cursor: pointer;
            border: none;
            color: white;
            transition: background-color 0.2s ease, transform 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            white-space: nowrap;
        }
        .action-btn i { margin-right: 5px; }
        .action-btn:hover { transform: scale(1.05); }

        .view-btn { background-color: var(--member-color); }
        .view-btn:hover { background-color: var(--button-search-hover); }

        .manage-btn { background-color: var(--leader-color); }
        .manage-btn:hover { background-color: var(--button-primary-hover); }

        .leave-btn { background-color: var(--button-leave-bg); }
        .leave-btn:hover { background-color: var(--button-leave-hover); }

        .leave-form { display: inline-block; /* Keep form inline */ }


        .no-groups {
            text-align: center;
            padding: 30px;
            background: #f9f9f9;
            border: 1px dashed #ddd;
            border-radius: 8px;
            color: #777;
            margin-top: 15px;
        }
        .no-groups i { font-size: 2em; margin-bottom: 10px; display: block; color: #ccc; }


        /* Confirmation Modal */
        .confirmation-overlay {
            display: none; /* Hidden by default */
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background-color: rgba(0, 0, 0, 0.6); /* Darker overlay */
            justify-content: center;
            align-items: center;
            z-index: 1000; /* Ensure it's on top */
            opacity: 0; /* Start hidden */
            transition: opacity 0.3s ease;
        }
        .confirmation-overlay.visible { display: flex; opacity: 1; } /* Class to show */

        .confirmation-box {
            background-color: white;
            padding: 25px 30px;
            border-radius: 8px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            text-align: center;
            max-width: 400px;
            width: 90%;
            transform: scale(0.95); /* Start slightly small */
            transition: transform 0.3s ease;
        }
        .confirmation-overlay.visible .confirmation-box { transform: scale(1); } /* Scale up when visible */

        .confirmation-box p { margin-bottom: 20px; font-size: 1.1em; }

        .confirmation-buttons {
            margin-top: 15px;
            display: flex;
            justify-content: center;
            gap: 15px;
        }

        .confirm-btn, .cancel-btn {
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
            border: none;
            font-weight: 500;
            transition: background-color 0.2s ease, transform 0.2s ease;
            min-width: 100px;
            text-align: center;
            font-size: 0.95em;
        }
        .confirm-btn:hover, .cancel-btn:hover { transform: translateY(-1px); }

        .confirm-btn { background-color: var(--button-leave-bg); color: white; }
        .cancel-btn { background-color: var(--button-clear-bg); color: white; }


        /* Responsive Adjustments */
        @media (max-width: 768px) {
            .page-header { justify-content: center; } /* Center items on smaller screens */
            .header-title { text-align: center; width: 100%; margin-bottom: 10px; }
            .header-actions { justify-content: center; width: 100%; }
            .search-form { flex-direction: column; align-items: stretch; }
            .search-input-wrapper { width: 100%; }
            .search-button, .clear-search { width: 100%; justify-content: center; }
            .group-header { flex-direction: column; align-items: flex-start; }
            .group-role { margin-left: 0; margin-top: 5px; }
            .group-footer { flex-direction: column; align-items: flex-start; }
            .group-actions { width: 100%; justify-content: flex-start; }
        }

        @media (max-width: 480px) {
            .container { padding: 20px 15px; }
            .btn { padding: 8px 12px; font-size: 0.9em; }
            .toggle-option { padding: 8px 12px; font-size: 0.9em; }
            .group-item { padding: 12px 15px; }
            .group-name { font-size: 1.1em; }
            .action-btn { padding: 5px 10px; font-size: 0.85em; }
            .confirmation-buttons { flex-direction: column; }
            .confirm-btn, .cancel-btn { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <a href="../home.php" class="btn btn-secondary">Back</a>
            <h1 class="header-title">My Groups</h1>
            <div class="header-actions">
                <a href="search_groups.php" class="btn btn-search-groups"><i class="fas fa-search"></i> Search For Groups</a>
                <a href="create_group.php" class="btn btn-primary"><i class="fas fa-plus"></i> Create Group</a>
            </div>
        </div>

        <?php if ($leave_success): ?>
            <div class="message success-message"><?= htmlspecialchars($leave_success) ?></div>
        <?php endif; ?>
        <?php if ($leave_error): ?>
            <div class="message error-message"><?= htmlspecialchars($leave_error) ?></div>
        <?php endif; ?>

        <div class="groups-container">
            <div class="group-toggle">
                <div class="toggle-option active" data-target="joined"><i class="fas fa-users"></i> Groups Joined</div>
                <div class="toggle-option leader" data-target="created"><i class="fas fa-crown"></i> Groups Created</div>
            </div>

            <div class="search-box">
                <form method="GET" action="groups.php" class="search-form">
                    <div class="search-input-wrapper">
                         <i class="fas fa-search search-icon"></i>
                         <input type="text" name="search" class="search-input"
                                placeholder="Search your groups by name or ID..."
                                value="<?= htmlspecialchars($search_term ?? '') ?>"
                                aria-label="Search your groups">
                    </div>
                    <button type="submit" class="search-button">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if ($search_term !== null && $search_term !== ''): ?>
                        <a href="groups.php" class="clear-search">
                           <i class="fas fa-times"></i> Clear
                        </a>
                    <?php endif; ?>
                </form>
            </div>

            <div class="group-content active" id="joined-content">
                <h2 class="text-xl font-semibold mb-4 text-gray-700">Groups You've Joined</h2>
                <?php if ($general_result && $general_result->num_rows > 0): ?>
                    <ul class="group-list">
                        <?php while ($group = $general_result->fetch_assoc()):
                            // Format date for display
                            $joined_date = new DateTime($group['joining_date']);
                            $formatted_date = $joined_date->format("M d, Y");
                        ?>
                            <li class="group-item member-group">
                                <div class="group-header">
                                    <div> <span class="group-name"><?= htmlspecialchars($group['name']) ?></span>
                                        <span class="group-id">ID: <?= $group['group_id'] ?></span>
                                    </div>
                                    <div class="group-role role-member">Member</div>
                                </div>
                                <?php if (!empty($group['description'])): ?>
                                    <p class="group-description"><?= nl2br(htmlspecialchars($group['description'])) ?></p>
                                <?php else: ?>
                                     <p class="group-description italic text-gray-500">No description provided.</p>
                                <?php endif; ?>
                                <div class="group-footer">
                                    <span><i class="far fa-calendar-alt mr-1"></i> Joined: <?= $formatted_date ?></span>
                                    <div class="group-actions">
                                        <a href="group_details.php?id=<?= $group['group_id'] ?>" class="action-btn view-btn"><i class="fas fa-eye"></i> View</a>
                                        <button type="button" class="action-btn leave-btn" onclick="showLeaveConfirmation(<?= $group['group_id'] ?>, '<?= htmlspecialchars(addslashes($group['name'])) ?>')">
                                            <i class="fas fa-sign-out-alt"></i> Leave
                                        </button>
                                    </div>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php elseif ($general_result): ?>
                     <div class="no-groups">
                         <i class="fas fa-users-slash"></i>
                         You haven't joined any groups<?=
                         ($search_term !== null && $search_term !== '') ? ' matching your search' : ''
                         ?>. <a href="search_groups.php" class="text-blue-600 hover:underline">Find groups to join!</a>
                     </div>
                <?php else: ?>
                    <div class="no-groups error-message">Error fetching joined groups. Please try again later.</div>
                <?php endif; ?>
            </div>

            <div class="group-content" id="created-content">
                 <h2 class="text-xl font-semibold mb-4 text-gray-700">Groups You've Created</h2>
                <?php if ($leader_result && $leader_result->num_rows > 0): ?>
                    <ul class="group-list">
                        <?php while ($group = $leader_result->fetch_assoc()):
                            // Format date for display
                            $created_date = new DateTime($group['joining_date']); // Assuming joining_date marks creation for leaders
                            $formatted_date = $created_date->format("M d, Y");
                        ?>
                            <li class="group-item leader-group">
                                <div class="group-header">
                                     <div> <span class="group-name"><?= htmlspecialchars($group['name']) ?></span>
                                        <span class="group-id">ID: <?= $group['group_id'] ?></span>
                                    </div>
                                    <div class="group-role role-leader">Leader</div>
                                </div>
                                <?php if (!empty($group['description'])): ?>
                                    <p class="group-description"><?= nl2br(htmlspecialchars($group['description'])) ?></p>
                                <?php else: ?>
                                     <p class="group-description italic text-gray-500">No description provided.</p>
                                <?php endif; ?>
                                <div class="group-footer">
                                    <span><i class="far fa-calendar-alt mr-1"></i> Created: <?= $formatted_date ?></span>
                                    <div class="group-actions">
                                        <a href="group_details.php?id=<?= $group['group_id'] ?>" class="action-btn view-btn"><i class="fas fa-eye"></i> View</a>
                                    </div>
                                </div>
                            </li>
                        <?php endwhile; ?>
                    </ul>
                <?php elseif ($leader_result): ?>
                     <div class="no-groups">
                         <i class="fas fa-crown"></i>
                         You haven't created any groups<?=
                         ($search_term !== null && $search_term !== '') ? ' matching your search' : ''
                         ?>. <a href="create_group.php" class="text-blue-600 hover:underline">Create one now!</a>
                     </div>
                <?php else: ?>
                    <div class="no-groups error-message">Error fetching created groups. Please try again later.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="confirmation-overlay" id="leaveConfirmation">
        <div class="confirmation-box">
            <p id="leaveConfirmationText">Are you sure you want to leave this group?</p>
            <div class="confirmation-buttons">
                <form id="leaveForm" method="post" action="groups.php" style="display: inline;">
                    <input type="hidden" name="action" value="leave">
                    <input type="hidden" name="group_id" id="groupToLeave">
                    <button type="submit" class="confirm-btn"><i class="fas fa-check"></i> Leave</button>
                </form>
                <button type="button" class="cancel-btn" onclick="hideLeaveConfirmation()"><i class="fas fa-times"></i> Cancel</button>
            </div>
        </div>
    </div>

    <script>
        // --- Tab Toggling ---
        const toggleOptions = document.querySelectorAll('.toggle-option');
        const groupContents = document.querySelectorAll('.group-content');

        toggleOptions.forEach(option => {
            option.addEventListener('click', function() {
                // Update active tab style
                toggleOptions.forEach(opt => opt.classList.remove('active'));
                this.classList.add('active');

                // Show the corresponding content section
                const targetId = this.dataset.target + '-content';
                groupContents.forEach(content => {
                    content.classList.toggle('active', content.id === targetId);
                });
            });
        });

        // --- Leave Confirmation Modal ---
        const leaveConfirmationOverlay = document.getElementById('leaveConfirmation');
        const leaveConfirmationText = document.getElementById('leaveConfirmationText');
        const groupToLeaveInput = document.getElementById('groupToLeave');
        const leaveForm = document.getElementById('leaveForm'); // Get the form itself

        function showLeaveConfirmation(groupId, groupName) {
            // Decode potential HTML entities in group name for display
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = groupName;
            const decodedGroupName = tempDiv.textContent || tempDiv.innerText || "";

            groupToLeaveInput.value = groupId; // Set the group ID in the hidden input
            leaveConfirmationText.textContent = `Are you sure you want to leave the group "${decodedGroupName}"?`;
            leaveConfirmationOverlay.classList.add('visible'); // Show the modal
        }

        function hideLeaveConfirmation() {
            leaveConfirmationOverlay.classList.remove('visible'); // Hide the modal
        }

        // Close confirmation modal when clicking outside the box
        leaveConfirmationOverlay.addEventListener('click', function(event) {
            if (event.target === leaveConfirmationOverlay) { // Check if the click is on the overlay itself
                hideLeaveConfirmation();
            }
        });

        // Handle form submission *after* confirmation (no AJAX needed if page reload is acceptable)
        // The form submission is now standard, triggered by the "Leave" button inside the modal.
        // We removed the AJAX part as the original code didn't use it and reloaded the page.

        // Optional: Add loading state to leave button on submit
        leaveForm.addEventListener('submit', function() {
            const button = this.querySelector('.confirm-btn');
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Leaving...';
            }
            // Allow form to submit normally
        });

         // Optional: Reset button state if the user navigates back without page reload
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) { // Check if page was loaded from cache
                const button = leaveForm.querySelector('.confirm-btn');
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-check"></i> Leave'; // Reset text
                }
            }
        });

    </script>
</body>
</html>
