<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Archive, ArchiveRestore, Pencil, Plus, Trash2 } from '@lucide/vue';
import { toast } from 'vue-sonner';
import AccountFormDialog from '@/components/AccountFormDialog.vue';
import type { AccountRow } from '@/components/AccountFormDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { destroy, index, update } from '@/routes/accounts';

type AccountRowVm = AccountRow & {
    balance_display: string;
};

defineProps<{
    myAccounts: AccountRowVm[];
    categories: AccountRowVm[];
    currencies: Array<{ code: string; name: string }>;
    baseCurrency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Accounts', href: index().url }],
    },
});

function toggleArchive(account: AccountRowVm) {
    router.put(
        update(account.id).url,
        { name: account.name, archived: !account.archived },
        { preserveScroll: true },
    );
}

function remove(account: AccountRowVm) {
    if (!confirm(`Delete "${account.name}"? This can't be undone.`)) {
        return;
    }

    router.delete(destroy(account.id).url, {
        preserveScroll: true,
        // Deleting an account that has postings is blocked server-side; surface that.
        onError: (errors) => {
            if (errors.account) {
                toast.error(errors.account);
            }
        },
    });
}
</script>

<template>
    <Head title="Accounts" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <!-- My Accounts -->
        <Card>
            <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle>My Accounts</CardTitle>
                <AccountFormDialog
                    group="account"
                    :currencies="currencies"
                    :base-currency="baseCurrency"
                >
                    <Button size="sm">
                        <Plus class="size-4" /> New account
                    </Button>
                </AccountFormDialog>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <p
                    v-if="myAccounts.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No accounts yet. Add the cards and wallets you spend from.
                </p>
                <div
                    v-for="account in myAccounts"
                    :key="account.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                    :class="{ 'opacity-50': account.archived }"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ account.name }}</span>
                        <Badge variant="outline">{{ account.currency }}</Badge>
                        <Badge v-if="account.archived" variant="secondary"
                            >Archived</Badge
                        >
                    </div>
                    <div class="flex items-center gap-1">
                        <span class="mr-2 tabular-nums">{{
                            account.balance_display
                        }}</span>
                        <AccountFormDialog
                            group="account"
                            :currencies="currencies"
                            :base-currency="baseCurrency"
                            :account="account"
                        >
                            <Button variant="ghost" size="icon" title="Edit">
                                <Pencil class="size-4" />
                            </Button>
                        </AccountFormDialog>
                        <Button
                            variant="ghost"
                            size="icon"
                            :title="account.archived ? 'Unarchive' : 'Archive'"
                            @click="toggleArchive(account)"
                        >
                            <ArchiveRestore
                                v-if="account.archived"
                                class="size-4"
                            />
                            <Archive v-else class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Delete"
                            @click="remove(account)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>

        <!-- Categories -->
        <Card>
            <CardHeader class="flex flex-row items-center justify-between">
                <CardTitle>Categories</CardTitle>
                <AccountFormDialog
                    group="category"
                    :currencies="currencies"
                    :base-currency="baseCurrency"
                >
                    <Button size="sm">
                        <Plus class="size-4" /> New category
                    </Button>
                </AccountFormDialog>
            </CardHeader>
            <CardContent class="flex flex-col gap-2">
                <p
                    v-if="categories.length === 0"
                    class="py-6 text-center text-sm text-muted-foreground"
                >
                    No categories yet.
                </p>
                <div
                    v-for="category in categories"
                    :key="category.id"
                    class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                    :class="{ 'opacity-50': category.archived }"
                >
                    <div class="flex items-center gap-2">
                        <span class="font-medium">{{ category.name }}</span>
                        <Badge variant="outline">{{
                            category.type === 'income' ? 'Income' : 'Expense'
                        }}</Badge>
                        <Badge v-if="category.archived" variant="secondary"
                            >Archived</Badge
                        >
                    </div>
                    <div class="flex items-center gap-1">
                        <AccountFormDialog
                            group="category"
                            :currencies="currencies"
                            :base-currency="baseCurrency"
                            :account="category"
                        >
                            <Button variant="ghost" size="icon" title="Edit">
                                <Pencil class="size-4" />
                            </Button>
                        </AccountFormDialog>
                        <Button
                            variant="ghost"
                            size="icon"
                            :title="category.archived ? 'Unarchive' : 'Archive'"
                            @click="toggleArchive(category)"
                        >
                            <ArchiveRestore
                                v-if="category.archived"
                                class="size-4"
                            />
                            <Archive v-else class="size-4" />
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            title="Delete"
                            @click="remove(category)"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
