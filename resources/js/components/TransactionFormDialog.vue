<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store, update } from '@/routes/transactions';

export type Kind = 'expense' | 'income' | 'transfer';

export type MoneyAccount = {
    id: number;
    name: string;
    type: string;
    currency: string;
};

// A category option is either a root group (a non-selectable header carrying leaf
// children) or an ungrouped leaf. Only leaves are postable (decision #13).
export type CategoryOption = {
    id: number;
    name: string;
    is_group: boolean;
    children?: CategoryOption[];
};

export type TransactionEdit = {
    kind: Kind;
    date: string;
    payee: string | null;
    memo: string | null;
    amount?: string;
    account_id?: number;
    category_id?: number;
    from_account_id?: number;
    to_account_id?: number;
    to_amount?: string;
};

const props = defineProps<{
    accounts: MoneyAccount[];
    expenseCategories: CategoryOption[];
    incomeCategories: CategoryOption[];
    baseCurrency: string;
    // Last rate per foreign currency, base units per 1 foreign (e.g. { USD: '3.7' }), and the
    // deviation band the server warns past — both drive the live exchange reference.
    lastRates: Record<string, string>;
    rateBand: number;
    edit?: { id: number; edit: TransactionEdit } | null;
}>();

const open = ref(false);

const tabs: Array<{ value: Kind; label: string }> = [
    { value: 'expense', label: 'Expense' },
    { value: 'income', label: 'Income' },
    { value: 'transfer', label: 'Transfer' },
];

const isEditing = computed(() => !!props.edit);

const today = () => new Date().toISOString().slice(0, 10);

const form = useForm({
    kind: 'expense' as Kind,
    date: today(),
    payee: '',
    memo: '',
    amount: '',
    account_id: null as number | null,
    category_id: null as number | null,
    from_account_id: null as number | null,
    to_account_id: null as number | null,
    to_amount: '',
    // Set true to acknowledge a deviation-guard warning and record anyway (decision #11).
    confirm_rate: false,
});

function resetForm() {
    form.clearErrors();
    form.defaults({
        kind: props.edit?.edit.kind ?? 'expense',
        date: props.edit?.edit.date ?? today(),
        payee: props.edit?.edit.payee ?? '',
        memo: props.edit?.edit.memo ?? '',
        amount: props.edit?.edit.amount ?? '',
        account_id: props.edit?.edit.account_id ?? null,
        category_id: props.edit?.edit.category_id ?? null,
        from_account_id: props.edit?.edit.from_account_id ?? null,
        to_account_id: props.edit?.edit.to_account_id ?? null,
        to_amount: props.edit?.edit.to_amount ?? '',
        confirm_rate: false,
    });
    form.reset();
}

// Editing either amount retracts a prior rate confirmation and clears its warning, so a new
// pair of amounts is re-checked by the deviation guard on the next submit.
watch([() => form.amount, () => form.to_amount], () => {
    form.confirm_rate = false;
    form.clearErrors('confirm_rate');
});

watch(open, (isOpen) => {
    if (isOpen) {
        resetForm();
    }
});

const categories = computed(() =>
    form.kind === 'income' ? props.incomeCategories : props.expenseCategories,
);

const selectedAccount = computed(() =>
    props.accounts.find((account) => account.id === form.account_id),
);
const fromAccount = computed(() =>
    props.accounts.find((account) => account.id === form.from_account_id),
);
const toAccount = computed(() =>
    props.accounts.find((account) => account.id === form.to_account_id),
);

// A cross-currency transfer is an exchange: it reveals the destination amount, and the
// rate is derived server-side from the two real amounts (decision #16). A foreign purchase
// needs nothing extra — it's recorded natively in its own currency.
const showToAmount = computed(
    () =>
        !!fromAccount.value &&
        !!toAccount.value &&
        fromAccount.value.currency !== toAccount.value.currency,
);

const amountCurrency = computed(() =>
    form.kind === 'transfer'
        ? (fromAccount.value?.currency ?? props.baseCurrency)
        : (selectedAccount.value?.currency ?? props.baseCurrency),
);

