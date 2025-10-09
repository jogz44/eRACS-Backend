# Enhanced Budget Database Structure

## Overview

The eRAC system now uses an enhanced single-database approach to separate annual and supplemental budgets while maintaining data integrity and performance. This structure provides better organization, clearer separation of concerns, and improved maintainability.

## Database Schema

### 1. Enhanced `budgets` Table

The main `budgets` table has been enhanced with additional fields to support budget type separation:

```sql
-- New fields added to budgets table
budget_type ENUM('annual', 'supplemental') NOT NULL DEFAULT 'annual'
status ENUM('draft', 'approved', 'active', 'closed') NOT NULL DEFAULT 'draft'
approved_at TIMESTAMP NULL
approved_by BIGINT UNSIGNED NULL (FK to barangay_users)
effective_date DATE NULL
```

**Indexes:**
- `idx_budget_type_lookup` on (`barangay_id`, `fiscal_year_id`, `budget_type`)
- `idx_budget_type_status` on (`budget_type`, `status`)

### 2. `annual_budget_details` Table

Stores specific information for annual budgets:

```sql
CREATE TABLE annual_budget_details (
    id BIGINT PRIMARY KEY,
    budget_id BIGINT REFERENCES budgets(id),
    barangay_id BIGINT REFERENCES barangays(id),
    
    -- Annual budget specific fields
    budget_category VARCHAR(255) NULL,
    source_of_funds VARCHAR(255) NULL,
    allocated_amount DECIMAL(12,2),
    utilized_amount DECIMAL(12,2) DEFAULT 0,
    remaining_amount DECIMAL(12,2),
    
    -- Planning and approval fields
    justification TEXT NULL,
    implementation_plan TEXT NULL,
    planned_start_date DATE NULL,
    planned_end_date DATE NULL,
    
    -- Status tracking
    allocation_status ENUM('planned', 'approved', 'active', 'completed', 'cancelled') DEFAULT 'planned',
    allocation_approved_at TIMESTAMP NULL,
    allocation_approved_by BIGINT UNSIGNED NULL (FK to barangay_users),
    
    timestamps
);
```

### 3. `supplemental_budget_details` Table

Stores specific information for supplemental budgets:

```sql
CREATE TABLE supplemental_budget_details (
    id BIGINT PRIMARY KEY,
    budget_id BIGINT REFERENCES budgets(id),
    barangay_id BIGINT REFERENCES barangays(id),
    
    -- Supplemental budget specific fields
    supplement_type VARCHAR(255) NULL,
    source_of_supplement VARCHAR(255) NULL,
    supplement_amount DECIMAL(12,2),
    utilized_amount DECIMAL(12,2) DEFAULT 0,
    remaining_amount DECIMAL(12,2),
    
    -- Emergency/Urgency fields
    emergency_justification TEXT NULL,
    urgency_level ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
    impact_assessment TEXT NULL,
    
    -- Approval and implementation
    request_date DATE,
    effective_date DATE,
    expiry_date DATE NULL,
    implementation_notes TEXT NULL,
    
    -- Status tracking
    supplement_status ENUM('requested', 'under_review', 'approved', 'active', 'expired', 'cancelled') DEFAULT 'requested',
    supplement_approved_at TIMESTAMP NULL,
    supplement_approved_by BIGINT UNSIGNED NULL (FK to barangay_users),
    
    -- Reference to original budget if this is a reallocation
    original_budget_id BIGINT UNSIGNED NULL (FK to budgets),
    
    timestamps
);
```

## Models and Relationships

### Budget Model Enhancements

The `Budget` model now includes:

- **Budget Type Scopes**: `annual()`, `supplemental()`, `byType()`
- **Status Scopes**: `draft()`, `approved()`, `active()`, `closed()`
- **Helper Methods**: `isAnnual()`, `isSupplemental()`, `approve()`, `activate()`, `close()`
- **Relationships**: `annualBudgetDetail()`, `supplementalBudgetDetail()`, `approvedBy()`

### New Models

1. **AnnualBudgetDetail**: Manages annual budget specific data
2. **SupplementalBudgetDetail**: Manages supplemental budget specific data

## Services

### AnnualBudgetService

Provides dedicated methods for annual budget operations:

- `createAnnualBudget()`: Create annual budget with details
- `getAnnualBudgets()`: Retrieve annual budgets for barangay/fiscal year
- `getActiveAnnualBudget()`: Get active annual budget
- `approveAnnualBudget()`: Approve annual budget
- `activateAnnualBudget()`: Activate annual budget
- `getAnnualBudgetSummary()`: Get summary statistics
- `updateUtilization()`: Update budget utilization
- `canCreateAnnualBudget()`: Check if annual budget can be created

### SupplementalBudgetService

Provides dedicated methods for supplemental budget operations:

