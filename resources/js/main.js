import ithenticateSimilarityScoreCell from "./Components/ithenticateSimilarityScoreCell.vue";

pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);

const {useLocalize } = pkp.modules.useLocalize;
const { t, localize } = useLocalize();

function addIthenticateColumn(columns, args) {
    const newColumns = [...columns];

    newColumns.splice(newColumns.length - 1, 0, {
        header: 'iThenticate',
        component: 'ithenticateSimilarityScoreCell',
        props: {
            submission: {},
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

pkp.registry.storeExtendFn(
    "fileManager_SUBMISSION_FILES",
    "getItemActions",
    (originalResult, args) => {
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
                            stageId: 1,
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