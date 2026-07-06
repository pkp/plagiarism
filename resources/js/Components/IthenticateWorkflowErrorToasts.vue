<template>
    <!--
        Persistent, dismissible toasts for iThenticate processing errors — both submission-wide
        errors and file-specific ones -- which carry the file's name for attribution.
    -->
    <div
        v-if="errors.length"
        class="ithenticate-error-toasts"
        role="region"
        aria-live="polite"
        :aria-label="t('plugins.generic.plagiarism.workflow.errorToasts.region')"
    >
        <PkpNotification
            v-for="error in errors"
            :key="error.id"
            type="warning"
            :can-dismiss="true"
            class="ithenticate-error-toasts__item"
            @dismiss="dismiss(error.id)"
        >
            <span
                v-if="error.fileName"
                class="ithenticate-error-toasts__file"
            >
                {{ error.fileName }}
            </span>
            <span class="ithenticate-error-toasts__message">{{ error.message }}</span>
        </PkpNotification>
    </div>
</template>

<script setup>

    import { computed, ref } from "vue";

    const { useApp } = pkp.modules.useApp;
    const { useLocalize } = pkp.modules.useLocalize;
    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;

    const props = defineProps({
        fileStageNamespace: { type: String, required: false },
    });

    const { t } = useLocalize();
    const { isOPS } = useApp();

    const fileStore = isOPS()
        ? pkp.registry.getPiniaStore('galleyManager')
        : pkp.registry.getPiniaStore(props.fileStageNamespace);

    // Notification ids hidden optimistically on dismiss, so the toast disappears immediately even
    // when no SSE stream is active to refresh the authoritative list. The server-side date_read
    // makes the dismissal durable across reloads regardless.
    const dismissedIds = ref(new Set());

    const errors = computed(() => {
        const list = fileStore?.ithenticateStatus?.submission?.ithenticateProcessingErrors ?? [];
        return list.filter((error) => !dismissedIds.value.has(error.id));
    });

    const submissionId = computed(() => {
        const workflowStore = pkp.registry.getPiniaStore('workflow');
        return workflowStore?.submission?.id ?? fileStore?.props?.submission?.id;
    });

    async function dismiss(notificationId) {
        // Optimistically hide first.
        dismissedIds.value = new Set(dismissedIds.value).add(notificationId);

        // Without a submission id we cannot persist the dismissal; keep the optimistic hide
        // (it will re-appear on reload, which is acceptable) and skip the request.
        if (!submissionId.value) {
            return;
        }

        try {
            const { apiUrl } = useUrl(`submissions/${submissionId.value}/plagiarism/error/dismiss`);
            const { fetch: dismissError } = useFetch(apiUrl, {
                method: 'PUT',
                body: { notificationId },
            });
            await dismissError();
        } catch (error) {
            // Restore the toast if the dismissal request failed.
            const next = new Set(dismissedIds.value);
            next.delete(notificationId);
            dismissedIds.value = next;
        }
    }
</script>

<style scoped>
    .ithenticate-error-toasts {
        position: fixed;
        top: 1rem;
        inset-inline-end: 1rem;
        z-index: 9999;
        display: flex;
        flex-direction: column;
        gap: .5rem;
        width: 22rem;
        max-width: calc(100vw - 2rem);
        max-height: calc(100vh - 2rem);
        overflow-y: auto;
    }

    .ithenticate-error-toasts__item {
        background: var(--background-color-elevation-1, #ffffff);
        box-shadow: 0 .25rem .75rem rgba(0, 0, 0, .15);
    }

    .ithenticate-error-toasts__file {
        display: block;
        margin-bottom: .125rem;
        font-size: .8rem;
        opacity: .85;
    }

    .ithenticate-error-toasts__message {
        display: block;
        font: var(--font-base-bold);
    }
</style>
