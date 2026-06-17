<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import {
    ArrowDownLeft,
    ArrowLeftRight,
    ArrowUpRight,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import TransactionFormDialog from '@/components/TransactionFormDialog.vue';
import type {
    CategoryOption,
    Kind,
    MoneyAccount,
    TransactionEdit,
} from '@/components/TransactionFormDialog.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Skeleton } from '@/components/ui/skeleton';
import { destroy, index } from '@/routes/transactions';

type TransactionRow = {
    id: number;
    kind: Kind;
    date: string;
    date_label: string;
    payee: string | null;
    memo: string | null;
    summary: string;
    account_label: string;
    amount_display: string;
    direction: 'in' | 'out' | 'transfer';
    edit: TransactionEdit | null;
};

defineProps<{
    transactions?: TransactionRow[];
    accounts: MoneyAccount[];
    expenseCategories: CategoryOption[];
    incomeCategories: CategoryOption[];
    baseCurrency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Transactions', href: index().url }],
    },
});

const directionIcon = {
    in: ArrowDownLeft,
    out: ArrowUpRight,
    transfer: ArrowLeftRight,
};

function remove(transaction: TransactionRow) {
    if (!confirm("Delete this transaction? This can't be undone.")) {
        return;
    }

    router.delete(destroy(transaction.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Transactions" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <Card>
            <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle>Transactions</CardTitle>
                <TransactionFormDialog
                    :accounts="accounts"
                    :expense-categories="expenseCategories"
                    :income-categories="incomeCategories"
                    :base-currency="baseCurrency"
                >
                    <Button size="sm">
                        <Plus class="size-4" /> New transaction
                    </Button>
                </TransactionFormDialog>
            </CardHeader>
            <CardContent class="flex flex-col gap-1">
                <Deferred data="transactions">
                    <template #fallback>
                        <div
                            v-for="n in 5"
                            :key="n"
                            class="flex items-center gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                        >
                            <Skeleton class="size-9 shrink-0 rounded-full" />
                            <div class="flex-1 space-y-2">
                                <Skeleton class="h-4 w-32" />
                                <Skeleton class="h-3 w-48" />
                            </div>
                            <Skeleton class="h-4 w-16" />
                        </div>
                    </template>

                    <div
                        v-if="(transactions?.length ?? 0) === 0"
                        class="flex flex-col items-center gap-3 py-10 text-center"
                    >
                        <p class="text-sm text-muted-foreground">
                            No transactions yet. Record your first expense to
                            get going.
                        </p>
                        <TransactionFormDialog
                            :accounts="accounts"
                            :expense-categories="expenseCategories"
                            :income-categories="incomeCategories"
                            :base-currency="baseCurrency"
                        >
                            <Button size="sm">
                                <Plus class="size-4" /> New transaction
                            </Button>
                        </TransactionFormDialog>
                    </div>

                    <div
                        v-for="transaction in transactions ?? []"
                        :key="transaction.id"
                        class="group flex items-center gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                    >
                        <div
                            class="flex size-9 shrink-0 items-center justify-center rounded-full"
                            :class="{
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-400':
                                    transaction.direction === 'in',
                                'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-400':
                                    transaction.direction === 'out',
                                'bg-muted text-muted-foreground':
                                    transaction.direction === 'transfer',
                            }"
                        >
                            <component
                                :is="directionIcon[transaction.direction]"
                                class="size-4"
                            />
                        </div>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="truncate font-medium">{{
                                    transaction.payee || transaction.summary
                                }}</span>
                            </div>
                            <p class="truncate text-sm text-muted-foreground">
                                <template v-if="transaction.payee">{{
                                    transaction.summary
                                }}</template>
                                <template
                                    v-if="
                                        transaction.payee &&
                                        transaction.account_label
                                    "
                                >
                                    ·
                                </template>
                                <template v-if="transaction.account_label">{{
                                    transaction.account_label
                                }}</template>
                                · {{ transaction.date_label }}
                            </p>
                        </div>

                        <span
                            class="shrink-0 tabular-nums"
                            :class="{
                                'text-emerald-600 dark:text-emerald-400':
                                    transaction.direction === 'in',
                                'text-red-600 dark:text-red-400':
                                    transaction.direction === 'out',
                                'text-muted-foreground':
                                    transaction.direction === 'transfer',
                            }"
                        >
                            <template v-if="transaction.direction === 'in'"
                                >+</template
                            >
                            <template
                                v-else-if="transaction.direction === 'out'"
                                >−</template
                            >
                            {{ transaction.amount_display }}
                        </span>

                        <div
                            class="flex shrink-0 items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100"
                        >
                            <TransactionFormDialog
                                v-if="transaction.edit"
                                :accounts="accounts"
                                :expense-categories="expenseCategories"
                                :income-categories="incomeCategories"
                                :base-currency="baseCurrency"
                                :edit="{
                                    id: transaction.id,
                                    edit: transaction.edit,
                                }"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Edit"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                            </TransactionFormDialog>
                            <Button
                                variant="ghost"
                                size="icon"
                                title="Delete"
                                @click="remove(transaction)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                </Deferred>
            </CardContent>
        </Card>
    </div>
</template>
