<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowRight, Wallet } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dashboard } from '@/routes';
import { index as transactionsIndex } from '@/routes/transactions';

type AccountBalance = {
    id: number;
    name: string;
    currency: string;
    balance_display: string;
};

type NetWorthBucket = {
    currency: string;
    display: string;
};

defineProps<{
    netWorth: NetWorthBucket[];
    assets: AccountBalance[];
    liabilities: AccountBalance[];
    baseCurrency: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard().url }],
    },
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <!-- Net worth -->
        <Card>
            <CardHeader
                class="flex flex-row items-center justify-between space-y-0"
            >
                <div>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        Net worth
                    </CardTitle>
                    <div
                        class="mt-1 flex flex-wrap items-baseline gap-x-4 gap-y-1"
                    >
                        <p
                            v-for="bucket in netWorth"
                            :key="bucket.currency"
                            class="text-3xl font-semibold tabular-nums"
                        >
                            {{ bucket.display }}
                        </p>
                        <p
                            v-if="netWorth.length === 0"
                            class="text-3xl font-semibold text-muted-foreground tabular-nums"
                        >
                            —
                        </p>
                    </div>
                </div>
                <Button as-child variant="outline" size="sm">
                    <Link :href="transactionsIndex()">
                        Transactions
                        <ArrowRight class="size-4" />
                    </Link>
                </Button>
            </CardHeader>
        </Card>

        <div class="grid gap-6 md:grid-cols-2">
            <!-- Assets -->
            <Card>
                <CardHeader>
                    <CardTitle>Accounts</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <p
                        v-if="assets.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        No accounts yet.
                    </p>
                    <div
                        v-for="account in assets"
                        :key="account.id"
                        class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                    >
                        <div class="flex items-center gap-2">
                            <Wallet class="size-4 text-muted-foreground" />
                            <span class="font-medium">{{ account.name }}</span>
                        </div>
                        <span class="tabular-nums">{{
                            account.balance_display
                        }}</span>
                    </div>
                </CardContent>
            </Card>

            <!-- Liabilities -->
            <Card>
                <CardHeader>
                    <CardTitle>Owed</CardTitle>
                </CardHeader>
                <CardContent class="flex flex-col gap-2">
                    <p
                        v-if="liabilities.length === 0"
                        class="py-4 text-center text-sm text-muted-foreground"
                    >
                        Nothing owed.
                    </p>
                    <div
                        v-for="account in liabilities"
                        :key="account.id"
                        class="flex items-center justify-between gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                    >
                        <span class="font-medium">{{ account.name }}</span>
                        <span class="tabular-nums">{{
                            account.balance_display
                        }}</span>
                    </div>
                </CardContent>
            </Card>
        </div>
    </div>
</template>
