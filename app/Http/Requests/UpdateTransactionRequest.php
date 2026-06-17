<?php

namespace App\Http\Requests;

/**
 * Editing replaces the whole posting set (decision #8), so an edit validates exactly
 * like a fresh entry. Ownership of the transaction itself is authorized in the controller.
 */
class UpdateTransactionRequest extends StoreTransactionRequest {}
