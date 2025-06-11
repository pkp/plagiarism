import ithenticateSimilarityScoreCell from "./Components/ithenticateSimilarityScoreCell.vue";

pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);

const { useLocalize } = pkp.modules.useLocalize;
const { useApp } = pkp.modules.useApp;
const { useNotify } = pkp.modules.useNotify;
const { useCurrentUser } = pkp.modules.useCurrentUser;
import { computed, watch } from "vue";
import { deduceFileStatus } from "./fileStatus";

function runPlagiarismAction(piniaContext, stageNamespace) {
    
    const dashboardStore = pkp.registry.getPiniaStore("dashboard");
    if (dashboardStore.dashboardPage !== "editorialDashboard") {
        return;
    }

    const { useUrl } = pkp.modules.useUrl;
    const { useFetch } = pkp.modules.useFetch;
    const { t } = useLocalize();

    const { isOPS } = useApp();

    const fileStore = piniaContext.store;
    const { submission, submissionStageId } = fileStore.props;

    const ithenticateRequestParams = computed(() => {
        const fileIds = isOPS()
            ? (fileStore?.galleys?.map((galley) => galley.file.id) || [])
            : (fileStore?.files?.map((file) => file.id) || []);

        return {
            fileIds: fileIds,
            submissionId: submission.id,
            stageId: isOPS() ? pkp.const.WORKFLOW_STAGE_ID_PRODUCTION : submissionStageId
        };
    });

    const { apiUrl } = useUrl(`submissions/${submission.id}/plagiarism/status`);
    
    const { 
        fetch: fetchIthenticateStatus, 
        data: ithenticateStatus,
    } = useFetch(
        apiUrl, { 
            method: 'POST',
            body: ithenticateRequestParams 
        }
    );
    
    watch(ithenticateRequestParams, (newRequestParams) => {
        if (newRequestParams?.fileIds?.length) {
            fetchIthenticateStatus();
        }
    });

    if (isOPS()) {
        fetchIthenticateStatus();
    }

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
        if (!fileStatus.ithenticateId) {
            return t('plugins.generic.plagiarism.similarity.action.submitforPlagiarismCheck.title');
        }

        if (fileStatus.ithenticateId && !fileStatus.ithenticateSimilarityScheduled) {
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

    function isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus)
    {
        // Check if EULA confirmation required for this tenant
        if (!contextStatus.eulaRequired) {
            return false;
        }

        // If no EULA is stamped with submission
        // means submission never passed through iThenticate process
        if (!submissionStatus.ithenticateEulaVersion) {
            return true;
        }

        // If no EULA is stamped with submitting user
        // means user has never previously interacted with iThenticate process
        if (!userStatus.ithenticateEulaVersion) {
            return true;
        }

        // If user and submission EULA do not match
        // means users previously agreed upon different EULA
        if (submissionStatus.ithenticateEulaVersion !== userStatus.ithenticateEulaVersion) {
            return true;
        }

        return false;
    }

    async function executePlagiarismAction(fileStatus)
    {
        const actionUrl = getActionUrl(fileStatus);
        
        const { 
            fetch: executeIthenticateAction, 
            data: ithenticateActionData,
        } = useFetch(actionUrl);
        
        await executeIthenticateAction();

        return ithenticateActionData;
    }

    fileStore.extender.extendFn('getItemActions', (originalResult, args) => {
        const submission = fileStore.props.submission;
        const submissionFile = isOPS() ? args.galley.file : args.file;

        // For OJS and OMP, actions are one allowed proper current workflow stage
        // e.g. when submission stage match the current workflow stage
        // and for OPS, as only available stage is production after submission done
        if (!isOPS() && (submission.stageId !== submissionStageId)) {
            return [...originalResult];
        }

        if (ithenticateStatus.value) {
            const fileStatus = deduceFileStatus(submissionFile, ithenticateStatus.value);
            const userStatus = ithenticateStatus.value?.user;
            const submissionStatus = ithenticateStatus.value?.submission;
            const contextStatus = ithenticateStatus.value?.context;

            // If file status is not found, return original result
            if (!fileStatus) {
                return [...originalResult];
            }

            // Action on non allowed file is restricted
            if (!fileStatus.ithenticateUploadAllowed) {
                return [...originalResult];
            }

            const { hasCurrentUserAtLeastOneRole } = useCurrentUser();
            if (!hasCurrentUserAtLeastOneRole(userStatus.ithenticateActionAllowedRoles)) {
                return [...originalResult];
            }

            return [
                ...originalResult,
                {
                    label: getLabel(userStatus, submissionStatus, fileStatus),
                    name: "conductPlagiarismCheck",
                    icon: "Globe",
                    actionFn: (args) => {

                        const {notify} = useNotify();
                        
                        if (!fileStatus.ithenticateId && isEulaConfirmationRequired(contextStatus, submissionStatus, userStatus)) {
                            const {useLegacyGridUrl} = pkp.modules.useLegacyGridUrl;

                            const {openLegacyModal} = useLegacyGridUrl({
                                component: 'plugins.generic.plagiarism.controllers.PlagiarismIthenticateHandler',
                                op: 'confirmEula',
                                params: {
                                    submissionId: submissionFile.submissionId,
                                    submissionFileId: submissionFile.id,
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

                                        const ithenticateActionData = await executePlagiarismAction(fileStatus);

                                        if (ithenticateActionData.value?.content) {
                                            notify(
                                                ithenticateActionData.value.content, 
                                                ithenticateActionData.value?.status ? 'success': 'warning'
                                            );
                                        }

                                        fetchIthenticateStatus();
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

        return [...originalResult];
    });
}

pkp.registry.storeExtend('fileManager_SUBMISSION_FILES', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_SUBMISSION_FILES');
});

pkp.registry.storeExtend('fileManager_EDITOR_REVIEW_FILES', (piniaContext) => {
    runPlagiarismAction(piniaContext, 'fileManager_EDITOR_REVIEW_FILES');
});

pkp.registry.storeExtend('galleyManager', (piniaContext) => {
    const { isOPS } = useApp();

    if (!isOPS()) {
        return;
    }

    runPlagiarismAction(piniaContext, 'galleyManager');
});
