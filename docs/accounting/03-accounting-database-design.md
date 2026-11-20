# 03. Accounting Database Design & Entity Relationships

> **Platform:** B2Bhaat Multi-Vendor E-Commerce  
> **Database:** PostgreSQL (Production) / SQLite `:memory:` (Automated Test Suite)  
> **Architecture:** Strict Double-Entry Ledger with Sub-Ledger Dimensions  

---

## 1. Entity Relationship Diagram (ERD)

```mermaid
erDiagram
    accounting_account_groups ||--o{ accounting_accounts : categorizes
    accounting_accounts ||--o{ accounting_accounts : parent_child
    accounting_fiscal_years ||--o{ accounting_periods : contains
    
    accounting_periods ||--o{ accounting_journal_entries : locks_posting_to
    accounting_journal_entries ||--|{ accounting_journal_lines : contains_lines
    accounting_accounts ||--o{ accounting_journal_lines : aggregates_to

    orders ||--o{ accounting_journal_entries : generates
    sub_orders ||--o{ accounting_journal_entries : generates
    refunds ||--o{ accounting_journal_entries : generates
    seller_payments ||--o{ accounting_journal_entries : generates

    users ||--o{ accounting_journal_lines : seller_dimension
    users ||--o{ accounting_journal_lines : customer_dimension
    seller_wallets ||--o{ wallet_transactions : records

    accounting_journal_entries {
        bigint id PK
        string journal_number UK
        foreignId period_id FK
        date entry_date
        timestamp posting_date
        string status
        string financial_event
        string reference_type
        bigint reference_id
        string idempotency_key UK
        text description
        foreignId reversal_journal_id FK
        foreignId created_by_user_id FK
        jsonb metadata
    }

    accounting_journal_lines {
        bigint id PK
        foreignId journal_entry_id FK
        foreignId account_id FK
        decimal debit
        decimal credit
        foreignId seller_id FK
        foreignId customer_id FK
        foreignId sub_order_id FK
        foreignId order_id FK
        text narration
    }

    accounting_accounts {
        bigint id PK
        foreignId group_id FK
        foreignId parent_id FK
        string code UK
        string system_slug UK
        string name
        string type
        string normal_balance
        boolean is_system
        boolean is_active
    }

    accounting_account_groups {
        bigint id PK
        string name
        string type
        string code_prefix
        text description
    }

    accounting_fiscal_years {
        bigint id PK
        string name
        date start_date
        date end_date
        boolean is_closed
    }

    accounting_periods {
        bigint id PK
        foreignId fiscal_year_id FK
        int period_number
        string name
        date start_date
        date end_date
        boolean is_closed
    }
```

---

## 2. Integrity Constraints & Invariants

1. **Double-Entry Balancing Constraint:**
   Every posted entry MUST satisfy:
   $$\sum \text{debit} = \sum \text{credit} \quad \text{where } \sum \text{debit} > 0$$
   An entry with unbalanced debits and credits is rejected at database and service level.

2. **Immutability of Posted Lines:**
   Lines belonging to `posted` or `reversed` journals cannot be updated or deleted. Database-level foreign keys use `onDelete('restrict')`. Corrections are made exclusively by posting an offsetting reversal journal.

3. **Period Locking:**
   Postings are checked against `accounting_periods.is_closed`. If an accounting period is closed, transactions cannot be posted into that date range without authorized period reopening.

4. **Idempotency Guarantee:**
   `accounting_journal_entries.idempotency_key` has a `UNIQUE` database constraint. Any duplicate event dispatch (e.g. repeated courier webhook or duplicate payment callback) fails safely without duplicate ledger entries.

5. **Sub-Ledger Reconcilability:**
   Every balance in `seller_wallets` (`available_balance`, `escrow_held_balance`) is mathematically verifiable against:
   $$\text{Seller Balance} = \sum \text{Credits}(seller\_id) - \sum \text{Debits}(seller\_id)$$
   on accounts `seller_wallet_payable` and `seller_escrow_payable`.

---

*End of 03-accounting-database-design.md*
