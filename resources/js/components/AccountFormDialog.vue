<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store, update } from '@/routes/accounts';

export type AccountRow = {
    id: number;
    name: string;
    type: string;
    currency: string;
    archived: boolean;
    parent_id: number | null;
    is_group: boolean;
};

const props = defineProps<{
    group: 'account' | 'category';
    currencies: Array<{ code: string; name: string }>;
    baseCurrency: string;
    account?: AccountRow | null;
    // Candidate parent groups (same shape as rows); the dialog filters them by the
    // selected type. Only passed for categories — My-Account grouping is deferred.
    groups?: AccountRow[];
    // Preset parent for the "Add subcategory under …" action: forces a leaf nested
    // under this group, with the type fixed to match.
    parent?: AccountRow | null;
}>();

const open = ref(false);

const typeOptions =
    props.group === 'account'
        ? [
              { value: 'asset', label: 'Asset — cash, bank, debit' },
              { value: 'liability', label: 'Liability — credit card, loan' },
          ]
        : [
              { value: 'expense', label: 'Expense' },
              { value: 'income', label: 'Income' },
          ];

const isEditing = computed(() => !!props.account);
// "Add subcategory under …": a fixed-parent create that always yields a leaf.
const presetParent = computed(() =>
    !isEditing.value ? (props.parent ?? null) : null,
);
const isCategoryForm = computed(() => props.group === 'category');
// A group holds no money, so it never carries a currency. Groups are categories-only
// in this scope; the `!form.is_group` guard keeps it correct if that widens later.
const needsCurrency = computed(
    () =>
        (form.type === 'asset' || form.type === 'liability') && !form.is_group,
);
// The group flag is immutable, so it's offered only when creating a fresh category
// (never when editing, never when adding a subcategory — those are always leaves).
const canToggleGroup = computed(
    () => isCategoryForm.value && !isEditing.value && !presetParent.value,
);
const showTypeSelect = computed(() => !isEditing.value && !presetParent.value);
// A leaf category may sit under a same-type root group. Hidden for groups (roots) and
// for the fixed-parent subcategory flow.
const showParent = computed(
    () => isCategoryForm.value && !form.is_group && !presetParent.value,
);
const parentOptions = computed(() =>
    (props.groups ?? []).filter((candidate) => candidate.type === form.type),
);
const dialogTitle = computed(() => {
    if (isEditing.value) {
        return 'Edit';
    }

    if (presetParent.value) {
        return `New subcategory in ${presetParent.value.name}`;
    }

    return props.group === 'account' ? 'New account' : 'New category';
});
// An opening balance can be seeded only on creation of a My Account; its base value
// is needed when the account currency differs from base.
const showOpeningBalance = computed(
    () => !isEditing.value && needsCurrency.value,
);
const showOpeningBalanceBase = computed(
    () =>
        showOpeningBalance.value &&
        !!form.currency &&
        form.currency !== props.baseCurrency,
);

const form = useForm({
    name: '',
    type: typeOptions[0].value,
    // Default to the user's base currency so most accounts need no manual pick;
    // still changeable for a foreign card.
    currency: props.baseCurrency,
    parent_id: null as number | null,
    is_group: false,
    archived: false,
    opening_balance: '',
    opening_balance_base: '',
});

// The Select can't bind null cleanly, so route the "no parent" choice through a sentinel.
const parentModel = computed({
    get: () => (form.parent_id == null ? 'none' : String(form.parent_id)),
    set: (value: string) => {
        form.parent_id = value === 'none' ? null : Number(value);
    },
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.name = props.account?.name ?? '';
    form.type =
        props.parent?.type ?? props.account?.type ?? typeOptions[0].value;
    form.currency = props.account?.currency ?? props.baseCurrency;
    form.parent_id = props.account?.parent_id ?? props.parent?.id ?? null;
    form.is_group = props.account?.is_group ?? false;
    form.archived = props.account?.archived ?? false;
    form.opening_balance = '';
    form.opening_balance_base = '';
});

