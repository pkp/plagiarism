<template>
    <!--
        Inline, non-dismissible notice for submission-level iThenticate processing errors.
        Rendered in normal document flow above the file manager (not a floating toast). Each
        error clears automatically when the submit-time plagiarism check next succeeds.
    -->
    <div
        v-if="errors.length"
        class="ithenticate-error-notice"
        role="region"
        aria-live="polite"
        :aria-label="t('plugins.generic.plagiarism.workflow.errorNotice.region')"
    >
        <PkpNotification
            v-for="(error, index) in errors"
            :key="index"
            type="warning"
        >
            {{ error.message }}
        </PkpNotification>
    </div>
</template>

<script setup>

    import { computed, ref, onMounted, nextTick } from "vue";

    const { useApp } = pkp.modules.useApp;
    const { useLocalize } = pkp.modules.useLocalize;

    const props = defineProps({
        fileStageNamespace: { type: String, required: false },
    });

    const { t } = useLocalize();
    const { isOPS } = useApp();

    const storeName = isOPS() ? 'galleyManager' : props.fileStageNamespace;

    // The file/galley-manager store this notice reads is created when that manager component mounts.
    // As a sibling in the workflow primary content, this notice can render before the store exists,
    // and pkp.registry.getPiniaStore() THROWS on a not-yet-registered store — which would break the
    // whole workflow render. Resolve it defensively, and retry a few ticks after mount so we never
    // throw and still surface errors once the store is available.
    const fileStore = ref(null);

    function resolveFileStore() {
        try {
            fileStore.value = pkp.registry.getPiniaStore(storeName);
        } catch (e) {
            fileStore.value = null;
        }
    }

    resolveFileStore();

    if (!fileStore.value) {
        onMounted(() => {
            let attempts = 0;
            const retry = () => {
                resolveFileStore();
                if (!fileStore.value && attempts++ < 10) {
                    nextTick(retry);
                }
            };
            retry();
        });
    }

    const errors = computed(
        () => fileStore.value?.ithenticateStatus?.submission?.ithenticateProcessingErrors ?? []
    );
</script>

<style scoped>
    .ithenticate-error-notice {
        display: flex;
        flex-direction: column;
        gap: .5rem;
        /*
         * Rendered after the Submission Files panel in the workflow primary stack (so the
         * file-manager Pinia store it reads its errors from already exists), but visually
         * pulled above it via flex order so the notice sits directly under the workflow header.
         */
        order: -1;
    }
</style>
