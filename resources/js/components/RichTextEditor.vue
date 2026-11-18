<script setup>
import { Placeholder } from '@tiptap/extensions';
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { onBeforeUnmount, watch } from 'vue';
import { useI18n } from 'vue-i18n';

import AppIcon from '@/components/AppIcon.vue';

/**
 * Formatted text for notes and consultations. Emits HTML ('' when empty); the
 * server keeps only an allowlist of it (App\Support\RichText), so the toolbar
 * offers nothing the server would strip. Loaded on demand — pages import it
 * with defineAsyncComponent, so the editor code is fetched only where used.
 */
const model = defineModel({ type: String, default: '' });

const props = defineProps({
    label: { type: String, required: true },
    placeholder: { type: String, default: '' },
    invalid: { type: Boolean, default: false },
    describedby: { type: String, default: undefined },
    height: { type: String, default: '110px' },
});

const { t } = useI18n();

const html = (editor) => (editor.isEmpty ? '' : editor.getHTML());

const editor = useEditor({
    content: model.value || '',
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3] },
            codeBlock: false,
            link: {
                openOnClick: false,
                autolink: true,
                protocols: ['http', 'https', 'mailto'],
                HTMLAttributes: { rel: 'noopener noreferrer nofollow', target: '_blank' },
            },
        }),
        Placeholder.configure({ placeholder: () => props.placeholder }),
    ],
    editorProps: {
        attributes: {
            role: 'textbox',
            'aria-multiline': 'true',
            'aria-label': props.label,
            ...(props.describedby ? { 'aria-describedby': props.describedby } : {}),
        },
    },
    onUpdate: ({ editor: instance }) => {
        model.value = html(instance);
    },
});

// A new value from outside (a reset, another record) replaces the content.
watch(model, (value) => {
    if (editor.value && (value || '') !== html(editor.value)) {
        editor.value.commands.setContent(value || '', { emitUpdate: false });
    }
});

onBeforeUnmount(() => editor.value?.destroy());

// Toolbar buttons never take focus from the text on a mouse click (mousedown is
// prevented), so the selection and the next keystrokes stay in the editor.
const run = (command) => command(editor.value.chain().focus()).run();

function setLink() {
    const current = editor.value.getAttributes('link').href ?? '';
    const href = window.prompt(t('editor.linkPrompt'), current);

    if (href === null) return;

    if (href.trim() === '') {
        run((chain) => chain.extendMarkRange('link').unsetLink());
    } else if (/^(https?:\/\/|mailto:)/i.test(href.trim())) {
        run((chain) => chain.extendMarkRange('link').setLink({ href: href.trim() }));
    }
}

const buttons = [
    { key: 'bold', label: 'editor.bold', icon: 'bold', command: (c) => c.toggleBold() },
    { key: 'italic', label: 'editor.italic', icon: 'italic', command: (c) => c.toggleItalic() },
    {
        key: 'heading',
        label: 'editor.heading',
        icon: 'heading',
        active: ['heading', { level: 3 }],
        command: (c) => c.toggleHeading({ level: 3 }),
    },
    { sep: true },
    { key: 'bulletList', label: 'editor.bulletList', icon: 'bulletList', command: (c) => c.toggleBulletList() },
    { key: 'orderedList', label: 'editor.orderedList', icon: 'orderedList', command: (c) => c.toggleOrderedList() },
    { key: 'blockquote', label: 'editor.quote', icon: 'quote', command: (c) => c.toggleBlockquote() },
    { key: 'link', label: 'editor.link', icon: 'link', custom: setLink },
    { sep: true },
    { key: 'undo', label: 'editor.undo', icon: 'undo', command: (c) => c.undo(), stateless: true },
    { key: 'redo', label: 'editor.redo', icon: 'redo', command: (c) => c.redo(), stateless: true },
];

function isActive(button) {
    if (!editor.value || button.stateless) return undefined;

    return button.active ? editor.value.isActive(...button.active) : editor.value.isActive(button.key);
}
</script>

<template>
    <div class="editor" :aria-invalid="invalid ? 'true' : undefined" :style="{ '--editor-height': height }">
        <div class="editor-toolbar" role="toolbar" :aria-label="t('editor.toolbar')">
            <template v-for="(button, index) in buttons" :key="button.key ?? `sep-${index}`">
                <span v-if="button.sep" class="sep" aria-hidden="true" />
                <button
                    v-else
                    type="button"
                    v-tip="t(`tips.${button.label}`)"
                    :aria-label="t(button.label)"
                    :aria-pressed="isActive(button)"
                    :disabled="!editor"
                    @mousedown.prevent
                    @click="button.custom ? button.custom() : run(button.command)"
                >
                    <AppIcon :name="button.icon" />
                </button>
            </template>
        </div>
        <EditorContent :editor="editor" class="rich" />
    </div>
</template>
