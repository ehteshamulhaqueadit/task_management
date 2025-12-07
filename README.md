# Task Master
![EduManage Banner](https://i.postimg.cc/Z53gpQhD/Screenshot-2025-05-14-204748.png)

The *Task Management System* is a web-based platform designed to streamline task management, group collaboration, and report handling. It provides a user-friendly interface for users to manage their tasks, collaborate in groups, and submit reports, while offering an admin panel for administrative oversight.

---
# 🌐 EhadIT Web Portal

Welcome to the EhadIT Web Portal project!

🚀 **Live Site:** [http://ehadit.mooo.com:370/welcome.html](http://ehadit.mooo.com:370/welcome.html)

---
## Features

### 1. *Authentication*
- Secure user login and registration.
- Session management with cookies.
- Role-based access control (Admin, Leader, General Member).

### 2. *Task Management*
- Create, edit, and delete tasks.
- Tasks categorized as:
  - *Private Tasks*: Managed by individual users.
  - *Group Tasks*: Shared within groups.
- Task status tracking: To-Do, Done, Dismissed.
- Deadlines and task details management.

### 3. *Group Collaboration*
- Create and manage groups.
- Join and leave groups.
- Group leaders can:
  - Assign tasks to group members.
  - Manage group details and members.
- Group search functionality.

### 4. *Report Management*
- Submit detailed reports with optional file attachments.
- Admins can:
  - View, filter, and manage reports.
  - Approve or reject submitted reports.

### 5. *User Management*
- Personal profile management.
- View and update user details.
- Gender, profession, and contact information handling.

### 6. *Admin Panel*
- Dedicated admin dashboard.
- Manage users, groups, and reports.
- Oversee system activities.

### 7. *Database Design*
- Relational database with the following tables:
  - user, session, task, groups, member, reports, created_group, joined_group.
- Foreign key constraints for data integrity.

### 8. *Responsive Design*
- Fully responsive UI for desktop and mobile devices.
- Styled using *CSS* and *Tailwind CSS*.
- Interactive buttons and modals for better user experience.

### 9. *Security*
- Input sanitization to prevent SQL injection.
- Secure session and cookie management.

---

## Installation

### Prerequisites
- *PHP* (Version 7.4 or higher)
- *MySQL* (Version 5.7 or higher)
- A web server (e.g., Apache or Nginx)

### Steps
1. Clone the repository:
   ```bash
   git clone <repository-url>
