<script setup>
import { useI18n } from 'vue-i18n';

import SecuritySettings from '@/pages/settings/SecuritySettings.vue';
import { useAuthStore } from '@/stores/auth';

/**
 * The admin account's own password, sessions and two-factor sign-in (Phase 8c).
 * Until two-factor sign-in is on, this is the only admin screen that opens.
 */
const { t } = useI18n();
const auth = useAuthStore();
</script>

<template>
    <div class="page-head">
        <div class="eyebrow">{{ t('admin.badge') }}</div>
        <h1>{{ t('nav.security') }}</h1>
    </div>

    <div class="max-w-3xl space-y-4">
        <div v-if="!auth.user?.two_factor_enabled" class="notice n-warn">
            <div>
                <strong>{{ t('admin.noTwoFactor.title') }}</strong>
                {{ t('admin.noTwoFactor.body') }}
            </div>
        </div>
        <SecuritySettings />
    </div>
</template>