// Live exchange reference. The rate is always priced as base units per 1 foreign unit — the
// same orientation the server's deviation guard uses — so the entered ("implied") rate and the
// "last used" reference are directly comparable whichever direction the transfer runs. Null
// unless this is a base↔foreign exchange (decision #11).
const rateHint = computed(() => {
    if (!showToAmount.value) {
        return null;
    }

    const base = props.baseCurrency;
    const fromCurrency = fromAccount.value?.currency;
    const toCurrency = toAccount.value?.currency;

    if (!fromCurrency || !toCurrency) {
        return null;
    }

    const fromAmount = parseFloat(form.amount);
    const toAmount = parseFloat(form.to_amount);

    // One leg is base, the other foreign (the server enforces this); price the foreign leg in
    // base regardless of which side the user entered as "from".
    let foreign: string;
    let baseAmount: number;
    let foreignAmount: number;

    if (fromCurrency === base) {
        foreign = toCurrency;
        baseAmount = fromAmount;
        foreignAmount = toAmount;
    } else if (toCurrency === base) {
        foreign = fromCurrency;
        baseAmount = toAmount;
        foreignAmount = fromAmount;
    } else {
        return null;
    }

    const lastRate = props.lastRates[foreign] ?? null;
    const implied =
        baseAmount > 0 && foreignAmount > 0 ? baseAmount / foreignAmount : null;

    const ratio = implied !== null && lastRate !== null ? implied / parseFloat(lastRate) : null;
    const diverges =
        ratio !== null && (ratio >= props.rateBand || ratio <= 1 / props.rateBand);

    return {
        base,
        foreign,
        implied: implied !== null ? implied.toFixed(4) : null,
        lastRate,
        diverges,
    };
});

function selectKind(kind: Kind) {
    if (isEditing.value) {
        return;
    }

    form.kind = kind;
    form.clearErrors();
}

function submit() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    };

    if (props.edit) {
        form.put(update(props.edit.id).url, options);

        return;
    }

    form.post(store().url, options);
}

