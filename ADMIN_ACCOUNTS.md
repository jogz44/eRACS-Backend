# Admin Accounts Documentation

## Overview
The eRAC system now supports multiple types of admin accounts with different permission levels.

## Admin Account Types

### 1. Super Administrator
- **Username**: admin
- **Password**: admin123
- **Role**: super_admin
- **Permissions**: 
  - Access to all main functions (Dashboard, Transactions)
  - Full user management access (User Access, Pending Users, Accepted Users, Logs)
  - Complete system control

### 2. COA Officer
- **Username**: coa
- **Password**: coa123
- **Role**: coa
- **Permissions**:
  - Access to all main functions (Dashboard, Transactions)
  - NO access to user management functions
  - Focused on compliance and auditing

## Access Control

### Main Functions (Available to all admin types)
- Dashboard
- Transactions (Appropriation, Disbursement, Augmentation)
- Continuing Transactions

### User Management (Super Admin only)
- User Access control
- Pending Users approval
- Accepted Users management
- System Logs

## Security Features

1. **Role-based Access Control**: Each admin type has specific permissions
2. **Token Authentication**: Secure API access using Laravel Sanctum
3. **Password Hashing**: All passwords are securely hashed
4. **Session Management**: Automatic token expiration and logout

## Database Changes

The system has been updated with:
- New `role` field in the `admins` table
- New `name` field for better user identification
- Three distinct admin accounts with different permission levels

## Usage Notes

- Only Super Administrators can manage user accounts
- Accounting and COA officers can perform all financial transactions
- All admin types can access the main dashboard and transaction functions
- Role information is displayed in the header and footer for user awareness
