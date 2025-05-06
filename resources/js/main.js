import ithenticateSimilarityScoreCell from "./Components/ithenticateSimilarityScoreCell.vue";

pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);

const {useLocalize } = pkp.modules.useLocalize;
import { computed, watch } from "vue";

function runPlagiarismAction(piniaContext, stageNamespace) {
    
    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;
    const { t } = useLocalize();

    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;

    const ithenticateQueryParams = computed(() => {
        const fileIds = fileStore?.files?.map((file) => file.id) || [];
    
        return {
            fileIds: fileIds,
            submissionId: submission.id,
            stageId: submissionStageId
        };
    });

    const { apiUrl } = useUrl(`submissions/${submission.id}/plagiarism/status`);
    
    const { 
        fetch: fetchIthenticateStatus, 
        data: ithenticateStatus,
    } = useFetch(
        apiUrl, { 
            method: 'POST',
            body: ithenticateQueryParams 
        }
    );
    
    watch(ithenticateQueryParams, (newQueryParams) => {
        if (newQueryParams?.fileIds?.length) {
            fetchIthenticateStatus();
        }
    });

    fileStore.ithenticateStatus = ithenticateStatus;

    fileStore.extender.extendFn('getColumns', (columns, args) => {
        const newColumns = [...columns];

        newColumns.splice(newColumns.length - 1, 0, {
            header: t('plugins.generic.plagiarism.similarity.match.title'),
            component: 'ithenticateSimilarityScoreCell',
            props: {
                fileStageNamespace: stageNamespace
            },
        });

        return newColumns;
    });

    function getLabel(userStatus, submissionStatus, fileStatus)
    {
        if (!userStatus.ithenticateEulaVersion || !submissionStatus.ithenticateEulaVersion) {
            return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title');
        }
    
        if (!fileStatus.ithenticateId) {
            return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title');
        }
    
        if (!fileStatus.ithenticateSimilarityScheduled) {
            return t('plugins.generic.plagiarism.similarity.action.generateReport.title');
        }
    
        return t('plugins.generic.plagiarism.similarity.action.refreshReport.title');
    }

    function getConfirmationMessage(fileStatus)
    {
        if (!fileStatus.ithenticateId) {
            return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.confirmation');
        }

        if (!fileStatus.ithenticateSimilarityScheduled) {
            return t('plugins.generic.plagiarism.similarity.action.generateReport.confirmation');
        }

        return t('plugins.generic.plagiarism.similarity.action.refreshReport.confirmation');
    }

    function getActionUrl(fileStatus)
    {
        if (!fileStatus.ithenticateId) {
            return fileStatus.ithenticateUploadUrl;
        }
    
        if (!fileStatus.ithenticateSimilarityScheduled) {
            return fileStatus.ithenticateReportScheduleUrl;
        }
    
        return fileStatus.ithenticateReportRefreshUrl;
    }

    async function executePlagiarismAction(fileStatus)
    {
        const actionUrl = getActionUrl(fileStatus);

        const { apiUrl } = useUrl(actionUrl);
    
        const { 
            fetch: executeIthenticateAction, 
            data: ithenticateActionData,
        } = useFetch(apiUrl);
        
        await executeIthenticateAction();

        return ithenticateActionData.value?.status;
    }

    fileStore.extender.extendFn('getItemActions', (originalResult, args) => {
        const submission = fileStore.props.submission;

        // will only allow action on submission current stage
        if (submission.stageId !== submissionStageId) {
            return [...originalResult];
        }

        if (ithenticateStatus.value) {
            const fileId = args.file.sourceSubmissionFileId || args.file.id;
            const fileStatus = ithenticateStatus.value?.files?.[fileId];
            const userStatus = ithenticateStatus.value?.user;
            const submissionStatus = ithenticateStatus.value?.submission;

            // Action on non allowed file is restricted
            if (!fileStatus.ithenticateUploadAllowed) {
                return [...originalResult];
            }

            return [
                ...originalResult,
                {
                    label: getLabel(userStatus, submissionStatus, fileStatus),
                    name: "conductPlagiarismCheck",
                    icon: "Globe",
                    actionFn: ({ file }) => {
                        
                        if (!userStatus.ithenticateEulaVersion || !submissionStatus.ithenticateEulaVersion) {
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
                                    fetchIthenticateStatus();
                                },
                            );

                            return;
                        }

                        const { useModal } = pkp.modules.useModal;
                        const { openDialog } = useModal();

                        openDialog({
                            title: getLabel(userStatus, submissionStatus, fileStatus),
                            message: getConfirmationMessage(fileStatus),
                            actions: [
                                {
                                    label: t('common.yes'),
                                    isPrimary: true,
                                    callback: async (close) => {
                                        close();
                                        const status = await executePlagiarismAction(fileStatus);
                                        
                                        if (status) {
                                            fetchIthenticateStatus();
                                        }
                                    },
                                },
                                {
                                    label: t('common.no'),
                                    isWarnable: true,
                                    callback: (close) => {
                                        close();
                                    },
                                },
                            ],
                        });
                    },
                },
            ];
        }
    });
}

pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_SUBMISSION_FILES');
});

pkp.registry.storeExtend('fileManager_EDITOR_REVIEW_FILES', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_EDITOR_REVIEW_FILES');
});

pkp.registry.storeExtend('fileManager_PRODUCTION_READY_FILES', (piniaContext) => {
    const appName = window.pkp.context.app;

    if (appName !== 'ops') {
        return;
    }
    
    runPlagiarismAction(piniaContext, 'fileManager_PRODUCTION_READY_FILES');
});
