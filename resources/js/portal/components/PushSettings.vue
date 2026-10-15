<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';

import ToggleRow from '@/components/ToggleRow.vue';
import http from '@/portal/http';
import { deviceLabel, madeWithOtherKey, pushDevices, pushProblem, sha256Hex, supportNotice } from '@/portal/lib/pwa';
import { loadScreen } from '@/portal/load';
import { currentSubscription, pushSupportHere, sendTest, subscribe, unsubscribe } from '@/portal/pwa/push';
import { usePwaStore } from '@/portal/stores/pwa';
import { useToastStore } from '@/stores/toast';

/**
 * Profile → Notifications (Phase 9c, docs/spec/12, "Web push"): web push on
 * or off for this device, a test notification, the other devices that have it
 * on, how notices reach the client (email, notifications or both — offered once
 * a device has push on; without one they come by email), and the reminder a
 * day before each appointment. Every choice is saved at once.
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

const CHANNELS = ['email', 'push', 'both'];

const push = ref(null);
const loaded = ref(false);
const support = ref(null);
const endpoint = ref(null);
const hash = ref(null);
const channel = ref(props.profile.notification_channel ?? 'email');
const reminders = ref(props.profile.appointment_reminders ?? true);
const busy = ref(false);
const savingChannel = ref(false);
const savingReminders = ref(false);

const available = computed(() => Boolean(push.value?.available));
const devices = computed(() => pushDevices(push.value?.devices, hash.value));
const on = computed(() => devices.value.here && Boolean(endpoint.value));
const supportText = computed(() => supportNotice(support.value));
const remindersModel = computed({ get: () => reminders.value, set: saveReminders });

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
    channel.value = data.channel;
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

async function saveChannel(value) {
    if (value === channel.value) return;

    const previous = channel.value;
    channel.value = value;
    savingChannel.value = true;

    try {
        const response = await http.put('/profile', { notification_channel: value });
        channel.value = response.data.data.notification_channel;
        emit('saved', response.data.data);
        toast.success(t('portal.notifications.saved'));
    } catch (error) {
        channel.value = previous;
        complain(error);
    } finally {
        savingChannel.value = false;
    }
}

async function saveReminders(value) {
    const previous = reminders.value;
    reminders.value = value;
    savingReminders.value = true;

    try {
        const response = await http.put('/profile', { appointment_reminders: value });
        reminders.value = response.data.data.appointment_reminders;
        emit('saved', response.data.data);
        toast.success(t('portal.notifications.saved'));
    } catch (error) {
        reminders.value = previous;
        complain(error);
    } finally {
        savingReminders.value = false;
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
        <p v-else-if="supportText" class="notice n-neutral mt-2">
            {{ t(`portal.notifications.states.${supportText}`) }}
        </p>
        <template v-else>
            <p class="text-sm text-ink-2">
                {{ on ? t('portal.notifications.isOn') : t('portal.notifications.isOff') }}
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button v-if="on" type="button" class="btn" :disabled="busy" @click="turnOff">
                    {{ t('portal.notifications.turnOff') }}
                </button>
                <button v-else type="button" class="btn btn-primary" :disabled="busy" @click="turnOn">
                    {{ t('portal.notifications.turnOn') }}
                </button>
                <button v-if="on" type="button" class="btn btn-ghost" :disabled="busy" @click="test">
                    {{ t('portal.notifications.test') }}
                </button>
            </div>
            <p class="mt-3 text-xs text-ink-3">{{ t('portal.notifications.generic') }}</p>
        </template>

        <p v-if="devices.others.length" class="mt-2 text-xs text-ink-3">{{ othersText }}</p>
    </div>

    <div class="card-body">
        <template v-if="loaded">
            <fieldset v-if="available && devices.any" class="mb-2">
                <legend class="mb-1.5 text-xs font-medium text-ink-2">{{ t('portal.notifications.channel') }}</legend>
                <label v-for="option in CHANNELS" :key="option" class="flex items-center gap-2 py-1 text-sm">
                    <input
                        type="radio"
                        name="notification-channel"
                        :value="option"
                        :checked="channel === option"
                        :disabled="savingChannel"
                        @change="saveChannel(option)"
                    />
                    {{ t(`portal.notifications.channels.${option}`) }}
                </label>
                <p class="mt-1 text-xs text-ink-3">{{ t('portal.notifications.channelHint') }}</p>
            </fieldset>
            <p v-else class="text-sm text-ink-2">
                {{ t('portal.notifications.byEmail', { email: profile.email }) }}
            </p>
        </template>
        <div class="mt-2">
            <ToggleRow
                v-model="remindersModel"
                :label="t('portal.profile.reminders')"
                :description="t('portal.profile.remindersHint')"
                :disabled="savingReminders"
            />
        </div>
    </div>
</template>
