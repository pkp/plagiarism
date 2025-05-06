<template>
    <PkpTableCell>
        <span
            v-if="fileStatus.ithenticateSimilarityResult !== null" 
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

    const props = defineProps({ 
        file: { type: Object, required: true },
        fileStageNamespace : { type: String, required: true },
    });
    
    const fileStore = pkp.registry.getPiniaStore(props.fileStageNamespace);

    const fileStatus = computed(() => {
        const status = fileStore?.ithenticateStatus?.files?.[props.file.id] || {};
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
  