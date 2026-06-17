<?php

namespace App\Enums;

/**
 * Cosmetic hint stamped on each transaction by the RecordTransaction service: it
 * picks the entry mini-form and colors the history row. It never affects ledger math.
 */
enum TransactionKind: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Transfer = 'transfer';
}