// A group is a root, so turning the flag on drops any chosen parent.
watch(
    () => form.is_group,
    (isGroup) => {
        if (isGroup) {
            form.parent_id = null;
        }
    },
);

// Changing the type can strand a parent of the old type; clear it once it no longer
// appears among the valid options (the fixed-parent flow keeps its preset).
watch(parentOptions, (options) => {
    if (
        !presetParent.value &&
        form.parent_id != null &&
        !options.some((option) => option.id === form.parent_id)
    ) {
        form.parent_id = null;
    }
});

function submit() {
    if (props.account) {
        form.put(update(props.account.id).url, {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        });

        return;
    }

    form.post(store().url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
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
                    <DialogTitle>{{ dialogTitle }}</DialogTitle>
                    <DialogDescription>
                        {{
                            group === 'account'
                                ? 'Cards, wallets and bank accounts you own or owe.'
                                : 'Buckets that group your income and spending.'
                        }}
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="account-name">Name</Label>
                    <Input id="account-name" v-model="form.name" required />
                    <InputError :message="form.errors.name" />
                </div>

                <div v-if="showTypeSelect" class="grid gap-2">
                    <Label>Type</Label>
                    <Select v-model="form.type">
                        <SelectTrigger class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="opt in typeOptions"
                                :key="opt.value"
                                :value="opt.value"
                            >
                                {{ opt.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.type" />
                </div>

                <label
                    v-if="canToggleGroup"
                    class="flex items-start gap-3 rounded-lg border border-sidebar-border/70 px-4 py-3 dark:border-sidebar-border"
                >
                    <Checkbox
                        :model-value="form.is_group"
                        class="mt-0.5"
                        @update:model-value="
                            (checked) => (form.is_group = checked === true)
                        "
                    />
                    <span class="grid gap-0.5">
                        <span class="text-sm font-medium">Group</span>
                        <span class="text-xs text-muted-foreground">
                            A non-spendable header that totals its
                            subcategories.
                        </span>
                    </span>
                </label>

                <div v-if="showParent" class="grid gap-2">
                    <Label>
                        Parent group
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <Select v-model="parentModel">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="None — top level" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none"
                                >None — top level</SelectItem
                            >
                            <SelectItem
                                v-for="parentGroup in parentOptions"
                                :key="parentGroup.id"
                                :value="String(parentGroup.id)"
                            >
                                {{ parentGroup.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.parent_id" />
                </div>

                <div v-if="!isEditing && needsCurrency" class="grid gap-2">
                    <Label>Currency</Label>
                    <Select v-model="form.currency">
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="Select a currency" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="c in currencies"
                                :key="c.code"
                                :value="c.code"
                            >
                                {{ c.code }} — {{ c.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.currency" />
                </div>

                <div v-if="showOpeningBalance" class="grid gap-2">
                    <Label for="opening-balance">
                        Opening balance
                        <span class="text-muted-foreground">(optional)</span>
                    </Label>
                    <Input
                        id="opening-balance"
                        v-model="form.opening_balance"
                        type="number"
                        step="any"
                        min="0"
                        inputmode="decimal"
                        :placeholder="
                            form.type === 'liability'
                                ? 'What you currently owe'
                                : 'What you currently have'
                        "
                    />
                    <InputError :message="form.errors.opening_balance" />
                </div>

                <div v-if="showOpeningBalanceBase" class="grid gap-2">
                    <Label for="opening-balance-base">
                        Opening balance in {{ baseCurrency }}
                    </Label>
                    <Input
                        id="opening-balance-base"
                        v-model="form.opening_balance_base"
                        type="number"
                        step="any"
                        min="0"
                        inputmode="decimal"
                    />
                    <InputError :message="form.errors.opening_balance_base" />
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEditing ? 'Save' : 'Create' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
