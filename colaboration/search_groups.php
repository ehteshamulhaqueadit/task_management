<?php
require_once("../authentication/session_check.php");
require_once("../db_connection.php");

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


// Get search term from query parameters, trim whitespace
$search_term = isset($_GET['search']) ? trim($_GET['search']) : null;

// Initialize variables for join status messages
$join_error = null;
$join_success = null;

// Handle join group request via POST method
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'join' && isset($_POST['group_id'])) {
    // Sanitize the group ID input
    $group_id = filter_input(INPUT_POST, 'group_id', FILTER_SANITIZE_NUMBER_INT);

    // Validate group existence
    $stmt = $conn->prepare("SELECT group_id FROM groups WHERE group_id = ?");
    if ($stmt === false) {
        error_log("Error preparing group existence check statement: " . $conn->error);
        $join_error = "Error processing request. Please try again.";
    } else {
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // Group does not exist
            $join_error = "Group does not exist.";
        } else {
            // Check if user is already a member of the group
            $check_membership = $conn->prepare("
                SELECT j.group_id
                FROM joined_group j
                JOIN member m ON j.membership_id = m.membership_id
                WHERE j.user_id = ? AND j.group_id = ?
            ");
            if ($check_membership === false) {
                error_log("Error preparing membership check statement: " . $conn->error);
                $join_error = "Error checking membership. Please try again.";
            } else {
                $check_membership->bind_param("ii", $user_id, $group_id);
                $check_membership->execute();
                $check_membership->store_result();

                if ($check_membership->num_rows > 0) {
                    // User is already a member
                    $join_error = "You are already a member of this group.";
                } else {
                    // User is not a member, proceed with joining
                    $conn->begin_transaction(); // Start database transaction

                    try {
                        // Insert into member table with 'general' type
                        $stmt_member = $conn->prepare("INSERT INTO member (type) VALUES ('general')");
                        if (!$stmt_member) throw new Exception("Error preparing member insert statement: " . $conn->error);
                        $stmt_member->execute();
                        if ($stmt_member->affected_rows !== 1) throw new Exception("Failed to insert into member table.");
                        $membership_id = $conn->insert_id; // Get the new membership ID
                        $stmt_member->close();

                        // Insert into joined_group table linking user, membership, and group
                        $stmt_joined = $conn->prepare("
                            INSERT INTO joined_group (user_id, membership_id, group_id, joining_date)
                            VALUES (?, ?, ?, NOW())
                        ");
                        if (!$stmt_joined) throw new Exception("Error preparing joined_group insert statement: " . $conn->error);
                        $stmt_joined->bind_param("iii", $user_id, $membership_id, $group_id);
                        $stmt_joined->execute();
                        if ($stmt_joined->affected_rows !== 1) throw new Exception("Failed to insert into joined_group table.");
                        $stmt_joined->close();

                        // Commit the transaction if all queries were successful
                        $conn->commit();
                        $join_success = "You have successfully joined the group!";

                    } catch (Exception $e) {
                        // Rollback transaction on error
                        $conn->rollback();
                        error_log("Error joining group (User ID: $user_id, Group ID: $group_id): " . $e->getMessage());
                        $join_error = "Error joining group. Please try again.";
                    }
                }
                $check_membership->close();
            }
        }
        $stmt->close();
    }
}

// Fetch public groups that the user hasn't joined, applying search filters
$public_groups_query = "
    SELECT
        g.group_id,
        g.name,
        g.description,
        -- Subquery to count members in the group
        (SELECT COUNT(*) FROM joined_group j WHERE j.group_id = g.group_id) as member_count,
        -- Subquery to get the name of the group creator (leader)
        (SELECT u.name FROM created_group c
         JOIN user u ON c.user_id = u.user_id
         WHERE c.group_id = g.group_id LIMIT 1) as leader_name
    FROM groups g
    -- Exclude groups the current user has already joined
    WHERE g.group_id NOT IN (
        SELECT j.group_id
        FROM joined_group j
        WHERE j.user_id = ?
    )
";

// Initialize parameters for the prepared statement
$params = [$user_id];
$param_types = "i";

// Add search conditions if a search term is provided
if ($search_term !== null && $search_term !== '') {
    // Check if the search term is numeric (potential group ID)
    if (is_numeric($search_term)) {
        $public_groups_query .= " AND g.group_id = ?";
        $params[] = (int)$search_term; // Add group ID to parameters
        $param_types .= "i"; // Add integer type
    } else {
        // Search by group name using LIKE
        $public_groups_query .= " AND g.name LIKE ?";
        $params[] = "%$search_term%"; // Add wildcard search term
        $param_types .= "s"; // Add string type
    }
}

// Order the results by group name
$public_groups_query .= " ORDER BY g.name ASC";

// Prepare and execute the query to fetch public groups
$public_stmt = $conn->prepare($public_groups_query);
if ($public_stmt === false) {
    // Log error if statement preparation fails
    error_log("Error preparing public groups statement: " . $conn->error);
    $public_result = null; // Set result to null on error
} else {
    // Bind parameters dynamically based on search criteria
    $public_stmt->bind_param($param_types, ...$params);
    $public_stmt->execute();
    $public_result = $public_stmt->get_result(); // Get the result set
    if ($public_result === false) {
        // Log error if execution fails
        error_log("Error executing public groups statement: " . $public_stmt->error);
        $public_result = null; // Set result to null on error
    }
}

// Close the database connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Search Groups</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom styles for enhanced UI */
        :root {
            --primary-color: #3b82f6; /* Blue */
            --secondary-color: #10b981; /* Green */
            --danger-color: #ef4444; /* Red */
            --dark-color: #1f2937; /* Dark Gray */
            --light-color: #f9fafb; /* Light Gray */
        }

        /* Style for group cards with transitions */
        .group-card {
            transition: all 0.3s ease;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.05);
        }

        .group-card:hover {
            transform: translateY(-2px); /* Slight lift effect on hover */
            box-shadow: 0 10px 15px rgba(0, 0, 0, 0.1); /* Increased shadow on hover */
        }

        /* Styles for modal transitions (optional, requires JS) */
        .modal {
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .modal-enter { opacity: 0; transform: translateY(-20px); }
        .modal-enter-active { opacity: 1; transform: translateY(0); }
        .modal-exit { opacity: 1; transform: translateY(0); }
        .modal-exit-active { opacity: 0; transform: translateY(-20px); }

        /* Utility class to limit text lines */
        .line-clamp-2 {
            overflow: hidden;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="container mx-auto px-4 py-8">
        <div class="flex justify-between items-center mb-8 w-full">
            <div class="flex-none"> <a href="groups.php" class="bg-gray-600 hover:bg-gray-700 text-white px-4 py-2 rounded-lg transition duration-200 inline-flex items-center">
                      Back
                 </a>
            </div>
            <div class="flex-grow text-center"> <h1 class="text-3xl font-bold text-gray-800">Search Groups</h1>
            </div>
            <div class="flex-none invisible"> <a href="#" class="bg-gray-600 text-white px-4 py-2 rounded-lg inline-flex items-center">
                      Back
                 </a>
            </div>
        </div>

        <?php if ($join_success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                <?= htmlspecialchars($join_success) ?>
            </div>
        <?php endif; ?>

        <?php if ($join_error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                <?= htmlspecialchars($join_error) ?>
            </div>
        <?php endif; ?>

        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <form method="GET" action="search_groups.php" class="flex flex-col md:flex-row gap-4 items-center">
                <div class="flex-grow relative w-full md:w-auto">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i class="fas fa-search text-gray-400"></i>
                    </div>
                    <input
                        type="text"
                        name="search"
                        class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg bg-gray-50 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                        placeholder="Search by group name or ID"
                        value="<?= htmlspecialchars($search_term ?? '') ?>"
                        aria-label="Search groups"
                    >
                </div>
                <button type="submit" class="w-full md:w-auto bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded-lg transition duration-200 flex items-center justify-center">
                    <i class="fas fa-search mr-2"></i> Search
                </button>
                <?php if ($search_term !== null && $search_term !== ''): ?>
                    <a href="search_groups.php" class="w-full md:w-auto bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded-lg transition duration-200 flex items-center justify-center">
                        <i class="fas fa-times mr-2"></i> Clear
                    </a>
                <?php endif; ?>
            </form>
        </div>

        <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
            <?php if ($public_result && $public_result->num_rows > 0): ?>
                <?php while ($group = $public_result->fetch_assoc()): ?>
                    <div class="group-card bg-white rounded-lg shadow-md overflow-hidden flex flex-col">
                        <div class="p-6 flex-grow">
                            <div class="flex justify-between items-start mb-3">
                                <h3 class="text-xl font-semibold text-gray-800">
                                    <?= htmlspecialchars($group['name']) ?>
                                    <span class="text-sm font-normal bg-blue-100 text-blue-700 px-2 py-0.5 rounded ml-2 whitespace-nowrap">ID: <?= $group['group_id'] ?></span>
                                </h3>
                            </div>
                            <p class="text-gray-500 text-sm mb-3">
                                <i class="fas fa-users mr-1 text-gray-400"></i> <?= $group['member_count'] ?> member<?= $group['member_count'] != 1 ? 's' : '' ?>
                            </p>
                            <?php if (!empty($group['description'])): ?>
                                <p class="text-gray-600 mb-4 line-clamp-2" title="<?= htmlspecialchars($group['description']) ?>">
                                    <?= nl2br(htmlspecialchars($group['description'])) ?>
                                </p>
                            <?php else: ?>
                                <p class="text-gray-400 italic mb-4">No description provided.</p>
                            <?php endif; ?>
                        </div>
                        <div class="p-4 bg-gray-50 border-t border-gray-200 flex space-x-3">
                             <button
                                 onclick="showGroupDetails(<?= $group['group_id'] ?>, '<?= htmlspecialchars(addslashes($group['name'])) ?>', '<?= htmlspecialchars(addslashes(str_replace(["\r", "\n"], ' ', $group['description'] ?? ''))) ?>', <?= $group['member_count'] ?>, '<?= htmlspecialchars(addslashes($group['leader_name'] ?? 'Unknown')) ?>')"
                                 class="flex-1 bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg transition duration-200 text-center text-sm"
                                 aria-label="View details for group <?= htmlspecialchars($group['name']) ?>"
                             >
                                 <i class="fas fa-eye mr-1"></i> View
                             </button>
                             <button
                                 onclick="showJoinConfirmation(<?= $group['group_id'] ?>, '<?= htmlspecialchars(addslashes($group['name'])) ?>')"
                                 class="flex-1 bg-green-600 hover:bg-green-700 text-white px-4 py-2 rounded-lg transition duration-200 text-center text-sm"
                                 aria-label="Join group <?= htmlspecialchars($group['name']) ?>"
                             >
                                 <i class="fas fa-user-plus mr-1"></i> Join
                             </button>
                        </div>
                    </div>
                <?php endwhile; ?>
                <?php $public_stmt->close(); // Close statement after loop ?>
            <?php elseif ($public_result): ?>
                <div class="col-span-full bg-white rounded-lg shadow-md p-8 text-center">
                    <i class="fas fa-search text-4xl text-gray-400 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">No Groups Found</h3>
                    <p class="text-gray-500">
                        <?= ($search_term !== null && $search_term !== '') ? 'No groups match your search criteria.' : 'There are currently no public groups available for you to join.' ?>
                    </p>
                </div>
                 <?php if ($public_stmt) $public_stmt->close(); // Close statement if it exists ?>
            <?php else: ?>
                <div class="col-span-full bg-white rounded-lg shadow-md p-8 text-center">
                    <i class="fas fa-exclamation-triangle text-4xl text-red-400 mb-4"></i>
                    <h3 class="text-xl font-semibold text-gray-700 mb-2">Error Loading Groups</h3>
                    <p class="text-gray-500">We encountered an issue fetching the list of groups. Please try again later or contact support if the problem persists.</p>
                </div>
                 <?php if ($public_stmt) $public_stmt->close(); // Close statement if it exists ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="groupDetailsModal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="hideGroupDetails()"></div>
            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span> <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full modal modal-enter">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-info-circle text-blue-600"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                            <h3 id="modalGroupName" class="text-xl leading-6 font-bold text-gray-900 mb-3" ></h3>
                            <div class="mt-2 space-y-4">
                                <div>
                                    <p class="text-sm font-medium text-gray-500 mb-1">Description</p>
                                    <p id="modalGroupDescription" class="text-gray-700 whitespace-pre-wrap break-words bg-gray-50 p-3 rounded-md border border-gray-200"></p>
                                </div>
                                <div class="grid grid-cols-2 gap-4">
                                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-200">
                                        <p class="text-xs text-gray-500 uppercase tracking-wider">Group ID</p>
                                        <p id="modalGroupId" class="font-semibold text-gray-800"></p>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-200">
                                        <p class="text-xs text-gray-500 uppercase tracking-wider">Members</p>
                                        <p id="modalGroupMembers" class="font-semibold text-gray-800"></p>
                                    </div>
                                    <div class="bg-gray-50 p-3 rounded-lg border border-gray-200 col-span-2">
                                        <p class="text-xs text-gray-500 uppercase tracking-wider">Group Leader</p>
                                        <p id="modalGroupLeader" class="font-semibold text-gray-800"></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse items-center">
                    <form id="joinFormInModal" method="post" action="search_groups.php" class="inline-flex">
                        <input type="hidden" name="action" value="join">
                        <input type="hidden" name="group_id" id="modalGroupIdInput">
                        <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-green-600 text-base font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 sm:ml-3 sm:w-auto sm:text-sm">
                            <i class="fas fa-user-plus mr-2"></i> Join Group
                        </button>
                    </form>
                    <button type="button" onclick="hideGroupDetails()" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </div>

    <div id="joinConfirmationModal" class="fixed inset-0 z-50 overflow-y-auto hidden" aria-labelledby="confirmation-title" role="dialog" aria-modal="true">
         <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="hideJoinConfirmation()"></div>
             <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span> <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full modal modal-enter">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <div class="sm:flex sm:items-start">
                        <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full bg-blue-100 sm:mx-0 sm:h-10 sm:w-10">
                            <i class="fas fa-question-circle text-blue-600 text-xl"></i>
                        </div>
                        <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left">
                            <h3 class="text-lg leading-6 font-medium text-gray-900" id="confirmation-title">Confirm Join</h3>
                            <div class="mt-2">
                                <p id="confirmationText" class="text-sm text-gray-600"></p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse items-center">
                     <form id="joinForm" method="post" action="search_groups.php">
                        <input type="hidden" name="action" value="join">
                        <input type="hidden" name="group_id" id="groupToJoin">
                        <button type="submit" class="w-full inline-flex justify-center rounded-lg border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                            <i class="fas fa-check mr-2"></i> Confirm Join
                        </button>
                    </form>
                     <button type="button" onclick="hideJoinConfirmation()" class="mt-3 w-full inline-flex justify-center rounded-lg border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:w-auto sm:text-sm">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Get modal elements
        const groupDetailsModal = document.getElementById('groupDetailsModal');
        const joinConfirmationModal = document.getElementById('joinConfirmationModal');
        const modalPanelDetails = groupDetailsModal.querySelector('.modal');
        const modalPanelConfirm = joinConfirmationModal.querySelector('.modal');

        // --- Group Details Modal Functions ---
        function showGroupDetails(groupId, groupName, groupDescription, memberCount, leaderName) {
            // Populate modal content
            document.getElementById('modalGroupId').textContent = groupId;
            document.getElementById('modalGroupIdInput').value = groupId; // For the join form inside the details modal
            document.getElementById('modalGroupName').textContent = groupName;
            // Decode HTML entities and handle potential null description
            const descElement = document.getElementById('modalGroupDescription');
            const tempDiv = document.createElement('div'); // Use temp element to decode entities safely
            tempDiv.innerHTML = groupDescription || 'No description provided.';
            descElement.textContent = tempDiv.textContent; // Set decoded text content
            descElement.innerHTML = descElement.innerHTML.replace(/\n/g, '<br>'); // Convert newlines to <br> for display

            document.getElementById('modalGroupMembers').textContent = `${memberCount} member${memberCount !== 1 ? 's' : ''}`;
            document.getElementById('modalGroupLeader').textContent = leaderName || 'Unknown';

            // Show modal with animation
            groupDetailsModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden'); // Prevent background scrolling
            // Add animation class after a short delay for transition effect
            requestAnimationFrame(() => {
                 modalPanelDetails.classList.remove('modal-enter');
                 modalPanelDetails.classList.add('modal-enter-active');
            });
        }

        function hideGroupDetails() {
             // Add exit animation class
             modalPanelDetails.classList.remove('modal-enter-active');
             modalPanelDetails.classList.add('modal-exit-active');
             // Hide modal after animation duration (300ms)
             setTimeout(() => {
                groupDetailsModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                modalPanelDetails.classList.remove('modal-exit-active'); // Reset class
                modalPanelDetails.classList.add('modal-enter'); // Reset start state
             }, 300);
        }

        // --- Join Confirmation Modal Functions ---
        function showJoinConfirmation(groupId, groupName) {
            // Populate confirmation modal
            document.getElementById('groupToJoin').value = groupId; // Set group ID for the confirmation form
            const tempDiv = document.createElement('div'); // Use temp element to decode entities safely
            tempDiv.innerHTML = groupName;
            document.getElementById('confirmationText').textContent = `Are you sure you want to join the group "${tempDiv.textContent}"?`;

            // Show modal with animation
            joinConfirmationModal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
             requestAnimationFrame(() => {
                 modalPanelConfirm.classList.remove('modal-enter');
                 modalPanelConfirm.classList.add('modal-enter-active');
            });
        }

        function hideJoinConfirmation() {
            // Add exit animation class
            modalPanelConfirm.classList.remove('modal-enter-active');
            modalPanelConfirm.classList.add('modal-exit-active');
             // Hide modal after animation duration (300ms)
            setTimeout(() => {
                joinConfirmationModal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                modalPanelConfirm.classList.remove('modal-exit-active'); // Reset class
                modalPanelConfirm.classList.add('modal-enter'); // Reset start state
            }, 300);
        }

        // --- General Modal Handling ---

        // Close modals if clicking on the background overlay
        groupDetailsModal.addEventListener('click', (event) => {
            // Check if the click is directly on the overlay (not children)
            if (event.target === groupDetailsModal.querySelector('.fixed.inset-0')) {
                hideGroupDetails();
            }
        });
        joinConfirmationModal.addEventListener('click', (event) => {
             if (event.target === joinConfirmationModal.querySelector('.fixed.inset-0')) {
                hideJoinConfirmation();
            }
        });

        // Close modals with the Escape key
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                if (!groupDetailsModal.classList.contains('hidden')) {
                    hideGroupDetails();
                }
                if (!joinConfirmationModal.classList.contains('hidden')) {
                    hideJoinConfirmation();
                }
            }
        });

        // --- Form Submission Handling (Show loading state) ---
        function showLoadingState(formElement) {
             const submitButton = formElement.querySelector('button[type="submit"]');
             if (submitButton) {
                 submitButton.disabled = true; // Disable button
                 // Store original content if not already stored
                 if (!submitButton.dataset.originalContent) {
                     submitButton.dataset.originalContent = submitButton.innerHTML;
                 }
                 submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
             }
        }

        // Add loading state listener to both join forms
        document.getElementById('joinForm').addEventListener('submit', function() {
            showLoadingState(this);
        });

        document.getElementById('joinFormInModal').addEventListener('submit', function() {
             showLoadingState(this);
        });

        // Optional: Reset button state if the user navigates back without page reload
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) { // Check if page was loaded from cache
                const forms = [document.getElementById('joinForm'), document.getElementById('joinFormInModal')];
                forms.forEach(form => {
                    if (form) {
                        const button = form.querySelector('button[type="submit"]');
                        if (button && button.dataset.originalContent) {
                             button.innerHTML = button.dataset.originalContent;
                             button.disabled = false;
                        }
                    }
                });
            }
        });

    </script>
</body>
</html>