// "Record anyway" on a deviation warning: acknowledge and resubmit (decision #11).
function confirmAndRecord() {
    form.confirm_rate = true;
    submit();
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <slot />
        </DialogTrigger>
        <DialogContent>
            <form class="space-y-5" @submit.prevent="submit">
                <DialogHeader>
                    <DialogTitle>{{
                        isEditing ? 'Edit transaction' : 'New transaction'
                    }}</DialogTitle>
                    <DialogDescription>
                        Record what moved, from which account, in seconds.
                    </DialogDescription>
                </DialogHeader>

                <!-- Kind tabs -->
                <div
                    class="grid grid-cols-3 gap-1 rounded-lg bg-muted p-1"
                    :class="{ 'pointer-events-none opacity-60': isEditing }"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors"
                        :class="
                            form.kind === tab.value
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="selectKind(tab.value)"
                    >
                        {{ tab.label }}
                    </button>
                </div>

                <!-- Amount -->
                <div class="grid gap-2">
                    <Label for="txn-amount">
                        Amount
                        <span class="text-muted-foreground"
                            >({{ amountCurrency }})</span
                        >
                    </Label>
                    <Input
                        id="txn-amount"
                        v-model="form.amount"
                        type="number"
                        step="any"
                        min="0"
                        inputmode="decimal"
                        required
                        autofocus
                    />
                    <InputError :message="form.errors.amount" />
                </div>

                <!-- Expense / Income fields -->
                <template v-if="form.kind !== 'transfer'">
                    <div class="grid gap-2">
                        <Label>{{
                            form.kind === 'income'
                                ? 'To account'
                                : 'From account'
                        }}</Label>
                        <Select v-model="form.account_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select an account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }} ({{ account.currency }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.account_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label>Category</Label>
                        <Select v-model="form.category_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Select a category" />
                            </SelectTrigger>
                            <SelectContent>
                                <template
                                    v-for="category in categories"
                                    :key="category.id"
                                >
                                    <!-- Groups are headers, not options: only their leaf
                                         children carry a selectable value. -->
                                    <SelectGroup v-if="category.is_group">
                                        <SelectLabel>{{
                                            category.name
                                        }}</SelectLabel>
                                        <SelectItem
                                            v-for="child in category.children"
                                            :key="child.id"
                                            :value="child.id"
                                            class="pl-6"
                                        >
                                            {{ child.name }}
                                        </SelectItem>
                                    </SelectGroup>
                                    <SelectItem v-else :value="category.id">
                                        {{ category.name }}
                                    </SelectItem>
                                </template>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.category_id" />
                    </div>
                </template>

                <!-- Transfer fields -->
                <template v-else>
                    <div class="grid gap-2">
                        <Label>From</Label>
                        <Select v-model="form.from_account_id">
                            <SelectTrigger class="w-full">
                                <SelectValue placeholder="Source account" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }} ({{ account.currency }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.from_account_id" />
                    </div>

                    <div class="grid gap-2">
                        <Label>To</Label>
                        <Select v-model="form.to_account_id">
                            <SelectTrigger class="w-full">
                                <SelectValue
                                    placeholder="Destination account"
                                />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem
                                    v-for="account in accounts"
                                    :key="account.id"
                                    :value="account.id"
                                >
                                    {{ account.name }} ({{ account.currency }})
                                </SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="form.errors.to_account_id" />
                    </div>

                    <div v-if="showToAmount" class="grid gap-2">
                        <Label for="txn-to-amount">
                            Amount received
                            <span class="text-muted-foreground"
                                >({{ toAccount?.currency }})</span
                            >
                        </Label>
                        <Input
                            id="txn-to-amount"
                            v-model="form.to_amount"
                            type="number"
                            step="any"
                            min="0"
                            inputmode="decimal"
                        />
                        <div v-if="rateHint" class="space-y-0.5 text-xs">
                            <p
                                v-if="rateHint.implied"
                                :class="
                                    rateHint.diverges
                                        ? 'font-medium text-amber-600 dark:text-amber-400'
                                        : 'text-muted-foreground'
                                "
                            >
                                1 {{ rateHint.foreign }} ≈
                                {{ rateHint.implied }} {{ rateHint.base }}
                                <template v-if="rateHint.diverges">
                                    — far from your last; double-check the
                                    amounts</template
                                >
                            </p>
                            <p
                                v-if="rateHint.lastRate"
                                class="text-muted-foreground"
                            >
                                Last used: 1 {{ rateHint.foreign }} ≈
                                {{ rateHint.lastRate }} {{ rateHint.base }}
                            </p>
                            <p v-else class="text-muted-foreground">
                                First {{ rateHint.foreign }}↔{{ rateHint.base }}
                                exchange — this sets your reference rate.
                            </p>
                        </div>
                        <InputError :message="form.errors.to_amount" />
                    </div>
                </template>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="txn-date">Date</Label>
                        <Input
                            id="txn-date"
                            v-model="form.date"
                            type="date"
                            required
                        />
                        <InputError :message="form.errors.date" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="txn-payee">Payee</Label>
                        <Input
                            id="txn-payee"
                            v-model="form.payee"
                            placeholder="Optional"
                        />
                        <InputError :message="form.errors.payee" />
                    </div>
                </div>

                <div class="grid gap-2">
                    <Label for="txn-memo">Memo</Label>
                    <Input
                        id="txn-memo"
                        v-model="form.memo"
                        placeholder="Optional"
                    />
                    <InputError :message="form.errors.memo" />
                </div>

                <!-- Soft deviation warning: the entered rate is far from the last one for
                     this pair. Not a hard error — the user can record it anyway (decision #11). -->
                <div
                    v-if="form.errors.confirm_rate"
                    class="space-y-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200"
                >
                    <p>{{ form.errors.confirm_rate }}</p>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        :disabled="form.processing"
                        @click="confirmAndRecord"
                    >
                        Record anyway
                    </Button>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEditing ? 'Save' : 'Record' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
