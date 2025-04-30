<template v-if="fileStatus.ithenticateId">
    <PkpTableCell>
        <span class="plagiarism-similarity-score">
            <a
                :href="fileStatus.ithenticateViewerUrl ?? '#'"
                :target="_blank"
            >
                <img :src="fileStatus.ithenticateLogo" />
            </a>
            <span>{{ fileStatus.ithenticateSimilarityResult }}% </span>
        </span>
    </PkpTableCell>
</template>
  
<script setup>
    import { computed, onMounted } from "vue";

    const { useLocalize } = pkp.modules.useLocalize;
    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;

    const props = defineProps({ 
        file: { type: Object, required: true },
        submission: { type: Object, required: false },
    });

    const { t, localize } = useLocalize();

    const {apiUrl} = useUrl(`submissions/${props.file.submissionId}/files/${props.file.id}/plagiarism/status`);

    const {
        data: plagiarismFileStatus,
        fetch: fetchPlagiarismFileStatus,
    } = useFetch(apiUrl, {
        query: {
		    stageId: pkp.const.WORKFLOW_STAGE_ID_SUBMISSION
		}
    });

    onMounted(async () => {
        await fetchPlagiarismFileStatus();
    });

    // const percentage = computed(() => localize(props.file.name).length);
    const fileStatus = computed(() => plagiarismFileStatus.value || {});
</script>

<style scoped>
    span.plagiarism-similarity-score {
        display: flex;
        align-items: center;
    }

    span.plagiarism-similarity-score img {
        max-width: 75px;
        cursor: pointer;
    }

    span.plagiarism-similarity-score span {
        padding-left: 10px;
        padding-bottom: 5px;
        font-weight: 550;
        font-size: 12px;
        color: #006798;
    }
</style>
  