<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';
import http from '@/portal/http';
import { CLIENT_ALWAYS_BY_EMAIL, CLIENT_CHANNELS, CLIENT_NOTICE_GROUPS, noticesForm } from '@/portal/lib/notices';
import { deviceLabel, madeWithOtherKey, pushDevices, pushProblem, sha256Hex, supportNotice } from '@/portal/lib/pwa';
import { loadScreen } from '@/portal/load';
import { currentSubscription, pushSupportHere, sendTest, subscribe, unsubscribe } from '@/portal/pwa/push';
import { usePwaStore } from '@/portal/stores/pwa';
import { useToastStore } from '@/stores/toast';

/**
 * Profile → Notifications (Phase 9c, docs/spec/12, "Web push"): web push on
 * or off for this device, a test notification, the other devices that have it
 * on, and — since 18 Nov 2026 — every notice about an appointment with email
 * and push to tick or untick (neither turns it off; push without a device
 * comes by email). The invitation and the sign-in codes are listed as always
 * by email. Every choice is saved at once.
 *
 * This device is recognised by its endpoint's SHA-256 among the account's
 * devices; a subscription the portal does not know (left from an earlier
 * sign-in) is dropped, and so is one made with the portal's earlier keys.
 * Push is hidden when the server has no keys for it.
 */
const props = defineProps({
    profile: { type: Object, required: true },
});

const emit = defineEmits(['saved']);

const { t, locale } = useI18n();
const pwa = usePwaStore();
const toast = useToastStore();

const push = ref(null);
const loaded = ref(false);
const support = ref(null);
const endpoint = ref(null);
const hash = ref(null);
const notices = ref(noticesForm(props.profile.notifications));
const busy = ref(false);
const saving = ref(null);

const available = computed(() => Boolean(push.value?.available));
const devices = computed(() => pushDevices(push.value?.devices, hash.value));
const on = computed(() => devices.value.here && Boolean(endpoint.value));
const supportText = computed(() => supportNotice(support.value));
// The push column only where the portal can send push at all.
const channels = computed(() => (available.value ? CLIENT_CHANNELS : ['mail']));

const othersText = computed(() => {
    const others = devices.value.others;
    const names = others.map(
        (device) =>
            deviceLabel(device.user_agent, (browser, system) => t('portal.security.device', { browser, system })) ??
            t('portal.notifications.unknownDevice'),
    );

    return t('portal.notifications.others', { count: others.length, devices: list(names) }, others.length);
});

onMounted(() => loadScreen(load).finally(() => (loaded.value = true)));

async function load() {
    await refresh();

    if (!push.value.available) return;

    support.value = await pushSupportHere({ platform: pwa.platform, standalone: pwa.standalone });

    if (support.value === 'ok') await readThisDevice();
}

async function refresh() {
    const response = await http.get('/push');
    apply(response.data.data);
}

function apply(data) {
    push.value = data;
}

async function readThisDevice() {
    try {
        const subscription = await currentSubscription();
        const own = subscription ? await sha256Hex(subscription.endpoint) : null;

        if (subscription && !push.value.devices.some((device) => device.hash === own)) {
            await subscription.unsubscribe().catch(() => false);
            forgetThisDevice();
            return;
        }

        if (subscription && madeWithOtherKey(subscription.options?.applicationServerKey, push.value.public_key)) {
            await dropOldSubscription(subscription);
            return;
        }

        endpoint.value = subscription?.endpoint ?? null;
        hash.value = own;
    } catch {
        forgetThisDevice();
    }
}

/**
 * The portal's keys changed since this subscription was made: nothing would ever
 * arrive. The portal and the browser both forget it (the browser even when the
 * portal could not be asked), so "Turn on" makes a fresh one with the current key.
 */
async function dropOldSubscription(subscription) {
    try {
        const response = await http.delete('/push/subscriptions', { data: { endpoint: subscription.endpoint } });
        apply(response.data.data);
    } finally {
        await subscription.unsubscribe().catch(() => false);
        forgetThisDevice();
    }
}

function forgetThisDevice() {
    endpoint.value = null;
    hash.value = null;
}

