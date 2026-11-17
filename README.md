# PrintERP – Printing Business Management System

A web-based ERP application developed using Core PHP and MySQL to manage the day-to-day operations of a printing business.

PrintERP provides a centralized system for managing products, categories, vendors, purchases, clients, sales, printing services, payments, inventory, reports, and user access permissions.

## Key Features

### Dashboard
- Business overview with summary statistics.
- Monthly sales analytics and charts.
- Product stock overview.
- Service status tracking.
- Recent sales, services, and payment activities.
- Permission-based quick access.

### Product & Inventory Management
- Product and category management.
- Product stock tracking.
- Product-related records and information.

### Vendor & Purchase Management
- Vendor management.
- Purchase record management.
- Purchase item tracking.

### Client & Sales Management
- Client information management.
- Sales creation and management.
- Sale item management.
- Client transaction records.

### Printing Service Management
- Create and manage printing services.
- Manage service items.
- Track service progress:
  - Pending
  - In Progress
  - Completed
  - Delivered

### Payment Management
- Payment record management.
- Track received and paid amounts.
- Maintain payment transaction details.

### Business Reports
- Sales Report
- Purchase Report
- Profit & Loss Report
- Stock Report
- Service Report
- Client Report
- Vendor Report

### User & Role Management
- User management.
- Dynamic role creation and management.
- Role-based permissions.
- Custom user-level permissions.
- Active/inactive user and role management.
- Restricted access to unauthorized modules and pages.

## Technology Stack

| Technology | Usage |
|---|---|
| PHP | Backend Development |
| MySQL | Database |
| MySQLi | Database Connectivity |
| HTML5 | Structure |
| CSS3 | Styling |
| JavaScript | Frontend Functionality |
| Tailwind CSS | UI Styling |
| Chart.js | Dashboard Charts |
| Font Awesome | Icons |
| Apache | Local Server |

## Project Structure

```text
print-erp/
│
├── categories/
├── clients/
├── config/
├── includes/
├── payments/
├── product_purchase/
├── products/
├── reports/
├── roles/
├── sales/
├── services/
├── sizes/
├── users/
├── vendors/
│
├── create_admin.php
├── dashboard.php
├── index.php
├── login.php
├── logout.php
└── README.md
```

## Installation & Setup

### Requirements
- XAMPP
- PHP 8.2+
- MySQL / MariaDB
- Web Browser

### 1. Clone Repository

```bash
git clone https://github.com/Him895/print-erp.git
```

### 2. Move Project

Place the project inside the XAMPP `htdocs` directory.

```text
C:\xampp\htdocs\print-erp
```

### 3. Start XAMPP

Start Apache and MySQL.

### 4. Create Database

Open phpMyAdmin and create a database:

```sql
CREATE DATABASE print_erp;
```

Import the project's database SQL file if provided.

### 5. Configure Database

Update the database credentials in:

`config/config.php`

```php
$host = 'localhost';
$user = 'root';
$password = '';
$database = 'print_erp';
```

Verify the `BASE_URL` configuration as well.

### 6. Run Application

Open your browser:

```text
http://localhost/print-erp/
```

## Security & Access Control

PrintERP includes a dynamic access control system:

- Admin users have full system access.
- Other users access modules according to assigned permissions.
- Individual custom permissions are supported.
- Unauthorized page access is restricted.

## Project Status

- Core ERP modules implemented.
- Role and permission management implemented.
- Module-level testing performed during development.
- Client-side practical testing planned.

## Developer

**Himanshu Gondane**

PHP Developer | Core PHP | MySQL | Laravel

GitHub: https://github.com/Him895

---

**PrintERP – Simplifying Printing Business Management.**
