# TaskMaster - Task Management System

![TaskMaster Banner](https://i.postimg.cc/Z53gpQhD/Screenshot-2025-05-14-204748.png)

A comprehensive web-based task management platform designed to streamline personal productivity, team collaboration, and administrative oversight. TaskMaster provides an intuitive interface for managing tasks, collaborating within groups, submitting reports, and administrative management.

🚀 **Live Demo:** [https://taskmaster.byethost11.com](https://taskmaster.byethost11.com)

---

## 📋 Table of Contents

- [Features](#features)
- [Technology Stack](#technology-stack)
- [Database Schema](#database-schema)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Security Features](#security-features)
- [Project Structure](#project-structure)
- [Contributing](#contributing)
- [License](#license)

---

## ✨ Features

### 🔐 Authentication & Authorization

- **Secure User Registration and Login**
  - SHA-3 256-bit password hashing with Base64 encoding
  - Custom session management with 128-character session tokens
  - Cookie-based authentication with configurable expiration (24 hours default)
  - Device tracking (IP address, user agent, hostname)
- **Role-Based Access Control**
  - Guest users: Standard task and group management
  - Admin users: Full system access including user and report management
  - Group leaders: Group administration and task assignment capabilities

### 📝 Task Management

- **Personal Tasks**

  - Create, edit, and delete private tasks
  - Task status tracking: Todo, In Progress, Done, Dismissed
  - Deadline management with sorting by urgency
  - Character limits: 30 for titles, 200 for details
  - Real-time status updates with form submissions

- **Group Tasks**
  - Create tasks within group contexts
  - Assign tasks to group members (leader only)
  - View all group tasks with member visibility
  - Edit and delete permissions based on role
  - Task filtering by status

### 👥 Group Collaboration

- **Group Management**

  - Create groups with name (max 30 chars) and description (max 200 chars)
  - Search groups by ID or name
  - Join public groups
  - Leave groups (non-leaders)
  - View group members and details

- **Leadership Features**
  - Edit group information (name, description)
  - Remove members from groups
  - Delete entire groups
  - Assign and manage group tasks
  - Full member oversight

### 📊 Report Management

- **User Reports**

  - Submit reports to administrators
  - Attach images (JPEG/PNG, max 10MB)
  - Track report status: Pending, Reviewed, Resolved, Dismissed
  - View submission history
  - Edit and delete pending reports

- **Admin Report Dashboard**
  - View all user reports with pagination (10 per page)
  - Filter by user ID, report ID, subject, date range, and status
  - Accept or reject reports
  - View attached files
  - Persistent filter state across sessions

### 👤 User Profile Management

- **Profile Information**

  - Username, full name, and account type
  - Birth date, joining date
  - Contact information (email, phone)
  - Gender and profession
  - Profile editing with validation

- **Security Settings**
  - Change password with old password verification
  - View all active sessions with device information
  - Delete specific sessions remotely
  - Account deletion with password confirmation
  - Submit reports directly to admin

### 🛡️ Admin Panel

- **User Management**

  - View all registered users in tabular format
  - Delete user accounts (except self)
  - View user details (ID, username, name, type, join date, email)
  - User action tracking

- **Report Oversight**
  - Comprehensive report filtering and pagination
  - Accept/reject report submissions
  - View report details and attachments
  - Status management workflow

---

## 🛠️ Technology Stack

### Backend

- **PHP 7.4+**: Server-side scripting
- **MySQL 5.7+**: Relational database management
- **MySQLi**: Database connectivity with prepared statements

### Frontend

- **HTML5**: Semantic markup
- **CSS3**: Modern styling with flexbox and grid
- **JavaScript (Vanilla)**: Client-side interactivity
- **Responsive Design**: Mobile-first approach

### Security

- **SHA-3 256-bit Hashing**: Password encryption
- **Prepared Statements**: SQL injection prevention
- **HttpOnly Cookies**: XSS protection
- **Input Sanitization**: Data validation and cleaning

---

## 🗄️ Database Schema

### Core Tables

#### `user`

```sql
- user_id (PK, AUTO_INCREMENT)
- username (UNIQUE, VARCHAR(30))
- name (VARCHAR(50))
- type (ENUM: 'guest', 'admin')
- password_hash (VARCHAR(255))
- birth_date (DATE, NULLABLE)
- joining_date (DATE)
- phone_number (VARCHAR(20), UNIQUE, NULLABLE)
- email (VARCHAR(320), UNIQUE, NULLABLE)
- gender (ENUM: 'M', 'F', 'Other', NULLABLE)
- profession (VARCHAR(100), NULLABLE)
```

#### `session`

```sql
- session_id (PK, CHAR(128))
- user_id (FK → user.user_id)
- expire_time (DATETIME)
- device_login_info (VARCHAR(809))
- created_at (DATETIME, DEFAULT CURRENT_TIMESTAMP)
```

#### `task`

```sql
- task_id (PK, AUTO_INCREMENT)
- title (VARCHAR(100))
- detail (VARCHAR(500))
- status (ENUM: 'todo', 'in_progress', 'done', 'dismissed')
- type (ENUM: 'personal', 'group')
- creation_time (DATETIME, DEFAULT CURRENT_TIMESTAMP)
- deadline (DATETIME, NULLABLE)
- user_id (FK → user.user_id, NULLABLE)
- membership_id (FK → member.membership_id, NULLABLE)
```

#### `groups`

```sql
- group_id (PK, AUTO_INCREMENT)
- name (VARCHAR(50))
- description (VARCHAR(500))
```

#### `member`

```sql
- membership_id (PK, AUTO_INCREMENT)
- type (ENUM: 'general', 'leader')
```

#### `joined_group`

```sql
- membership_id (PK, FK → member.membership_id)
- user_id (FK → user.user_id)
- group_id (PK, FK → groups.group_id)
- joining_date (DATETIME, DEFAULT CURRENT_TIMESTAMP)
```

#### `created_group`

```sql
- membership_id (PK, FK → member.membership_id)
- user_id (FK → user.user_id)
- group_id (PK, FK → groups.group_id)
- creation_date (DATETIME, DEFAULT CURRENT_TIMESTAMP)
```

#### `reports`

```sql
- report_id (PK, AUTO_INCREMENT)
- user_id (FK → user.user_id)
- subject (VARCHAR(100))
- details (VARCHAR(1000))
- file_extension (VARCHAR(10), NULLABLE)
- submission_date (DATETIME, DEFAULT CURRENT_TIMESTAMP)
- status (ENUM: 'pending', 'reviewed', 'resolved', 'dismissed')
```

### Database Relationships

- **ON DELETE CASCADE**: Ensures data integrity when users or groups are deleted
- **Foreign Key Constraints**: Maintains referential integrity across all tables
- **Indexed Fields**: Optimized queries on username, email, user_id, group_id, status fields

---

## 📥 Installation

### Prerequisites

- **PHP**: Version 7.4 or higher
- **MySQL**: Version 5.7 or higher
- **Web Server**: Apache, Nginx, or PHP built-in server
- **Composer** (optional): For dependency management

### Step-by-Step Setup

1. **Clone the Repository**

   ```bash
   git clone https://github.com/ehteshamulhaqueadit/task_management.git
   cd task_management
   ```

2. **Create MySQL Database**

   ```bash
   mysql -u root -p
   ```

   ```sql
   CREATE DATABASE task_management;
   CREATE USER 'tm_admin'@'localhost' IDENTIFIED BY 'tmadmin1234';
   GRANT ALL PRIVILEGES ON task_management.* TO 'tm_admin'@'localhost';
   FLUSH PRIVILEGES;
   EXIT;
   ```

3. **Import Database Schema**

   Execute the SQL commands from `aditnote.txt` to create all required tables:

   ```bash
   mysql -u tm_admin -p task_management < aditnote.txt
   ```

   Or run the SQL commands manually in your MySQL client. The schema includes:

   - User table with authentication fields
   - Session management table
   - Task management tables
   - Group collaboration tables (groups, member, joined_group, created_group)
   - Reports table

4. **Configure Database Connection**

   Edit `db_connection.php` if needed (default credentials are already set):

   ```php
   $servername = "localhost";
   $serverusername = "tm_admin";
   $password = "tmadmin1234";
   $dbname = "task_management";
   ```

5. **Set Up File Permissions**

   ```bash
   # Create resources directory for uploaded files
   mkdir -p resources/images/reports
   chmod 755 resources
   chmod 755 resources/images
   chmod 777 resources/images/reports
   ```

6. **Start the Web Server**

   **Option A: Apache/Nginx**

   - Place the project in your web server's document root
   - Ensure mod_rewrite is enabled for Apache
   - Access via `http://localhost/task_management/`

   **Option B: PHP Built-in Server**

   ```bash
   php -S localhost:8000
   ```

   Access via `http://localhost:8000/welcome.html`

7. **Create Admin Account**

   First, register a regular account through the web interface, then update it to admin:

   ```sql
   UPDATE user SET type = 'admin' WHERE username = 'your_username';
   ```

---

## ⚙️ Configuration

### Session Management

Edit `authentication/session_check.php` to customize session behavior:

- **Session Duration**: Default 86400 seconds (24 hours)
- **Cookie Path**: Default '/' (entire domain)
- **Session Key Length**: 128 characters (cryptographically secure)

### File Upload Settings

Edit `user_management/security.php` for report attachments:

- **Max File Size**: 10MB
- **Allowed Types**: JPEG, PNG images only
- **Storage Location**: `resources/images/reports/`

### Pagination

Edit `admin/reports/reports.php` to change pagination:

```php
$records_per_page = 10; // Adjust as needed
```

---

## 🚀 Usage

### For Regular Users

1. **Getting Started**

   - Navigate to the welcome page
   - Register a new account with username, name, and password (min 6 characters)
   - Login with your credentials

2. **Managing Personal Tasks**

   - Access "Tasks" from the home page
   - Create new tasks with title, details, and optional deadline
   - Update task status: Todo → In Progress → Done or Dismissed
   - Edit or delete existing tasks
   - Filter tasks by status

3. **Group Collaboration**

   - Browse "Groups" to see your joined and created groups
   - Search for groups by name or ID
   - Create new groups with descriptive information
   - Join existing groups
   - View group tasks and participate in collaboration

4. **Profile Management**
   - Access "Personal Profile" to view/edit your information
   - Update contact details, birth date, profession, and gender
   - Navigate to "Security" for password management
   - View and manage active sessions
   - Submit reports to administrators

### For Group Leaders

1. **Managing Groups**

   - Edit group details (name, description)
   - View all group members
   - Remove members from the group
   - Delete the group entirely

2. **Task Assignment**
   - Create group tasks visible to all members
   - Edit or delete any group task
   - Monitor task completion by members

### For Administrators

1. **Access Admin Panel**

   - Login with admin account
   - Navigate to Admin Panel from home page

2. **User Management**

   - View all registered users
   - Delete user accounts (maintains data integrity via CASCADE)
   - Monitor user registration trends

3. **Report Management**
   - Review submitted reports with filtering options
   - Filter by user ID, report ID, subject, date range, or status
   - Accept or reject reports
   - View attached files
   - Track report resolution

---

## 🔒 Security Features

### Password Security

- **SHA-3 256-bit Hashing**: Industry-standard cryptographic hash function
- **Base64 Encoding**: Additional encoding layer for storage
- **Password Requirements**: Minimum 6 characters enforced
- **Old Password Verification**: Required for password changes

### Session Security

- **Custom Session Management**: Not relying on PHP's default sessions
- **128-Character Tokens**: Cryptographically random session IDs
- **HttpOnly Cookies**: Prevents JavaScript access to session cookies
- **Expiration Handling**: Automatic session cleanup after 24 hours
- **Device Tracking**: Monitors login location and device information

### SQL Injection Prevention

- **Prepared Statements**: All database queries use parameterized queries
- **Input Sanitization**: User input cleaned before processing
- **Type Validation**: Filter_input with appropriate filters

### File Upload Security

- **MIME Type Validation**: Server-side verification of file types
- **File Size Limits**: Maximum 10MB enforced
- **Restricted Extensions**: Only JPEG and PNG images allowed
- **Secure Storage**: Files stored outside web root where possible

### Additional Security Measures

- **CSRF Protection**: Form tokens (can be enhanced)
- **Access Control**: Role-based permissions enforced server-side
- **Error Handling**: Logged errors without exposing system details
- **Database Constraints**: Foreign key relationships prevent orphaned data

---

## 📁 Project Structure

```
task_management/
├── admin/                          # Admin-only features
│   ├── admin.php                   # Admin dashboard
│   ├── reports/
│   │   ├── reports.php             # Report management interface
│   │   └── view_report.php         # Individual report viewer
│   └── users/
│       └── users.php               # User management interface
├── authentication/                  # Authentication system
│   ├── login.php                   # User login page
│   ├── register.php                # New user registration
│   └── session_check.php           # Session validation & helper functions
├── colaboration/                    # Group features (note: typo in folder name)
│   ├── groups.php                  # User's groups dashboard
│   ├── create_group.php            # Create new group
│   ├── edit_group.php              # Edit group details (leader only)
│   ├── group_details.php           # View group information & members
│   └── search_groups.php           # Search and join groups
├── reports_management/              # User report features
│   ├── reports.php                 # User's submitted reports
│   └── edit_report.php             # Edit pending reports
├── resources/                       # Uploaded files & assets
│   └── images/
│       └── reports/                # Report attachment storage
├── tasks/                           # Task management
│   ├── task.php                    # Personal task dashboard
│   ├── create_task.php             # Create personal task
│   ├── edit_task.php               # Edit personal task
│   ├── group_task.php              # Group task dashboard
│   ├── create_group_task.php       # Create group task (leader)
│   └── edit_group_task.php         # Edit group task
├── test/                            # Testing files
│   └── test.php                    # Development testing
├── user_management/                 # User profile features
│   ├── personal_profile.php        # View user profile
│   ├── edit_profile.php            # Edit profile information
│   ├── security.php                # Security settings & session management
│   ├── delete_session.php          # Session removal handler
│   └── serve.php                   # Utility functions
├── aditnote.txt                     # Database schema SQL commands
├── db_connection.php                # Database connection handler
├── feature_list.txt                 # Feature planning document
├── home.php                         # User dashboard after login
├── index.php                        # Main entry point (redirector)
├── README.md                        # This file
└── welcome.html                     # Landing page for visitors
```

### Key Files Explained

- **`index.php`**: Entry point that redirects users based on authentication status
- **`db_connection.php`**: Centralized database connection with error handling
- **`authentication/session_check.php`**: Core authentication library with helper functions:
  - `cookie_exist()`: Check for session cookie
  - `get_session_from_cookie()`: Retrieve session ID
  - `get_user_existence_and_id()`: Validate session and get user ID
  - `set_cookie()`: Custom cookie setter
  - `generateSessionKey()`: Create secure random session tokens
  - `username_exist()`, `email_exist()`, `phone_number_exist()`: Uniqueness validators
  - `get_all_sessions()`: Retrieve user's active sessions
  - `user_type()`: Get user role (guest/admin)

---

## 🤝 Contributing

Contributions are welcome! Please follow these guidelines:

1. **Fork the Repository**

   ```bash
   git clone https://github.com/ehteshamulhaqueadit/task_management.git
   cd task_management
   git checkout -b feature/your-feature-name
   ```

2. **Code Standards**

   - Follow PHP PSR-12 coding standards
   - Use prepared statements for all database queries
   - Comment complex logic
   - Validate and sanitize all user inputs

3. **Testing**

   - Test all functionality before submitting
   - Ensure no SQL injection vulnerabilities
   - Verify role-based access controls work correctly

4. **Submit Pull Request**
   - Provide clear description of changes
   - Reference any related issues
   - Include screenshots for UI changes

---

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

---

## 👨‍💻 Author

**Ehteshamul Haque Adit**

- GitHub: [@ehteshamulhaqueadit](https://github.com/ehteshamulhaqueadit)
- Live Demo: [https://taskmaster.byethost11.com](https://taskmaster.byethost11.com)

---

## 🙏 Acknowledgments

- PHP and MySQL communities for excellent documentation
- Bootstrap and modern CSS techniques for responsive design inspiration
- Security best practices from OWASP guidelines

---

## 📞 Support

For issues, questions, or suggestions:

- Open an issue on GitHub
- Submit a report through the application's report feature (if you have admin access)

---

## 🔄 Version History

- **v1.0.0** (Current)
  - Initial release
  - Complete task management system
  - Group collaboration features
  - Admin panel implementation
  - Report submission system
  - Comprehensive security measures

---

## 🚧 Future Enhancements

Potential features for future versions:

- Real-time notifications
- Email integration for password recovery
- Task priority levels
- Calendar view for deadlines
- File attachments for tasks
- Group chat functionality
- API for mobile app integration
- Two-factor authentication
- Activity logs and audit trails
- Data export functionality (CSV/PDF)

---

**Made with ❤️ for better task management and team collaboration**
