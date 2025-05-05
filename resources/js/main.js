import ithenticateSimilarityScoreCell from "./Components/ithenticateSimilarityScoreCell.vue";

pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);

const {useLocalize } = pkp.modules.useLocalize;
const { useUrl } = pkp.modules.useUrl;
const { useFetch } = pkp.modules.useFetch;
const { t, localize } = useLocalize();

// Create a map to store plagiarism status for each file
const plagiarismStatusMap = new Map();

// Function to fetch plagiarism status for a file
async function fetchPlagiarismFileStatus(submissionId, fileId, stageId) {
    const {apiUrl} = useUrl(`submissions/${submissionId}/files/${fileId}/plagiarism/status`);
    const {
        data: plagiarismFileStatus,
        fetch: fetchPlagiarismFileStatus,
    } = useFetch(apiUrl, {
        query: {
            stageId: stageId
        }
    });

    await fetchPlagiarismFileStatus();
    return plagiarismFileStatus;
}

function addIthenticateColumn(columns, args) {
    const newColumns = [...columns];

    newColumns.splice(newColumns.length - 1, 0, {
        header: 'iThenticate',
        component: 'ithenticateSimilarityScoreCell',
        props: {
            submission: args.props.submission,
        },
    });
    return newColumns;
}

// Adding iThenticate column
pkp.registry.storeExtendFn(
    'fileManager_SUBMISSION_FILES',
    'getColumns',
    addIthenticateColumn
);

pkp.registry.storeExtendFn(
    'fileManager_EDITOR_REVIEW_FILES',
    'getColumns',
    addIthenticateColumn
);

function getItemActionLabel(plagiarismStatus) {
    if (!plagiarismStatus.user.ithenticateEulaVersion || !plagiarismStatus.submission.ithenticateEulaVersion) {
        return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title');
    }

    if (plagiarismStatus.file.ithenticateId) {
        return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title');
    }

    if (!plagiarismStatus.file.ithenticateSimilarityScheduled) {
        return t('plugins.generic.plagiarism.similarity.action.generateReport.title');
    }

    return t('plugins.generic.plagiarism.similarity.action.refreshReport.title');
}

pkp.registry.storeExtendFn(
    "fileManager_SUBMISSION_FILES",
    "getItemActions",
    async (originalResult, args, context) => {
        const submissionFile = args.file;
        const submission = context.props.submission;

        // Check if we already have the status for this file
        const cacheKey = `${submission.id}-${submissionFile.id}`;
        if (!plagiarismStatusMap.has(cacheKey)) {
            const status = await fetchPlagiarismFileStatus(submission.id, submissionFile.id, submission.stageId);
            plagiarismStatusMap.set(cacheKey, status.value);
        }

        const plagiarismStatus = plagiarismStatusMap.get(cacheKey)?.value;
        console.log('plagiarismStatus');
        console.log(plagiarismStatus);

        return [
            ...originalResult,
            {
                label: t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title'),
                name: "conductPlagiarismCheck",
                icon: "Globe",
                actionFn: ({ file }) => {
                    
                    const {useLegacyGridUrl} = pkp.modules.useLegacyGridUrl;

                    const {openLegacyModal} = useLegacyGridUrl({
                        component: 'plugins.generic.plagiarism.controllers.PlagiarismIthenticateHandler',
                        op: 'acceptEulaAndExecuteIntendedAction',
                        params: {
                            submissionId: file.submissionId,
                            submissionFileId: file.id,
                            stageId: submission.stageId,
                        },
                    });
                    openLegacyModal(
                        {
                            title: t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title')
                        },
                        () => {
                            console.log('EUAL confirmed');
                        },
                    );
                },
            },
        ];
    }
);

