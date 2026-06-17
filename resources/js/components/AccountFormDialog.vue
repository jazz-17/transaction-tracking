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
};

const props = defineProps<{
    group: 'account' | 'category';
    currencies: Array<{ code: string; name: string }>;
    account?: AccountRow | null;
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
const needsCurrency = computed(
    () => form.type === 'asset' || form.type === 'liability',
);

const form = useForm({
    name: '',
    type: typeOptions[0].value,
    currency: '',
    archived: false,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.clearErrors();
    form.name = props.account?.name ?? '';
    form.type = props.account?.type ?? typeOptions[0].value;
    form.currency = props.account?.currency ?? '';
    form.archived = props.account?.archived ?? false;
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
                    <DialogTitle>
                        {{
                            isEditing
                                ? 'Edit'
                                : group === 'account'
                                  ? 'New account'
                                  : 'New category'
                        }}
                    </DialogTitle>
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

                <div v-if="!isEditing" class="grid gap-2">
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

                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{ isEditing ? 'Save' : 'Create' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
