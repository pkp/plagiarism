import ithenticateSimilarityScoreCell from "./Components/ithenticateSimilarityScoreCell.vue";

pkp.registry.registerComponent("ithenticateSimilarityScoreCell", ithenticateSimilarityScoreCell);

const { useLocalize } = pkp.modules.useLocalize;
const { useApp } = pkp.modules.useApp;
const { useNotify } = pkp.modules.useNotify;
const { useCurrentUser } = pkp.modules.useCurrentUser;
import { ref, computed, watch, onUnmounted } from "vue";
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

    const { notify } = useNotify();

    const eventSource = ref(null);
    const timeoutId = ref(null);
    
    const maxDuration = ref(600); // Store maxDuration from server, Default to 600 seconds

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
    
    watch(
        ithenticateRequestParams, 
        async (newRequestParams) => {
            if (newRequestParams?.fileIds?.length) {
                try {
                    await fetchIthenticateStatus();
                    fileStore.ithenticateStatus = ithenticateStatus;
                    
                    // Only if valid ithenticateStatus data available,
                    // then should look for possibility to initiate SSE stream
                    if (ithenticateStatus.value) {
                        // Only if require SSE streaming and no SSE stream already active
                        // initiate new SSE stream
                        if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                            streamPlagiarismResults(newRequestParams);
                        }
                    }
                } catch (error) {
                    console.error("Error fetching ithenticateStatus:", error);
                }
            }
        },
        { deep: true }
    );

    // Initial fetch for OPS
    if (isOPS()) {
        (async () => {
            try {
                await fetchIthenticateStatus();
                fileStore.ithenticateStatus = ithenticateStatus;

                // Only if valid ithenticateStatus data available,
                // then should look for possibility to initiate SSE stream
                if (ithenticateStatus.value && ithenticateRequestParams.value?.fileIds?.length) {
                    // Only if require SSE streaming and no SSE stream already active
                    // initiate new SSE stream
                    if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                        streamPlagiarismResults(ithenticateRequestParams.value);
                    }
                }
            } catch (error) {
                console.error("Error in initial OPS fetch:", error);
            }
        })();
    }

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

    function shouldStreamPlagiarismResults(status) {
        if (!status?.files) {
            return false;
        }
        return Object.values(status.files).some(file => 
            file.ithenticateId !== null &&
            file.ithenticateSimilarityResult === null
        );
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
                                async () => {
                                    await fetchIthenticateStatus();

                                    if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                                        streamPlagiarismResults(ithenticateRequestParams.value);
                                    }
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

                                        await fetchIthenticateStatus();

                                        if (shouldStreamPlagiarismResults(ithenticateStatus.value) && !eventSource.value) {
                                            streamPlagiarismResults(ithenticateRequestParams.value);
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

        return [...originalResult];
    });

    // Implementation for streaming plagiarism results
    function streamPlagiarismResults(params) {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }

        if (timeoutId.value) {
            clearTimeout(timeoutId.value);
            timeoutId.value = null;
        }

        if (!params?.fileIds?.length) {
            return;
        }

        const queryParams = new URLSearchParams({
            submissionId: params.submissionId,
            stageId: params.stageId,
            fileIds: params.fileIds.join(","),
        });
        const streamUrl = `${apiUrl.value}/stream?${queryParams.toString()}`;

        let lastMessageTime = Date.now();
        let pollingInterval = null;
        let retryCount = 0;
        const maxRetries = 3;

        // Initialize EventSource
        try {
            eventSource.value = new EventSource(streamUrl);

            // Fallback to polling if no SSE messages after 30 seconds
            const fallbackTimeout = setTimeout(() => {
                if (Date.now() - lastMessageTime > 30000) {
                    console.log("No SSE messages received, falling back to polling");
                    if (eventSource.value) {
                        eventSource.value.close();
                        eventSource.value = null;
                    }
                    pollingInterval = setInterval(async () => {
                        
                        try {
                            if (!fileStore.ithenticateStatus.value && retryCount < maxRetries) {
                                await fetchIthenticateStatus();
                                retryCount++;
                            } else if (shouldStreamPlagiarismResults(ithenticateStatus.value)) {
                                await fetchIthenticateStatus();
                                retryCount = 0; // Reset retries on successful fetch
                            } else {
                                clearInterval(pollingInterval);
                                pollingInterval = null;
                                retryCount = 0;
                            }
                        } catch (error) {
                            retryCount++;
                            if (retryCount >= maxRetries) {
                                clearInterval(pollingInterval);
                                pollingInterval = null;
                                retryCount = 0;
                            }
                        }
                    }, 10000);
                }
            }, 30000);

            // Handle SSE messages
            eventSource.value.onmessage = (event) => {
                lastMessageTime = Date.now();

                try {
                    const data = JSON.parse(event.data);
                    // console.log("Parsed data:", data);

                    // Check for maxDuration from server
                    if (data.maxDuration) {
                        maxDuration.value = Number(data.maxDuration);
                        
                        // Update timeout with new maxDuration
                        if (timeoutId.value) {
                            clearTimeout(timeoutId.value);
                            timeoutId.value = null;
                        }

                        timeoutId.value = setTimeout(() => {
                            console.log(`Client-side ${maxDuration.value}-second limit reached at:`, new Date().toISOString());
                            if (eventSource.value) {
                                eventSource.value.close();
                                eventSource.value = null;
                            }
                            timeoutId.value = null;
                            clearTimeout(fallbackTimeout);
                            if (pollingInterval) {
                                clearInterval(pollingInterval);
                            }
                            fetchIthenticateStatus();
                        }, maxDuration.value * 1000);

                        return;
                    }

                    if (data && fileStore.ithenticateStatus) {
                        
                        const oldFiles = { ...fileStore.ithenticateStatus.value?.files || {} };

                        fileStore.ithenticateStatus.value = {
                            ...fileStore.ithenticateStatus.value,
                            ...data,
                        };

                        let hasNewSimilarityResult = false,
                            hasNewReportScheduled = false;


                        if (data.files && oldFiles) {
                            Object.entries(data.files).forEach(([fileId, newFile]) => {
                                const oldFile = oldFiles[fileId];
                                if (oldFile && oldFile.ithenticateId !== null) {
                                    if (oldFile.ithenticateSimilarityResult === null && newFile.ithenticateSimilarityResult !== null) {
                                        hasNewSimilarityResult = true;
                                    }

                                    if (oldFile.ithenticateSimilarityScheduled != newFile.ithenticateSimilarityScheduled) {
                                        hasNewReportScheduled = true;
                                    }
                                }
                            });
                        }

                        if (hasNewReportScheduled) {
                            notify(
                                t('plugins.generic.plagiarism.action.scheduleSimilarityReport.success'),
                                'success'
                            );
                        }

                        if (hasNewSimilarityResult) {
                            notify(
                                t('plugins.generic.plagiarism.action.refreshSimilarityResult.success'),
                                'success'
                            );
                        }

                        if (hasNewSimilarityResult || hasNewReportScheduled) {
                            fetchIthenticateStatus();
                        }

                        if (!shouldStreamPlagiarismResults(fileStore.ithenticateStatus.value)) {
                            // No files require further SSE streaming, close connection
                            if (eventSource.value) {
                                eventSource.value.close();
                                eventSource.value = null;
                            }
                            if (timeoutId.value) {
                                clearTimeout(timeoutId.value);
                                timeoutId.value = null;
                            }

                            clearTimeout(fallbackTimeout);
                            if (pollingInterval) {
                                clearInterval(pollingInterval);
                            }

                            fetchIthenticateStatus();
                            return;
                        }
                    }
                } catch (e) {
                    console.error("Error parsing EventSource data:", e);
                }
            };

            // Handle custom stream_end event
            eventSource.value.addEventListener("stream_end", () => {
                if (eventSource.value) {
                    eventSource.value.close();
                    eventSource.value = null;
                }
                if (timeoutId.value) {
                    clearTimeout(timeoutId.value);
                    timeoutId.value = null;
                }

                clearTimeout(fallbackTimeout);
                if (pollingInterval) {
                    clearInterval(pollingInterval);
                }

                fetchIthenticateStatus();
            });

            eventSource.value.onerror = (event) => {
                if (eventSource.value) {
                    eventSource.value.close();
                    eventSource.value = null;
                }
                if (timeoutId.value) {
                    clearTimeout(timeoutId.value);
                    timeoutId.value = null;
                }

                clearTimeout(fallbackTimeout);

                pollingInterval = setInterval(() => {
                    if (shouldStreamPlagiarismResults(fileStore.ithenticateStatus.value)) {
                        fetchIthenticateStatus();
                    } else {
                        // No files require polling, stopping
                        clearInterval(pollingInterval);
                    }
                }, 10000);
                
                fetchIthenticateStatus();
            };

            eventSource.value.onopen = () => {
                console.log("EventSource connection opened at:", new Date().toISOString());
            };

        } catch (e) {
            console.error("Failed to initialize EventSource:", e);
            
            pollingInterval = setInterval(() => {
                if (shouldStreamPlagiarismResults(fileStore.ithenticateStatus.value)) {
                    fetchIthenticateStatus();
                } else {
                    clearInterval(pollingInterval);
                }
            }, 10000);
        }
        
    }

    // Cleanup on component unmount or page unload
    onUnmounted(() => {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }
        if (timeoutId.value) {
            clearTimeout(timeoutId.value);
            timeoutId.value = null;
        }
    });

    window.addEventListener("beforeunload", () => {
        if (eventSource.value) {
            eventSource.value.close();
            eventSource.value = null;
        }
        if (timeoutId.value) {
            clearTimeout(timeoutId.value);
            timeoutId.value = null;
        }
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