// The browser asks for permission only right after a click: subscribe() asks before anything else.
async function turnOn() {
    busy.value = true;

    try {
        const result = await subscribe(push.value.public_key);

        if (!result.state) {
            if (result.permission === 'denied') {
                support.value = 'denied';
            } else {
                toast.error(t('portal.notifications.notAllowed'));
            }

            return;
        }

        apply(result.state);
        endpoint.value = result.endpoint;
        hash.value = await sha256Hex(result.endpoint);
        // The first device may have added push beside email: read the choices again.
        await refreshNotices();
        toast.success(t('portal.notifications.turnedOn'));
    } catch (error) {
        report(error);
    } finally {
        busy.value = false;
    }
}

async function turnOff() {
    busy.value = true;

    try {
        const data = await unsubscribe();

        if (data) {
            apply(data);
        } else {
            await refresh();
        }

        forgetThisDevice();
        toast.success(t('portal.notifications.turnedOff'));
    } catch (error) {
        report(error, 'portal.errors.generic');
    } finally {
        busy.value = false;
    }
}

async function test() {
    busy.value = true;

    try {
        if (await sendTest(endpoint.value)) {
            toast.success(t('portal.notifications.testSent'));
        } else {
            toast.error(t('portal.notifications.testFailed'));
            // The push service may have said this subscription is gone; the server then forgot it.
            await loadScreen(load);
        }
    } catch (error) {
        report(error, 'portal.errors.generic');

        if (pushProblem(error) === 'notOn') await loadScreen(load);
    } finally {
        busy.value = false;
    }
}

/** Says what went wrong; `fallback` replaces the turning-on wording for other steps. */
function report(error, fallback = null) {
    const problem = pushProblem(error);

    if (problem === null) return;

    if (problem === 'unavailable') {
        push.value = { ...push.value, available: false };
    }

    toast.error(problem === 'failed' && fallback ? t(fallback) : t(`portal.notifications.problems.${problem}`));
}

async function refreshNotices() {
    try {
        const response = await http.get('/profile');
        notices.value = noticesForm(response.data.data.notifications);
        emit('saved', response.data.data);
    } catch {
        // The choices on screen stay; the next save reads them again.
    }
}

/** One notice on one channel, saved at once. */
async function saveNotice(kind, channel, value) {
    const previous = notices.value[kind][channel];
    notices.value[kind][channel] = value;
    saving.value = `${kind}.${channel}`;

    try {
        const response = await http.put('/profile', { notifications: { [kind]: { [channel]: value } } });
        notices.value = noticesForm(response.data.data.notifications);
        emit('saved', response.data.data);
        toast.success(t('portal.notifications.saved'));
    } catch (error) {
        notices.value[kind][channel] = previous;
        complain(error);
    } finally {
        saving.value = null;
    }
}

// A 401 or 403 has already moved the person on (main.js).
function complain(error) {
    const status = error.response?.status;

    if (status !== 401 && status !== 403) toast.error(t('portal.errors.generic'));
}

function list(names) {
    try {
        return new Intl.ListFormat(locale.value, { type: 'conjunction' }).format(names);
    } catch {
        return names.join(', ');
    }
}
</script>