- `createSupplementalBudget()`: Create supplemental budget with details
- `getSupplementalBudgets()`: Retrieve supplemental budgets
- `getActiveSupplementalBudgets()`: Get active supplemental budgets
- `getEmergencySupplementalBudgets()`: Get emergency supplemental budgets
- `approveSupplementalBudget()`: Approve supplemental budget
- `activateSupplementalBudget()`: Activate supplemental budget
- `getSupplementalBudgetSummary()`: Get summary statistics
- `updateUtilization()`: Update budget utilization
- `canCreateSupplementalBudget()`: Check if supplemental budget can be created
- `checkExpiredBudgets()`: Check for expired budgets
- `expireOverdueBudgets()`: Expire overdue budgets

## Budget Types

### Annual Budget
- **Purpose**: Main budget for the fiscal year
- **Characteristics**: Planned, comprehensive, long-term
- **Categories**: General Fund, Special Education Fund, Economic Development Fund, etc.
- **Sources**: Internal Revenue Allotment, Local Tax Revenue, etc.

### Supplemental Budget
- **Purpose**: Additional budget allocation during the fiscal year
- **Characteristics**: Reactive, specific, time-bound
- **Types**: Emergency, Additional Allocation, Reallocation, etc.
- **Sources**: Unexpected Revenue, Savings, Emergency Fund, etc.
- **Urgency Levels**: Low, Medium, High, Critical

## Usage Examples

### Creating an Annual Budget

```php
use App\Services\AnnualBudgetService;
use App\BudgetType;

$service = new AnnualBudgetService();

$budget = $service->createAnnualBudget([
    'fiscal_year_id' => $fiscalYearId,
    'start_date' => '2024-01-01',
    'end_date' => '2024-12-31',
    'description' => 'Annual Budget 2024',
    'original_amount' => 5000000,
    'details' => [
        [
            'budget_category' => 'General Fund',
            'source_of_funds' => 'Internal Revenue Allotment (IRA)',
            'allocated_amount' => 5000000,
            'justification' => 'Annual operations budget',
            'implementation_plan' => 'Distributed across programs'
        ]
    ]
], $user);
```

### Creating a Supplemental Budget

```php
use App\Services\SupplementalBudgetService;

$service = new SupplementalBudgetService();

$budget = $service->createSupplementalBudget([
    'fiscal_year_id' => $fiscalYearId,
    'description' => 'Emergency Road Repair',
    'original_amount' => 500000,
    'effective_date' => '2024-06-01',
    'details' => [
        [
            'supplement_type' => 'Emergency',
            'source_of_supplement' => 'Unexpected Revenue',
            'supplement_amount' => 500000,
            'emergency_justification' => 'Urgent road repair needed',
            'urgency_level' => 'high',
            'request_date' => '2024-05-15',
            'effective_date' => '2024-06-01',
            'expiry_date' => '2024-12-31'
        ]
    ]
], $user);
```

### Querying Budgets by Type

```php
// Get all annual budgets
$annualBudgets = Budget::annual()->where('barangay_id', $barangayId)->get();

// Get all supplemental budgets
$supplementalBudgets = Budget::supplemental()->where('barangay_id', $barangayId)->get();

// Get active annual budget
$activeAnnual = Budget::annual()->active()->where('barangay_id', $barangayId)->first();

// Get emergency supplemental budgets
$emergencyBudgets = Budget::supplemental()
    ->whereHas('supplementalBudgetDetail', function($query) {
        $query->where('urgency_level', 'critical');
    })
    ->get();
```

## Migration Strategy

1. **Run the migrations** in the following order:
   - `enhance_budgets_table_with_budget_type`
   - `create_annual_budget_details_table`
   - `create_supplemental_budget_details_table`

2. **Update existing data**:
   - Set `budget_type` to 'annual' for existing budgets
   - Create corresponding detail records for existing budgets

3. **Update seeders**:
   - The `BudgetSeeder` has been updated to create both annual and supplemental budgets with their respective details

## Benefits

1. **Clear Separation**: Annual and supplemental budgets are clearly distinguished
2. **Better Performance**: Optimized indexes for budget type queries
3. **Enhanced Features**: Support for approval workflows, urgency levels, and detailed tracking
4. **Maintainability**: Dedicated services and models for each budget type
5. **Flexibility**: Easy to extend with additional budget types or fields
6. **Data Integrity**: Foreign key constraints maintain referential integrity
7. **Audit Trail**: Comprehensive tracking of approvals and status changes

## Future Enhancements

1. **Budget Templates**: Predefined templates for common budget types
2. **Automated Workflows**: Automatic status transitions based on dates
3. **Budget Analytics**: Advanced reporting and analytics
4. **Integration**: Better integration with disbursement and appropriation systems
5. **Notifications**: Automated notifications for budget status changes

