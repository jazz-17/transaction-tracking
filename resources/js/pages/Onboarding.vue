<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { store } from '@/routes/onboarding';

defineProps<{
    currencies: Array<{ code: string; name: string }>;
}>();

defineOptions({
    layout: {
        title: 'Set your base currency',
        description:
            "Every report rolls up to this currency. It can't be changed later, so pick the one you think in.",
    },
});

const form = useForm({
    base_currency: '',
});

function submit() {
    form.post(store().url);
}
</script>

<template>
    <Head title="Welcome" />

    <form class="flex flex-col gap-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="base_currency">Base currency</Label>
            <Select v-model="form.base_currency">
                <SelectTrigger id="base_currency" class="w-full">
                    <SelectValue placeholder="Select a currency" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="currency in currencies"
                        :key="currency.code"
                        :value="currency.code"
                    >
                        {{ currency.code }} — {{ currency.name }}
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="form.errors.base_currency" />
        </div>

        <Button
            type="submit"
            class="w-full"
            :disabled="form.processing || !form.base_currency"
        >
            Continue
        </Button>
    </form>
</template>
