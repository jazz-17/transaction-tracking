<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import {
    Archive,
    ArchiveRestore,
    ChevronDown,
    ChevronRight,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
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

type CategoryNode = AccountRowVm & { children: AccountRowVm[] };

const props = defineProps<{
    myAccounts: AccountRowVm[];
    categories: AccountRowVm[];
    currencies: Array<{ code: string; name: string }>;
    baseCurrency: string;
}>();

// Categories arrive flat (name-ordered); nest each root group's children beneath it.
const categoryTree = computed<CategoryNode[]>(() =>
    props.categories
        .filter((category) => category.parent_id === null)
        .map((root) => ({
            ...root,
            children: root.is_group
                ? props.categories.filter(
                      (category) => category.parent_id === root.id,
                  )
                : [],
        })),
);

// Root groups offered as parents in the create/edit dialog.
const groupOptions = computed(() =>
    props.categories.filter(
        (category) => category.is_group && category.parent_id === null,
    ),
);

const collapsedGroups = ref(new Set<number>());

function toggleCollapsed(id: number) {
    if (collapsedGroups.value.has(id)) {
        collapsedGroups.value.delete(id);
    } else {
        collapsedGroups.value.add(id);
    }
}

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
                    :groups="groupOptions"
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

                <template v-for="node in categoryTree" :key="node.id">
                    <!-- Group: a header that totals its children, with nested rows. -->
                    <div v-if="node.is_group" class="flex flex-col">
                        <div
                            class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-3 py-3 dark:border-sidebar-border"
                            :class="{ 'opacity-50': node.archived }"
                        >
                            <div class="flex min-w-0 items-center gap-2">
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="size-6 shrink-0"
                                    :title="
                                        collapsedGroups.has(node.id)
                                            ? 'Expand'
                                            : 'Collapse'
                                    "
                                    @click="toggleCollapsed(node.id)"
                                >
                                    <ChevronRight
                                        v-if="collapsedGroups.has(node.id)"
                                        class="size-4"
                                    />
                                    <ChevronDown v-else class="size-4" />
                                </Button>
                                <span class="truncate font-medium">{{
                                    node.name
                                }}</span>
                                <Badge variant="secondary">Group</Badge>
                                <Badge v-if="node.archived" variant="secondary"
                                    >Archived</Badge
                                >
                            </div>
                            <div class="flex items-center gap-1">
                                <span class="mr-2 tabular-nums">{{
                                    node.balance_display
                                }}</span>
                                <AccountFormDialog
                                    group="category"
                                    :currencies="currencies"
                                    :base-currency="baseCurrency"
                                    :parent="node"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Add subcategory"
                                    >
                                        <Plus class="size-4" />
                                    </Button>
                                </AccountFormDialog>
                                <AccountFormDialog
                                    group="category"
                                    :currencies="currencies"
                                    :base-currency="baseCurrency"
                                    :groups="groupOptions"
                                    :account="node"
                                >
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Edit"
                                    >
                                        <Pencil class="size-4" />
                                    </Button>
                                </AccountFormDialog>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    :title="
                                        node.archived ? 'Unarchive' : 'Archive'
                                    "
                                    @click="toggleArchive(node)"
                                >
                                    <ArchiveRestore
                                        v-if="node.archived"
                                        class="size-4"
                                    />
                                    <Archive v-else class="size-4" />
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Delete"
                                    @click="remove(node)"
                                >
                                    <Trash2 class="size-4" />
                                </Button>
                            </div>
                        </div>

                        <div
                            v-show="!collapsedGroups.has(node.id)"
                            class="mt-2 flex flex-col gap-2 pl-6"
                        >
                            <p
                                v-if="node.children.length === 0"
                                class="px-4 py-2 text-sm text-muted-foreground"
                            >
                                No subcategories yet.
                            </p>
                            <div
                                v-for="child in node.children"
                                :key="child.id"
                                class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                                :class="{ 'opacity-50': child.archived }"
                            >
                                <div class="flex min-w-0 items-center gap-2">
                                    <span class="truncate font-medium">{{
                                        child.name
                                    }}</span>
                                    <Badge
                                        v-if="child.archived"
                                        variant="secondary"
                                        >Archived</Badge
                                    >
                                </div>
                                <div class="flex items-center gap-1">
                                    <span class="mr-2 tabular-nums">{{
                                        child.balance_display
                                    }}</span>
                                    <AccountFormDialog
                                        group="category"
                                        :currencies="currencies"
                                        :base-currency="baseCurrency"
                                        :groups="groupOptions"
                                        :account="child"
                                    >
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            title="Edit"
                                        >
                                            <Pencil class="size-4" />
                                        </Button>
                                    </AccountFormDialog>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        :title="
                                            child.archived
                                                ? 'Unarchive'
                                                : 'Archive'
                                        "
                                        @click="toggleArchive(child)"
                                    >
                                        <ArchiveRestore
                                            v-if="child.archived"
                                            class="size-4"
                                        />
                                        <Archive v-else class="size-4" />
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        title="Delete"
                                        @click="remove(child)"
                                    >
                                        <Trash2 class="size-4" />
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Ungrouped leaf category. -->
                    <div
                        v-else
                        class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                        :class="{ 'opacity-50': node.archived }"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <span class="truncate font-medium">{{
                                node.name
                            }}</span>
                            <Badge variant="outline">{{
                                node.type === 'income' ? 'Income' : 'Expense'
                            }}</Badge>
                            <Badge v-if="node.archived" variant="secondary"
                                >Archived</Badge
                            >
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="mr-2 tabular-nums">{{
                                node.balance_display
                            }}</span>
                            <AccountFormDialog
                                group="category"
                                :currencies="currencies"
                                :base-currency="baseCurrency"
                                :groups="groupOptions"
                                :account="node"
                            >
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    title="Edit"
                                >
                                    <Pencil class="size-4" />
                                </Button>
                            </AccountFormDialog>
                            <Button
                                variant="ghost"
                                size="icon"
                                :title="node.archived ? 'Unarchive' : 'Archive'"
                                @click="toggleArchive(node)"
                            >
                                <ArchiveRestore
                                    v-if="node.archived"
                                    class="size-4"
                                />
                                <Archive v-else class="size-4" />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                title="Delete"
                                @click="remove(node)"
                            >
                                <Trash2 class="size-4" />
                            </Button>
                        </div>
                    </div>
                </template>
            </CardContent>
        </Card>
    </div>
</template>