<template>
    <div v-if="available" class="card-body border-b border-line-soft">
        <div class="mb-1 flex flex-wrap items-center gap-2">
            <span class="text-[13px] font-medium">{{ t('portal.notifications.thisDevice') }}</span>
            <span v-if="support === 'ok'" class="badge" :class="on ? 'b-ok' : 'b-plain'">
                {{ on ? t('portal.notifications.on') : t('portal.notifications.off') }}
            </span>
        </div>

        <p v-if="support === null" class="text-sm text-ink-3">{{ t('common.loading') }}</p>
        <template v-else-if="supportText">
            <p class="notice n-neutral mt-2">
                {{ t(`portal.notifications.states.${supportText}`) }}
            </p>
        </template>
        <template v-else>
            <p class="text-sm text-ink-2">
                {{ on ? t('portal.notifications.isOn') : t('portal.notifications.isOff') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button
                    v-if="on"
                    type="button"
                    class="btn"
                    :disabled="busy"
                    @click="turnOff"
                    v-tip="t('tips.portal.notifications.turnOff')"
                >
                    <AppIcon name="notificationsOff" />
                    {{ t('portal.notifications.turnOff') }}
                </button>
                <button
                    v-else
                    type="button"
                    class="btn btn-primary"
                    :disabled="busy"
                    @click="turnOn"
                    v-tip="t('tips.portal.notifications.turnOn')"
                >
                    <AppIcon name="notificationsOn" />
                    {{ t('portal.notifications.turnOn') }}
                </button>
                <button
                    v-if="on"
                    type="button"
                    class="btn btn-ghost"
                    :disabled="busy"
                    @click="test"
                    v-tip="t('tips.portal.notifications.test')"
                >
                    <AppIcon name="send" />
                    {{ t('portal.notifications.test') }}
                </button>
            </div>
            <p class="mt-3 text-xs text-ink-3">{{ t('portal.notifications.generic') }}</p>
        </template>

        <p v-if="devices.others.length" class="mt-2 text-xs text-ink-3">{{ othersText }}</p>
    </div>

    <div class="card-body">
        <p class="mb-3 text-sm text-ink-2">{{ t('portal.notifications.choose', { email: profile.email }) }}</p>
        <p v-if="loaded && available && !devices.any" class="notice n-neutral mb-3">
            {{ t('portal.notifications.pushWithoutDevice') }}
        </p>
        <div class="table-wrap">
            <table class="notif-matrix">
                <thead>
                    <tr>
                        <th scope="col">{{ t('portal.notifications.columns.notice') }}</th>
                        <th v-for="channel in channels" :key="channel" scope="col" class="ch">
                            <AppIcon :name="channel === 'mail' ? 'mail' : 'notificationsOn'" />
                            {{ t(`portal.notifications.columns.${channel}`) }}
                        </th>
                    </tr>
                </thead>
                <tbody v-for="group in CLIENT_NOTICE_GROUPS" :key="group.key">
                    <tr class="group">
                        <th :colspan="channels.length + 1" scope="colgroup">
                            {{ t(`portal.notifications.groups.${group.key}`) }}
                        </th>
                    </tr>
                    <tr v-for="kind in group.kinds" :key="kind">
                        <th scope="row">
                            <span class="block font-medium text-ink">{{
                                t(`portal.notifications.kinds.${kind}`)
                            }}</span>
                        </th>
                        <td v-for="channel in channels" :key="channel" class="ch">
                            <input
                                :id="`notice-${kind}-${channel}`"
                                type="checkbox"
                                :checked="notices[kind][channel]"
                                :disabled="saving !== null"
                                :aria-label="
                                    t('portal.notifications.checkbox', {
                                        channel: t(`portal.notifications.columns.${channel}`),
                                        notice: t(`portal.notifications.kinds.${kind}`),
                                    })
                                "
                                @change="saveNotice(kind, channel, $event.target.checked)"
                            />
                        </td>
                    </tr>
                </tbody>
                <!-- They get the client in, so they always come by email. -->
                <tbody>
                    <tr class="group">
                        <th :colspan="channels.length + 1" scope="colgroup">
                            {{ t('portal.notifications.groups.always') }}
                            <span class="block text-xs font-normal text-ink-3 normal-case">{{
                                t('portal.notifications.alwaysHint')
                            }}</span>
                        </th>
                    </tr>
                    <tr v-for="key in CLIENT_ALWAYS_BY_EMAIL" :key="key" class="locked">
                        <th scope="row">
                            <span class="block font-medium text-ink">{{
                                t(`portal.notifications.always.${key}`)
                            }}</span>
                        </th>
                        <td class="ch">
                            <span class="badge" :title="t('portal.notifications.alwaysTitle')">{{
                                t('portal.notifications.alwaysBadge')
                            }}</span>
                        </td>
                        <td v-if="channels.length > 1" class="ch">
                            <span class="text-ink-3" :title="t('portal.notifications.notAvailable')"
                                >—<span class="sr-only">{{ t('portal.notifications.notAvailable') }}</span></span
                            >
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-ink-3">{{ t('portal.notifications.offHint') }}</p>
    </div>
</template>
