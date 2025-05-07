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
        const fileId = submissionFile.id;
        const sourceSubmissionFileId = submissionFile.sourceSubmissionFileId;

        const status = (
            fileStore?.ithenticateStatus?.files?.[fileId].ithenticateId
                ? fileStore?.ithenticateStatus?.files?.[fileId]
                : (fileStore?.ithenticateStatus?.files?.[sourceSubmissionFileId] 
                    ?? fileStore?.ithenticateStatus?.files?.[fileId]
                )
        ) || {};

        return status;
    });
</script>

<style scoped>
    span.plagiarism-similarity-score {
        display: flex;
        align-items: center;
    }

    span.plagiarism-similarity-score img {
        max-width: 65px;
        cursor: pointer;
    }

    span.plagiarism-similarity-score span {
        padding-left: 5px;
        font-weight: 700;
        font-size: 12px;
        color: #006798;
    }
</style>
  