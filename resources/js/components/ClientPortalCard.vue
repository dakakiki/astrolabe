<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import { formatDateTime } from '@/lib/datetime';
import { formatRelative } from '@/lib/format';
import http from '@/lib/http';
import { useAuthStore } from '@/stores/auth';
import { useToastStore } from '@/stores/toast';

/**
 * The client's access to the practice's portal (docs/spec/12, "Pozivnica"):
 * not invited, invited (until when), active (last visit), paused while
 * archived, or revoked — with invite, resend and revoke.
 */
const props = defineProps({
    clientId: { type: Number, required: true },
    // The client's status: archiving and restoring change what the card can do.
    clientStatus: { type: String, default: null },
});

const { t, locale } = useI18n();
const auth = useAuthStore();
const toast = useToastStore();

const portal = ref(null);
const busy = ref(false);

const zone = computed(() => auth.user?.timezone || 'UTC');
const when = (iso) => formatDateTime(iso, locale.value, zone.value, { dateStyle: 'medium', timeStyle: 'short' });

const state = computed(() => {
    if (!portal.value) return null;
    if (portal.value.paused) return 'paused';
    if (portal.value.status === 'invited' && (portal.value.invitation?.expired || !portal.value.invitation))
        return 'expired';

    return portal.value.status;
});

async function load() {
    const { data } = await http.get(`/clients/${props.clientId}/portal`);
    portal.value = data.data;
}

async function invite() {
    busy.value = true;

    try {
        const resend = portal.value.status === 'invited';
        const { data } = await http.post(`/clients/${props.clientId}/portal/invitation`);
        portal.value = data.data;
        toast.success(t(resend ? 'clientPortal.resent' : 'clientPortal.sent', { email: portal.value.email }));
    } catch (error) {
        const errors = error.response?.data?.errors;
        toast.error(errors ? Object.values(errors)[0][0] : (error.response?.data?.message ?? t('errors.generic')));
    } finally {
        busy.value = false;
    }
}

async function revoke() {
    const question = portal.value.status === 'invited' ? 'clientPortal.confirmWithdraw' : 'clientPortal.confirmRevoke';
    if (!window.confirm(t(question))) return;

    busy.value = true;

    try {
        const { data } = await http.delete(`/clients/${props.clientId}/portal`);
        portal.value = data.data;
        toast.success(t('clientPortal.revoked'));
    } finally {
        busy.value = false;
    }
}

onMounted(load);
defineExpose({ reload: load });
</script>

<template>
    <section class="card">
        <div class="card-head">
            <h2>{{ t('clientPortal.title') }}</h2>
            <span v-if="state === 'active'" class="badge b-ok right">{{ t('clientPortal.states.active') }}</span>
            <span v-else-if="state === 'invited'" class="badge b-info right">{{
                t('clientPortal.states.invited')
            }}</span>
            <span v-else-if="state === 'paused' || state === 'expired'" class="badge b-warn right">{{
                t(`clientPortal.states.${state}`)
            }}</span>
        </div>
        <div class="card-body text-sm">
            <p v-if="!portal" class="text-ink-3">{{ t('common.loading') }}</p>

            <template v-else>
                <p v-if="state === 'none'" class="text-ink-3">{{ t('clientPortal.none') }}</p>
                <template v-else-if="state === 'invited' || state === 'expired'">
                    <p>{{ t('clientPortal.invitedTo', { email: portal.email }) }}</p>
                    <p class="mt-1 text-xs text-ink-3">
                        {{
                            state === 'expired'
                                ? t('clientPortal.expired')
                                : t('clientPortal.expires', { date: when(portal.invitation.expires_at) })
                        }}
                    </p>
                </template>
                <template v-else-if="state === 'active' || state === 'paused'">
                    <p>{{ t('clientPortal.signsInAs', { email: portal.account_email ?? portal.email }) }}</p>
                    <p class="mt-1 text-xs text-ink-3">
                        {{
                            portal.last_seen_at
                                ? t('clientPortal.lastSeen', { when: formatRelative(portal.last_seen_at, locale) })
                                : t('clientPortal.acceptedOn', { date: when(portal.accepted_at) })
                        }}
                    </p>
                    <p v-if="state === 'paused'" class="notice n-warn mt-2">{{ t('clientPortal.paused') }}</p>
                    <p
                        v-if="
                            portal.client_email &&
                            portal.account_email &&
                            portal.client_email.toLowerCase() !== portal.account_email
                        "
                        class="mt-2 text-xs text-ink-3"
                    >
                        {{ t('clientPortal.otherAddress') }}
                    </p>
                </template>
                <p v-else-if="state === 'revoked'" class="text-ink-3">
                    {{ t('clientPortal.revokedOn', { date: when(portal.revoked_at) }) }}
                </p>

                <p v-if="!portal.client_email && portal.status !== 'active'" class="mt-2 text-xs text-ink-3">
                    {{ t('clientPortal.needsEmail') }}
                </p>
                <p
                    v-else-if="clientStatus === 'archived' && portal.status !== 'active'"
                    class="mt-2 text-xs text-ink-3"
                >
                    {{ t('clientPortal.archived') }}
                </p>

                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        v-if="portal.can_invite"
                        type="button"
                        class="btn btn-sm"
                        :class="{ 'btn-primary': portal.status !== 'invited' }"
                        :disabled="busy"
                        @click="invite"
                    >
                        {{ portal.status === 'invited' ? t('clientPortal.resend') : t('clientPortal.invite') }}
                    </button>
                    <button
                        v-if="portal.status === 'invited' || portal.status === 'active'"
                        type="button"
                        class="btn btn-sm btn-ghost text-danger"
                        :disabled="busy"
                        @click="revoke"
                    >
                        {{ portal.status === 'invited' ? t('clientPortal.withdraw') : t('clientPortal.revoke') }}
                    </button>
                </div>
                <p class="mt-3 text-xs text-ink-4">{{ t('clientPortal.where', { url: portal.portal_url }) }}</p>
            </template>
        </div>
    </section>
</template>
