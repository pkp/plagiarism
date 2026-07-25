<template>
    <PkpTableCell>
        <span
            v-if="fileStatus?.ithenticateSimilarityResult"
            class="plagiarism-similarity-score"
        >
            <a
                :href="fileStatus.ithenticateViewerUrl ?? '#'"
                target="_blank"
                :title="t('plugins.generic.plagiarism.similarity.action.launch.viewer.title')"
            >
                <img
                    :alt="t('plugins.generic.plagiarism.similarity.match.title')"
                    :src="fileStatus.ithenticateLogo"
                />
            </a>
            <span>{{ fileStatus.ithenticateSimilarityResult }} % </span>
        </span>

        <span class="plagiarism-error" v-else-if="fileStatus?.ithenticateProcessingError">
            <button
                type="button"
                class="plagiarism-error__button"
                :aria-label="t('plugins.generic.plagiarism.similarity.error.ariaLabel')"
                @click="openErrorDetails"
            >
                <icon icon="Error" class="h-6 w-6"></icon>
            </button>
            <span class="tooltip">{{ fileStatus.ithenticateProcessingError }}</span>
        </span>

        <span class="plagiarism-status" v-else-if="fileStatus?.ithenticateId">
            <icon icon="InProgress" class="h-6 w-6"></icon>
            <span class="tooltip">
                <span v-if="!fileStatus?.ithenticateSimilarityScheduled">
                    {{ t('plugins.generic.plagiarism.file.statusText.waitingOnReportSchedule') }}
                </span>
                <span v-else>
                    {{ t('plugins.generic.plagiarism.file.statusText.reportScheduleCompletd') }} {{ t('plugins.generic.plagiarism.file.statusText.waitingOnSimilarityScore') }}
                </span>
            </span>
        </span>
    </PkpTableCell>
</template>

<script setup>

    import { computed } from "vue";
    import { deduceFileStatus } from "../fileStatus";

    const { useApp } = pkp.modules.useApp;
    const { useLocalize } = pkp.modules.useLocalize;
    const { useModal } = pkp.modules.useModal;

    const { t } = useLocalize();

    const props = defineProps({
        file: { type: Object, required: false },
        galley: { type: Object, required: false },
        fileStageNamespace : { type: String, required: false },
    });

    const { isOPS } = useApp();

    // Defensive: getPiniaStore throws on a not-yet-registered store. This cell is always a child of
    // its FileManager (store present), but never let a store lookup break the file-manager render.
    let fileStore = null;
    try {
        fileStore = isOPS()
            ? pkp.registry.getPiniaStore('galleyManager')
            : pkp.registry.getPiniaStore(props.fileStageNamespace);
    } catch (e) {
        fileStore = null;
    }

    const fileStatus = computed(() => {

        const submissionFile = isOPS() ? props.galley.file : props.file;

        if (!fileStore?.ithenticateStatus) {
            return {};
        }

        const status = deduceFileStatus(submissionFile, fileStore.ithenticateStatus || {});

        return status;
    });

    function openErrorDetails() {
        const { openDialog } = useModal();

        openDialog({
            title: t('plugins.generic.plagiarism.similarity.error.title'),
            message: fileStatus.value.ithenticateProcessingError,
            modalStyle: 'negative',
            actions: [
                {
                    label: t('common.ok'),
                    isPrimary: true,
                    callback: (close) => close(),
                },
            ],
        });
    }
</script>

<style scoped>
    span.plagiarism-similarity-score {
        display: flex;
        align-items: center;
    }

    span.plagiarism-similarity-score img {
        max-width: 4rem;
        cursor: pointer;
    }

    span.plagiarism-similarity-score span {
        padding-inline-start: .25rem;
        font: var(--font-base-bold);
        color: var(--color-primary);
    }

    span.plagiarism-status {
        position: relative;
        display: inline-block;
    }

    span.plagiarism-status .tooltip {
        visibility: hidden;
        background-color: #E1D9D1;
        font: var(--font-base-bold);
        color: var(--color-primary);
        text-align: center;
        padding: .315rem .625rem;
        border-radius: .25rem;
        position: absolute;
        z-index: 1000;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%);
        white-space: nowrap;
        font-size: 0.775rem;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    span.plagiarism-status:hover .tooltip {
        visibility: visible;
        opacity: 1;
    }

    /* Optional: Add an arrow to the tooltip */
    span.plagiarism-status .tooltip::after {
        content: "";
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -.315rem;
        border-width: .315rem;
        border-style: solid;
        border-color: #333 transparent transparent transparent;
    }

    span.plagiarism-error {
        position: relative;
        display: inline-block;
    }

    span.plagiarism-error .plagiarism-error__button {
        display: inline-flex;
        align-items: center;
        padding: 0;
        border: 0;
        background: transparent;
        cursor: pointer;
        color: var(--color-attention, #b91c1c);
    }

    span.plagiarism-error .tooltip {
        visibility: hidden;
        background-color: #E1D9D1;
        font: var(--font-base-bold);
        color: var(--color-primary);
        text-align: start;
        padding: .315rem .625rem;
        border-radius: .25rem;
        position: absolute;
        z-index: 1000;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%);
        white-space: normal;
        width: min(24rem, calc(100vw - 2rem));
        font-size: 0.775rem;
        opacity: 0;
        transition: opacity 0.2s ease;
    }

    span.plagiarism-error:hover .tooltip {
        visibility: visible;
        opacity: 1;
    }
</style>
