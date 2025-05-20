<template>
    <PkpTableCell>
        <span
            v-if="fileStatus?.ithenticateSimilarityResult" 
            class="plagiarism-similarity-score"
        >
            <a
                :href="fileStatus.ithenticateViewerUrl ?? '#'"
                :target="_blank"
                :title="t('plugins.generic.plagiarism.similarity.action.launch.viewer.title')"
            >
                <img 
                    :alt="t('plugins.generic.plagiarism.similarity.match.title')"
                    :src="fileStatus.ithenticateLogo" 
                />
            </a>
            <span>{{ fileStatus.ithenticateSimilarityResult }} % </span>
        </span>
    </PkpTableCell>
</template>
  
<script setup>
    import { computed } from "vue";
    import { deduceFileStatus } from "../fileStatus";

    const { useApp } = pkp.modules.useApp;

    const props = defineProps({ 
        file: { type: Object, required: false },
        galley: { type: Object, required: false },
        fileStageNamespace : { type: String, required: false },
    });

    const { isOPS } = useApp();

    const fileStore = isOPS()
        ? pkp.registry.getPiniaStore('galleyManager')
        : pkp.registry.getPiniaStore(props.fileStageNamespace);

    const fileStatus = computed(() => {

    const submissionFile = isOPS() ? props.galley.file : props.file;

    if (!fileStore?.ithenticateStatus) {
        return {};
    }

    const status = deduceFileStatus(submissionFile, fileStore.ithenticateStatus || {});
        return status;
    });
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
</style>
  