<?php
require_once("../authentication/session_check.php"); // Checks session and provides $user_id if logged in
require_once("../db_connection.php"); // Provides db_connection() function

// --- Database Connection ---
$conn = db_connection();
if ($conn === false) {
    die("ERROR: Could not connect. " . mysqli_connect_error());
}

// --- Get User and Group ID ---
$current_user_id = null;
$is_logged_in = false;
$user_data = get_user_existence_and_id(conn: $conn); // Assuming this returns [bool exists, int user_id]
if ($user_data[0]) {
    $current_user_id = $user_data[1];
    $is_logged_in = true;
} else {
    header("Location: ../authentication/login.php?redirect=" . urlencode($_SERVER['REQUEST_URI']));
}



// Check if the user is an admin
if (user_type(conn: $conn, user_id: $current_user_id) == "admin") {
    echo "<a>You are not authorized to access this page.</a>";
    exit();
}


// Get group ID from URL parameter and sanitize
$group_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

// --- Initialize Variables ---
$group_info = null;
$leader_info = null;
$member_count = 0;
$members = [];
$current_user_role = 'non-member'; // Default role
$action_error = null;
$action_success = null;

// --- Action Handling (POST Requests) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in && isset($_POST['action']) && isset($_POST['group_id_action']) && (int)$_POST['group_id_action'] === $group_id) {

    $conn->begin_transaction(); // Start transaction for all actions

    try {
        // --- Leave Group Action ---
        if ($_POST['action'] === 'leave') {
            // Fetch membership_id for the current user in this group
            $stmt_get_mem = $conn->prepare("SELECT membership_id FROM joined_group WHERE user_id = ? AND group_id = ?");
            if (!$stmt_get_mem) throw new Exception("Prepare failed (get_mem): " . $conn->error);
            $stmt_get_mem->bind_param("ii", $current_user_id, $group_id);
            $stmt_get_mem->execute();
            $res_mem = $stmt_get_mem->get_result();

            if ($res_mem->num_rows > 0) {
                $membership_id = $res_mem->fetch_assoc()['membership_id'];
                $stmt_get_mem->close();

                // Delete from joined_group
                $stmt_del_jg = $conn->prepare("DELETE FROM joined_group WHERE user_id = ? AND group_id = ?");
                if (!$stmt_del_jg) throw new Exception("Prepare failed (del_jg): " . $conn->error);
                $stmt_del_jg->bind_param("ii", $current_user_id, $group_id);
                $stmt_del_jg->execute();
                $affected_jg = $stmt_del_jg->affected_rows;
                $stmt_del_jg->close();

                // Delete from created_group (if they were the leader)
                $stmt_del_cg = $conn->prepare("DELETE FROM created_group WHERE user_id = ? AND group_id = ?");
                 if (!$stmt_del_cg) throw new Exception("Prepare failed (del_cg): " . $conn->error);
                $stmt_del_cg->bind_param("ii", $current_user_id, $group_id);
                $stmt_del_cg->execute();
                // No need to check affected rows, might not exist
                $stmt_del_cg->close();

                // Delete from member table
                $stmt_del_mem = $conn->prepare("DELETE FROM member WHERE membership_id = ?");
                if (!$stmt_del_mem) throw new Exception("Prepare failed (del_mem): " . $conn->error);
                $stmt_del_mem->bind_param("i", $membership_id);
                $stmt_del_mem->execute();
                $affected_mem = $stmt_del_mem->affected_rows;
                $stmt_del_mem->close();

                if ($affected_jg > 0 && $affected_mem > 0) {
                    $conn->commit();
                    $action_success = "You have successfully left the group.";
                     // Redirect to groups page after leaving
                    header("Location: groups.php?leave_success=1");
                    exit();
                } else {
                    throw new Exception("Failed to delete membership records during leave.");
                }
            } else {
                 $stmt_get_mem->close();
                 throw new Exception("Cannot leave group: You are not a member.");
            }
        }

        // --- Remove Member Action (Leader Only) ---
        elseif ($_POST['action'] === 'remove_member' && isset($_POST['member_user_id'])) {
            $member_to_remove_id = filter_input(INPUT_POST, 'member_user_id', FILTER_VALIDATE_INT);

            // 1. Verify current user is the leader of THIS group
            $stmt_check_leader = $conn->prepare("SELECT m.type FROM joined_group j JOIN member m ON j.membership_id = m.membership_id WHERE j.user_id = ? AND j.group_id = ?");
            if (!$stmt_check_leader) throw new Exception("Prepare failed (check_leader): " . $conn->error);
            $stmt_check_leader->bind_param("ii", $current_user_id, $group_id);
            $stmt_check_leader->execute();
            $res_leader_check = $stmt_check_leader->get_result();

            if ($res_leader_check->num_rows === 0 || $res_leader_check->fetch_assoc()['type'] !== 'leader') {
                 $stmt_check_leader->close();
                 throw new Exception("Permission denied: Only the group leader can remove members.");
            }
            $stmt_check_leader->close();

            // 2. Prevent leader from removing themselves
            if ($member_to_remove_id === $current_user_id) {
                throw new Exception("Leaders cannot remove themselves. Use 'Delete Group' or transfer leadership (if implemented).");
            }

            // 3. Fetch membership_id for the member to remove
            $stmt_get_mem_rem = $conn->prepare("SELECT membership_id FROM joined_group WHERE user_id = ? AND group_id = ?");
             if (!$stmt_get_mem_rem) throw new Exception("Prepare failed (get_mem_rem): " . $conn->error);
            $stmt_get_mem_rem->bind_param("ii", $member_to_remove_id, $group_id);
            $stmt_get_mem_rem->execute();
            $res_mem_rem = $stmt_get_mem_rem->get_result();

            if ($res_mem_rem->num_rows > 0) {
                $membership_id_to_remove = $res_mem_rem->fetch_assoc()['membership_id'];
                $stmt_get_mem_rem->close();

                // 4. Delete from joined_group
                $stmt_del_jg_rem = $conn->prepare("DELETE FROM joined_group WHERE user_id = ? AND group_id = ?");
                 if (!$stmt_del_jg_rem) throw new Exception("Prepare failed (del_jg_rem): " . $conn->error);
                $stmt_del_jg_rem->bind_param("ii", $member_to_remove_id, $group_id);
                $stmt_del_jg_rem->execute();
                $affected_jg_rem = $stmt_del_jg_rem->affected_rows;
                $stmt_del_jg_rem->close();

                // 5. Delete from member table
                $stmt_del_mem_rem = $conn->prepare("DELETE FROM member WHERE membership_id = ?");
                if (!$stmt_del_mem_rem) throw new Exception("Prepare failed (del_mem_rem): " . $conn->error);
                $stmt_del_mem_rem->bind_param("i", $membership_id_to_remove);
                $stmt_del_mem_rem->execute();
                $affected_mem_rem = $stmt_del_mem_rem->affected_rows;
                $stmt_del_mem_rem->close();

                 if ($affected_jg_rem > 0 && $affected_mem_rem > 0) {
                    $conn->commit();
                    $action_success = "Member removed successfully.";
                } else {
                    throw new Exception("Failed to delete membership records during removal.");
                }
            } else {
                $stmt_get_mem_rem->close();
                throw new Exception("Cannot remove member: Member not found in this group.");
            }
        }

        // --- Delete Group Action (Leader Only) ---
        elseif ($_POST['action'] === 'delete_group') {
             // 1. Verify current user is the leader of THIS group
            $stmt_check_leader_del = $conn->prepare("SELECT m.type FROM joined_group j JOIN member m ON j.membership_id = m.membership_id WHERE j.user_id = ? AND j.group_id = ?");
            if (!$stmt_check_leader_del) throw new Exception("Prepare failed (check_leader_del): " . $conn->error);
            $stmt_check_leader_del->bind_param("ii", $current_user_id, $group_id);
            $stmt_check_leader_del->execute();
            $res_leader_check_del = $stmt_check_leader_del->get_result();

            if ($res_leader_check_del->num_rows === 0 || $res_leader_check_del->fetch_assoc()['type'] !== 'leader') {
                 $stmt_check_leader_del->close();
                 throw new Exception("Permission denied: Only the group leader can delete the group.");
            }
            $stmt_check_leader_del->close();

            // 2. Get all membership IDs associated with this group
            $stmt_get_all_mems = $conn->prepare("SELECT membership_id FROM joined_group WHERE group_id = ?");
             if (!$stmt_get_all_mems) throw new Exception("Prepare failed (get_all_mems): " . $conn->error);
            $stmt_get_all_mems->bind_param("i", $group_id);
            $stmt_get_all_mems->execute();
            $res_all_mems = $stmt_get_all_mems->get_result();
            $membership_ids_to_delete = [];
            while ($row = $res_all_mems->fetch_assoc()) {
                $membership_ids_to_delete[] = $row['membership_id'];
            }
            $stmt_get_all_mems->close();

            // 3. Delete from joined_group for all members
            $stmt_del_all_jg = $conn->prepare("DELETE FROM joined_group WHERE group_id = ?");
            if (!$stmt_del_all_jg) throw new Exception("Prepare failed (del_all_jg): " . $conn->error);
            $stmt_del_all_jg->bind_param("i", $group_id);
            $stmt_del_all_jg->execute();
            $stmt_del_all_jg->close();

            // 4. Delete from created_group for the leader
            $stmt_del_all_cg = $conn->prepare("DELETE FROM created_group WHERE group_id = ?");
            if (!$stmt_del_all_cg) throw new Exception("Prepare failed (del_all_cg): " . $conn->error);
            $stmt_del_all_cg->bind_param("i", $group_id);
            $stmt_del_all_cg->execute();
            $stmt_del_all_cg->close();

            // 5. Delete from member table for all associated memberships
            if (!empty($membership_ids_to_delete)) {
                $placeholders = implode(',', array_fill(0, count($membership_ids_to_delete), '?'));
                $types = str_repeat('i', count($membership_ids_to_delete));
                $stmt_del_all_mem = $conn->prepare("DELETE FROM member WHERE membership_id IN ($placeholders)");
                 if (!$stmt_del_all_mem) throw new Exception("Prepare failed (del_all_mem): " . $conn->error);
                $stmt_del_all_mem->bind_param($types, ...$membership_ids_to_delete);
                $stmt_del_all_mem->execute();
                $stmt_del_all_mem->close();
            }

            // 6. TODO: Handle associated group tasks (requires schema change - add group_id to task table)
            // Example: $conn->query("DELETE FROM task WHERE group_id = $group_id AND type = 'group'");

            // 7. Delete the group itself
            $stmt_del_group = $conn->prepare("DELETE FROM groups WHERE group_id = ?");
            if (!$stmt_del_group) throw new Exception("Prepare failed (del_group): " . $conn->error);
            $stmt_del_group->bind_param("i", $group_id);
            $stmt_del_group->execute();
            $affected_group = $stmt_del_group->affected_rows;
            $stmt_del_group->close();

            if ($affected_group > 0) {
                $conn->commit();
                // Redirect to groups page after successful deletion
                header("Location: groups.php?delete_success=1");
                exit();
            } else {
                 throw new Exception("Failed to delete the group record.");
            }
        }

        // --- Join Group Action (for non-members) ---
        elseif ($_POST['action'] === 'join') {
             // Check if user is already a member (redundant check, but safe)
            $check_membership = $conn->prepare("SELECT group_id FROM joined_group WHERE user_id = ? AND group_id = ?");
            if (!$check_membership) throw new Exception("Prepare failed (check_join): " . $conn->error);
            $check_membership->bind_param("ii", $current_user_id, $group_id);
            $check_membership->execute();
            $check_membership->store_result();

            if ($check_membership->num_rows > 0) {
                $check_membership->close();
                throw new Exception("You are already a member of this group.");
            }
            $check_membership->close();

            // Insert into member table as general member
            $stmt_member = $conn->prepare("INSERT INTO member (type) VALUES ('general')");
            if (!$stmt_member) throw new Exception("Prepare failed (insert_member_join): " . $conn->error);
            $stmt_member->execute();
            if ($stmt_member->affected_rows !== 1) throw new Exception("Failed to insert into member table for join.");
            $new_membership_id = $conn->insert_id;
            $stmt_member->close();

            // Insert into joined_group table
            $stmt_joined = $conn->prepare("INSERT INTO joined_group (user_id, membership_id, group_id, joining_date) VALUES (?, ?, ?, NOW())");
            if (!$stmt_joined) throw new Exception("Prepare failed (insert_joined_join): " . $conn->error);
            $stmt_joined->bind_param("iii", $current_user_id, $new_membership_id, $group_id);
            $stmt_joined->execute();
             if ($stmt_joined->affected_rows !== 1) throw new Exception("Failed to insert into joined_group table for join.");
            $stmt_joined->close();

            $conn->commit();
            $action_success = "Successfully joined the group!";
            // No redirect here, stay on the page to see updated details/buttons
        }


    } catch (Exception $e) {
        $conn->rollback(); // Rollback on any error
        error_log("Group Action Error (Group ID: $group_id, User ID: $current_user_id, Action: {$_POST['action']}): " . $e->getMessage());
        $action_error = "An error occurred: " . $e->getMessage();
    }
}

// --- Data Fetching (GET Request or after POST action) ---
// Fetch basic group info

$query = "SELECT g.*
FROM groups g
WHERE g.group_id = '$group_id'
AND (
    EXISTS (SELECT 1 FROM created_group WHERE group_id = g.group_id AND user_id = '$current_user_id')
    OR
    EXISTS (SELECT 1 FROM joined_group WHERE group_id = g.group_id AND user_id = '$current_user_id')
);";

$stmt_group = $conn->prepare($query);
if ($stmt_group) {
    $stmt_group->execute();
    $result_group = $stmt_group->get_result();
    if ($result_group->num_rows > 0) {
        $group_info = $result_group->fetch_assoc();
    } else {
        die("Group not found."); // Group ID exists but no data? Or group was just deleted.
    }
    $stmt_group->close();
} else {
     error_log("Error preparing group info statement: " . $conn->error);
     die("Error fetching group information.");
}


// Fetch leader info
$stmt_leader = $conn->prepare("
    SELECT u.user_id, u.name
    FROM groups g
    JOIN created_group cg ON g.group_id = cg.group_id
    JOIN member m ON cg.membership_id = m.membership_id
    JOIN user u ON cg.user_id = u.user_id
    WHERE g.group_id = ?
    AND m.type = 'leader';
");
if ($stmt_leader) {
    $stmt_leader->bind_param("i", $group_id);
    $stmt_leader->execute();
    $result_leader = $stmt_leader->get_result();
    if ($result_leader->num_rows > 0) {
        $leader_info = $result_leader->fetch_assoc();
    }
    $stmt_leader->close();
    // No need to handle case where leader is not found, as it should always exist if group exists
} else {
     error_log("Error preparing leader info statement: " . $conn->error);
     // Continue without leader info, maybe show 'Unknown'
}
if ($leader_info['user_id'] == $current_user_id) {
    $current_user_role = 'leader'; // User is the leader of this group
} else {
    // Check if user is a general member
    $stmt_check_member = $conn->prepare("SELECT membership_id FROM joined_group WHERE user_id = ? AND group_id = ?");
    if ($stmt_check_member) {
        $stmt_check_member->bind_param("ii", $current_user_id, $group_id);
        $stmt_check_member->execute();
        $res_check_member = $stmt_check_member->get_result();
        if ($res_check_member->num_rows > 0) {
            $current_user_role = 'general'; // User is a general member
        }
        $stmt_check_member->close();
    } else {
         error_log("Error preparing check member statement: " . $conn->error);
    }
}

// Fetch member count
$stmt_count = $conn->prepare("SELECT COUNT(*) as count FROM joined_group WHERE group_id = ?");
if ($stmt_count) {
    $stmt_count->bind_param("i", $group_id);
    $stmt_count->execute();
    $result_count = $stmt_count->get_result();
    $member_count = $result_count->fetch_assoc()['count'];
    $stmt_count->close();
} else {
     error_log("Error preparing member count statement: " . $conn->error);
}


// Fetch all members' details for the modal
$stmt_members = $conn->prepare("
    SELECT u.user_id, u.username, u.name as user_name, jg.joining_date, m.type as member_type
    FROM joined_group jg
    JOIN user u ON jg.user_id = u.user_id
    JOIN member m ON jg.membership_id = m.membership_id
    WHERE jg.group_id = ?
    ORDER BY m.type DESC, u.name ASC
");
if ($stmt_members) {
    $stmt_members->bind_param("i", $group_id);
    $stmt_members->execute();
    $result_members = $stmt_members->get_result();
    while ($row = $result_members->fetch_assoc()) {
        $members[] = $row;
    }
    $stmt_members->close();
} else {
     error_log("Error preparing members list statement: " . $conn->error);
     $action_error = ($action_error ? $action_error . " " : "") . "Could not load member list.";
}


// Close the main connection
$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Group Details: <?= htmlspecialchars($group_info['name'] ?? 'Group') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* Custom Styles */
        .modal { display: none; }
        .modal.active { display: flex; }
        /* Style leader differently in member list */
        .leader-badge {
            background-color: #fef3c7; /* Light yellow */
            color: #b45309; /* Dark yellow/brown */
            padding: 2px 6px;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            margin-left: 0.5rem;
        }
         /* Subtle animation for modal */
        .modal-content {
            animation: fadeInScale 0.3s ease-out forwards;
        }
        @keyframes fadeInScale {
            from { opacity: 0; transform: scale(0.95); }
            to { opacity: 1; transform: scale(1); }
        }
    </style>
</head>
<body class="bg-gray-100 font-sans">
    <div class="container mx-auto px-4 py-8 max-w-4xl">

        <div class="flex justify-between items-center mb-6">
        <a href="groups.php" class="bg-gray-600 text-white px-4 py-2 rounded-md hover:bg-gray-700 transition duration-150 ease-in-out">
    Back
</a>

             <h1 class="text-3xl font-bold text-gray-800 text-center flex-grow">
                <?= htmlspecialchars($group_info['name'] ?? 'Group Details') ?>
            </h1>
            <div class="w-24"></div> </div>


        <?php if ($action_success): ?>
            <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-6" role="alert">
                <?= htmlspecialchars($action_success) ?>
            </div>
        <?php endif; ?>
        <?php if ($action_error): ?>
            <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-6" role="alert">
                <?= htmlspecialchars($action_error) ?>
            </div>
        <?php endif; ?>


        <div class="bg-white rounded-lg shadow-md p-6 mb-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div class="md:col-span-2">
                    <h2 class="text-2xl font-semibold text-gray-800 mb-3 flex items-center">
                        <i class="fas fa-users mr-3 text-gray-500"></i>
                        <?= htmlspecialchars($group_info['name'] ?? 'N/A') ?>
                        <span class="text-sm font-normal bg-gray-200 text-gray-600 px-2 py-0.5 rounded ml-3">
                            ID: <?= $group_id ?>
                        </span>
                    </h2>
                    <p class="text-gray-600 whitespace-pre-wrap">
                        <?= !empty($group_info['description']) ? nl2br(htmlspecialchars($group_info['description'])) : '<span class="italic text-gray-400">No description provided.</span>' ?>
                    </p>
                </div>

                <div class="md:col-span-1 space-y-4">
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <p class="text-sm font-medium text-gray-500 mb-1">Group Leader</p>
                        <p class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-crown mr-2 text-yellow-500"></i>
                            <?= htmlspecialchars($leader_info['name'] ?? 'Unknown') ?>
                        </p>
                    </div>
                    <div class="bg-gray-50 p-4 rounded-lg border border-gray-200">
                        <p class="text-sm font-medium text-gray-500 mb-1">Members</p>
                        <p class="text-lg font-semibold text-gray-800">
                            <i class="fas fa-user-friends mr-2 text-blue-500"></i>
                            <?= $member_count ?>
                        </p>
                    </div>
                     <button onclick="showModal('membersModal')" class="w-full bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                        <i class="fas fa-list-ul mr-2"></i> View Members
                    </button>
                    <button onclick="window.location.href='../tasks/group_task.php?id=<?php echo $group_id?>';" class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                        <i class="fas fa-list-ul mr-2"></i> View Tasks
                    </button>
                    
                </div>
            </div>
        </div>

        <div class="bg-white rounded-lg shadow-md p-6">
            <h3 class="text-lg font-semibold text-gray-700 mb-4">Actions</h3>
            <div class="flex flex-wrap gap-4">
                <?php if ($is_logged_in): ?>
                    <?php if ($current_user_role === 'leader'): ?>
                        <a href="edit_group.php?id=<?= $group_id ?>" class="bg-yellow-500 hover:bg-yellow-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                            <i class="fas fa-edit mr-2"></i> Edit Group Info
                        </a>
                        <button onclick="showModal('deleteGroupConfirmModal')" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                            <i class="fas fa-trash-alt mr-2"></i> Delete Group
                        </button>
                    <?php elseif ($current_user_role === 'general'): ?>
                        <button onclick="showModal('leaveConfirmModal')" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                            <i class="fas fa-sign-out-alt mr-2"></i> Leave Group
                        </button>
                     <?php else: // Logged in but not a member ?>
                         <form method="post" action="group_details.php?id=<?= $group_id ?>">
                            <input type="hidden" name="action" value="join">
                            <input type="hidden" name="group_id_action" value="<?= $group_id ?>">
                            <button type="submit" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                                <i class="fas fa-user-plus mr-2"></i> Join Group
                            </button>
                        </form>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="text-gray-600">
                        <a href="../authentication/login.php?redirect=<?= urlencode($_SERVER['REQUEST_URI']) ?>" class="text-blue-600 hover:underline">Log in</a> to join or interact with this group.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="membersModal" class="modal fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 items-center justify-center p-4">
        <div class="modal-content bg-white rounded-lg shadow-xl w-full max-w-lg max-h-[80vh] overflow-hidden flex flex-col">
            <div class="flex justify-between items-center p-4 border-b">
                <h3 class="text-xl font-semibold">Group Members (<?= $member_count ?>)</h3>
                <button onclick="hideModal('membersModal')" class="text-gray-500 hover:text-gray-700">&times;</button>
            </div>
            <div class="p-6 overflow-y-auto">
                <ul class="space-y-3">
                    <?php if (!empty($members)): ?>
                        <?php foreach ($members as $member):
                            $joining_date = new DateTime($member['joining_date']);
                            $formatted_join_date = $joining_date->format("M d, Y");
                        ?>
                            <li class="flex items-center justify-between p-3 bg-gray-50 rounded-md border">
                                <div>
                                    <span class="font-semibold"><?= htmlspecialchars($member['user_name']) ?></span>
                                    (@<?= htmlspecialchars($member['username']) ?>)
                                    <?php if ($member['member_type'] === 'leader'): ?>
                                        <span class="leader-badge"><i class="fas fa-crown text-xs mr-1"></i>Leader</span>
                                    <?php endif; ?>
                                    <span class="block text-xs text-gray-500">Joined: <?= $formatted_join_date ?></span>
                                </div>
                                <?php if (($current_user_role === 'leader' && $member['user_id'] !== $current_user_id) && ($member['member_type'] != 'leader')): ?>
                                    <button onclick="showRemoveConfirmModal(<?= $member['user_id'] ?>, '<?= htmlspecialchars(addslashes($member['user_name'])) ?>')"
                                            class="text-red-500 hover:text-red-700 text-sm font-medium px-2 py-1 rounded hover:bg-red-100 transition duration-150 ease-in-out">
                                        <i class="fas fa-user-minus mr-1"></i> Remove
                                    </button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <li class="text-gray-500 italic">No members found.</li>
                    <?php endif; ?>
                </ul>
            </div>
             <div class="p-4 border-t bg-gray-50 text-right">
                 <button onclick="hideModal('membersModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                    Close
                </button>
            </div>
        </div>
    </div>

    <div id="leaveConfirmModal" class="modal fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 items-center justify-center p-4">
        <div class="modal-content bg-white rounded-lg shadow-xl w-full max-w-md">
            <div class="p-6 text-center">
                <i class="fas fa-exclamation-triangle text-4xl text-red-500 mb-4"></i>
                <h3 class="text-lg font-semibold mb-2">Confirm Leave</h3>
                <p class="text-gray-600 mb-6">Are you sure you want to leave the group "<?= htmlspecialchars($group_info['name'] ?? '') ?>"?</p>
                <div class="flex justify-center gap-4">
                    <button onclick="hideModal('leaveConfirmModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                        Cancel
                    </button>
                    <form method="post" action="group_details.php?id=<?= $group_id ?>" style="display: inline;">
                        <input type="hidden" name="action" value="leave">
                        <input type="hidden" name="group_id_action" value="<?= $group_id ?>">
                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                            Yes, Leave
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

     <div id="removeMemberConfirmModal" class="modal fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 items-center justify-center p-4">
        <div class="modal-content bg-white rounded-lg shadow-xl w-full max-w-md">
            <div class="p-6 text-center">
                <i class="fas fa-user-minus text-4xl text-red-500 mb-4"></i>
                <h3 class="text-lg font-semibold mb-2">Confirm Removal</h3>
                <p class="text-gray-600 mb-6">Are you sure you want to remove <strong id="removeMemberName">this member</strong> from the group?</p>
                <div class="flex justify-center gap-4">
                    <button onclick="hideModal('removeMemberConfirmModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                        Cancel
                    </button>
                    <form id="removeMemberForm" method="post" action="group_details.php?id=<?= $group_id ?>" style="display: inline;">
                        <input type="hidden" name="action" value="remove_member">
                        <input type="hidden" name="group_id_action" value="<?= $group_id ?>">
                        <input type="hidden" name="member_user_id" id="memberUserIdToRemove" value="">
                        <button type="submit" class="bg-red-500 hover:bg-red-600 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                            Yes, Remove
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="deleteGroupConfirmModal" class="modal fixed inset-0 z-50 overflow-y-auto bg-black bg-opacity-50 items-center justify-center p-4">
        <div class="modal-content bg-white rounded-lg shadow-xl w-full max-w-md">
             <div class="p-6 text-center">
                <i class="fas fa-trash-alt text-4xl text-red-600 mb-4"></i>
                <h3 class="text-lg font-semibold mb-2">Confirm Group Deletion</h3>
                <p class="text-gray-600 mb-6">Are you absolutely sure you want to delete the group "<?= htmlspecialchars($group_info['name'] ?? '') ?>"? This action cannot be undone and will remove all members.</p>
                <div class="flex justify-center gap-4">
                    <button onclick="hideModal('deleteGroupConfirmModal')" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                        Cancel
                    </button>
                    <form method="post" action="group_details.php?id=<?= $group_id ?>" style="display: inline;">
                        <input type="hidden" name="action" value="delete_group">
                        <input type="hidden" name="group_id_action" value="<?= $group_id ?>">
                        <button type="submit" class="bg-red-600 hover:bg-red-700 text-white font-bold py-2 px-4 rounded transition duration-150 ease-in-out">
                            Yes, Delete Group
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>


    <script>
        // --- Modal Handling ---
        function showModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.add('active');
                document.body.classList.add('overflow-hidden'); // Prevent background scroll
            }
        }

        function hideModal(modalId) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.classList.remove('active');
                // Only remove overflow hidden if no other modals are active
                const anyModalActive = document.querySelector('.modal.active');
                if (!anyModalActive) {
                     document.body.classList.remove('overflow-hidden');
                }
            }
        }

         // --- Specific Modal Setup ---
        function showRemoveConfirmModal(memberUserId, memberName) {
             // Decode name for display
             const tempDiv = document.createElement('div');
             tempDiv.innerHTML = memberName;
             const decodedName = tempDiv.textContent || tempDiv.innerText || "this member";

            document.getElementById('removeMemberName').textContent = decodedName;
            document.getElementById('memberUserIdToRemove').value = memberUserId;
            showModal('removeMemberConfirmModal');
        }


        // Close modal if clicking outside the modal content
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(event) {
                // Check if the click is directly on the overlay (modal background)
                if (event.target === modal) {
                    hideModal(modal.id);
                }
            });
        });

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                document.querySelectorAll('.modal.active').forEach(modal => {
                    hideModal(modal.id);
                });
            }
        });

         // Optional: Add loading state to submit buttons within modals on form submission
        document.querySelectorAll('.modal form').forEach(form => {
            form.addEventListener('submit', function() {
                const button = form.querySelector('button[type="submit"]');
                if (button) {
                    button.disabled = true;
                    // Add a spinner icon or change text
                    button.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i> Processing...';
                }
            });
        });

        // Optional: Reset button state if the user navigates back without page reload
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) { // Check if page was loaded from cache
                 document.querySelectorAll('.modal form button[type="submit"]').forEach(button => {
                     // You might need to store the original HTML of the button
                     // For simplicity, we'll just re-enable it. A better approach stores original text/HTML.
                     button.disabled = false;
                     // Reset text/HTML if you stored it, e.g., button.innerHTML = button.dataset.originalHtml;
                 });
            }
        });

    </script>

</body>
</html>
